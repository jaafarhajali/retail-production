<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Category extends Model
{
    private const SELECT = 'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
                            FROM categories c';

    /** @return array<int, array<string, mixed>> ordered for the POS grid tabs */
    public function all(bool $activeOnly = false): array
    {
        return $this->fetchAll(self::SELECT . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name');
    }

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE c.id = :id', ['id' => $id]);
    }

    /** Case-insensitive (utf8mb4_unicode_ci). */
    public function nameExists(string $name, int $exceptId = 0): bool
    {
        return (bool) $this->fetchValue(
            'SELECT COUNT(*) FROM categories WHERE name = :n AND id <> :id',
            ['n' => $name, 'id' => $exceptId]
        );
    }

    /** Categories the till shows (2026-10-18): active, marked for the till, and holding at least one product the till can sell. */
    public function forTill(): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE c.is_active = 1 AND c.on_till = 1
            AND EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.kind = \'finished\' AND p.is_active = 1 AND p.show_on_pos_grid = 1)
            ORDER BY c.sort_order, c.name');
    }

    public function create(string $name, string $color, int $sortOrder, bool $onTill = true): int
    {
        $this->execute(
            'INSERT INTO categories (name, color, sort_order, on_till) VALUES (:n, :c, :s, :t)',
            ['n' => $name, 'c' => $color, 's' => $sortOrder, 't' => (int) $onTill]
        );

        return $this->lastId();
    }

    public function update(int $id, string $name, string $color, int $sortOrder, bool $isActive, bool $onTill = true): void
    {
        $this->execute(
            'UPDATE categories SET name = :n, color = :c, sort_order = :s, is_active = :a, on_till = :t WHERE id = :id',
            ['n' => $name, 'c' => $color, 's' => $sortOrder, 'a' => (int) $isActive, 't' => (int) $onTill, 'id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM categories WHERE id = :id', ['id' => $id]);
    }
}
