<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Recipes (F2): what one batch of a mix or a finished product is made of. */
final class Recipe extends Model
{
    private const SELECT = 'SELECT r.*, p.name AS product_name, p.base_unit, p.kind AS product_kind, p.cost_per_base AS product_cost,
                                   (SELECT COUNT(*) FROM recipe_lines l WHERE l.recipe_id = r.id) AS line_count,
                                   (SELECT COUNT(*) FROM production_runs x WHERE x.recipe_id = r.id) AS run_count
                            FROM recipes r JOIN products p ON p.id = r.product_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE r.id = :id', ['id' => $id]);
    }

    /** Every active recipe, the product's name first. */
    public function all(bool $activeOnly = true): array
    {
        return $this->fetchAll(self::SELECT . ($activeOnly ? ' WHERE r.is_active = 1' : '') . ' ORDER BY p.name, r.is_default DESC, r.name');
    }

    public function forProduct(int $productId, bool $activeOnly = true): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE r.product_id = :p' . ($activeOnly ? ' AND r.is_active = 1' : '') . ' ORDER BY r.is_default DESC, r.name', ['p' => $productId]);
    }

    /** The lines with what they need to be shown and costed: the material's name, base unit, stock and cost now. */
    public function lines(int $recipeId): array
    {
        return $this->fetchAll(
            'SELECT l.*, m.name AS material_name, m.base_unit, m.kind AS material_kind, m.stock_base, m.cost_per_base
             FROM recipe_lines l JOIN products m ON m.id = l.material_id WHERE l.recipe_id = :r ORDER BY l.id',
            ['r' => $recipeId]
        );
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO recipes (product_id, name, output_base, notes, is_default) VALUES (:p, :n, :o, :t, :d)',
            ['p' => $f['product_id'], 'n' => $f['name'], 'o' => $f['output_base'], 't' => $f['notes'], 'd' => (int) $f['is_default']]
        );

        return $this->lastId();
    }

    public function update(int $id, array $f): void
    {
        $this->execute(
            'UPDATE recipes SET name = :n, output_base = :o, notes = :t, is_default = :d, is_active = :a WHERE id = :id',
            ['n' => $f['name'], 'o' => $f['output_base'], 't' => $f['notes'], 'd' => (int) $f['is_default'], 'a' => (int) $f['is_active'], 'id' => $id]
        );
    }

    /** The lines are rewritten as a whole: a recipe is a definition, not a record. */
    public function replaceLines(int $recipeId, array $lines): void
    {
        $this->execute('DELETE FROM recipe_lines WHERE recipe_id = :r', ['r' => $recipeId]);
        foreach ($lines as $l) {
            $this->execute('INSERT INTO recipe_lines (recipe_id, material_id, qty_base) VALUES (:r, :m, :q)', ['r' => $recipeId, 'm' => $l['material_id'], 'q' => $l['qty_base']]);
        }
    }

    /** One default per product. */
    public function setDefault(int $productId, int $recipeId): void
    {
        $this->execute('UPDATE recipes SET is_default = CASE WHEN id = :r THEN 1 ELSE 0 END WHERE product_id = :p', ['r' => $recipeId, 'p' => $productId]);
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM recipes WHERE id = :id', ['id' => $id]);
    }
}
