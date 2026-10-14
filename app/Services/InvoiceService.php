<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Settings;
use App\Models\Customer;
use App\Models\ExchangeRate;

/**
 * Desk invoices (2026-10-14): what a B2B customer still owes, invoice by invoice.
 * The ledger is the truth (append-only). Payments are applied to the customer's invoices oldest first (FIFO), so an
 * invoice is "paid" once the payments received cover it and every older one; "partly paid" when they reach into it;
 * "overdue" when its due date is past and something is left. Return credits and negative adjustments count as
 * payments; a positive adjustment is an open item of its own.
 */
final class InvoiceService
{
    public const DEFAULT_TERMS_DAYS = 45;

    /**
     * The customer's credit items in order, each with what is still unpaid, and the payments not yet used by any item.
     *
     * @return array{items: list<array{ledger_id: int, sale_id: ?int, invoice_no: ?string, type: string, date: string, due_date: ?string, amount: string, remaining: string, paid: string}>, unapplied: string}
     */
    public function outstanding(int $customerId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT l.id, l.type, l.amount_usd, l.created_at, l.sale_id, s.invoice_no, s.due_date, s.origin
             FROM customer_ledger l LEFT JOIN sales s ON s.id = l.sale_id WHERE l.customer_id = :c ORDER BY l.id'
        );
        $st->execute(['c' => $customerId]);
        $items = [];
        $carried = 0.0;   // payments received before anything was owed, or beyond it
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $amount = (float) $r['amount_usd'];
            if ($amount > 0.004) {
                $use = min($carried, $amount);
                $carried -= $use;
                $items[] = ['ledger_id' => (int) $r['id'], 'sale_id' => $r['sale_id'] === null ? null : (int) $r['sale_id'], 'invoice_no' => $r['invoice_no'], 'type' => $r['type'],
                    'date' => $r['created_at'], 'due_date' => $r['due_date'], 'origin' => $r['origin'], 'amount' => Money::fmt($amount), 'remaining_f' => $amount - $use];
                continue;
            }
            $pay = -$amount;
            foreach ($items as &$it) {
                if ($it['remaining_f'] <= 0.004) {
                    continue;
                }
                $use = min($pay, $it['remaining_f']);
                $it['remaining_f'] -= $use;
                $pay -= $use;
                if ($pay <= 0.004) {
                    break;
                }
            }
            unset($it);
            $carried += max(0.0, $pay);
        }
        foreach ($items as &$it) {
            $it['remaining'] = Money::fmt($it['remaining_f']);
            $it['paid'] = Money::fmt((float) $it['amount'] - $it['remaining_f']);
            $it['status'] = self::status($it['remaining_f'], (float) $it['amount'], $it['due_date']);
            unset($it['remaining_f']);
        }
        unset($it);

        return ['items' => $items, 'unapplied' => Money::fmt($carried)];
    }

    /** paid, partly, unpaid or overdue. */
    public static function status(float $remaining, float $amount, ?string $dueDate, ?string $today = null): string
    {
        if ($remaining <= 0.004) {
            return 'paid';
        }
        if ($dueDate !== null && $dueDate < ($today ?? date('Y-m-d'))) {
            return 'overdue';
        }

        return $remaining < $amount - 0.004 ? 'partly' : 'unpaid';
    }

    /**
     * The open items of several customers, keyed by sale id (for a list of invoices).
     *
     * @param list<int> $customerIds
     * @return array<int, array>
     */
    public function forSales(array $customerIds): array
    {
        $bySale = [];
        foreach (array_unique(array_filter(array_map('intval', $customerIds))) as $cid) {
            foreach ($this->outstanding($cid)['items'] as $it) {
                if ($it['sale_id'] !== null) {
                    $bySale[$it['sale_id']] = $it;
                }
            }
        }

        return $bySale;
    }

    /** The due date typed, or the issue date plus the customer's payment terms (or the shop's default). */
    public function dueDate(?array $customer, string $typed, ?string $issued = null): string
    {
        $typed = trim($typed);
        if ($typed !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $typed) || strtotime($typed) === false) {
                throw new \DomainException('The due date must be a date (YYYY-MM-DD).');
            }

            return $typed;
        }

        return date('Y-m-d', strtotime('+' . $this->termsDays($customer) . ' days', $issued === null ? time() : strtotime($issued)));
    }

    /**
     * A payment received at the desk (2026-10-14): by transfer, cheque or card, in USD or LBP at today's rate. Cash is
     * collected at the till so that it goes into a drawer. One ledger row per currency, applied to the oldest invoice
     * first by outstanding(). Floating products on the account are re-priced first, as at the till.
     *
     * @return array{paid_usd: string, balance: string}
     */
    public function recordPayment(int $customerId, string $usd, string $lbp, string $method, string $reference, string $note, int $userId): array
    {
        $customers = new Customer();
        $customer = $customers->find($customerId) ?? throw new \DomainException('Choose the customer.');
        if (!in_array($method, ['transfer', 'cheque', 'card'], true)) {
            throw new \DomainException('Choose how it was paid: bank transfer, cheque or card. Cash is collected at the till.');
        }
        $rate = (new ExchangeRate())->current();
        $usdIn = Pricing::parse($usd, true) ?? '0.00';
        $lbpIn = CashService::parseLbp($lbp, true);
        if ((float) $usdIn <= 0.004 && $lbpIn <= 0) {
            throw new \DomainException('Type what was received: in USD, in LBP, or both.');
        }
        $reference = mb_substr(trim($reference), 0, 80) ?: null;
        $note = mb_substr(trim($note), 0, 255) ?: null;
        (new DebtService())->charge($customerId, $userId, null);

        return Database::transaction(function () use ($customers, $customer, $customerId, $usdIn, $lbpIn, $rate, $method, $reference, $note, $userId): array {
            $paid = 0.0;
            if ((float) $usdIn > 0.004) {
                $customers->addLedger(['customer_id' => $customerId, 'type' => 'payment', 'amount_usd' => '-' . $usdIn, 'currency' => 'USD', 'amount_original' => $usdIn, 'exchange_rate' => $rate,
                                       'user_id' => $userId, 'note' => $note, 'method' => $method, 'reference' => $reference]);
                $paid += (float) $usdIn;
            }
            if ($lbpIn > 0) {
                $usdValue = Money::lbpToUsd($lbpIn, $rate);
                $customers->addLedger(['customer_id' => $customerId, 'type' => 'payment', 'amount_usd' => '-' . $usdValue, 'currency' => 'LBP', 'amount_original' => (string) $lbpIn, 'exchange_rate' => $rate,
                                       'user_id' => $userId, 'note' => $note, 'method' => $method, 'reference' => $reference]);
                $paid += (float) $usdValue;
            }
            Audit::log('payment.received', 'customer', $customerId, ['customer' => $customer['name'], 'method' => $method, 'reference' => $reference, 'usd' => $usdIn, 'lbp' => $lbpIn], $paid, 'USD');

            return ['paid_usd' => Money::fmt($paid), 'balance' => $customers->balance($customerId)];
        });
    }

    /**
     * The statement of account for a period: the balance before it, every ledger row in it with a running balance,
     * the totals, the balance now and the invoices still open.
     */
    public function statement(int $customerId, string $from, string $to): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COALESCE(SUM(amount_usd), 0) FROM customer_ledger WHERE customer_id = :c AND created_at < :f');
        $st->execute(['c' => $customerId, 'f' => $from . ' 00:00:00']);
        $opening = (float) $st->fetchColumn();
        $st = $pdo->prepare('SELECT l.*, s.invoice_no FROM customer_ledger l LEFT JOIN sales s ON s.id = l.sale_id WHERE l.customer_id = :c AND l.created_at BETWEEN :f AND :t ORDER BY l.id');
        $st->execute(['c' => $customerId, 'f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']);
        $rows = [];
        $debits = 0.0;
        $credits = 0.0;
        $methods = ['transfer' => 'bank transfer', 'cheque' => 'cheque', 'card' => 'card', 'cash' => 'cash'];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $amount = (float) $r['amount_usd'];
            $r['label'] = match ($r['type']) {
                'sale_credit' => 'Invoice ' . ($r['invoice_no'] ?? ''),
                'payment' => 'Payment' . (isset($methods[$r['method'] ?? '']) ? ' by ' . $methods[$r['method']] : ($r['session_id'] !== null ? ' in cash at the till' : '')) . ($r['currency'] === 'LBP' ? ' (' . number_format((float) $r['amount_original']) . ' LBP)' : ''),
                'return_credit' => 'Credit for returned goods' . ($r['invoice_no'] !== null ? ' on ' . $r['invoice_no'] : ''),
                default => 'Adjustment' . ($r['note'] !== null ? ': ' . $r['note'] : ''),
            };
            $r['reference'] = $r['reference'] ?? ($r['type'] === 'payment' ? $r['note'] : null);
            $amount > 0 ? $debits += $amount : $credits -= $amount;
            $rows[] = $r;
        }
        $o = $this->outstanding($customerId);

        return ['opening' => Money::fmt($opening), 'rows' => $rows, 'debits' => Money::fmt($debits), 'credits' => Money::fmt($credits), 'closing' => Money::fmt($opening + $debits - $credits),
                'balanceNow' => (new Customer())->balance($customerId), 'open' => array_values(array_filter($o['items'], static fn (array $i): bool => $i['status'] !== 'paid'))];
    }

    /**
     * Who owes what, by how long it is past due: not yet due, 1–30, 31–60, over 60 days. An item without a due date
     * (credit given at the till) is due after the customer's terms.
     *
     * @return list<array{customer_id: int, name: string, current: string, b30: string, b60: string, b90: string, total: string, oldest_days: ?int}>
     */
    public function aging(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $rows = [];
        foreach ((new Customer())->debtors() as $c) {
            $b = ['current' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0];
            $oldest = null;
            foreach ($this->outstanding((int) $c['id'])['items'] as $it) {
                $left = (float) $it['remaining'];
                if ($left <= 0.004) {
                    continue;
                }
                $due = $it['due_date'] ?? date('Y-m-d', strtotime('+' . $this->termsDays($c) . ' days', strtotime($it['date'])));
                $days = (int) floor((strtotime($today) - strtotime($due)) / 86400);
                $b[$days <= 0 ? 'current' : ($days <= 30 ? 'b30' : ($days <= 60 ? 'b60' : 'b90'))] += $left;
                $oldest = max($oldest ?? 0, $days);
            }
            $total = array_sum($b);
            if ($total <= 0.004) {
                continue;
            }
            $rows[] = ['customer_id' => (int) $c['id'], 'name' => $c['name'], 'current' => Money::fmt($b['current']), 'b30' => Money::fmt($b['b30']), 'b60' => Money::fmt($b['b60']), 'b90' => Money::fmt($b['b90']),
                       'total' => Money::fmt($total), 'oldest_days' => $oldest === null ? null : max(0, $oldest)];
        }
        usort($rows, static fn (array $a, array $b): int => (float) $b['total'] <=> (float) $a['total']);

        return $rows;
    }

    public function termsDays(?array $customer): int
    {
        if ($customer !== null && $customer['payment_terms_days'] !== null && (int) $customer['payment_terms_days'] >= 0) {
            return (int) $customer['payment_terms_days'];
        }
        $days = (int) Settings::get('invoice_terms_days', (string) self::DEFAULT_TERMS_DAYS);

        return $days > 0 ? $days : self::DEFAULT_TERMS_DAYS;
    }
}
