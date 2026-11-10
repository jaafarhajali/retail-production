<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Counter;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Supplier;

/** Posting a purchase: stock in, moving-average cost, supplier ledger (spec §11). */
final class PurchaseService
{
    /**
     * @param array<int, array{product_id: int, unit_id: int, qty: string, unit_cost: string}> $lines
     * @return int purchase id
     */
    public function create(?int $supplierId, string $ref, string $date, array $lines, string $paidNow, string $paidFrom, string $notes, int $userId, string $discount = ''): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \DomainException('Choose the purchase date.');
        }
        $supplier = null;
        if ($supplierId !== null && $supplierId > 0) {
            $supplier = (new Supplier())->find($supplierId) ?? throw new \DomainException('Supplier not found.');
        }
        $paid = Pricing::parse($paidNow, true) ?? '0.00';
        if ((float) $paid > 0 && $supplier === null) {
            throw new \DomainException('A payment needs a supplier. Leave "paid now" empty for a cash purchase without a supplier.');
        }
        $units = new ProductUnit();
        $products = new Product();
        $prepared = [];
        $total = 0.0;
        foreach ($lines as $l) {
            $unit = $units->find((int) $l['unit_id']);
            if ($unit === null || (int) $unit['product_id'] !== (int) $l['product_id']) {
                throw new \DomainException('Choose a product and one of its units on every line.');
            }
            $product = $products->find((int) $l['product_id']) ?? throw new \DomainException('Product not found.');
            if (($product['kind'] ?? 'finished') !== 'material') {
                throw new \DomainException("{$product['name']} is not bought from a supplier: it is " . ($product['kind'] === 'intermediate' ? 'a mix made by production' : 'a finished product made by production') . '. A purchase takes raw materials and packaging only.');
            }
            $qty = Quantity::parse((string) $l['qty'], (int) $unit['allows_fraction'] === 1);
            if ((float) $qty <= 0) {
                throw new \DomainException("Enter a quantity for {$product['name']}.");
            }
            $unitCost = Pricing::parse((string) $l['unit_cost']);
            $base = Quantity::toBase($qty, (int) $unit['factor'], (int) $unit['allows_fraction'] === 1);
            $lineTotal = Money::mul($unitCost, (float) $qty);
            $total += (float) $lineTotal;
            $prepared[] = [
                'product_id' => (int) $product['id'], 'product_unit_id' => (int) $unit['id'], 'qty' => $qty, 'base_qty' => $base,
                'unit_cost_usd' => $unitCost, 'line_total_usd' => $lineTotal, 'cost_per_base' => Pricing::costPerBase($unitCost, (int) $unit['factor']),
            ];
        }
        if ($prepared === []) {
            throw new \DomainException('Add at least one line.');
        }
        // The supplier's discount on the bill (2026-10-30), "10" dollars or "10%": spread across the lines in proportion, as on an
        // invoice, so each material goes into stock at its real net cost. The biggest line takes the rounding remainder (a free
        // sample as the last line would otherwise end with a negative cost, review of 2026-11-10).
        $subtotalUsd = Money::fmt($total);
        $discountUsd = self::discountAmount($discount, $total);
        $spread = 0.0;
        $biggest = 0;
        foreach ($prepared as $i => $l) {
            if ((float) $l['line_total_usd'] > (float) $prepared[$biggest]['line_total_usd']) {
                $biggest = $i;
            }
        }
        foreach ($prepared as $i => $l) {   // every line but the biggest takes its rounded share; the biggest takes what is left
            if ($i !== $biggest) {
                $spread += $total > 0 ? round((float) $discountUsd * (float) $l['line_total_usd'] / $total, 2) : 0.0;
            }
        }
        $rest = (float) $discountUsd - $spread;
        $spread = 0.0;
        foreach ($prepared as $i => &$line) {
            $share = $i === $biggest ? $rest : ($total > 0 ? round((float) $discountUsd * (float) $line['line_total_usd'] / $total, 2) : 0.0);
            $spread += $share;
            $net = (float) $line['line_total_usd'] - $share;
            $line['line_discount_usd'] = Money::fmt($share);
            $line['line_total_usd'] = Money::fmt($net);
            $line['cost_per_base'] = number_format($line['base_qty'] > 0 ? $net / $line['base_qty'] : 0, 6, '.', '');
        }
        unset($line);
        $totalUsd = Money::fmt($total - (float) $discountUsd);
        if (Money::cmp($paid, $totalUsd) > 0) {
            throw new \DomainException('Paid now cannot exceed the purchase total.');
        }
        // A supplier is never paid from the drawer (owner, 2026-10-09): "paid now" always comes from outside, so no cash session is touched.
        $sessionId = null;

        return Database::transaction(function () use ($supplier, $ref, $date, $prepared, $subtotalUsd, $discountUsd, $totalUsd, $paid, $notes, $userId, $sessionId): int {
            $no = Counter::format('PUR-', Counter::next('purchase'));
            $purchases = new Purchase();
            $id = $purchases->create([
                'purchase_no' => $no, 'supplier_id' => $supplier === null ? null : (int) $supplier['id'], 'supplier_invoice_ref' => $ref === '' ? null : mb_substr($ref, 0, 60),
                'purchase_date' => $date, 'subtotal_usd' => $subtotalUsd, 'discount_usd' => $discountUsd, 'total_usd' => $totalUsd, 'paid_now_usd' => $paid,
                'notes' => $notes === '' ? null : $notes, 'user_id' => $userId, 'session_id' => $sessionId,
            ]);
            $stock = new StockService();
            foreach ($prepared as $line) {
                $purchases->addItem($id, $line);
                $stock->purchase($line['product_id'], $line['base_qty'], $line['cost_per_base'], $id, $userId);
            }
            if ($supplier !== null) {
                $suppliers = new Supplier();
                $suppliers->addLedger((int) $supplier['id'], 'purchase', $totalUsd, $id, null, $userId, $no);
                if ((float) $paid > 0) {
                    $suppliers->addLedger((int) $supplier['id'], 'payment', '-' . $paid, $id, null, $userId, 'Paid with ' . $no);
                }
            }
            Audit::log('purchase.posted', 'purchase', $id, ['no' => $no, 'lines' => count($prepared)], (float) $totalUsd, 'USD');

            return $id;
        });
    }
    /** "10" is ten dollars, "10%" ten percent of the subtotal; empty is no discount. Never more than the bill. */
    public static function discountAmount(string $typed, float $subtotal): string
    {
        $text = str_replace([',', '$', ' '], '', trim($typed));
        if ($text === '') {
            return '0.00';
        }
        $percent = str_ends_with($text, '%');
        $number = $percent ? substr($text, 0, -1) : $text;
        if (!is_numeric($number) || (float) $number < 0 || ($percent && (float) $number > 100)) {
            throw new \DomainException('The discount is an amount in dollars (10) or a percentage (10%).');
        }
        $amount = $percent ? round($subtotal * (float) $number / 100, 2) : (float) $number;
        if ($amount > $subtotal + 0.0001) {
            throw new \DomainException('The discount cannot be more than the purchase itself.');
        }

        return Money::fmt($amount);
    }
}
