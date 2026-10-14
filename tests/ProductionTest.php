<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\ProductionService;
use App\Services\ProductService;
use App\Services\RecipeService;
use App\Services\StockService;

/**
 * Phase F2 (2026-10-13): a recipe says what one batch is made of; a run scales it, takes the materials out, puts the
 * product in at their cost. Since 2026-10-14 a recipe makes a mix only; the mix becomes products on the Packing page
 * (PackingTest), with the quantities typed each time.
 */
$kitchen = static function (): array {
    $svc = new ProductService();
    $stock = new StockService();
    $mk = static function (string $name, string $kind, string $base, array $units, string $cost, int $costFactor, ?string $opening = null) use ($svc, $stock): array {
        $id = $svc->create(['name' => $name, 'category_id' => '', 'base_unit' => $base, 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
            'show_on_pos_grid' => $kind === 'finished', 'allow_price_override' => false, 'kind' => $kind]);
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
    // materials: cost per kg → honey $10/kg, colouring $20/kg, B $5/kg, C $2/kg (the owner's example gives $5.00 per kg of mix)
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

    return ['m' => $m, 'mix' => $mix, 'joy' => $joy];
};
$stockOf = static fn (int $id): int => (int) (new Product())->find($id)['stock_base'];
$costOf = static fn (int $id): string => (string) (new Product())->find($id)['cost_per_base'];

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a recipe: one batch of 1 kg mix = 200 g honey + 10 g colouring + 300 g B + 490 g C; estimate $4.68 per kg' => function () use ($kitchen): void {
        $k = $kitchen();
        $recipes = new RecipeService();
        $line = static fn (array $mat, string $qty): array => ['material_id' => $mat['id'], 'qty' => $qty, 'unit_id' => $mat['units'][0]];
        $id = $recipes->save(0, ['product_id' => $k['mix']['id'], 'name' => 'Standard', 'output_qty' => '1', 'output_unit_id' => $k['mix']['units'][0], 'notes' => '',
            'lines' => [$line($k['m']['honey'], '0.2'), $line($k['m']['colour'], '0.01'), $line($k['m']['b'], '0.3'), $line($k['m']['c'], '0.49')]], TEST_ADMIN_ID);
        $r = (new Recipe())->find($id);
        assert_same([1000, 1, 4], [(int) $r['output_base'], (int) $r['is_default'], (int) $r['line_count']]);
        assert_same([200, 10, 300, 490], array_map('intval', array_column((new Recipe())->lines($id), 'qty_base')));
        $e = $recipes->estimate($id);
        assert_same('4.68', $e['batch_cost'], '0.2×10 + 0.01×20 + 0.3×5 + 0.49×2');
        assert_same('0.004680', $e['cost_per_base']);
        // rules: a finished product is never an ingredient; a material is never made; a line twice is refused
        $e = assert_throws(DomainException::class, fn () => $recipes->save(0, ['product_id' => $k['m']['honey']['id'], 'name' => '', 'output_qty' => '1', 'output_unit_id' => $k['m']['honey']['units'][0], 'lines' => [$line($k['m']['b'], '1')]], TEST_ADMIN_ID));
        assert_contains('raw material', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $recipes->save(0, ['product_id' => $k['mix']['id'], 'name' => 'x', 'output_qty' => '1', 'output_unit_id' => $k['mix']['units'][0], 'lines' => [['material_id' => $k['joy']['id'], 'qty' => '1', 'unit_id' => $k['joy']['units'][0]]]], TEST_ADMIN_ID));
        assert_contains('finished product', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $recipes->save(0, ['product_id' => $k['mix']['id'], 'name' => 'x', 'output_qty' => '1', 'output_unit_id' => $k['mix']['units'][0], 'lines' => [$line($k['m']['b'], '1'), $line($k['m']['b'], '2')]], TEST_ADMIN_ID));
        assert_contains('twice', $e->getMessage());
        $recipes->delete($id);
        assert_same(null, (new Recipe())->find($id));
    },

    'a run of 5 kg scales the recipe, takes the materials out, puts the mix in at $4.68/kg; a short material refuses the run' => function () use ($kitchen, $stockOf, $costOf): void {
        $k = $kitchen();
        $line = static fn (array $mat, string $qty): array => ['material_id' => $mat['id'], 'qty' => $qty, 'unit_id' => $mat['units'][0]];
        $recipe = (new RecipeService())->save(0, ['product_id' => $k['mix']['id'], 'name' => 'Standard', 'output_qty' => '1', 'output_unit_id' => $k['mix']['units'][0],
            'lines' => [$line($k['m']['honey'], '0.2'), $line($k['m']['colour'], '0.01'), $line($k['m']['b'], '0.3'), $line($k['m']['c'], '0.49')]], TEST_ADMIN_ID);
        $svc = new ProductionService();
        $p = $svc->preview($recipe, '5', $k['mix']['units'][0]);
        assert_same([5000, '5.0000', '23.40', '0.004680', '4.68'], [$p['qty_base'], $p['batches'], $p['material_cost'], $p['cost_per_base'], $p['cost_per_unit']]);
        assert_same([1000, 50, 1500, 2450], array_column($p['lines'], 'need_base'), 'the owner\'s example: 1 kg, 50 g, 1.5 kg, 2.45 kg');
        assert_same([], $p['short']);
        $r = $svc->run($recipe, '5', $k['mix']['units'][0], 'first batch', TEST_ADMIN_ID);
        assert_same('PRD-000001', $r['run_no']);
        assert_same(5000, $stockOf($k['mix']['id']));
        assert_same([2000, 150, 500, 2550], [$stockOf($k['m']['honey']['id']), $stockOf($k['m']['colour']['id']), $stockOf($k['m']['b']['id']), $stockOf($k['m']['c']['id'])]);
        assert_same('0.004680', $costOf($k['mix']['id']), '$4.68 per kg');
        $types = Database::pdo()->query("SELECT type, COUNT(*) AS n FROM stock_movements WHERE ref_type = 'production' GROUP BY type ORDER BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_same(['production_in' => 1, 'production_out' => 4], array_map('intval', $types));
        $run = Database::pdo()->query('SELECT run_no, qty_base, material_cost_usd, total_cost_usd, cost_per_base, notes FROM production_runs')->fetch(PDO::FETCH_ASSOC);
        assert_same(['run_no' => 'PRD-000001', 'qty_base' => 5000, 'material_cost_usd' => '23.40', 'total_cost_usd' => '23.40', 'cost_per_base' => '0.004680', 'notes' => 'first batch'], $run);
        assert_same(4, (int) Database::pdo()->query('SELECT COUNT(*) FROM production_lines')->fetchColumn());
        // honey left: 2 kg; another 15 kg of mix needs 3 kg
        $p = $svc->preview($recipe, '15', $k['mix']['units'][0]);
        assert_contains('Honey: needs 3 kg, in stock 2 kg', implode(' ', $p['short']));
        $e = assert_throws(DomainException::class, fn () => $svc->run($recipe, '15', $k['mix']['units'][0], '', TEST_ADMIN_ID));
        assert_contains('Not enough in stock', $e->getMessage());
        assert_same(1, (int) Database::pdo()->query('SELECT COUNT(*) FROM production_runs')->fetchColumn(), 'nothing written');
        // a lower yield raises the cost per kg: 4.8 kg out of the same materials
        $p = $svc->preview($recipe, '4.8', $k['mix']['units'][0]);
        assert_same('4.8000', $p['batches']);
    },

    'a recipe for a finished product is refused (products are packed from the mix); the Make screen counts in batches' => function () use ($kitchen, $stockOf): void {
        $k = $kitchen();
        $rs = new RecipeService();
        $line = static fn (array $mat, string $qty): array => ['material_id' => $mat['id'], 'qty' => $qty, 'unit_id' => $mat['units'][0]];
        $e = assert_throws(DomainException::class, fn () => $rs->save(0, ['product_id' => $k['joy']['id'], 'name' => 'Standard', 'output_qty' => '10', 'output_unit_id' => $k['joy']['units'][0],
            'lines' => [$line($k['mix'], '1'), $line($k['m']['box'], '10')]], TEST_ADMIN_ID));
        assert_contains('Packing page', $e->getMessage());
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM recipes')->fetchColumn());
        // unit 0 = one batch of the recipe: 2 batches of the 1 kg recipe make 2 kg
        $mixRecipe = $rs->save(0, ['product_id' => $k['mix']['id'], 'name' => 'Standard', 'output_qty' => '1', 'output_unit_id' => $k['mix']['units'][0],
            'lines' => [$line($k['m']['honey'], '0.2'), $line($k['m']['colour'], '0.01'), $line($k['m']['b'], '0.3'), $line($k['m']['c'], '0.49')]], TEST_ADMIN_ID);
        $svc = new ProductionService();
        $p = $svc->preview($mixRecipe, '2', 0);
        assert_same([2000, '2.0000', 'batch', '4.68'], [$p['qty_base'], $p['batches'], $p['unit_name'], $p['cost_per_unit']]);
        $r = $svc->run($mixRecipe, '2', 0, '', TEST_ADMIN_ID);
        assert_same('2 kg', $r['qty_text']);
        assert_same(2000, $stockOf($k['mix']['id']));
    },

    'the pages: recipes list and form, Make with its preview, the run, the report; a cashier may not' => function () use ($kitchen): void {
        $k = $kitchen();
        $line = static fn (array $mat, string $qty): array => ['material_id' => $mat['id'], 'qty' => $qty, 'unit_id' => $mat['units'][0]];
        $recipe = (new RecipeService())->save(0, ['product_id' => $k['mix']['id'], 'name' => 'Standard', 'output_qty' => '1', 'output_unit_id' => $k['mix']['units'][0],
            'lines' => [$line($k['m']['honey'], '0.2'), $line($k['m']['c'], '0.8')]], TEST_ADMIN_ID);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('recipes')->body;
        assert_contains('Raspberry mix', $page);
        assert_contains('$3.60', $page, 'batch cost 0.2×10 + 0.8×2');
        $page = $client->get('recipes/edit', ['id' => $recipe])->body;
        assert_contains('name="lines[', $page);
        assert_contains('Honey', $page);
        // the form refuses a recipe for a finished product, and offers only mixes
        assert_not_contains('Joy 100g', $client->get('recipes/edit')->body);
        $client->post('recipes/save', ['id' => '0', 'product_id' => (string) $k['joy']['id'], 'name' => 'Packing', 'output_qty' => '10', 'output_unit_id' => (string) $k['joy']['units'][0], 'notes' => '',
            'lines' => [['material_id' => (string) $k['mix']['id'], 'qty' => '1', 'unit_id' => (string) $k['mix']['units'][0]], ['material_id' => (string) $k['m']['box']['id'], 'qty' => '10', 'unit_id' => (string) $k['m']['box']['units'][0]]]]);
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM recipes WHERE name = 'Packing'")->fetchColumn());
        // Make: the preview and the run
        $page = $client->get('production/new', ['recipe_id' => $recipe])->body;
        assert_contains('What did you make?', $page);
        $r = $client->postJson('production/preview', ['recipe_id' => $recipe, 'qty' => '2', 'unit_id' => $k['mix']['units'][0]]);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same(['2 kg', '7.20', []], [$j['qty_text'], $j['material_cost'], $j['short']]);
        assert_same(302, $client->post('production/store', ['recipe_id' => (string) $recipe, 'qty' => '2', 'unit_id' => '0', 'notes' => ''])->status);   // 2 batches
        $page = $client->get('production')->body;
        assert_contains('PRD-000001', $page);
        assert_contains('2 kg', $page);
        $page = $client->get('production/view', ['id' => 1])->body;
        assert_contains('Taken out of stock', $page);
        assert_contains('Honey', $page);
        assert_contains('Raspberry mix', $client->get('reports', ['type' => 'production'])->body);
        assert_contains('production_in', $client->get('stock', ['type' => 'production_in'])->body);
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        assert_same(403, $cashier->get('recipes')->status);
        assert_same(403, $cashier->get('production/new')->status);
    },
];
