<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Production runs (F2): append-only records of what was made, from what, at what cost. */
final class ProductionRun extends Model
{
    private const SELECT = 'SELECT x.*, p.name AS product_name, p.base_unit, p.kind AS product_kind, r.name AS recipe_name, u.username
                            FROM production_runs x JOIN products p ON p.id = x.product_id JOIN recipes r ON r.id = x.recipe_id LEFT JOIN users u ON u.id = x.user_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE x.id = :id', ['id' => $id]);
    }

    public function recent(int $limit = 100): array
    {
        return $this->fetchAll(self::SELECT . ' ORDER BY x.id DESC LIMIT ' . $limit);
    }

    public function lines(int $runId): array
    {
        return $this->fetchAll(
            'SELECT l.*, m.name AS material_name, m.base_unit, m.kind AS material_kind FROM production_lines l JOIN products m ON m.id = l.material_id WHERE l.run_id = :r ORDER BY l.id',
            ['r' => $runId]
        );
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO production_runs (run_no, product_id, recipe_id, qty_base, batches, material_cost_usd, extra_cost_usd, total_cost_usd, cost_per_base, user_id, notes)
             VALUES (:no, :p, :r, :q, :b, :mc, :xc, :tc, :cpb, :u, :n)',
            ['no' => $f['run_no'], 'p' => $f['product_id'], 'r' => $f['recipe_id'], 'q' => $f['qty_base'], 'b' => $f['batches'], 'mc' => $f['material_cost_usd'],
             'xc' => $f['extra_cost_usd'], 'tc' => $f['total_cost_usd'], 'cpb' => $f['cost_per_base'], 'u' => $f['user_id'], 'n' => $f['notes']]
        );

        return $this->lastId();
    }

    public function addLine(int $runId, array $l): void
    {
        $this->execute(
            'INSERT INTO production_lines (run_id, material_id, qty_base, cost_per_base, cost_usd) VALUES (:r, :m, :q, :c, :u)',
            ['r' => $runId, 'm' => $l['material_id'], 'q' => $l['qty_base'], 'c' => $l['cost_per_base'], 'u' => $l['cost_usd']]
        );
    }

    /** Runs and packings in a period, one row per product: quantity made and what it cost (the production report, the dashboard). */
    public function byProduct(string $from, string $to): array
    {
        return $this->fetchAll(
            'SELECT product_id, product_name, base_unit, product_kind, SUM(runs) AS runs, SUM(qty_base) AS qty_base, SUM(cost_usd) AS cost_usd FROM (
                SELECT p.id AS product_id, p.name AS product_name, p.base_unit, p.kind AS product_kind, COUNT(*) AS runs, SUM(x.qty_base) AS qty_base, SUM(x.total_cost_usd) AS cost_usd
                FROM production_runs x JOIN products p ON p.id = x.product_id WHERE x.created_at BETWEEN :f1 AND :t1 GROUP BY p.id, p.name, p.base_unit, p.kind
                UNION ALL
                SELECT p.id, p.name, p.base_unit, p.kind, COUNT(DISTINCT l.packing_id), SUM(l.qty_base), SUM(l.cost_usd)
                FROM packing_lines l JOIN packings k ON k.id = l.packing_id JOIN products p ON p.id = l.product_id
                WHERE l.side = \'made\' AND k.created_at BETWEEN :f2 AND :t2 GROUP BY p.id, p.name, p.base_unit, p.kind
             ) z GROUP BY product_id, product_name, base_unit, product_kind ORDER BY product_name',
            ['f1' => $from . ' 00:00:00', 't1' => $to . ' 23:59:59', 'f2' => $from . ' 00:00:00', 't2' => $to . ' 23:59:59']
        );
    }

    /** Materials and mix consumed in a period, by runs and by packings. */
    public function consumed(string $from, string $to): array
    {
        return $this->fetchAll(
            'SELECT material_id, material_name, base_unit, material_kind, SUM(qty_base) AS qty_base, SUM(cost_usd) AS cost_usd FROM (
                SELECT m.id AS material_id, m.name AS material_name, m.base_unit, m.kind AS material_kind, SUM(l.qty_base) AS qty_base, SUM(l.cost_usd) AS cost_usd
                FROM production_lines l JOIN production_runs x ON x.id = l.run_id JOIN products m ON m.id = l.material_id
                WHERE x.created_at BETWEEN :f1 AND :t1 GROUP BY m.id, m.name, m.base_unit, m.kind
                UNION ALL
                SELECT m.id, m.name, m.base_unit, m.kind, SUM(l.qty_base), SUM(l.cost_usd)
                FROM packing_lines l JOIN packings k ON k.id = l.packing_id JOIN products m ON m.id = l.product_id
                WHERE l.side = \'used\' AND k.created_at BETWEEN :f2 AND :t2 GROUP BY m.id, m.name, m.base_unit, m.kind
             ) z GROUP BY material_id, material_name, base_unit, material_kind ORDER BY cost_usd DESC',
            ['f1' => $from . ' 00:00:00', 't1' => $to . ' 23:59:59', 'f2' => $from . ' 00:00:00', 't2' => $to . ' 23:59:59']
        );
    }
}
