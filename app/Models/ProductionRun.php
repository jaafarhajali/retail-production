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

    /** Runs in a period, one row per product: quantity made and what it cost (the production report, the dashboard). */
    public function byProduct(string $from, string $to): array
    {
        return $this->fetchAll(
            'SELECT p.id AS product_id, p.name AS product_name, p.base_unit, p.kind AS product_kind, COUNT(*) AS runs, SUM(x.qty_base) AS qty_base, SUM(x.total_cost_usd) AS cost_usd
             FROM production_runs x JOIN products p ON p.id = x.product_id WHERE x.created_at BETWEEN :f AND :t GROUP BY p.id, p.name, p.base_unit, p.kind ORDER BY p.name',
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']
        );
    }

    /** Materials consumed in a period. */
    public function consumed(string $from, string $to): array
    {
        return $this->fetchAll(
            'SELECT m.id AS material_id, m.name AS material_name, m.base_unit, m.kind AS material_kind, SUM(l.qty_base) AS qty_base, SUM(l.cost_usd) AS cost_usd
             FROM production_lines l JOIN production_runs x ON x.id = l.run_id JOIN products m ON m.id = l.material_id
             WHERE x.created_at BETWEEN :f AND :t GROUP BY m.id, m.name, m.base_unit, m.kind ORDER BY cost_usd DESC',
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']
        );
    }
}
