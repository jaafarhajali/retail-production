<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

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

    public function termsDays(?array $customer): int
    {
        if ($customer !== null && $customer['payment_terms_days'] !== null && (int) $customer['payment_terms_days'] >= 0) {
            return (int) $customer['payment_terms_days'];
        }
        $days = (int) Settings::get('invoice_terms_days', (string) self::DEFAULT_TERMS_DAYS);

        return $days > 0 ? $days : self::DEFAULT_TERMS_DAYS;
    }
}
