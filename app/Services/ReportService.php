<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\StockMovement;
use PDO;

/** Read-only report queries (spec §16). Dates are Y-m-d, inclusive. */
final class ReportService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function range(string $from, string $to): array
    {
        return ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
    }

    /** @param string $by day|cashier|product|level */
    public function sales(string $from, string $to, string $by): array
    {
        $p = $this->range($from, $to);
        return match ($by) {
            'cashier' => $this->rows("SELECT u.username AS label, COUNT(*) AS invoices, SUM(s.total_usd) AS total, SUM(s.cost_total_usd) AS cost FROM sales s JOIN users u ON u.id = s.user_id
                                      WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t GROUP BY u.id ORDER BY total DESC", $p),
            'product' => $this->rows("SELECT i.product_name AS label, i.unit_name, SUM(i.qty) AS qty, SUM(i.line_total_usd) AS total, SUM(i.line_cost_usd) AS cost FROM sale_items i JOIN sales s ON s.id = i.sale_id
                                      WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t GROUP BY i.product_id, i.product_unit_id ORDER BY total DESC", $p),
            'level' => $this->rows("SELECT price_level AS label, COUNT(*) AS invoices, SUM(total_usd) AS total, SUM(cost_total_usd) AS cost FROM sales
                                    WHERE status = 'completed' AND created_at BETWEEN :f AND :t GROUP BY price_level", $p),
            default => $this->rows("SELECT DATE(created_at) AS label, COUNT(*) AS invoices, SUM(total_usd) AS total, SUM(cost_total_usd) AS cost, SUM(discount_usd) AS discount FROM sales
                                    WHERE status = 'completed' AND created_at BETWEEN :f AND :t GROUP BY DATE(created_at) ORDER BY label", $p),
        };
    }

    public function payments(string $from, string $to): array
    {
        return $this->rows("SELECT p.method, p.currency, COUNT(*) AS n, SUM(p.amount) AS amount, SUM(p.amount_usd) AS amount_usd FROM sale_payments p JOIN sales s ON s.id = p.sale_id
                            WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t GROUP BY p.method, p.currency ORDER BY p.method, p.currency", $this->range($from, $to));
    }

    public function profit(string $from, string $to): array
    {
        $p = $this->range($from, $to);
        $s = $this->rows("SELECT COALESCE(SUM(total_usd), 0) AS gross, COALESCE(SUM(rounding_usd), 0) AS rounding, COALESCE(SUM(cost_total_usd), 0) AS cogs, COUNT(*) AS invoices
                          FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", $p)[0];
        // Two separate sums. Joining returns to their items repeats a return's total once per item,
        // so a refund of $10.22 with 2 items was reported as more than it was.
        $refunds = $this->rows("SELECT COALESCE(SUM(total_usd), 0) AS refunds, COALESCE(SUM(rounding_usd), 0) AS rounding FROM returns WHERE created_at BETWEEN :f AND :t", $p)[0];
        // LBP refunds are rounded to 5,000 like change: what that kept belongs on the same Rounding line as the sales' rounding.
        $s['rounding'] = (float) $s['rounding'] + (float) $refunds['rounding'];
        $r = [
            'refunds' => $refunds['refunds'],
            'cost' => $this->rows("SELECT COALESCE(SUM(ri.cost_usd), 0) AS cost FROM return_items ri JOIN returns r ON r.id = ri.return_id
                                   WHERE r.created_at BETWEEN :f AND :t", $p)[0]['cost'],
        ];
        $waste = (new StockMovement())->wasteCost($from, $to);
        $expenses = (new Expense())->totalUsd($from, $to);
        // Debts re-priced at the price of the day (2026-10-09) are revenue on top of the invoices.
        $repriced = $this->rows("SELECT COALESCE(SUM(amount_usd), 0) AS a FROM customer_ledger WHERE type = 'price_adjustment' AND created_at BETWEEN :f AND :t", $p)[0]['a'];
        $net = (float) $s['gross'] - (float) $r['refunds'] + (float) $s['rounding'] + (float) $repriced;
        $cogs = (float) $s['cogs'] - (float) $r['cost'];
        $grossProfit = $net - $cogs - (float) $waste;

        return [
            'invoices' => (int) $s['invoices'], 'gross_sales' => Money::fmt($s['gross']), 'returns' => Money::fmt($r['refunds']), 'rounding' => Money::fmt($s['rounding']), 'repriced' => Money::fmt($repriced),
            'net_sales' => Money::fmt($net), 'cogs' => Money::fmt($cogs), 'waste' => $waste, 'gross_profit' => Money::fmt($grossProfit),
            'expenses' => $expenses, 'net_profit' => Money::fmt($grossProfit - (float) $expenses),
        ];
    }

    /**
     * F5: every product sold in a period, quantity and money net of returns (settlement returns are already off the
     * lines; classic returns are deducted here), filtered by level, product, customer or salesperson.
     */
    public function productSales(string $from, string $to, array $filters): array
    {
        $p = $this->range($from, $to);
        $where = [];
        if (($filters['level'] ?? '') !== '') { $where[] = 's.price_level = :lv'; $p['lv'] = $filters['level']; }
        if (($filters['product_id'] ?? 0) > 0) { $where[] = 'i.product_id = :pid'; $p['pid'] = $filters['product_id']; }
        if (($filters['customer_id'] ?? 0) > 0) { $where[] = 's.customer_id = :cid'; $p['cid'] = $filters['customer_id']; }
        if (($filters['user_id'] ?? 0) > 0) { $where[] = 's.user_id = :uid'; $p['uid'] = $filters['user_id']; }
        $w = $where === [] ? '' : ' AND ' . implode(' AND ', $where);

        return $this->rows("SELECT i.product_id, i.product_name, pr.base_unit,
                                   SUM(i.base_qty) - COALESCE(SUM((SELECT SUM(ri.base_qty) FROM return_items ri WHERE ri.sale_item_id = i.id)), 0) AS qty_base,
                                   COALESCE(SUM((SELECT SUM(ri.base_qty) FROM return_items ri WHERE ri.sale_item_id = i.id)), 0) AS returned_base,
                                   SUM(i.line_total_usd) - COALESCE(SUM((SELECT SUM(ri.refund_usd) FROM return_items ri WHERE ri.sale_item_id = i.id)), 0) AS total,
                                   SUM(i.line_cost_usd) - COALESCE(SUM((SELECT SUM(ri.cost_usd) FROM return_items ri WHERE ri.sale_item_id = i.id)), 0) AS cost
                            FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN products pr ON pr.id = i.product_id
                            WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t{$w} GROUP BY i.product_id, i.product_name, pr.base_unit ORDER BY total DESC", $p);
    }

    public function credit(): array
    {
        $debts = new \App\Services\DebtService();
        return array_map(static function (array $c) use ($debts): array {
            $c['pending_usd'] = $debts->pending((int) $c['id'])['amount'];
            return $c;
        }, (new Customer())->debtors());
    }

    public function returns(string $from, string $to): array
    {
        return $this->rows("SELECT r.*, s.invoice_no, u.username, c.name AS customer_name FROM returns r LEFT JOIN sales s ON s.id = r.sale_id JOIN users u ON u.id = r.user_id
                            LEFT JOIN customers c ON c.id = r.customer_id WHERE r.created_at BETWEEN :f AND :t ORDER BY r.id DESC", $this->range($from, $to));
    }

    public function waste(string $from, string $to): array
    {
        return $this->rows("SELECT m.*, p.name AS product_name, p.base_unit, u.username FROM stock_movements m JOIN products p ON p.id = m.product_id LEFT JOIN users u ON u.id = m.user_id
                            WHERE m.type = 'waste' AND m.created_at BETWEEN :f AND :t ORDER BY m.id DESC", $this->range($from, $to));
    }

    public function purchases(string $from, string $to): array
    {
        return $this->rows("SELECT p.*, s.name AS supplier_name FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.purchase_date BETWEEN :f AND :t ORDER BY p.id DESC",
            ['f' => $from, 't' => $to]);
    }

    public function sessions(string $from, string $to): array
    {
        return $this->rows("SELECT s.*, r.name AS register_name, u.username FROM cash_sessions s JOIN registers r ON r.id = s.register_id JOIN users u ON u.id = s.user_id
                            WHERE s.opened_at BETWEEN :f AND :t ORDER BY s.id DESC", $this->range($from, $to));
    }

    /** Today's numbers for the dashboard. */
    public function today(): array
    {
        $d = date('Y-m-d');
        $s = $this->rows("SELECT COUNT(*) AS invoices, COALESCE(SUM(total_usd), 0) AS total FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", $this->range($d, $d))[0];
        $low = $this->rows('SELECT COUNT(*) FROM products WHERE is_active = 1 AND min_stock_base IS NOT NULL AND stock_base <= min_stock_base', [])[0];
        $lowKind = ['finished' => 0, 'intermediate' => 0, 'material' => 0];
        foreach ($this->rows('SELECT kind, COUNT(*) AS n FROM products WHERE is_active = 1 AND min_stock_base IS NOT NULL AND stock_base <= min_stock_base GROUP BY kind', []) as $r) {
            $lowKind[$r['kind']] = (int) $r['n'];
        }
        $open = $this->rows("SELECT COUNT(*) FROM cash_sessions WHERE status = 'open'", [])[0];

        return ['invoices' => (int) $s['invoices'], 'total' => Money::fmt($s['total']), 'low_stock' => (int) reset($low), 'low_kind' => $lowKind, 'open_sessions' => (int) reset($open)];
    }
}
