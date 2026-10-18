<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;
use App\Services\PackingService;
use App\Services\ProductionService;
use App\Services\ProductService;
use App\Services\RecipeService;
use App\Services\StockService;

/**
 * Packing (2026-10-14): the mix made by a recipe becomes finished products, and the split is typed each time.
 * The same 5 kg of mix gives 30 Joy today and 10 Joy + 10 Gold tomorrow; the cost of the mix and the boxes is
 * shared between the products in proportion to their selling price.
 */
$kitchen = static function (): array {
    $svc = new ProductService();
    $stock = new StockService();
    $mk = static function (string $name, string $kind, string $base, array $units, string $cost, int $costFactor, ?string $opening = null, bool $trackStock = true) use ($svc, $stock): array {
        $id = $svc->create(['name' => $name, 'category_id' => '', 'base_unit' => $base, 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
            'show_on_pos_grid' => $kind === 'finished', 'allow_price_override' => !$trackStock, 'kind' => $kind, 'track_stock' => $trackStock]);
        $ids = [];
        foreach ($units as [$type, $size, $fraction]) {
            $ids[] = $svc->addUnit($id, $type, (string) $size, $fraction, false);
        }
        if ($cost !== '') {
            $svc->setCost($id, $cost, $costFactor);
        }
        if ($opening !== null) {
            $stock->adjust($id, $ids[0], $opening, 'opening', '', null, TEST_ADMIN_ID);
        }

        return ['id' => $id, 'units' => $ids];
    };
    $m = [
        'honey' => $mk('Honey', 'material', 'g', [['kg', 1000, true]], '10', 1000, '3'),
        'colour' => $mk('Food colouring', 'material', 'g', [['kg', 1000, true]], '20', 1000, '0.2'),
        'b' => $mk('Ingredient B', 'material', 'g', [['kg', 1000, true]], '5', 1000, '2'),
        'c' => $mk('Ingredient C', 'material', 'g', [['kg', 1000, true]], '2', 1000, '5'),
        'box' => $mk('Box 100g', 'material', 'piece', [['Piece', 1, false]], '0.12', 1, '100'),
    ];
    $mix = $mk('Raspberry mix', 'intermediate', 'g', [['kg', 1000, true]], '', 1000);
    $joy = $mk('Joy 100g', 'finished', 'piece', [['Piece', 1, false]], '', 1);
    $svc->setPrices($joy['units'][0], '3', '2.5');
    $gold = $mk('Gold 100g', 'finished', 'piece', [['Piece', 1, false]], '', 1);
    $svc->setPrices($gold['units'][0], '4', '3.5');
    $bag = $mk('Party bag', 'finished', 'piece', [['Piece', 1, false]], '', 1);   // no price yet
    $custom = $mk('Customized Box', 'finished', 'piece', [['Piece', 1, false]], '', 1, null, false);
    // one Joy or Gold takes one box out of the packaging stock when it is packed
    $svc->savePackaging($joy['id'], [['material_id' => $m['box']['id'], 'qty' => '1', 'unit_id' => $m['box']['units'][0]]]);
    $svc->savePackaging($gold['id'], [['material_id' => $m['box']['id'], 'qty' => '1', 'unit_id' => $m['box']['units'][0]]]);
    // 5 kg of mix at $4.68 per kg (200 g honey + 10 g colouring + 300 g B + 490 g C per kg)
    $line = static fn (array $mat, string $qty): array => ['material_id' => $mat['id'], 'qty' => $qty, 'unit_id' => $mat['units'][0]];
    $recipe = (new RecipeService())->save(0, ['product_id' => $mix['id'], 'name' => 'Standard', 'output_qty' => '1', 'output_unit_id' => $mix['units'][0],
        'lines' => [$line($m['honey'], '0.2'), $line($m['colour'], '0.01'), $line($m['b'], '0.3'), $line($m['c'], '0.49')]], TEST_ADMIN_ID);
    (new ProductionService())->run($recipe, '5', 0, '', TEST_ADMIN_ID);

    return ['m' => $m, 'mix' => $mix, 'joy' => $joy, 'gold' => $gold, 'bag' => $bag, 'custom' => $custom, 'recipe' => $recipe];
};
$use = static fn (array $p, string $qty): array => ['product_id' => $p['id'], 'qty' => $qty, 'unit_id' => $p['units'][0]];
$stockOf = static fn (int $id): int => (int) (new Product())->find($id)['stock_base'];
$costOf = static fn (int $id): string => (string) (new Product())->find($id)['cost_per_base'];

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the same mix, two different splits: 3 kg → 30 Joy (30 boxes out by themselves); then 2 kg → 10 Joy + 10 Gold, the cost shared by selling price' => function () use ($kitchen, $use, $stockOf, $costOf): void {
        $k = $kitchen();
        assert_same([5000, '0.004680'], [$stockOf($k['mix']['id']), $costOf($k['mix']['id'])]);
        $svc = new PackingService();
        // packing 1: 3 kg of mix ($14.04) + 30 boxes ($3.60) → 30 Joy at $0.588
        $p = $svc->preview([$use($k['mix'], '3')], [$use($k['joy'], '30')]);
        assert_same(['17.64', [], true], [$p['cost'], $p['short'], $p['by_value']]);
        assert_same([3000, 30], array_column($p['used'], 'qty_base'));
        assert_same(['Box 100g', true, '30 pieces Joy 100g', '3.60'], [$p['used'][1]['name'], $p['used'][1]['auto'], $p['used'][1]['for'], $p['used'][1]['cost_usd']], 'the boxes come from the product, not from the form');
        assert_same(['17.64', '0.588000', '0.59', 100], [$p['made'][0]['cost_usd'], $p['made'][0]['cost_per_base'], $p['made'][0]['cost_per_unit'], $p['made'][0]['share_pct']]);
        $r = $svc->pack([$use($k['mix'], '3')], [$use($k['joy'], '30')], 'morning', TEST_ADMIN_ID);
        assert_same(['PCK-000001', 'Joy 100g ×30', '17.64'], [$r['pack_no'], $r['made_text'], $r['cost']]);
        assert_same([2000, 70, 30], [$stockOf($k['mix']['id']), $stockOf($k['m']['box']['id']), $stockOf($k['joy']['id'])]);
        assert_same('0.588000', $costOf($k['joy']['id']));
        // packing 2: 2 kg ($9.36) + 20 boxes ($2.40) = $11.76 → 10 Joy ($3 each, value 30) + 10 Gold ($4, value 40): Joy takes 3/7 = $5.04, Gold $6.72
        $p = $svc->preview([$use($k['mix'], '2')], [$use($k['joy'], '10'), $use($k['gold'], '10')]);
        assert_same('11.76', $p['cost']);
        assert_same([20, '10 pieces Joy 100g, 10 pieces Gold 100g'], [$p['used'][1]['qty_base'], $p['used'][1]['for']], 'one box line for both products');
        assert_same([['5.04', '0.504000', 43], ['6.72', '0.672000', 57]], array_map(static fn (array $l): array => [$l['cost_usd'], $l['cost_per_base'], $l['share_pct']], $p['made']));
        $r = $svc->pack([$use($k['mix'], '2')], [$use($k['joy'], '10'), $use($k['gold'], '10')], '', TEST_ADMIN_ID);
        assert_same(['PCK-000002', 'Joy 100g ×10, Gold 100g ×10'], [$r['pack_no'], $r['made_text']]);
        assert_same([0, 50, 40, 10], [$stockOf($k['mix']['id']), $stockOf($k['m']['box']['id']), $stockOf($k['joy']['id']), $stockOf($k['gold']['id'])]);
        assert_same(['0.567000', '0.672000'], [$costOf($k['joy']['id']), $costOf($k['gold']['id'])], 'Joy: (30 × 0.588 + 10 × 0.504) ÷ 40, the moving average');
        // the records: two packings, their lines, the stock movements that point at them
        assert_same([['PCK-000001', '17.64', 'morning'], ['PCK-000002', '11.76', null]], Database::pdo()->query('SELECT pack_no, cost_usd, notes FROM packings ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        assert_same(['used' => 4, 'made' => 3], array_map('intval', Database::pdo()->query('SELECT side, COUNT(*) FROM packing_lines GROUP BY side ORDER BY side')->fetchAll(PDO::FETCH_KEY_PAIR)));
        $types = Database::pdo()->query("SELECT type, COUNT(*) FROM stock_movements WHERE ref_type = 'packing' GROUP BY type ORDER BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_same(['production_in' => 3, 'production_out' => 4], array_map('intval', $types));
        assert_same(2, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'packing.done'")->fetchColumn());
    },

    'the rules: starts from a mix, uses no finished product, makes only finished products that keep stock, nothing twice, nothing short; a product without a price shares per piece' => function () use ($kitchen, $use, $stockOf): void {
        $k = $kitchen();
        $svc = new PackingService();
        $e = assert_throws(DomainException::class, fn () => $svc->preview([], [$use($k['joy'], '10')]));
        assert_contains('Start with the mix', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->preview([$use($k['m']['box'], '10')], [$use($k['joy'], '10')]));
        assert_contains('starts from a mix', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->preview([$use($k['mix'], '1'), $use($k['joy'], '1')], [$use($k['gold'], '10')]));
        assert_contains('finished product: it is made here', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->preview([$use($k['mix'], '1')], [$use($k['m']['box'], '10')]));
        assert_contains('not a finished product', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->preview([$use($k['mix'], '1')], [$use($k['custom'], '1')]));
        assert_contains('keeps no stock', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->preview([$use($k['mix'], '1')], [$use($k['joy'], '5'), $use($k['joy'], '5')]));
        assert_contains('twice', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->preview([$use($k['mix'], '1')], []));
        assert_contains('what came out', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->preview([$use($k['mix'], '1')], [$use($k['joy'], '2.5')]));
        assert_contains('whole numbers', $e->getMessage());
        // short: 6 kg when 5 are in stock, the preview says so and the packing is refused, nothing written
        $p = $svc->preview([$use($k['mix'], '6')], [$use($k['joy'], '60')]);
        assert_same(['Raspberry mix: needs 6 kg, in stock 5 kg'], $p['short']);
        $e = assert_throws(DomainException::class, fn () => $svc->pack([$use($k['mix'], '6')], [$use($k['joy'], '60')], '', TEST_ADMIN_ID));
        assert_contains('Not enough in stock', $e->getMessage());
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM packings')->fetchColumn());
        assert_same(5000, $stockOf($k['mix']['id']));
        // a product without a price in the split: the cost is shared per piece instead (1 kg $4.68 + 20 boxes $2.40 → 20 Joy + 20 bags = $3.54 each side)
        $p = $svc->preview([$use($k['mix'], '1')], [$use($k['joy'], '20'), $use($k['bag'], '20')]);
        assert_same([false, '3.54', '3.54'], [$p['by_value'], $p['made'][0]['cost_usd'], $p['made'][1]['cost_usd']]);
        // boxes typed by hand on top of the automatic ones add up on one line
        $p = $svc->preview([$use($k['mix'], '1'), $use($k['m']['box'], '5')], [$use($k['joy'], '10')]);
        assert_same([15, false], [$p['used'][1]['qty_base'], $p['used'][1]['auto']]);
        // the packaging list itself: a finished product is refused, a line twice is refused
        $ps = new ProductService();
        $e = assert_throws(DomainException::class, fn () => $ps->savePackaging($k['joy']['id'], [['material_id' => $k['gold']['id'], 'qty' => '1', 'unit_id' => $k['gold']['units'][0]]]));
        assert_contains('finished product', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $ps->savePackaging($k['joy']['id'], [['material_id' => $k['m']['box']['id'], 'qty' => '1', 'unit_id' => $k['m']['box']['units'][0]], ['material_id' => $k['m']['box']['id'], 'qty' => '2', 'unit_id' => $k['m']['box']['units'][0]]]));
        assert_contains('twice', $e->getMessage());
    },

    'what the products hold: each one\'s marshmallow per piece added up and compared with the mix used; a gap over 5% needs a second click; more than the pot holds is refused' => function () use ($kitchen, $use): void {
        $k = $kitchen();
        $db = \App\Core\Database::pdo();
        $db->exec('UPDATE products SET fill_base = 100 WHERE id IN (' . $k['joy']['id'] . ', ' . $k['gold']['id'] . ')');   // Joy 100g and Gold 100g hold 100 g each
        $svc = new PackingService();
        $p = $svc->preview([$use($k['mix'], '3')], [$use($k['joy'], '20'), $use($k['gold'], '10')]);
        assert_same([3000, '3 kg', [], null], [$p['held'], $p['held_text'], $p['unknown'], $p['mismatch']], '30 × 100 g = 3 kg: it agrees');
        $p = $svc->preview([$use($k['mix'], '3.1')], [$use($k['joy'], '20'), $use($k['gold'], '10')]);
        assert_same(null, $p['mismatch'], '100 g of crumbs is within 5%');
        $p = $svc->preview([$use($k['mix'], '3')], [$use($k['joy'], '30'), $use($k['gold'], '10')]);
        assert_same(['You used 3 kg of mix but these products hold 4 kg: check the numbers.', null], [$p['mismatch'], $p['over']], '4 kg from the 5 kg in stock: possible, so a warning');
        // more than the 5 kg in stock cannot have been packed, whatever was typed: a hard stop, even confirmed
        $p = $svc->preview([$use($k['mix'], '3')], [$use($k['joy'], '200'), $use($k['gold'], '70')]);
        assert_same(['These products hold 27 kg but only 5 kg of mix is in stock. Make more first.', null], [$p['over'], $p['mismatch']]);
        $e = assert_throws(DomainException::class, fn () => $svc->pack([$use($k['mix'], '3')], [$use($k['joy'], '200'), $use($k['gold'], '70')], '', TEST_ADMIN_ID, true));
        assert_contains('only 5 kg of mix is in stock', $e->getMessage());
        assert_same(0, (int) $db->query('SELECT COUNT(*) FROM packings')->fetchColumn(), 'nothing written');
        // such a gap is packed only when confirmed on purpose (the page's second click)
        $e = assert_throws(DomainException::class, fn () => $svc->pack([$use($k['mix'], '1')], [$use($k['joy'], '20')], '', TEST_ADMIN_ID));
        assert_contains('press Confirm anyway', $e->getMessage());
        $r = $svc->pack([$use($k['mix'], '1')], [$use($k['joy'], '15')], '', TEST_ADMIN_ID, true);
        assert_true($r['id'] > 0, '1 kg for 1.5 kg of products, confirmed: packed');
        $r = $svc->pack([$use($k['mix'], '3')], [$use($k['joy'], '20'), $use($k['gold'], '10')], '', TEST_ADMIN_ID);
        assert_true($r['id'] > 0, 'packed');
        // a product with no weight on its page: named, and no gap is claimed
        $p = $svc->preview([$use($k['mix'], '1')], [$use($k['joy'], '5'), $use($k['bag'], '50')]);
        assert_same([['Party bag'], null, 500], [$p['unknown'], $p['mismatch'], $p['held']]);
        // the page carries each product's weight for the running total
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('packing/new')->body;
        assert_contains('data-grams="100" dir="auto">Joy 100g', $page);
        assert_contains('id="pack-held"', $page);
        assert_contains('id="preview-gap"', $page);
        // 1 kg of mix is left now: 10 Joy (1 kg) against 500 g typed is a warning, 30 Joy (3 kg) a stop
        $j = json_decode($client->postJson('packing/preview', ['used' => [$use($k['mix'], '0.5')], 'made' => [$use($k['joy'], '10')]])->body, true);
        assert_same(['1 kg', 'You used 500 g of mix but these products hold 1 kg: check the numbers.', null], [$j['held_text'], $j['mismatch'], $j['over']]);
        $j = json_decode($client->postJson('packing/preview', ['used' => [$use($k['mix'], '0.5')], 'made' => [$use($k['joy'], '30')]])->body, true);
        assert_same(['These products hold 3 kg but only 1 kg of mix is in stock. Make more first.', null], [$j['over'], $j['mismatch']]);
        assert_contains('name="confirm_gap"', $page);
        // the form: refused without the confirmation, packed with it
        $post = static fn (array $extra) => $client->post('packing/store', $extra + ['used[0][product_id]' => (string) $k['mix']['id'], 'used[0][qty]' => '0.5', 'used[0][unit_id]' => (string) $k['mix']['units'][0],
            'made[0][product_id]' => (string) $k['joy']['id'], 'made[0][qty]' => '10', 'made[0][unit_id]' => (string) $k['joy']['units'][0], 'notes' => '']);
        $before = (int) \App\Core\Database::pdo()->query('SELECT COUNT(*) FROM packings')->fetchColumn();
        $post([]);
        assert_same($before, (int) \App\Core\Database::pdo()->query('SELECT COUNT(*) FROM packings')->fetchColumn(), 'not packed without the confirmation');
        assert_contains('press Confirm anyway', $client->get('packing/new')->body);
        $post(['confirm_gap' => '1', 'made[0][qty]' => '30']);
        assert_same($before, (int) \App\Core\Database::pdo()->query('SELECT COUNT(*) FROM packings')->fetchColumn(), 'more than the pot holds: refused even when confirmed');
        assert_contains('only 1 kg of mix is in stock', $client->get('packing/new')->body);
        $post(['confirm_gap' => '1']);
        assert_same($before + 1, (int) \App\Core\Database::pdo()->query('SELECT COUNT(*) FROM packings')->fetchColumn(), 'packed when confirmed');
    },

    'the pages: Pack with its preview, the packing in the production list and its detail, the report and the dashboard count it; a cashier may not' => function () use ($kitchen, $use): void {
        $k = $kitchen();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('packing/new')->body;
        assert_contains('Confirm: it is packed', $page);
        assert_contains('Raspberry mix', $page);
        assert_contains('Gold 100g', $page);
        // one mix (2026-10-18): its line asks only how much; the extras offer packaging and ingredients, never the mix
        assert_contains('<template id="mix-tpl">', $page);
        assert_contains('name="used[IDX][product_id]" value="' . $k['mix']['id'] . '"', $page);
        $extras = substr($page, (int) strpos($page, '<template id="used-tpl">'));
        $extras = substr($extras, 0, (int) strpos($extras, '</template>'));
        assert_not_contains('Raspberry mix', $extras, 'the extras never offer the mix');
        assert_not_contains('<optgroup label="Mix">', $extras);
        assert_not_contains('Customized Box', $page, 'keeps no stock, so it is not offered');
        $r = $client->postJson('packing/preview', ['used' => [$use($k['mix'], '2')], 'made' => [$use($k['joy'], '10'), $use($k['gold'], '10')]]);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same(['11.76', [], true, 2, 2], [$j['cost'], $j['short'], $j['by_value'], count($j['used']), count($j['made'])]);
        assert_same(['Box 100g', '20 pieces', true, '10 pieces Joy 100g, 10 pieces Gold 100g'], [$j['used'][1]['name'], $j['used'][1]['qty'], $j['used'][1]['auto'], $j['used'][1]['for']]);
        // the product page shows the packaging per piece
        $page = $client->get('products/edit', ['id' => $k['joy']['id']])->body;
        assert_contains('Packaging per piece', $page);
        assert_contains('name="packaging_given"', $page);
        assert_contains('"material_id":' . $k['m']['box']['id'], $page);
        assert_same(['Gold 100g', '10 pieces', 57, '6.72', '0.67', 'Piece'], array_values($j['made'][1]));
        $r = $client->postJson('packing/preview', ['used' => [], 'made' => [$use($k['joy'], '10')]]);
        assert_same(422, $r->status);
        // the empty rows the form sends along are ignored
        $r = $client->post('packing/store', ['used' => [['product_id' => (string) $k['mix']['id'], 'qty' => '2', 'unit_id' => (string) $k['mix']['units'][0]], ['product_id' => '', 'qty' => '', 'unit_id' => '']],   // the boxes are not typed
            'made' => [['product_id' => (string) $k['joy']['id'], 'qty' => '10', 'unit_id' => (string) $k['joy']['units'][0]], ['product_id' => (string) $k['gold']['id'], 'qty' => '10', 'unit_id' => (string) $k['gold']['units'][0]], ['product_id' => '', 'qty' => '', 'unit_id' => '']], 'notes' => 'by hand']);
        assert_same(302, $r->status, $r->body);
        assert_same(1, (int) Database::pdo()->query('SELECT COUNT(*) FROM packings')->fetchColumn());
        $page = $client->get('production')->body;
        assert_contains('PCK-000001', $page);
        assert_contains('Joy 100g ×10, Gold 100g ×10', $page);
        assert_contains('Pack into products', $page);
        $page = $client->get('packing/view', ['id' => 1])->body;
        foreach (['PCK-000001', 'Taken out of stock', 'Put into stock', 'Raspberry mix', 'Box 100g', '20 pieces', 'Gold 100g', 'by hand', '$9.36'] as $needle) {
            assert_contains($needle, $page, $needle);
        }
        $page = $client->get('reports', ['type' => 'production'])->body;
        assert_contains('Gold 100g', $page, 'made by packing shows in the production report');
        assert_contains('Raspberry mix', $page, 'made by the run, consumed by the packing');
        assert_contains('Gold 100g', $client->get('dashboard')->body);
        assert_contains('packing #1', $client->get('stock', ['type' => 'production_in'])->body);
        // a refused packing comes back to the form with the message
        $r = $client->post('packing/store', ['used' => [['product_id' => (string) $k['mix']['id'], 'qty' => '9', 'unit_id' => (string) $k['mix']['units'][0]]], 'made' => [['product_id' => (string) $k['joy']['id'], 'qty' => '10', 'unit_id' => (string) $k['joy']['units'][0]]], 'notes' => '']);
        assert_same(302, $r->status);
        assert_contains('Not enough in stock', $client->get('packing/new')->body);
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        assert_same(403, $cashier->get('packing/new')->status);
        assert_same(403, $cashier->postJson('packing/preview', [])->status);
    },
];
