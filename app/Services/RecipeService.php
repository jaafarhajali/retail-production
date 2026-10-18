<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Recipe;

/**
 * Recipes (F2, 2026-10-13): "1 kg of Marshmallow mix = 200 g honey + 10 g colouring + 300 g B + 490 g C". A recipe
 * belongs to a mix; its lines are raw materials, packaging, or another mix. Quantities are typed in any unit and kept
 * in base units. Since 2026-10-14 a recipe never makes a finished product: the mix is packed into products on the
 * Packing page, with the quantities typed each time.
 */
final class RecipeService
{
    public function __construct(private readonly Recipe $recipes = new Recipe(), private readonly Product $products = new Product(), private readonly ProductUnit $units = new ProductUnit())
    {
    }

    /**
     * @param array{product_id: mixed, name: mixed, output_qty: mixed, output_unit_id: mixed, notes: mixed, is_default: mixed, lines: mixed} $in
     *        lines: list of [material_id, qty, unit_id]
     */
    public function save(int $id, array $in, int $userId): int
    {
        $current = $id > 0 ? ($this->recipes->find($id) ?? throw new \DomainException('Recipe not found.')) : null;
        $productId = $current === null ? (int) ($in['product_id'] ?? 0) : (int) $current['product_id'];   // a recipe never changes product
        $product = $this->products->find($productId) ?? throw new \DomainException('Choose the product this recipe makes.');
        if ($product['kind'] === 'material') {
            throw new \DomainException($product['name'] . ' is a raw material: it is bought, not made. A recipe makes a mix.');
        }
        if ($product['kind'] === 'finished') {
            throw new \DomainException($product['name'] . ' is a finished product: it is made on the Packing page, from the mix, with the quantities typed each time. A recipe makes a mix.');
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new \DomainException('Type the flavour, e.g. Strawberry.');   // never "Standard" by itself (2026-10-16)
        }
        if (mb_strlen($name) > 120) {
            throw new \DomainException('The recipe name is at most 120 characters.');
        }
        $outputBase = $this->toBase($product, (string) ($in['output_qty'] ?? ''), (int) ($in['output_unit_id'] ?? 0), 'what one batch makes');
        $lines = [];
        foreach (is_array($in['lines'] ?? null) ? $in['lines'] : [] as $l) {
            if (!is_array($l) || trim((string) ($l['qty'] ?? '')) === '' && (int) ($l['material_id'] ?? 0) === 0) {
                continue;   // an empty row on the form
            }
            $material = $this->products->find((int) ($l['material_id'] ?? 0)) ?? throw new \DomainException('Choose a material on every line.');
            if ($material['kind'] === 'finished') {
                throw new \DomainException($material['name'] . ' is a finished product: a recipe uses raw materials, packaging or a mix.');
            }
            if ((int) $material['id'] === $productId) {
                throw new \DomainException('A recipe cannot use the product it makes.');
            }
            if (isset($lines[(int) $material['id']])) {
                throw new \DomainException($material['name'] . ' is on the recipe twice. Add the quantities on one line.');
            }
            $lines[(int) $material['id']] = ['material_id' => (int) $material['id'], 'qty_base' => $this->toBase($material, (string) ($l['qty'] ?? ''), (int) ($l['unit_id'] ?? 0), $material['name'], true)];
        }
        if ($lines === []) {
            throw new \DomainException('A recipe needs at least one line: what goes into one batch.');
        }
        $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 255);
        $isDefault = (bool) ($in['is_default'] ?? false) || $this->recipes->forProduct($productId) === [] || ($current !== null && (int) $current['is_default'] === 1 && !isset($in['is_default_given']));

        return Database::transaction(function () use ($id, $current, $productId, $product, $name, $outputBase, $notes, $isDefault, $lines): int {
            $fields = ['product_id' => $productId, 'name' => $name, 'output_base' => $outputBase, 'notes' => $notes === '' ? null : $notes, 'is_default' => $isDefault, 'is_active' => true];
            if ($current === null) {
                $id = $this->recipes->create($fields);
            } else {
                $this->recipes->update($id, $fields);
            }
            $this->recipes->replaceLines($id, array_values($lines));
            if ($isDefault) {
                $this->recipes->setDefault($productId, $id);
            }
            Audit::log($current === null ? 'recipe.created' : 'recipe.updated', 'recipe', $id, ['product' => $product['name'], 'name' => $name, 'output_base' => $outputBase, 'lines' => count($lines)]);

            return $id;
        });
    }

    /** A recipe that was never run can be deleted; one with runs is switched off, its history stays. */
    public function delete(int $id): void
    {
        $recipe = $this->recipes->find($id) ?? throw new \DomainException('Recipe not found.');
        if ((int) $recipe['run_count'] > 0) {
            $this->recipes->update($id, ['name' => $recipe['name'], 'output_base' => (int) $recipe['output_base'], 'notes' => $recipe['notes'], 'is_default' => false, 'is_active' => false]);
            Audit::log('recipe.deactivated', 'recipe', $id, ['product' => $recipe['product_name'], 'runs' => (int) $recipe['run_count']]);

            return;
        }
        $this->recipes->delete($id);
        Audit::log('recipe.deleted', 'recipe', $id, ['product' => $recipe['product_name'], 'name' => $recipe['name']]);
    }

    /**
     * What one batch costs with the materials' costs of today, and per base unit of the product.
     *
     * @return array{batch_cost: string, cost_per_base: string, lines: list<array>}
     */
    public function estimate(int $recipeId): array
    {
        $recipe = $this->recipes->find($recipeId) ?? throw new \DomainException('Recipe not found.');
        $total = 0.0;
        $lines = [];
        foreach ($this->recipes->lines($recipeId) as $l) {
            $cost = (int) $l['qty_base'] * (float) $l['cost_per_base'];
            $total += $cost;
            $lines[] = $l + ['cost_usd' => Money::fmt($cost)];
        }
        $out = max(1, (int) $recipe['output_base']);

        return ['batch_cost' => Money::fmt($total), 'cost_per_base' => number_format($total / $out, 6, '.', ''), 'lines' => $lines];
    }

    /** A quantity typed in one of the product's units, as base units; fractions are fine for weight and volume. */
    private function toBase(array $product, string $qty, int $unitId, string $what, bool $baseAllowed = false): int
    {
        // An ingredient can be written in its own base unit (g, ml, piece) without a unit of that name (2026-10-18): unit 0
        $unit = $unitId === 0 && $baseAllowed
            ? ['product_id' => $product['id'], 'name' => $product['base_unit'], 'factor' => 1, 'allows_fraction' => 0]
            : $this->units->find($unitId);
        if ($unit === null || (int) $unit['product_id'] !== (int) $product['id']) {
            throw new \DomainException("Choose the unit for {$what}.");
        }
        $fraction = $product['base_unit'] !== 'piece' || (int) $unit['allows_fraction'] === 1;
        $text = trim($qty);
        if ($text === '' || !is_numeric(str_replace(',', '', $text)) || (float) str_replace(',', '', $text) <= 0) {
            throw new \DomainException("Enter the quantity for {$what}.");
        }
        $base = (int) round((float) str_replace(',', '', $text) * (int) $unit['factor']);
        if (!$fraction && abs((float) str_replace(',', '', $text) - round((float) str_replace(',', '', $text))) > 0.0001) {
            throw new \DomainException("{$what}: {$unit['name']} is counted in whole numbers.");
        }
        if ($base <= 0) {
            throw new \DomainException("The quantity for {$what} is too small for one " . $product['base_unit'] . '.');
        }

        return $base;
    }
}
