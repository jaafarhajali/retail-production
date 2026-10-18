<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Counter;
use App\Models\Packing;
use App\Models\Product;
use App\Models\ProductUnit;

/**
 * Packing (2026-10-14): a batch of mix becomes finished products, and the split is decided at that moment.
 * "5 kg of mix → 30 Joy 100g + 20 Gold 100g" today, "→ 10 Joy + 40 Gold" tomorrow, from the same mix.
 * Nothing is predefined: the user types what was used and what came out. Each product's packaging per piece
 * (Product → Packaging per piece: a box, a label, 10 g of paste) is taken out by itself: 30 Joy take 30 boxes.
 * The mix and the packaging go out of stock at their cost of the moment; the products come in, and the cost of what
 * was used is shared between them in proportion to their selling value (a $6 Joy 200g takes twice the cost of a
 * $3 Joy 100g), per piece when a product in the split has no price. Each product's cost per piece becomes the
 * moving average of its packings, like a purchase.
 */
final class PackingService
{
    public function __construct(
        private readonly Product $products = new Product(),
        private readonly ProductUnit $units = new ProductUnit(),
        private readonly Packing $packings = new Packing(),
    ) {
    }

    /**
     * What a packing would do, before it is confirmed.
     *
     * @param list<array{product_id?: mixed, qty?: mixed, unit_id?: mixed}> $used the mix, and any extra typed by hand
     * @param list<array{product_id?: mixed, qty?: mixed, unit_id?: mixed}> $made the finished products and how many
     * @return array{used: list<array>, made: list<array>, short: list<string>, cost: string, by_value: bool}
     */
    public function preview(array $used, array $made): array
    {
        $usedLines = [];
        $addUsed = function (array $p, int $qtyBase, ?string $for) use (&$usedLines): void {
            $id = (int) $p['id'];
            if (!isset($usedLines[$id])) {
                $usedLines[$id] = ['product_id' => $id, 'name' => $p['name'], 'kind' => $p['kind'], 'base_unit' => $p['base_unit'], 'qty_base' => 0, 'typed' => false, 'for' => [],
                    'stock_base' => (int) $p['stock_base'], 'stock_text' => ProductionService::baseText((int) $p['stock_base'], $p['base_unit']), 'cost_per_base' => $p['cost_per_base']];
            }
            $usedLines[$id]['qty_base'] += $qtyBase;
            if ($for === null) {
                $usedLines[$id]['typed'] = true;
            } else {
                $usedLines[$id]['for'][] = $for;
            }
        };
        foreach ($used as $l) {
            if (!is_array($l) || (int) ($l['product_id'] ?? 0) === 0 && trim((string) ($l['qty'] ?? '')) === '') {
                continue;   // an empty row on the form
            }
            $p = $this->products->find((int) ($l['product_id'] ?? 0)) ?? throw new \DomainException('Choose what was used on every line.');
            if ($p['kind'] === 'finished') {
                throw new \DomainException($p['name'] . ' is a finished product: it is made here, not used. Packing uses the mix and the packaging.');
            }
            if (isset($usedLines[(int) $p['id']])) {
                throw new \DomainException($p['name'] . ' is used twice. Add the quantities on one line.');
            }
            [$qtyBase] = $this->toBase($p, (string) ($l['qty'] ?? ''), (int) ($l['unit_id'] ?? 0));
            $addUsed($p, $qtyBase, null);
        }
        if ($usedLines === []) {
            throw new \DomainException('Start with the mix: which one was packed, and how much of it.');
        }
        $madeLines = [];
        $weights = [];
        foreach ($made as $l) {
            if (!is_array($l) || (int) ($l['product_id'] ?? 0) === 0 && trim((string) ($l['qty'] ?? '')) === '') {
                continue;
            }
            $p = $this->products->find((int) ($l['product_id'] ?? 0)) ?? throw new \DomainException('Choose the product on every line of what was made.');
            if ($p['kind'] !== 'finished') {
                throw new \DomainException($p['name'] . ' is not a finished product. Packing makes the products that are sold.');
            }
            if ((int) $p['track_stock'] !== 1) {
                throw new \DomainException($p['name'] . ' keeps no stock, so it cannot be made here.');
            }
            if (isset($madeLines[(int) $p['id']])) {
                throw new \DomainException($p['name'] . ' is made twice. Add the quantities on one line.');
            }
            [$qtyBase, $unit] = $this->toBase($p, (string) ($l['qty'] ?? ''), (int) ($l['unit_id'] ?? 0));
            $weights[(int) $p['id']] = $qtyBase * $this->pricePerBase((int) $p['id']);
            $madeLines[(int) $p['id']] = ['product_id' => (int) $p['id'], 'name' => $p['name'], 'base_unit' => $p['base_unit'], 'qty_base' => $qtyBase,
                'qty_text' => ProductionService::baseText($qtyBase, $p['base_unit']), 'unit_name' => $unit['name'], 'factor' => (int) $unit['factor']];
            // the product's packaging per piece comes out by itself: 30 Joy → 30 boxes, 30 labels
            foreach ($this->products->packaging((int) $p['id']) as $pk) {
                $material = $this->products->find((int) $pk['material_id']);
                if ($material !== null) {
                    $addUsed($material, (int) $pk['qty_base'] * $qtyBase, $madeLines[(int) $p['id']]['qty_text'] . ' ' . $p['name']);
                }
            }
        }
        if ($madeLines === []) {
            throw new \DomainException('Type what came out: at least one product and how many.');
        }
        if (!in_array('intermediate', array_column($usedLines, 'kind'), true)) {
            throw new \DomainException('Packing starts from a mix. Add the mix that was packed on the first line.');
        }
        // What the products hold (2026-10-18): each piece's marshmallow per piece, added up and compared with the mix used.
        // Never refused: a packing with crumbs or a different fill is real; only a gap over 5% is pointed out.
        $held = 0;
        $unknown = [];
        foreach ($madeLines as $id => $l) {
            $perPiece = (int) ($this->products->find($id)['fill_base'] ?? 0);
            if ($perPiece > 0) {
                $held += $perPiece * $l['qty_base'];
            } else {
                $unknown[] = $l['name'];
            }
        }
        $mixUsed = array_sum(array_map(static fn (array $u): int => $u['kind'] === 'intermediate' ? (int) $u['qty_base'] : 0, $usedLines));
        $mixStock = array_sum(array_map(static fn (array $u): int => $u['kind'] === 'intermediate' ? (int) $u['stock_base'] : 0, $usedLines));
        $mismatch = null;
        $over = null;
        if ($unknown === [] && $held > $mixStock) {
            // more than the pot holds cannot have been packed, whatever was typed: a hard stop, no second click (2026-10-18)
            $over = 'These products hold ' . ProductionService::baseText($held, 'g') . ' but only ' . ProductionService::baseText($mixStock, 'g') . ' of mix is in stock. Make more first.';
        } elseif ($unknown === [] && $held > 0 && abs($mixUsed - $held) > 0.05 * max($mixUsed, $held)) {
            $mismatch = 'You used ' . ProductionService::baseText($mixUsed, 'g') . ' of mix but these products hold ' . ProductionService::baseText($held, 'g') . ': check the numbers.';
        }
        // what the used lines cost, which are short
        $short = [];
        $total = 0.0;
        foreach ($usedLines as &$u) {
            $cost = $u['qty_base'] * (float) $u['cost_per_base'];
            $total += $cost;
            $u['cost_usd'] = Money::fmt($cost);
            $u['qty_text'] = ProductionService::baseText($u['qty_base'], $u['base_unit']);
            $u['short'] = $u['qty_base'] > $u['stock_base'];
            $u['auto'] = !$u['typed'];
            $u['for'] = implode(', ', $u['for']);
            if ($u['short']) {
                $short[] = $u['name'] . ': needs ' . $u['qty_text'] . ', in stock ' . $u['stock_text'];
            }
        }
        unset($u);
        // the share of the cost: by selling value, or per piece when a product in the split has no price
        $byValue = $weights !== [] && min($weights) > 0;
        if (!$byValue) {
            foreach ($madeLines as $id => $l) {
                $weights[$id] = $l['qty_base'];
            }
        }
        $sumWeights = array_sum($weights);
        $given = 0.0;
        $ids = array_keys($madeLines);
        foreach ($ids as $i => $id) {
            $share = $i === count($ids) - 1 ? $total - $given : round($total * $weights[$id] / $sumWeights, 2);
            $given += $share;
            $madeLines[$id]['cost_usd'] = Money::fmt($share);
            $madeLines[$id]['cost_per_base'] = number_format($share / $madeLines[$id]['qty_base'], 6, '.', '');
            $madeLines[$id]['cost_per_unit'] = Money::fmt($share / $madeLines[$id]['qty_base'] * $madeLines[$id]['factor']);
            $madeLines[$id]['share_pct'] = (int) round($weights[$id] / $sumWeights * 100);
        }

        return ['used' => array_values($usedLines), 'made' => array_values($madeLines), 'short' => $short, 'cost' => Money::fmt($total), 'by_value' => $byValue,
            'held' => $held, 'held_text' => ProductionService::baseText($held, 'g'), 'unknown' => $unknown, 'mismatch' => $mismatch, 'over' => $over];
    }

    /** @return array{id: int, pack_no: string, made_text: string, cost: string} */
    public function pack(array $used, array $made, string $notes, int $userId, bool $gapConfirmed = false): array
    {
        $p = $this->preview($used, $made);
        if ($p['over'] !== null) {
            throw new \DomainException($p['over']);
        }
        if ($p['short'] !== []) {
            throw new \DomainException('Not enough in stock. ' . implode('; ', $p['short']) . '. Make or buy the missing part first.');
        }
        if ($p['mismatch'] !== null && !$gapConfirmed) {   // a gap over 5% is confirmed on purpose, with a second click (2026-10-18)
            throw new \DomainException($p['mismatch'] . ' If these numbers are right, press Confirm anyway.');
        }
        $notes = mb_substr(trim($notes), 0, 255);

        return Database::transaction(function () use ($p, $notes, $userId): array {
            $no = Counter::format('PCK-', Counter::next('packing'));
            $id = $this->packings->create(['pack_no' => $no, 'cost_usd' => $p['cost'], 'user_id' => $userId, 'notes' => $notes === '' ? null : $notes]);
            $stock = new StockService();
            foreach ($p['used'] as $l) {
                $this->packings->addLine($id, 'used', $l);
                $stock->move($l['product_id'], -$l['qty_base'], 'production_out', $no, $l['cost_per_base'], 'packing', $id, $userId, null);
            }
            $madeText = [];
            foreach ($p['made'] as $l) {
                $this->packings->addLine($id, 'made', $l);
                $stock->produce($l['product_id'], $l['qty_base'], $l['cost_per_base'], $id, $userId, 'packing');
                $madeText[] = $l['name'] . ' ×' . number_format($l['qty_base']);
            }
            Audit::log('packing.done', 'packing', $id, ['no' => $no, 'used' => count($p['used']), 'made' => implode(', ', $madeText)], (float) $p['cost'], 'USD');

            return ['id' => $id, 'pack_no' => $no, 'made_text' => implode(', ', $madeText), 'cost' => $p['cost']];
        });
    }

    /** The retail price of the product's default sale unit, per base unit; 0 when it has none. */
    private function pricePerBase(int $productId): float
    {
        $units = $this->units->forProduct($productId);
        $sale = array_values(array_filter($units, static fn (array $u): bool => (int) $u['is_default_sale'] === 1))[0] ?? ($units[0] ?? null);
        if ($sale === null || $sale['retail_price'] === null || (float) $sale['retail_price'] <= 0) {
            return 0.0;
        }

        return (float) $sale['retail_price'] / max(1, (int) $sale['factor']);
    }

    /** @return array{0: int, 1: array} the base quantity and the unit */
    private function toBase(array $product, string $qty, int $unitId): array
    {
        $unit = $this->units->find($unitId);
        if ($unit === null || (int) $unit['product_id'] !== (int) $product['id']) {
            throw new \DomainException('Choose the unit for ' . $product['name'] . '.');
        }
        $text = str_replace(',', '', trim($qty));
        if ($text === '' || !is_numeric($text) || (float) $text <= 0) {
            throw new \DomainException('Enter the quantity for ' . $product['name'] . '.');
        }
        if ($product['base_unit'] === 'piece' && (int) $unit['allows_fraction'] !== 1 && abs((float) $text - round((float) $text)) > 0.0001) {
            throw new \DomainException($product['name'] . ': ' . $unit['name'] . ' is counted in whole numbers.');
        }
        $base = (int) round((float) $text * (int) $unit['factor']);
        if ($base <= 0) {
            throw new \DomainException('The quantity for ' . $product['name'] . ' is too small for one ' . $product['base_unit'] . '.');
        }

        return [$base, $unit];
    }
}
