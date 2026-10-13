<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;
use App\Models\Customer;
use App\Models\ProductionRun;

/**
 * The factory dashboard (F5, 2026-10-13): the business as a flow, raw materials → production → finished stock →
 * out with the seller → paid or owed, with this month's sales, who owes, production, the two stocks and break-even.
 * Everything is read from the records; nothing here writes.
 */
final class DashboardService
{
    /** @return array<string, mixed> */
    public function summary(?string $month = null): array
    {
        $month = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $today = date('Y-m-d');
        $pdo = Database::pdo();
        $one = static fn (string $sql, array $p = []): array => (static function () use ($pdo, $sql, $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch(\PDO::FETCH_ASSOC) ?: []; })();
        $all = static fn (string $sql, array $p = []): array => (static function () use ($pdo, $sql, $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(\PDO::FETCH_ASSOC); })();
        $range = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];

        // ---- sales this month and today
        $m = $one("SELECT COUNT(*) AS invoices, COALESCE(SUM(total_usd), 0) AS total, COALESCE(SUM(cost_total_usd), 0) AS cost,
                          COALESCE(SUM(CASE WHEN price_level = 'wholesale' THEN total_usd ELSE 0 END), 0) AS wholesale,
                          COALESCE(SUM(CASE WHEN price_level = 'retail' THEN total_usd ELSE 0 END), 0) AS retail
                   FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", $range);
        $t = $one("SELECT COUNT(*) AS invoices, COALESCE(SUM(total_usd), 0) AS total FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", ['f' => $today . ' 00:00:00', 't' => $today . ' 23:59:59']);
        $pay = $one("SELECT COALESCE(SUM(CASE WHEN p.method = 'credit' THEN p.amount_usd ELSE 0 END), 0) AS credit, COALESCE(SUM(CASE WHEN p.method <> 'credit' THEN p.amount_usd ELSE 0 END), 0) AS paid
                     FROM sale_payments p JOIN sales s ON s.id = p.sale_id WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t", $range);
        $paidInvoices = (int) $one("SELECT COUNT(*) AS n FROM sales s WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t
                                    AND NOT EXISTS (SELECT 1 FROM sale_payments p WHERE p.sale_id = s.id AND p.method = 'credit')", $range)['n'];
        $units = (int) $one("SELECT COALESCE(SUM(i.base_qty), 0) AS n FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN products p ON p.id = i.product_id
                             WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t AND p.base_unit = 'piece'", $range)['n'];

        // ---- out with the seller, who owes
        $held = $one("SELECT COUNT(*) AS n, COALESCE(SUM(total_usd), 0) AS total FROM sales WHERE status = 'on_hold'");
        $debtors = (new Customer())->debtors();
        usort($debtors, static fn (array $a, array $b): int => (float) $b['balance_usd'] <=> (float) $a['balance_usd']);
        $owed = array_sum(array_map(static fn (array $c): float => (float) $c['balance_usd'], $debtors));

        // ---- production this month, the two stocks
        $runs = new ProductionRun();
        $made = $runs->byProduct($from, $to);
        $consumed = $runs->consumed($from, $to);
        $stock = [];
        foreach ($all("SELECT kind, COUNT(*) AS n, COALESCE(SUM(stock_base * cost_per_base), 0) AS value_usd,
                              SUM(CASE WHEN min_stock_base IS NOT NULL AND stock_base <= min_stock_base THEN 1 ELSE 0 END) AS low
                       FROM products WHERE is_active = 1 GROUP BY kind") as $r) {
            $stock[$r['kind']] = ['n' => (int) $r['n'], 'value_usd' => Money::fmt($r['value_usd']), 'low' => (int) $r['low']];
        }
        foreach (['finished', 'intermediate', 'material'] as $k) {
            $stock[$k] ??= ['n' => 0, 'value_usd' => '0.00', 'low' => 0];
        }
        $lowList = $all("SELECT name, kind, stock_base, min_stock_base, base_unit FROM products WHERE is_active = 1 AND min_stock_base IS NOT NULL AND stock_base <= min_stock_base ORDER BY kind, name LIMIT 12");

        // ---- this month's products
        $products = $all("SELECT i.product_id, i.product_name, p.base_unit, SUM(i.base_qty) AS qty_base, SUM(i.line_total_usd) AS total, SUM(i.line_cost_usd) AS cost
                          FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN products p ON p.id = i.product_id
                          WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t GROUP BY i.product_id, i.product_name, p.base_unit ORDER BY total DESC LIMIT 10", $range);

        // ---- break-even: fixed costs = this month's expenses in the categories marked fixed; margin = (revenue − cost) ÷ pieces sold
        $fixedCats = array_values(array_filter(array_map('trim', explode(',', Settings::get('fixed_expense_categories', '')))));
        $fixed = 0.0;
        if ($fixedCats !== []) {
            $in = implode(',', array_map(static fn (string $c): string => $pdo->quote($c), $fixedCats));
            $fixed = (float) $one("SELECT COALESCE(SUM(amount_usd), 0) AS a FROM expenses WHERE supplier_id IS NULL AND expense_date BETWEEN :f AND :t AND category IN ({$in})", ['f' => $from, 't' => $to])['a'];
        }
        $revenue = (float) $m['total'];
        $margin = $units > 0 ? ($revenue - (float) $m['cost']) / $units : 0.0;
        $avgPrice = $units > 0 ? $revenue / $units : 0.0;
        $bepUnits = $margin > 0 ? (int) ceil($fixed / $margin) : null;
        $breakEven = [
            'fixed_usd' => Money::fmt($fixed), 'categories' => $fixedCats, 'units_sold' => $units, 'avg_price' => Money::fmt($avgPrice), 'avg_cost' => Money::fmt($units > 0 ? (float) $m['cost'] / $units : 0),
            'margin' => Money::fmt($margin), 'bep_units' => $bepUnits, 'bep_revenue' => $bepUnits === null ? null : Money::fmt($bepUnits * $avgPrice),
            'remaining_units' => $bepUnits === null ? null : max(0, $bepUnits - $units), 'revenue' => Money::fmt($revenue),
            'profit' => Money::fmt($revenue - (float) $m['cost'] - $fixed), 'pct' => $bepUnits === null || $bepUnits === 0 ? null : min(999, (int) round($units / $bepUnits * 100)),
            'ready' => $fixedCats !== [] && $units > 0,
        ];

        return [
            'month' => $month, 'from' => $from, 'to' => $to, 'month_label' => date('F Y', strtotime($from)),
            'today' => ['total' => Money::fmt($t['total']), 'invoices' => (int) $t['invoices']],
            'month_sales' => ['total' => Money::fmt($m['total']), 'invoices' => (int) $m['invoices'], 'wholesale' => Money::fmt($m['wholesale']), 'retail' => Money::fmt($m['retail']),
                              'paid' => Money::fmt($pay['paid']), 'credit' => Money::fmt($pay['credit']), 'paid_invoices' => $paidInvoices, 'unpaid_invoices' => (int) $m['invoices'] - $paidInvoices, 'units' => $units],
            'held' => ['n' => (int) $held['n'], 'total' => Money::fmt($held['total'])],
            'owed' => ['total' => Money::fmt($owed), 'customers' => count($debtors), 'top' => array_slice($debtors, 0, 5)],
            'production' => ['runs' => array_sum(array_map(static fn (array $r): int => (int) $r['runs'], $made)), 'made' => $made, 'consumed' => $consumed,
                             'cost' => Money::fmt(array_sum(array_map(static fn (array $r): float => (float) $r['cost_usd'], $made)))],
            'stock' => $stock, 'low' => $lowList, 'products' => $products, 'break_even' => $breakEven,
        ];
    }
}
