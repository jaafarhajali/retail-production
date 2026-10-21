<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUnit;

/**
 * The custom order (owner, 2026-10-21): a box made to the customer's wish, sold on the built-in "Custom order" product
 * (migration 025). Nothing is set up for it: at each sale the price, the grams of marshmallow, the packaging taken from
 * the stock and the other costs are typed. This gives the till and the invoice form what they need to offer it.
 */
final class CustomOrderService
{
    /** @return array{id: int, unit: int, mix: ?array{name: string, stock: int}, materials: list<array{id: int, name: string, unit: string, stock: int}>}|null */
    public static function payload(): ?array
    {
        $products = new Product();
        $custom = $products->customOrder();
        if ($custom === null) {
            return null;
        }
        $unit = (new ProductUnit())->forProduct((int) $custom['id'])[0] ?? null;
        if ($unit === null) {
            return null;
        }
        $mix = $products->mixFor($custom);

        return [
            'id' => (int) $custom['id'], 'unit' => (int) $unit['id'],
            'mix' => $mix === null ? null : ['name' => $mix['name'], 'stock' => (int) $mix['stock_base']],
            // the packaging categories only (boxes, labels, bags): the ingredients are in the mix already
            'materials' => array_values(array_map(static fn (array $m): array => ['id' => (int) $m['id'], 'name' => $m['name'], 'unit' => $m['base_unit'], 'stock' => (int) $m['stock_base']], ProductService::packagingOnly($products->forPrint('material')))),
        ];
    }
}
