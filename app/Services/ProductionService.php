<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Counter;
use App\Models\Product;
use App\Models\ProductionRun;
use App\Models\ProductUnit;
use App\Models\Recipe;
use App\Models\StockMovement;

/**
 * Production runs (F2, 2026-10-13). "Make 5 kg of Marshmallow mix with the standard recipe": the recipe is scaled to
 * the quantity (batches = 5 kg ÷ 1 kg), every material goes out of stock at its cost of the moment, the product
 * comes in at the sum of those costs, and the product's cost per unit becomes the moving average of its runs,
 * exactly as a purchase does for a bought product. A run is refused when a material is short. Since 2026-10-14 a
 * recipe makes a mix only, the quantity is counted in batches (unit 0) or in the mix's units, and the mix is turned
 * into finished products on the Packing page.
 */
final class ProductionService
{
    public function __construct(
        private readonly Recipe $recipes = new Recipe(),
        private readonly ProductionRun $runs = new ProductionRun(),
        private readonly Product $products = new Product(),
        private readonly ProductUnit $units = new ProductUnit(),
    ) {
    }

    /**
     * What a run would do, before it is confirmed: the materials and quantities, which are short, and the cost.
     *
     * @return array{product: array, recipe: array, qty_base: int, qty_text: string, batches: string, lines: list<array>, short: list<string>,
     *               material_cost: string, cost_per_base: string, cost_per_unit: string}
     */
    public function preview(int $recipeId, string $qty, int $unitId): array
    {
        $recipe = $this->recipes->find($recipeId) ?? throw new \DomainException('Choose the recipe.');
        if ((int) $recipe['is_active'] !== 1) {
            throw new \DomainException('This recipe is switched off.');
        }
        $product = $this->products->find((int) $recipe['product_id']) ?? throw new \DomainException('Product not found.');
        $unit = $unitId === 0
            ? ['name' => 'batch', 'factor' => max(1, (int) $recipe['output_base']), 'allows_fraction' => $product['base_unit'] === 'piece' ? 0 : 1, 'product_id' => (int) $product['id']]
            : $this->units->find($unitId);
        if ($unit === null || (int) $unit['product_id'] !== (int) $product['id']) {
            throw new \DomainException('Choose the unit of the quantity produced.');
        }
        $text = str_replace(',', '', trim($qty));
        if ($text === '' || !is_numeric($text) || (float) $text <= 0) {
            throw new \DomainException('Enter how much was produced.');
        }
        if ($product['base_unit'] === 'piece' && (int) $unit['allows_fraction'] !== 1 && abs((float) $text - round((float) $text)) > 0.0001) {
            throw new \DomainException($unit['name'] . ' is counted in whole numbers.');
        }
        $qtyBase = (int) round((float) $text * (int) $unit['factor']);
        if ($qtyBase <= 0) {
            throw new \DomainException('The quantity is too small for one ' . $product['base_unit'] . '.');
        }
        $batches = $qtyBase / max(1, (int) $recipe['output_base']);
        $lines = [];
        $short = [];
        $total = 0.0;
        foreach ($this->recipes->lines($recipeId) as $l) {
            $need = (int) round((int) $l['qty_base'] * $batches);
            $cost = $need * (float) $l['cost_per_base'];
            $total += $cost;
            $isShort = $need > (int) $l['stock_base'];
            if ($isShort) {
                $short[] = $l['material_name'] . ': needs ' . self::baseText($need, $l['base_unit']) . ', in stock ' . self::baseText((int) $l['stock_base'], $l['base_unit']);
            }
            $lines[] = ['material_id' => (int) $l['material_id'], 'material_name' => $l['material_name'], 'base_unit' => $l['base_unit'], 'material_kind' => $l['material_kind'],
                        'per_batch' => (int) $l['qty_base'], 'need_base' => $need, 'need_text' => self::baseText($need, $l['base_unit']),
                        'stock_base' => (int) $l['stock_base'], 'stock_text' => self::baseText((int) $l['stock_base'], $l['base_unit']),
                        'short' => $isShort, 'cost_per_base' => $l['cost_per_base'], 'cost_usd' => Money::fmt($cost)];
        }
        $costPerBase = number_format($total / $qtyBase, 6, '.', '');

        return [
            'product' => $product, 'recipe' => $recipe, 'qty_base' => $qtyBase, 'qty_text' => self::baseText($qtyBase, $product['base_unit']),
            'batches' => number_format($batches, 4, '.', ''), 'lines' => $lines, 'short' => $short, 'material_cost' => Money::fmt($total),
            'cost_per_base' => $costPerBase, 'cost_per_unit' => Money::fmt((float) $costPerBase * (int) $unit['factor']), 'unit_name' => $unit['name'],
        ];
    }

    /** @return array{id: int, run_no: string, qty_text: string, total_cost_usd: string, cost_per_unit: string} */
    public function run(int $recipeId, string $qty, int $unitId, string $notes, int $userId): array
    {
        $p = $this->preview($recipeId, $qty, $unitId);
        if ($p['short'] !== []) {
            throw new \DomainException('Not enough in stock. ' . implode('; ', $p['short']) . '. Buy or produce the missing part first.');
        }
        $notes = mb_substr(trim($notes), 0, 255);

        return Database::transaction(function () use ($p, $recipeId, $notes, $userId): array {
            $no = Counter::format('PRD-', Counter::next('production'));
            $runId = $this->runs->create([
                'run_no' => $no, 'product_id' => (int) $p['product']['id'], 'recipe_id' => $recipeId, 'qty_base' => $p['qty_base'], 'batches' => $p['batches'],
                'material_cost_usd' => $p['material_cost'], 'extra_cost_usd' => '0.00', 'total_cost_usd' => $p['material_cost'], 'cost_per_base' => $p['cost_per_base'],
                'user_id' => $userId, 'notes' => $notes === '' ? null : $notes,
            ]);
            $stock = new StockService();
            foreach ($p['lines'] as $l) {
                $this->runs->addLine($runId, ['material_id' => $l['material_id'], 'qty_base' => $l['need_base'], 'cost_per_base' => $l['cost_per_base'], 'cost_usd' => $l['cost_usd']]);
                $stock->move($l['material_id'], -$l['need_base'], 'production_out', $no, $l['cost_per_base'], 'production', $runId, $userId, null);
            }
            $stock->produce((int) $p['product']['id'], $p['qty_base'], $p['cost_per_base'], $runId, $userId);
            Audit::log('production.run', 'production', $runId, ['no' => $no, 'product' => $p['product']['name'], 'qty' => $p['qty_text'], 'lines' => count($p['lines'])], (float) $p['material_cost'], 'USD');

            return ['id' => $runId, 'run_no' => $no, 'qty_text' => $p['qty_text'], 'total_cost_usd' => $p['material_cost'], 'cost_per_unit' => $p['cost_per_unit']];
        });
    }

    /** "1,500 g" → "1.5 kg"; "50 piece" → "50 pieces". */
    public static function baseText(int $base, string $baseUnit): string
    {
        if ($baseUnit === 'piece') {
            return number_format($base) . ($base === 1 ? ' piece' : ' pieces');
        }
        $big = $baseUnit === 'g' ? 'kg' : 'L';
        if (abs($base) >= 1000) {
            return rtrim(rtrim(number_format($base / 1000, 3, '.', ','), '0'), '.') . ' ' . $big;
        }

        return number_format($base) . ' ' . $baseUnit;
    }
}
