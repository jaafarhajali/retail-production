<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\FixedCost;

/**
 * The dashboard (2026-10-20, owner's requirement through Aya): the break-even and nothing else.
 *
 * Fixed costs, two figures a month: the **estimate** the administrator types on the 1st (carried forward until changed),
 * and the **actual**, which is every expense recorded that month on the Expenses page (supplier payments left out: what
 * was bought is already in the cost of each piece). During the month the break-even and the net profit use the estimate;
 * once the month is over they use the actual. A history compares the two month by month.
 *
 * Each product carries a share of the fixed costs equal to its share of **last month's profit** (what it sold for minus
 * what its pieces cost), this month's when last month earned nothing, equal shares before any sale. A product that earned
 * nothing carries nothing. Its target is that share divided by what one piece earns now:
 * fixed $1,500; Joy 100g earned $180 of $330 last month → 55% → $818 → at $1.80 a piece, 455 pieces.
 * Sales and returns move "Sold" and "Left" at once. Everything is read from the records; the only write is the estimate.
 */
final class DashboardService
{
    /** @return array<string, mixed> */
    public function summary(?string $month = null): array
    {
        $month = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $from = $month . '-01';
        $prev = date('Y-m', strtotime($from . ' -1 month'));
        $over = $month < date('Y-m');   // a past month: its actual is final
        $pdo = Database::pdo();
        $one = static fn (string $sql, array $p = []): array => (static function () use ($pdo, $sql, $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch(\PDO::FETCH_ASSOC) ?: []; })();
        $all = static fn (string $sql, array $p = []): array => (static function () use ($pdo, $sql, $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(\PDO::FETCH_ASSOC); })();
        $range = static fn (string $m): array => ['f' => $m . '-01 00:00:00', 't' => date('Y-m-t', strtotime($m . '-01')) . ' 23:59:59'];

        // ---- fixed costs: the estimate, the actual, and the one the month uses
        $fc = (new FixedCost())->forMonth($month);
        $estimate = $fc === null ? null : (float) $fc['amount_usd'];
        $actual = $this->actual($month);
        $used = $over || $estimate === null ? $actual : $estimate;
        $fixedInfo = [
            'estimate' => $estimate === null ? null : Money::fmt($estimate), 'from_month' => $fc === null || $fc['month'] === $month ? null : date('F Y', strtotime($fc['month'] . '-01')),
            'actual' => Money::fmt($actual), 'used' => Money::fmt($used), 'uses' => $over || $estimate === null ? 'actual' : 'estimate', 'over' => $over,
            'history' => $this->history($month),
        ];

        // ---- the month's money: sales minus returns, and what the pieces sold had cost (mix and packaging)
        $rm = $range($month);
        $s = $one("SELECT COALESCE(SUM(total_usd), 0) AS total, COALESCE(SUM(cost_total_usd), 0) AS cost FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", $rm);
        $r = $one("SELECT COALESCE(SUM(r.total_usd), 0) AS total, COALESCE((SELECT SUM(i.cost_usd) FROM return_items i JOIN returns r2 ON r2.id = i.return_id WHERE r2.created_at BETWEEN :f2 AND :t2), 0) AS cost
                   FROM returns r WHERE r.created_at BETWEEN :f AND :t", $rm + ['f2' => $rm['f'], 't2' => $rm['t']]);
        $revenue = (float) $s['total'] - (float) $r['total'];
        $variable = (float) $s['cost'] - (float) $r['cost'];
        $money = ['sales' => Money::fmt($revenue), 'variable' => Money::fmt($variable), 'margin' => Money::fmt($revenue - $variable), 'fixed' => Money::fmt($used), 'profit' => Money::fmt($revenue - $variable - $used)];

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

        // ---- each finished product sold by the piece: what one piece earns now, and its profit as the share's basis
        $products = $all("SELECT p.id, p.name, p.cost_per_base, u.retail_price, u.factor FROM products p
                          LEFT JOIN product_units u ON u.product_id = p.id AND u.is_default_sale = 1
                          WHERE p.is_active = 1 AND p.kind = 'finished' AND p.base_unit = 'piece' ORDER BY p.name");
        $profitOf = static fn (array $sold, int $id): float => isset($sold[$id]) ? $sold[$id]['total'] - $sold[$id]['cost'] : 0.0;
        $earned = static fn (array $sold): float => array_sum(array_map(static fn (array $p): float => max(0.0, $profitOf($sold, (int) $p['id'])), $products));
        $basis = $earned($lastMonth) > 0 ? 'last' : ($earned($thisMonth) > 0 ? 'this' : 'equal');
        $basisMonth = $basis === 'last' ? $lastMonth : $thisMonth;
        $rows = [];
        foreach ($products as $p) {
            $id = (int) $p['id'];
            // what one piece earns: this month's sales, else last month's, else the list price less the cost
            $src = ($thisMonth[$id]['qty'] ?? 0) > 0 ? $thisMonth[$id] : (($lastMonth[$id]['qty'] ?? 0) > 0 ? $lastMonth[$id] : null);
            if ($src !== null) {
                $price = $src['total'] / $src['qty'];
                $cost = $src['cost'] / $src['qty'];
            } else {
                $price = $p['retail_price'] === null ? 0.0 : (float) $p['retail_price'] / max(1, (int) $p['factor']);
                $cost = (float) $p['cost_per_base'];
            }
            $weight = $basis === 'equal' ? ($price - $cost > 0 ? 1.0 : 0.0) : max(0.0, $profitOf($basisMonth, $id));
            if ($weight <= 0) {
                continue;   // earned nothing: carries nothing, no column
            }
            $rows[$id] = ['product_id' => $id, 'name' => $p['name'], 'weight' => $weight, 'margin' => $price - $cost, 'sold' => max(0, $thisMonth[$id]['qty'] ?? 0)];
        }
        $sumWeight = array_sum(array_column($rows, 'weight'));
        $ready = $used > 0 && $rows !== [];
        foreach ($rows as &$row) {
            $row['share'] = $sumWeight > 0 ? $row['weight'] / $sumWeight : 0.0;
            $carries = $used * $row['share'];
            $row['carries'] = Money::fmt($carries);
            $row['share_pct'] = round($row['share'] * 100, 1);
            $row['need'] = $ready && $row['margin'] > 0 ? (int) ceil($carries / $row['margin'] - 1e-9) : null;
            $row['left'] = $row['need'] === null ? null : max(0, $row['need'] - $row['sold']);
            $row['margin_text'] = Money::fmt($row['margin']);
        }
        unset($row);
        usort($rows, static fn (array $a, array $b): int => $b['share'] <=> $a['share']);
        $sum = static fn (string $k): int => array_sum(array_map(static fn (array $r): int => (int) ($r[$k] ?? 0), $rows));

        return [
            'month' => $month, 'month_label' => date('F Y', strtotime($from)), 'prev_label' => date('F Y', strtotime($prev . '-01')),
            'fixed' => $fixedInfo, 'money' => $money,
            'targets' => ['ready' => $ready, 'rows' => array_values($rows), 'need' => $sum('need'), 'sold' => $sum('sold'), 'left' => $sum('left'), 'share_from' => $basis],
        ];
    }

    /** The month's actual fixed costs: every expense recorded in it, supplier payments left out. */
    public function actual(string $month): float
    {
        $st = Database::pdo()->prepare('SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE supplier_id IS NULL AND category <> :sp AND expense_date BETWEEN :f AND :t');
        $st->execute(['sp' => ExpenseService::SUPPLIER_PAYMENT, 'f' => $month . '-01', 't' => date('Y-m-t', strtotime($month . '-01'))]);

        return (float) $st->fetchColumn();
    }

    /**
     * Estimated against actual, the given month and the five before it, newest first.
     *
     * @return list<array{month: string, label: string, estimate: ?string, actual: string, diff: ?string, pct: ?float, final: bool}>
     */
    public function history(string $month, int $months = 6): array
    {
        $fixed = new FixedCost();
        $out = [];
        for ($k = 0; $k < $months; $k++) {
            $m = date('Y-m', strtotime($month . '-01 -' . $k . ' month'));
            $fc = $fixed->forMonth($m);
            $est = $fc === null ? null : (float) $fc['amount_usd'];
            $act = $this->actual($m);
            if ($est === null && $act <= 0) {
                continue;
            }
            $out[] = ['month' => $m, 'label' => date('F Y', strtotime($m . '-01')), 'estimate' => $est === null ? null : Money::fmt($est), 'actual' => Money::fmt($act),
                      'diff' => $est === null ? null : Money::fmt($act - $est), 'pct' => $est === null || $est <= 0 ? null : round(($act - $est) / $est * 100, 1), 'final' => $m < date('Y-m')];
        }

        return $out;
    }

    /** The administrator types the month's estimated fixed costs on the dashboard. */
    public function setFixedCosts(string $month, string $amount, int $userId): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new \DomainException('Choose the month.');
        }
        $text = str_replace([',', '$', ' '], '', trim($amount));
        if ($text === '' || !is_numeric($text) || (float) $text < 0 || (float) $text > 9999999) {
            throw new \DomainException('Estimated fixed costs: type an amount in dollars, like 1500.');
        }
        $value = Money::fmt((float) $text);
        (new FixedCost())->set($month, $value, $userId);
        Audit::log('fixed_costs.set', 'fixed_costs', null, ['month' => $month, 'amount' => $value], (float) $value, 'USD');

        return $value;
    }
}
