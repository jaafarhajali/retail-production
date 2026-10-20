<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\FixedCost;

/**
 * The dashboard (2026-10-20, owner's requirement through Aya): the break-even and nothing else.
 * The monthly fixed costs are typed here, one amount per month. The break-even is split between the products
 * by their share of last month's pieces sold (this month's when last month sold nothing; equal shares before any
 * sale), each product weighed by what one piece earns, so that the targets together cover the fixed costs exactly.
 * Example: fixed $1,500; Joy 100g earns $1.80 a piece and is half of the pieces sold, Joy 200g earns $3.60 (20%),
 * Stick 25g $0.35 (30%): 435 + 174 + 261 pieces cover the month. Sales and returns move "Sold" and "Left" at once.
 * Everything is read from the records; the only write is the fixed amount.
 */
final class DashboardService
{
    /** @return array<string, mixed> */
    public function summary(?string $month = null): array
    {
        $month = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $prev = date('Y-m', strtotime($from . ' -1 month'));
        $pdo = Database::pdo();
        $one = static fn (string $sql, array $p = []): array => (static function () use ($pdo, $sql, $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch(\PDO::FETCH_ASSOC) ?: []; })();
        $all = static fn (string $sql, array $p = []): array => (static function () use ($pdo, $sql, $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(\PDO::FETCH_ASSOC); })();
        $range = static fn (string $m): array => ['f' => $m . '-01 00:00:00', 't' => date('Y-m-t', strtotime($m . '-01')) . ' 23:59:59'];

        // ---- the fixed costs: this month's amount, or the latest earlier one carried forward
        $fc = (new FixedCost())->forMonth($month);
        $fixed = $fc === null ? 0.0 : (float) $fc['amount_usd'];
        $fixedInfo = ['amount' => $fc === null ? null : Money::fmt($fixed), 'from_month' => $fc === null || $fc['month'] === $month ? null : date('F Y', strtotime($fc['month'] . '-01'))];

        // ---- the month's money: sales minus returns, and what the pieces sold had cost (mix and packaging)
        $s = $one("SELECT COALESCE(SUM(total_usd), 0) AS total, COALESCE(SUM(cost_total_usd), 0) AS cost FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", $range($month));
        $r = $one("SELECT COALESCE(SUM(r.total_usd), 0) AS total, COALESCE((SELECT SUM(i.cost_usd) FROM return_items i JOIN returns r2 ON r2.id = i.return_id WHERE r2.created_at BETWEEN :f2 AND :t2), 0) AS cost
                   FROM returns r WHERE r.created_at BETWEEN :f AND :t", $range($month) + ['f2' => $range($month)['f'], 't2' => $range($month)['t']]);
        $revenue = (float) $s['total'] - (float) $r['total'];
        $variable = (float) $s['cost'] - (float) $r['cost'];
        $money = ['sales' => Money::fmt($revenue), 'variable' => Money::fmt($variable), 'margin' => Money::fmt($revenue - $variable), 'fixed' => Money::fmt($fixed), 'profit' => Money::fmt($revenue - $variable - $fixed)];

        // ---- pieces per product: sold (net of returns), what they sold for and had cost
        $perProduct = function (string $m) use ($all, $range): array {
            $out = [];
            foreach ($all("SELECT i.product_id, SUM(i.base_qty) AS qty, SUM(i.line_total_usd) AS total, SUM(i.line_cost_usd) AS cost FROM sale_items i JOIN sales s ON s.id = i.sale_id
                           WHERE s.status = 'completed' AND s.created_at BETWEEN :f AND :t GROUP BY i.product_id", $range($m)) as $row) {
                $out[(int) $row['product_id']] = ['qty' => (int) $row['qty'], 'total' => (float) $row['total'], 'cost' => (float) $row['cost']];
            }
            foreach ($all("SELECT i.product_id, SUM(i.base_qty) AS qty, SUM(i.refund_usd) AS total, SUM(i.cost_usd) AS cost FROM return_items i JOIN returns r ON r.id = i.return_id
                           WHERE r.created_at BETWEEN :f AND :t GROUP BY i.product_id", $range($m)) as $row) {
                $id = (int) $row['product_id'];
                $out[$id] ??= ['qty' => 0, 'total' => 0.0, 'cost' => 0.0];
                $out[$id]['qty'] -= (int) $row['qty'];
                $out[$id]['total'] -= (float) $row['total'];
                $out[$id]['cost'] -= (float) $row['cost'];
            }

            return $out;
        };
        $thisMonth = $perProduct($month);
        $lastMonth = $perProduct($prev);

        // ---- the candidates: finished products sold by the piece, with the list price and cost as a fallback
        $products = $all("SELECT p.id, p.name, p.cost_per_base, u.retail_price, u.factor FROM products p
                          LEFT JOIN product_units u ON u.product_id = p.id AND u.is_default_sale = 1
                          WHERE p.is_active = 1 AND p.kind = 'finished' AND p.base_unit = 'piece' ORDER BY p.name");
        $sumShare = static fn (array $month, array $ids): int => array_sum(array_map(static fn (int $id): int => max(0, $month[$id]['qty'] ?? 0), $ids));
        $ids = array_map('intval', array_column($products, 'id'));
        $shareFrom = $sumShare($lastMonth, $ids) > 0 ? $lastMonth : ($sumShare($thisMonth, $ids) > 0 ? $thisMonth : null);
        $shareTotal = $shareFrom === null ? count($products) : $sumShare($shareFrom, $ids);
        $rows = [];
        foreach ($products as $p) {
            $id = (int) $p['id'];
            $share = $shareFrom === null ? 1 / max(1, $shareTotal) : max(0, $shareFrom[$id]['qty'] ?? 0) / $shareTotal;
            if ($share <= 0) {
                continue;   // not part of what sells: no target
            }
            $sold = $thisMonth[$id] ?? ['qty' => 0, 'total' => 0.0, 'cost' => 0.0];
            if ($sold['qty'] > 0) {
                $price = $sold['total'] / $sold['qty'];
                $cost = $sold['cost'] / $sold['qty'];
            } else {
                $price = $p['retail_price'] === null ? 0.0 : (float) $p['retail_price'] / max(1, (int) $p['factor']);
                $cost = (float) $p['cost_per_base'];
            }
            $rows[$id] = ['product_id' => $id, 'name' => $p['name'], 'share' => $share, 'margin' => $price - $cost, 'price' => Money::fmt($price), 'cost' => Money::fmt($cost), 'sold' => max(0, $sold['qty'])];
        }
        // the pieces needed: fixed ÷ Σ(share × margin), the products that earn nothing left out of the split
        $earning = array_filter($rows, static fn (array $r): bool => $r['margin'] > 0);
        $earningShare = array_sum(array_column($earning, 'share'));
        $perPiece = $earningShare > 0 ? array_sum(array_map(static fn (array $r): float => $r['share'] / $earningShare * $r['margin'], $earning)) : 0.0;
        $ready = $fc !== null && $fixed > 0 && $perPiece > 0;
        $total = $ready ? $fixed / $perPiece : 0.0;
        foreach ($rows as $id => &$row) {
            $need = $ready && $row['margin'] > 0 ? (int) ceil($row['share'] / $earningShare * $total) : null;
            $row['need'] = $need;
            $row['left'] = $need === null ? null : max(0, $need - $row['sold']);
            $row['share_pct'] = (int) round($row['share'] * 100);
            $row['margin_text'] = Money::fmt($row['margin']);
        }
        unset($row);
        usort($rows, static fn (array $a, array $b): int => $b['share'] <=> $a['share']);
        $sum = static fn (string $k): int => array_sum(array_map(static fn (array $r): int => (int) ($r[$k] ?? 0), $rows));

        return [
            'month' => $month, 'month_label' => date('F Y', strtotime($from)), 'prev_label' => date('F Y', strtotime($prev . '-01')),
            'fixed' => $fixedInfo, 'money' => $money,
            'targets' => ['ready' => $ready, 'rows' => array_values($rows), 'need' => $sum('need'), 'sold' => $sum('sold'), 'left' => $sum('left'),
                          'share_from' => $shareFrom === null ? 'equal' : ($shareFrom === $lastMonth ? 'last' : 'this'), 'per_piece' => Money::fmt($perPiece)],
        ];
    }

    /** The administrator types the month's fixed costs on the dashboard. */
    public function setFixedCosts(string $month, string $amount, int $userId): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new \DomainException('Choose the month.');
        }
        $text = str_replace([',', '$', ' '], '', trim($amount));
        if ($text === '' || !is_numeric($text) || (float) $text < 0 || (float) $text > 9999999) {
            throw new \DomainException('Monthly fixed costs: type an amount in dollars, like 1500.');
        }
        $value = Money::fmt((float) $text);
        (new FixedCost())->set($month, $value, $userId);
        Audit::log('fixed_costs.set', 'fixed_costs', null, ['month' => $month, 'amount' => $value], (float) $value, 'USD');

        return $value;
    }
}
