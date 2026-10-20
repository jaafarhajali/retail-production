<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Settings;
use App\Models\CashSession;
use App\Models\Counter;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\User;

/**
 * Completing sales (spec §5–§7; voids removed 2026-10-05, a sale is undone with a return). One transaction touches sales, sale_items,
 * sale_payments, stock_movements, cash_movements, customer_ledger, counters and audit_log.
 */
final class SaleService
{
    /**
     * @param array{
     *   register_id: int, session_id: int, user_id: int, customer_id: ?int, price_level: string,
     *   lines: list<array{product_id: int, unit_id: int, qty?: string, amount_usd?: string, price?: ?string, discount?: string}>,
     *   invoice_discount: string, payments: list<array{method: string, currency: string, amount: string}>,
     *   change_currency: string, notes: string, pin: string
     * } $in
     * @return array{id: int, invoice_no: string, change_usd: string, change_lbp: int, warnings: list<string>}
     */
    public function complete(array $in): array
    {
        $p = $this->prepare($in);
        $pay = $this->payments($in, $p['total'], $p['rate'], $p['step'], $p['customer'], $p['needsPin']);
        // Change must come out of a drawer that has it; lira the customer just handed over counts (I5, warn and allow).
        $short = CashService::drawerShortfall((int) $in['session_id'], ['USD' => $pay['change_usd'], 'LBP' => $pay['change_lbp']], $pay['cash_in'], 'change');
        if ($short !== null && empty($in['allow_short_drawer'])) {
            throw new \DomainException($short, CashService::SHORT_DRAWER);
        }
        // Admin PIN for anything the cashier may not do alone (§15)
        $approver = $p['needsPin'] !== [] ? $this->verifyPin((string) ($in['pin'] ?? ''), $p['needsPin']) : null;
        $warnings = $p['warnings'];

        $result = Database::transaction(function () use ($in, $p, $pay, $approver, &$warnings): array {
            $no = Counter::format('INV-', Counter::next('invoice'));
            $sales = new Sale();
            $saleId = $sales->create($this->saleFields($no, $in, $p, $pay, 'completed'));
            foreach ($p['lines'] as $l) {
                $sales->addItem($saleId, $l);
                if ($l['track_stock'] && $l['stock_base'] - $l['base_qty'] < 0) {
                    $warnings[] = $l['product_name'] . ' is now below zero stock.';
                }
                if ($l['fill_mix_id'] !== null && $l['fill_stock'] - $l['fill_base'] * $l['base_qty'] < 0) {
                    $warnings[] = $l['fill_name'] . ' is now below zero stock.';   // filled at the sale (2026-10-18)
                }
            }
            $this->fulfil($saleId, $no, $p['lines'], $pay, $p['customer'], $p['rate'], (int) $in['session_id'], (int) $in['user_id']);
            Audit::log('sale.completed', 'sale', $saleId, ['no' => $no, 'lines' => count($p['lines']), 'level' => $p['level']], (float) $p['total'], 'USD');
            $this->auditOverrides($saleId, $p, $approver);

            return ['id' => $saleId, 'invoice_no' => $no];
        });
        $this->auditAfter($result, $p, $short);

        return $result + ['change_usd' => $pay['change_usd'], 'change_lbp' => $pay['change_lbp'], 'warnings' => $warnings, 'total_usd' => $p['total'], 'rounding_usd' => Money::fmt($pay['rounding'])];
    }

    /**
     * F3 (2026-10-13): the delivery invoice. Same lines and rules as a sale, a customer is required, and nothing else
     * happens yet: no payment, no stock, no ledger. It gets its number, prints, and goes out with the seller.
     *
     * @return array{id: int, invoice_no: string, total_usd: string}
     */
    public function hold(array $in): array
    {
        $p = $this->prepare($in);
        if ($p['customer'] === null) {
            throw new \DomainException('A held invoice needs the customer it goes to. Choose the customer first.');
        }
        $approver = $p['needsPin'] !== [] ? $this->verifyPin((string) ($in['pin'] ?? ''), $p['needsPin']) : null;
        $empty = ['payments' => [], 'change_usd' => '0.00', 'change_lbp' => 0, 'rounding' => 0.0];

        return Database::transaction(function () use ($in, $p, $empty, $approver): array {
            $no = Counter::format('INV-', Counter::next('invoice'));
            $sales = new Sale();
            $saleId = $sales->create($this->saleFields($no, $in, $p, $empty, 'on_hold') + ['held_at' => date('Y-m-d H:i:s')]);
            foreach ($p['lines'] as $l) {
                $sales->addItem($saleId, $l);
            }
            Audit::log('sale.held', 'sale', $saleId, ['no' => $no, 'customer' => $p['customer']['name'], 'lines' => count($p['lines'])], (float) $p['total'], 'USD');
            $this->auditOverrides($saleId, $p, $approver);

            return ['id' => $saleId, 'invoice_no' => $no, 'total_usd' => $p['total']];
        });
    }

    /**
     * A desk invoice (2026-10-14): written by the owner for a B2B customer, with no cash session. The lines follow the
     * same rules as a sale; the goods leave the stock now and the whole amount goes on the customer's account, due on
     * the date given or after the customer's payment terms. It is paid later: by transfer or cheque at the desk, or in
     * cash at the till (Collect debt), oldest invoice first.
     *
     * @return array{id: int, invoice_no: string, total_usd: string, due_date: string, warnings: list<string>}
     */
    public function issue(array $in): array
    {
        $in += ['register_id' => null, 'session_id' => null];
        $in['agreed_prices'] = (int) ($in['customer_id'] ?? 0) > 0 ? (new Customer())->agreedPrices((int) $in['customer_id']) : [];
        $p = $this->prepare($in);
        if ($p['customer'] === null) {
            throw new \DomainException('An invoice needs its customer. Choose the customer first.');
        }
        $pay = $this->payments(['payments' => [['method' => 'credit', 'currency' => 'USD', 'amount' => $p['total']]], 'change_currency' => 'USD'], $p['total'], $p['rate'], $p['step'], $p['customer'], $p['needsPin']);
        if ($p['short'] !== []) {   // the desk never forces it: the pieces on the invoice were packed, or they do not exist (2026-10-20)
            throw new \DomainException('Not enough in stock: ' . implode('; ', $p['short']) . '. Pack more first.');
        }
        if ($p['needsPin'] !== []) {
            throw new \DomainException('Only the administrator may do this: ' . implode(', ', $p['needsPin']) . '.');
        }
        $dueDate = (new InvoiceService())->dueDate($p['customer'], (string) ($in['due_date'] ?? ''));
        $shipTo = mb_substr(trim((string) ($in['ship_to'] ?? '')), 0, 255) ?: null;
        $warnings = $p['warnings'];

        $result = Database::transaction(function () use ($in, $p, $pay, $dueDate, $shipTo, &$warnings): array {
            $no = Counter::format('INV-', Counter::next('invoice'));
            $sales = new Sale();
            $saleId = $sales->create($this->saleFields($no, $in, $p, $pay, 'completed') + ['origin' => 'desk', 'due_date' => $dueDate, 'ship_to' => $shipTo]);
            foreach ($p['lines'] as $l) {
                $sales->addItem($saleId, $l);
                if ($l['track_stock'] && $l['stock_base'] - $l['base_qty'] < 0) {
                    $warnings[] = $l['product_name'] . ' is now below zero stock.';
                }
                if ($l['fill_mix_id'] !== null && $l['fill_stock'] - $l['fill_base'] * $l['base_qty'] < 0) {
                    $warnings[] = $l['fill_name'] . ' is now below zero stock.';   // filled at the sale (2026-10-18)
                }
            }
            $this->fulfil($saleId, $no, $p['lines'], $pay, $p['customer'], $p['rate'], null, (int) $in['user_id']);
            Audit::log('invoice.issued', 'sale', $saleId, ['no' => $no, 'customer' => $p['customer']['name'], 'lines' => count($p['lines']), 'due' => $dueDate], (float) $p['total'], 'USD');

            return ['id' => $saleId, 'invoice_no' => $no];
        });
        $this->auditAfter($result, $p, null);

        return $result + ['total_usd' => $p['total'], 'due_date' => $dueDate, 'warnings' => $warnings];
    }

    /**
     * F3: the seller is back. What came back is taken off the lines at the invoice's own prices, the net quantities
     * leave the stock, the cash goes into the settler's drawer and the rest onto the customer's account. The invoice
     * as printed stays in sale_settlements.
     *
     * @param array<int|string, string> $returns  sale_item_id => quantity that came back, in the line's unit
     * @param list<array{method: string, currency: string, amount: string}> $payments
     */
    public function settle(int $saleId, array $returns, array $payments, string $changeCurrency, bool $allowShort, string $pin, int $userId, int $sessionId, int $registerId): array
    {
        $sales = new Sale();
        $sale = $sales->find($saleId) ?? throw new \DomainException('Invoice not found.');
        if ($sale['status'] !== 'on_hold') {
            throw new \DomainException($sale['invoice_no'] . ' is not out with the seller: it was already finished.');
        }
        $customer = $sale['customer_id'] === null ? null : (new Customer())->find((int) $sale['customer_id']);
        $rate = (new ExchangeRate())->current();
        $step = Money::step();
        $products = new Product();
        $lines = [];
        $back = [];
        $returnedValue = 0.0;
        $netSubtotal = 0.0;
        foreach ($sales->items($saleId) as $i) {
            $text = trim((string) ($returns[(int) $i['id']] ?? ''));
            $retBase = 0;
            if ($text !== '' && (float) str_replace(',', '', $text) > 0) {
                $fraction = (int) $i['allows_fraction'] === 1;
                $retQty = Quantity::parse($text, $fraction);
                $retBase = Quantity::toBase($retQty, (int) $i['factor'], $fraction);
                if ($retBase > (int) $i['base_qty']) {
                    throw new \DomainException('Only ' . Quantity::unitQty((int) $i['base_qty'], (int) $i['factor']) . " {$i['unit_name']} of {$i['product_name']} went out, so no more than that can come back.");
                }
            }
            $netBase = (int) $i['base_qty'] - $retBase;
            $value = (int) $i['base_qty'] > 0 ? Money::fmt((float) $i['line_total_usd'] * $retBase / (int) $i['base_qty']) : '0.00';
            $ratio = (int) $i['base_qty'] > 0 ? $netBase / (int) $i['base_qty'] : 0;
            $product = $products->find((int) $i['product_id']);
            $lineTotal = Money::sub($i['line_total_usd'], $value);
            $lines[] = [
                'item_id' => (int) $i['id'], 'product_id' => (int) $i['product_id'], 'product_name' => $i['product_name'], 'base_qty' => $netBase,
                'qty' => Quantity::unitQty($netBase, (int) $i['factor']), 'line_total_usd' => $lineTotal, 'line_discount_usd' => Money::fmt((float) $i['line_discount_usd'] * $ratio),
                'cost_per_base' => $i['cost_per_base'], 'line_cost_usd' => Money::fmt($netBase * (float) $i['cost_per_base']), 'stock_base' => (int) ($product['stock_base'] ?? 0),
                'track_stock' => (int) ($product['track_stock'] ?? 1) === 1,
                'fill_mix_id' => $i['fill_mix_id'] !== null ? (int) $i['fill_mix_id'] : null, 'fill_base' => $i['fill_base'] !== null ? (int) $i['fill_base'] : null,
            ];
            if ($retBase > 0) {
                $back[] = ['sale_item_id' => (int) $i['id'], 'qty' => Quantity::unitQty($retBase, (int) $i['factor']), 'base_qty' => $retBase, 'value_usd' => $value, 'product_name' => $i['product_name']];
            }
            $returnedValue += (float) $value;
            $netSubtotal += (float) $lineTotal;
        }
        // Line totals already carry their share of the invoice discount (§6), so the net total is simply their sum;
        // the discount and the subtotal shown on the invoice shrink in the same proportion.
        $netTotal = Money::fmt($netSubtotal);
        $netDiscount = (float) $sale['total_usd'] > 0 ? Money::fmt((float) $sale['discount_usd'] * (float) $netTotal / (float) $sale['total_usd']) : '0.00';
        $netSubtotal = (float) $netTotal + (float) $netDiscount;
        if ((float) $netTotal <= 0.004 && $back === []) {
            throw new \DomainException('Nothing to settle on this invoice.');
        }
        $needsPin = [];
        $pay = $this->payments(['payments' => $payments, 'change_currency' => $changeCurrency], $netTotal, $rate, $step, $customer, $needsPin);
        $short = CashService::drawerShortfall($sessionId, ['USD' => $pay['change_usd'], 'LBP' => $pay['change_lbp']], $pay['cash_in'], 'change');
        if ($short !== null && !$allowShort) {
            throw new \DomainException($short, CashService::SHORT_DRAWER);
        }
        $approver = $needsPin !== [] ? $this->verifyPin($pin, $needsPin) : null;
        $warnings = [];

        $result = Database::transaction(function () use ($sales, $sale, $saleId, $lines, $back, $pay, $customer, $rate, $userId, $sessionId, $registerId, $netSubtotal, $netDiscount, $netTotal, $returnedValue, $approver, $needsPin, &$warnings): array {
            foreach ($lines as $l) {
                $sales->settleItem($l['item_id'], $l);
                if ($l['track_stock'] && $l['base_qty'] > 0 && $l['stock_base'] - $l['base_qty'] < 0) {
                    $warnings[] = $l['product_name'] . ' is now below zero stock.';
                }
            }
            $sales->settle($saleId, [
                'session_id' => $sessionId, 'register_id' => $registerId, 'subtotal_usd' => Money::fmt($netSubtotal), 'discount_usd' => $netDiscount, 'total_usd' => $netTotal,
                'rounding_usd' => Money::fmt($pay['rounding']), 'cost_total_usd' => Money::fmt(array_sum(array_map(static fn (array $l): float => (float) $l['line_cost_usd'], $lines))),
                'exchange_rate' => $rate, 'change_usd' => $pay['change_usd'], 'change_lbp' => $pay['change_lbp'], 'settled_by' => $userId,
            ]);
            $this->fulfil($saleId, $sale['invoice_no'], array_filter($lines, static fn (array $l): bool => $l['base_qty'] > 0), $pay, $customer, $rate, $sessionId, $userId);
            $paidCash = array_sum(array_map(static fn (array $x): float => $x['method'] === 'credit' ? 0.0 : (float) $x['amount_usd'], $pay['payments']));
            $settlementId = $sales->addSettlement($saleId, [
                'original_usd' => $sale['total_usd'], 'returned_usd' => Money::fmt($returnedValue), 'net_usd' => $netTotal, 'paid_usd' => Money::fmt($paidCash - (float) $pay['change_usd'] - (float) Money::lbpToUsd($pay['change_lbp'], $rate)),
                'balance_usd' => Money::fmt($pay['credit']), 'settled_by' => $userId, 'notes' => null,
            ]);
            foreach ($back as $b) {
                $sales->addSettlementReturn($settlementId, $b);
            }
            Audit::log('sale.settled', 'sale', $saleId, ['no' => $sale['invoice_no'], 'original' => $sale['total_usd'], 'returned' => Money::fmt($returnedValue), 'net' => $netTotal, 'credit' => Money::fmt($pay['credit'])], (float) $netTotal, 'USD');
            if ($approver !== null) {
                Audit::log('pin.override', 'sale', $saleId, ['approved_by' => $approver['username'], 'for' => $needsPin]);
            }

            return ['id' => $saleId, 'invoice_no' => $sale['invoice_no'], 'original_usd' => $sale['total_usd'], 'returned_usd' => Money::fmt($returnedValue), 'net_usd' => $netTotal,
                    'paid_usd' => Money::fmt($paidCash), 'balance_usd' => Money::fmt($pay['credit']), 'change_usd' => $pay['change_usd'], 'change_lbp' => $pay['change_lbp'], 'returned_lines' => $back];
        });
        if ($short !== null) {
            Audit::log('drawer.short', 'sale', $saleId, ['no' => $sale['invoice_no'], 'warning' => $short]);
        }

        return $result + ['warnings' => $warnings];
    }

    /** The lines, totals, customer and what needs the administrator: everything a sale needs before money or stock move. */
    private function prepare(array $in): array
    {
        $rate = (new ExchangeRate())->current();
        $step = Money::step();
        $level = $in['price_level'] === 'wholesale' ? 'wholesale' : 'retail';
        $needsPin = [];
        $warnings = [];
        if ($level === 'wholesale' && !Gate::allows('sale.wholesale')) {
            $needsPin[] = 'wholesale prices';
        }
        $customer = null;
        if (!empty($in['customer_id'])) {
            $customer = (new Customer())->find((int) $in['customer_id']) ?? throw new \DomainException('Customer not found.');
        }

        ['lines' => $lines, 'subtotal' => $subtotal, 'invoiceDiscount' => $invoiceDiscount, 'total' => $total, 'listGross' => $listGross, 'cutTotal' => $cutTotal, 'worst' => $worst]
            = $this->priceLines($in, $level, $needsPin);
        // More than the stock (2026-10-20): every piece comes from Packing, so the stock is the real one and a sale beyond it
        // is a mistake, not a lag. The till needs the administrator's PIN to force it (the pieces are on the table, Packing
        // is late); a desk invoice is refused (issue()). A box filled at the sale is only warned about: its mix is weighed by hand.
        $asked = [];
        foreach ($lines as $l) {
            if ($l['track_stock'] && $l['fill_mix_id'] === null) {
                $asked[$l['product_id']] = ($asked[$l['product_id']] ?? 0) + $l['base_qty'];
            }
        }
        $short = [];
        foreach ($lines as $l) {
            if (isset($asked[$l['product_id']]) && $asked[$l['product_id']] > $l['stock_base']) {
                $short[] = $l['product_name'] . ' (' . number_format(max(0, $l['stock_base'])) . ' in stock, ' . number_format($asked[$l['product_id']]) . ' asked)';
                unset($asked[$l['product_id']]);
            }
        }
        if ($short !== []) {
            $needsPin[] = 'more than the stock: ' . implode(', ', $short);
        }
        $low = $this->belowCostLines($lines);
        $belowCost = array_values(array_unique(array_column($low, 'name')));
        // A discount or a changed price that takes a line under its cost is the administrator's call, whatever the percentage.
        // A list price that is already under cost is only warned about: the cashier did nothing to cause it.
        $lowered = array_values(array_unique(array_column(array_filter($low, static fn (array $l): bool => $l['lowered']), 'name')));
        if ($lowered !== [] && (int) (Auth::user()['is_super'] ?? 0) !== 1) {
            $needsPin[] = 'sale below cost: ' . implode(', ', $lowered);
        }
        foreach ($belowCost as $name) {
            $warnings[] = $name . ' was sold below cost.';
        }
        // The allowed percentage is a ceiling on every single discount (bug of 2026-10-09: measured on the whole cart, 20 % on a $10
        // jar hid behind a $120 box of 24). It is checked on each line (a lowered price counts as a discount), on the invoice
        // discount, and on the sale as a whole; the first one over the ceiling needs the administrator.
        if (!Gate::allows('sale.discount')) {
            $maxPct = (float) Settings::get('max_cashier_discount_pct', '0');
            $checks = [];
            if ($worst['pct'] > 0) {
                $checks[] = [$worst['pct'], sprintf('discount of %.1f%% on %s', $worst['pct'], $worst['name'])];
            }
            $invoicePct = (float) $subtotal > 0 ? (float) $invoiceDiscount / (float) $subtotal * 100 : 0;
            if ($invoicePct > 0) {
                $checks[] = [$invoicePct, sprintf('invoice discount of %.1f%%', $invoicePct)];
            }
            $overallPct = $listGross > 0 ? ($cutTotal + (float) $invoiceDiscount) / $listGross * 100 : 0;
            if ($overallPct > 0) {
                $checks[] = [$overallPct, sprintf('discount of %.1f%%', $overallPct)];
            }
            foreach ($checks as [$pct, $what]) {
                if ($pct > $maxPct + 0.0001) {
                    $needsPin[] = $what;
                    break;
                }
            }
        }

        return ['rate' => $rate, 'step' => $step, 'level' => $level, 'customer' => $customer, 'lines' => $lines, 'subtotal' => $subtotal, 'invoiceDiscount' => $invoiceDiscount,
                'total' => $total, 'needsPin' => $needsPin, 'warnings' => $warnings, 'belowCost' => $belowCost, 'short' => $short];
    }

    /** Payments (§7.1), then the settlement: tolerance, change and rounding (§7.2, §7.3). */
    private function payments(array $in, string $total, int $rate, int $step, ?array $customer, array &$needsPin): array
    {
        $payments = [];
        $paid = 0.0;
        $credit = 0.0;
        foreach ($in['payments'] as $p) {
            $method = (string) ($p['method'] ?? '');
            $currency = (string) ($p['currency'] ?? 'USD');
            if ($method === 'cash' && $currency === 'LBP') {
                $amount = (string) CashService::parseLbp((string) $p['amount'], false);
                $usd = Money::lbpToUsd((int) $amount, $rate);
            } elseif (in_array($method, ['cash', 'card', 'credit'], true)) {
                $currency = 'USD';
                $amount = Pricing::parse((string) $p['amount']);
                $usd = $amount;
            } else {
                throw new \DomainException('Unknown payment method.');
            }
            if ((float) $usd <= 0) {
                continue;
            }
            if ($method === 'credit') {
                if ($customer === null) {
                    throw new \DomainException('Credit needs a customer on the sale.');
                }
                if (!Gate::allows('sale.credit')) {
                    $needsPin[] = 'sale on credit';
                }
                $credit += (float) $usd;
            }
            $payments[] = ['method' => $method, 'currency' => $currency, 'amount' => $amount, 'amount_usd' => $usd];
            $paid += (float) $usd;
        }
        if ($credit > 0 && $customer !== null && $customer['credit_limit_usd'] !== null
            && (float) $customer['balance_usd'] + $credit > (float) $customer['credit_limit_usd'] + 0.004) {
            $needsPin[] = 'credit limit of ' . usd($customer['credit_limit_usd']);
        }

        $remaining = round((float) $total - $paid, 2);
        $tolerance = ($step / 2) / $rate;
        $rounding = 0.0;
        $changeUsd = '0.00';
        $changeLbp = 0;
        if ($remaining > 0.004) {
            if ($remaining <= $tolerance + 0.000001) {
                $rounding = -$remaining;   // shop loss of a few hundred lira
            } else {
                throw new \DomainException('Not fully paid: ' . usd($remaining) . ' (' . lbp(Money::roundLbp(Money::usdToLbp(Money::fmt($remaining), $rate), $step)) . ') is still due.');
            }
        } elseif ($remaining < -0.004) {
            $over = -$remaining;
            if ($credit > 0 && $paid - $credit < (float) $total - 0.004) {
                throw new \DomainException('Credit cannot exceed the amount due.');
            }
            $changeCurrency = (string) ($in['change_currency'] ?? 'LBP');
            if ($changeCurrency === 'USD') {
                $whole = floor($over);
                $rest = round($over - $whole, 2);
                $changeUsd = Money::fmt($whole);
                if ($rest > 0.004) {
                    $exact = Money::usdToLbp(Money::fmt($rest), $rate);
                    $changeLbp = Money::roundLbp($exact, $step);
                    $rounding = round($rest - (float) Money::lbpToUsd($changeLbp, $rate), 2);
                }
            } else {
                $exact = Money::usdToLbp(Money::fmt($over), $rate);
                $changeLbp = Money::roundLbp($exact, $step);
                $rounding = round($over - (float) Money::lbpToUsd($changeLbp, $rate), 2);
            }
        }
        $cashIn = ['USD' => 0.0, 'LBP' => 0.0];
        foreach ($payments as $p) {
            if ($p['method'] === 'cash') {
                $cashIn[$p['currency']] += (float) $p['amount'];
            }
        }

        return ['payments' => $payments, 'paid' => $paid, 'credit' => $credit, 'change_usd' => $changeUsd, 'change_lbp' => $changeLbp, 'rounding' => $rounding, 'cash_in' => $cashIn];
    }

    private function saleFields(string $no, array $in, array $p, array $pay, string $status): array
    {
        return [
            'invoice_no' => $no, 'register_id' => $in['register_id'], 'session_id' => $in['session_id'], 'user_id' => $in['user_id'],
            'customer_id' => $p['customer'] === null ? null : (int) $p['customer']['id'], 'price_level' => $p['level'], 'subtotal_usd' => Money::fmt($p['subtotal']),
            'discount_usd' => $p['invoiceDiscount'], 'total_usd' => $p['total'], 'rounding_usd' => Money::fmt($pay['rounding']),
            'cost_total_usd' => Money::fmt(array_sum(array_map(static fn (array $l): float => (float) $l['line_cost_usd'], $p['lines']))),
            'exchange_rate' => $p['rate'], 'change_usd' => $pay['change_usd'], 'change_lbp' => $pay['change_lbp'], 'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'status' => $status,
            'invoice_type' => ($in['invoice_type'] ?? 'normal') === 'official' ? 'official' : 'normal',
        ];
    }

    /** Stock out, payments and their cash movements, change, and the credit part on the customer's ledger. */
    private function fulfil(int $saleId, string $no, array $lines, array $pay, ?array $customer, int $rate, ?int $sessionId, int $userId): void
    {
        $stock = new StockService();
        $products = new Product();
        foreach ($lines as $l) {
            if (!empty($l['fill_mix_id']) && (int) $l['base_qty'] > 0) {
                // filled at the sale (2026-10-18): the grams of mix and the packaging leave the stock; the box has none of its own
                $mix = $products->find((int) $l['fill_mix_id']);
                $stock->move((int) $l['fill_mix_id'], -((int) $l['fill_base'] * (int) $l['base_qty']), 'sale', null, $mix['cost_per_base'] ?? null, 'sale', $saleId, $userId, $sessionId);
                foreach ($products->packaging((int) $l['product_id']) as $pk) {
                    $stock->move((int) $pk['material_id'], -((int) $pk['qty_base'] * (int) $l['base_qty']), 'sale', null, null, 'sale', $saleId, $userId, $sessionId);
                }
                continue;
            }
            if (!($l['track_stock'] ?? true)) {
                continue;   // F6: assembled on the spot, no stock of its own
            }
            $stock->move($l['product_id'], -$l['base_qty'], 'sale', null, $l['cost_per_base'], 'sale', $saleId, $userId, $sessionId);
        }
        $sales = new Sale();
        $cash = new CashSession();
        foreach ($pay['payments'] as $p) {
            $sales->addPayment($saleId, $p['method'], $p['currency'], $p['amount'], $p['amount_usd']);
            if ($p['method'] === 'cash') {
                $cash->addMovement(['session_id' => $sessionId, 'currency' => $p['currency'], 'amount' => $p['amount'], 'type' => 'sale', 'ref_type' => 'sale', 'ref_id' => $saleId, 'user_id' => $userId]);
            }
        }
        if ((float) $pay['change_usd'] > 0) {
            $cash->addMovement(['session_id' => $sessionId, 'currency' => 'USD', 'amount' => '-' . $pay['change_usd'], 'type' => 'change', 'ref_type' => 'sale', 'ref_id' => $saleId, 'user_id' => $userId]);
        }
        if ($pay['change_lbp'] > 0) {
            $cash->addMovement(['session_id' => $sessionId, 'currency' => 'LBP', 'amount' => '-' . $pay['change_lbp'], 'type' => 'change', 'ref_type' => 'sale', 'ref_id' => $saleId, 'user_id' => $userId]);
        }
        if ($pay['credit'] > 0 && $customer !== null) {
            (new Customer())->addLedger(['customer_id' => (int) $customer['id'], 'type' => 'sale_credit', 'amount_usd' => Money::fmt($pay['credit']), 'currency' => 'USD',
                                         'amount_original' => Money::fmt($pay['credit']), 'exchange_rate' => $rate, 'sale_id' => $saleId, 'session_id' => $sessionId, 'user_id' => $userId, 'note' => $no]);
        }
    }

    private function auditOverrides(int $saleId, array $p, ?array $approver): void
    {
        if ($approver !== null) {
            Audit::log('pin.override', 'sale', $saleId, ['approved_by' => $approver['username'], 'for' => $p['needsPin']]);
        }
        foreach ($p['lines'] as $l) {
            if ($l['price_overridden']) {
                Audit::log('sale.price_override', 'sale', $saleId, ['product' => $l['product_name'], 'price' => $l['charged_price_usd'] ?? $l['unit_price_usd'], 'list' => $l['unit_price_usd']]);
            }
        }
    }

    private function auditAfter(array $result, array $p, ?string $short): void
    {
        if ($p['belowCost'] !== []) {
            Audit::log('sale.below_cost', 'sale', (int) $result['id'], ['no' => $result['invoice_no'], 'products' => $p['belowCost']]);
        }
        if ($short !== null) {   // confirmed by the cashier: the owner sees who gave change the drawer did not hold
            Audit::log('drawer.short', 'sale', (int) $result['id'], ['no' => $result['invoice_no'], 'warning' => $short]);
        }
    }


    /** An Admin types their PIN on the till to approve one action. Returns the approving user. */
    /**
     * Prices the cart: list or changed prices and line discounts, then the invoice discount spread across the lines (§6).
     * Adds to $needsPin what the signed-in user may not do alone.
     *
     * @return array{lines: list<array<string, mixed>>, gross: float, lineDiscounts: float, subtotal: float, invoiceDiscount: string, total: string}
     */
    private function priceLines(array $in, string $level, array &$needsPin): array
    {
        // Lines
        $units = new ProductUnit();
        $products = new Product();
        $lines = [];
        $gross = 0.0;
        $lineDiscounts = 0.0;
        $listGross = 0.0;   // what the lines would cost at the list price
        $cutTotal = 0.0;    // what the cashier took off them: line discounts and lowered prices
        $worst = ['pct' => 0.0, 'name' => ''];
        foreach ($in['lines'] as $l) {
            $unit = $units->find((int) ($l['unit_id'] ?? 0));
            if ($unit === null || (int) $unit['product_id'] !== (int) ($l['product_id'] ?? 0)) {
                throw new \DomainException('A line refers to an unknown product or unit.');
            }
            $product = $products->find((int) $unit['product_id']);
            if ($product === null || (int) $product['is_active'] !== 1) {
                throw new \DomainException('Product ' . ($product['name'] ?? '') . ' is not active.');
            }
            if (($product['kind'] ?? 'finished') !== 'finished') {
                throw new \DomainException($product['name'] . ' is a ' . ($product['kind'] === 'material' ? 'raw material' : 'mix') . ', it is not sold.');
            }
            $listPrice = $level === 'wholesale' ? $unit['wholesale_price'] : $unit['retail_price'];
            $catalogPrice = $listPrice;   // the price the line shows; anything charged under it is recorded as a discount (2026-10-20)
            if (isset($in['agreed_prices'][(int) $unit['id']])) {
                $listPrice = $in['agreed_prices'][(int) $unit['id']];   // the price agreed with this customer (desk invoices, 2026-10-14)
            }
            if ($listPrice === null || (float) $listPrice <= 0) {
                throw new \DomainException("{$product['name']} ({$unit['name']}) is not sold at the {$level} price.");
            }
            $price = $listPrice;
            $overridden = false;
            if (isset($l['price']) && $l['price'] !== null && trim((string) $l['price']) !== '') {
                $override = Pricing::parse((string) $l['price']);
                if (Money::cmp($override, $listPrice) !== 0) {
                    if ((int) $product['allow_price_override'] !== 1) {
                        throw new \DomainException("{$product['name']} does not allow a price override.");
                    }
                    if (!Gate::allows('sale.price_override')) {
                        $needsPin[] = 'price override on ' . $product['name'];
                    }
                    $price = $override;
                    $overridden = true;
                }
            }
            $factor = (int) $unit['factor'];
            $fraction = (int) $unit['allows_fraction'] === 1;
            if (isset($l['amount_usd']) && trim((string) $l['amount_usd']) !== '') {
                // Sell by amount (§4): the total stays what the customer pays; grams round down.
                $amount = Pricing::parse((string) $l['amount_usd']);
                $base = (int) floor((float) $amount / ((float) $price / $factor));
                if ($base <= 0) {
                    throw new \DomainException('The amount is too small for one ' . $product['base_unit'] . ' of ' . $product['name'] . '.');
                }
                $qty = Quantity::unitQty($base, $factor);
                $lineGross = $amount;
                $mode = 'amount';
            } else {
                $qty = Quantity::parse((string) ($l['qty'] ?? '1'), $fraction);
                if ((float) $qty <= 0) {
                    throw new \DomainException('Enter a quantity for ' . $product['name'] . '.');
                }
                $base = Quantity::toBase($qty, $factor, $fraction);
                $lineGross = Money::mul($price, (float) $qty);
                $mode = 'qty';
            }
            $discount = Pricing::parse((string) ($l['discount'] ?? ''), true) ?? '0.00';
            if (Money::cmp($discount, $lineGross) > 0) {
                throw new \DomainException('A line discount cannot exceed the line total.');
            }
            $lineTotal = Money::sub($lineGross, $discount);
            // The price is never lowered on the record (owner, 2026-10-20): a price typed under the list price, or a customer's
            // agreed price, keeps the list price on the line and the difference becomes part of its discount, so every
            // reduction is counted in the discounts. A price typed above the list (a bigger custom box) stays a price.
            $shownPrice = $catalogPrice !== null && (float) $catalogPrice > 0 && Money::cmp($catalogPrice, $price) > 0 ? $catalogPrice : $price;
            $priceCut = $shownPrice === $price ? '0.00' : Money::fmt(max(0.0, (float) Money::mul($shownPrice, (float) $qty) - (float) $lineGross));
            $gross += (float) $lineGross;
            $lineDiscounts += (float) $discount;
            $atList = (float) Money::mul($listPrice, (float) $qty);
            $cut = max(0.0, $atList - (float) $lineTotal);
            $listGross += $atList;
            $cutTotal += $cut;
            if ($atList > 0 && $cut / $atList * 100 > $worst['pct']) {
                $worst = ['pct' => $cut / $atList * 100, 'name' => $product['name']];
            }
            // Filled at the sale (2026-10-18): the grams of mix weighed into each piece (the product's usual weight unless
            // typed); the piece costs that mix plus its packaging, and the sale takes both out of stock (fulfil()).
            $costPerBase = (string) $product['cost_per_base'];
            $fill = null;
            if ($product['fill_mix_id'] !== null) {
                $typed = trim((string) ($l['fill'] ?? ''));
                $grams = $typed === '' ? (int) ($product['fill_base'] ?? 0) : (ctype_digit($typed) ? (int) $typed : -1);
                if ($grams < 1 || $grams > 100000) {
                    throw new \DomainException("Type how many grams of marshmallow go into one {$product['name']}.");
                }
                $mix = $products->find((int) $product['fill_mix_id']) ?? throw new \DomainException('The mix of ' . $product['name'] . ' no longer exists.');
                $packCost = 0.0;
                foreach ($products->packaging((int) $product['id']) as $pk) {
                    $packCost += (int) $pk['qty_base'] * (float) (($products->find((int) $pk['material_id']) ?? [])['cost_per_base'] ?? 0);
                }
                $costPerBase = number_format($grams * (float) $mix['cost_per_base'] + $packCost, 6, '.', '');
                $fill = ['mix_id' => (int) $mix['id'], 'grams' => $grams, 'name' => $mix['name'], 'stock' => (int) $mix['stock_base']];
            }
            $lines[] = [
                'product_id' => (int) $product['id'], 'product_unit_id' => (int) $unit['id'], 'product_name' => $product['name'], 'unit_name' => $unit['name'],
                'qty' => $qty, 'base_qty' => $base, 'unit_price_usd' => $shownPrice, 'line_discount_usd' => Money::add($discount, $priceCut), 'line_total_usd' => $lineTotal,
                'price_cut_usd' => $priceCut, 'charged_price_usd' => $price,
                'cost_per_base' => $costPerBase, 'line_cost_usd' => Money::fmt($base * (float) $costPerBase),
                'entry_mode' => $mode, 'price_overridden' => $overridden, 'stock_base' => (int) $product['stock_base'],
                'note' => mb_substr(trim((string) ($l['note'] ?? '')), 0, 255) ?: null, 'track_stock' => (int) ($product['track_stock'] ?? 1) === 1,
                'fill_mix_id' => $fill['mix_id'] ?? null, 'fill_base' => $fill['grams'] ?? null, 'fill_name' => $fill['name'] ?? null, 'fill_stock' => $fill['stock'] ?? 0,
            ];
        }
        if ($lines === []) {
            throw new \DomainException('The cart is empty.');
        }

        // Invoice discount, spread across lines in proportion (§6)
        $subtotal = array_sum(array_map(static fn (array $l): float => (float) $l['line_total_usd'], $lines));
        $invoiceDiscount = Pricing::parse((string) ($in['invoice_discount'] ?? ''), true) ?? '0.00';
        if ((float) $invoiceDiscount > $subtotal + 0.0001) {
            throw new \DomainException('The discount cannot exceed the subtotal.');
        }
        $spread = 0.0;
        $last = count($lines) - 1;
        foreach ($lines as $i => &$line) {
            $share = $i === $last
                ? (float) $invoiceDiscount - $spread
                : ($subtotal > 0 ? round((float) $invoiceDiscount * (float) $line['line_total_usd'] / $subtotal, 2) : 0.0);
            $spread += $share;
            $line['line_discount_usd'] = Money::fmt((float) $line['line_discount_usd'] + $share);
            $line['line_total_usd'] = Money::fmt((float) $line['line_total_usd'] - $share);
        }
        unset($line);
        $total = Money::fmt($subtotal - (float) $invoiceDiscount);

        return ['lines' => $lines, 'gross' => $gross, 'lineDiscounts' => $lineDiscounts, 'subtotal' => $subtotal, 'invoiceDiscount' => $invoiceDiscount, 'total' => $total,
            'listGross' => $listGross, 'cutTotal' => $cutTotal, 'worst' => $worst];
    }

    /**
     * The lines whose amount after every discount is under their cost, as [i => position in the cart, name,
     * lowered => a discount or a changed price did it (and not the list price alone)].
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array{i: int, name: string, lowered: bool}>
     */
    private function belowCostLines(array $lines): array
    {
        $low = [];
        foreach ($lines as $i => $l) {
            if ((float) $l['line_cost_usd'] > 0 && (float) $l['line_total_usd'] < (float) $l['line_cost_usd'] - 0.004) {
                $low[] = ['i' => $i, 'name' => (string) $l['product_name'], 'lowered' => (float) $l['line_discount_usd'] - (float) ($l['price_cut_usd'] ?? 0) > 0.004 || (bool) $l['price_overridden']];
            }
        }

        return $low;
    }

    /**
     * For the till, before the sale is completed: which lines would be sold below cost. It answers yes or no per line
     * and never the cost itself, so a cashier who may not see costs still gets the warning.
     *
     * @return list<array{i: int, name: string, lowered: bool}>
     */
    public function belowCost(array $in): array
    {
        $needsPin = [];
        $level = ($in['price_level'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
        $in['lines'] = is_array($in['lines'] ?? null) ? $in['lines'] : [];

        return $this->belowCostLines($this->priceLines($in, $level, $needsPin)['lines']);
    }

    public function verifyPin(string $pin, array $for): array
    {
        if ($pin === '') {
            throw new \DomainException('Needs an Admin PIN: ' . implode(', ', $for) . '.');
        }
        foreach ((new User())->all() as $u) {
            if ((int) $u['is_super'] === 1 && (int) $u['is_active'] === 1 && $u['pin_hash'] !== null && password_verify($pin, (string) $u['pin_hash'])) {
                return $u;
            }
        }
        Audit::log('pin.failed', null, null, ['for' => $for, 'by' => Auth::id()]);
        throw new \DomainException('Wrong PIN.');
    }
}
