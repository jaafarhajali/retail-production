<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Packings (2026-10-14): append-only records of mix packed into finished products, with the quantities of that day. */
final class Packing extends Model
{
    private const SELECT = 'SELECT k.*, u.username FROM packings k LEFT JOIN users u ON u.id = k.user_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE k.id = :id', ['id' => $id]);
    }

    /** The latest packings with a one-line summary of what was made ("Joy 100g ×30, Gold 100g ×20") and used. */
    public function recent(int $limit = 100): array
    {
        return $this->fetchAll(
            "SELECT k.*, u.username,
                    (SELECT GROUP_CONCAT(CONCAT(p.name, ' ×', l.qty_base) ORDER BY l.id SEPARATOR ', ') FROM packing_lines l JOIN products p ON p.id = l.product_id WHERE l.packing_id = k.id AND l.side = 'made') AS made_text,
                    (SELECT SUM(l.qty_base) FROM packing_lines l WHERE l.packing_id = k.id AND l.side = 'made') AS made_base,
                    (SELECT GROUP_CONCAT(p.name ORDER BY l.id SEPARATOR ', ') FROM packing_lines l JOIN products p ON p.id = l.product_id WHERE l.packing_id = k.id AND l.side = 'used') AS used_text
             FROM packings k LEFT JOIN users u ON u.id = k.user_id ORDER BY k.id DESC LIMIT " . $limit
        );
    }

    public function lines(int $packingId): array
    {
        return $this->fetchAll(
            'SELECT l.*, p.name AS product_name, p.base_unit, p.kind FROM packing_lines l JOIN products p ON p.id = l.product_id WHERE l.packing_id = :k ORDER BY l.side DESC, l.id',
            ['k' => $packingId]
        );
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO packings (pack_no, cost_usd, user_id, notes) VALUES (:no, :c, :u, :n)',
            ['no' => $f['pack_no'], 'c' => $f['cost_usd'], 'u' => $f['user_id'], 'n' => $f['notes']]
        );

        return $this->lastId();
    }

    public function addLine(int $packingId, string $side, array $l): void
    {
        $this->execute(
            'INSERT INTO packing_lines (packing_id, side, product_id, qty_base, cost_per_base, cost_usd) VALUES (:k, :s, :p, :q, :c, :u)',
            ['k' => $packingId, 's' => $side, 'p' => $l['product_id'], 'q' => $l['qty_base'], 'c' => $l['cost_per_base'], 'u' => $l['cost_usd']]
        );
    }
}
