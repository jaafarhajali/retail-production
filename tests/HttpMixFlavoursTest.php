<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Services\ProductService;
use App\Services\RecipeService;

/**
 * 2026-10-15 (asked by Aya): one Marshmallow mix is the pot; its flavours are its recipes. The screens say so:
 * a mix is always weighed, the Mixes tab and the mix's page list its flavours, a recipe asks for the flavour,
 * and no hint from the hookah shop is left on the product form.
 */
$pot = static function (): array {
    $svc = new ProductService();
    $mk = static function (string $name, string $kind, string $base, string $unit, string $size) use ($svc): array {
        $id = $svc->create(['name' => $name, 'category_id' => '', 'base_unit' => $base, 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
            'show_on_pos_grid' => false, 'allow_price_override' => false, 'kind' => $kind]);

        return ['id' => $id, 'unit' => $svc->addUnit($id, $unit, $size, $base !== 'piece', false)];
    };
    $sugar = $mk('Sugar', 'material', 'g', 'kg', '1000');
    $mix = $mk('Marshmallow mix', 'intermediate', 'g', 'kg', '1000');
    $recipes = new RecipeService();
    foreach (['Strawberry', 'Vanilla'] as $flavour) {
        $recipes->save(0, ['product_id' => $mix['id'], 'name' => $flavour, 'output_qty' => '1', 'output_unit_id' => $mix['unit'], 'notes' => '',
            'lines' => [['material_id' => $sugar['id'], 'qty' => '0.5', 'unit_id' => $sugar['unit']]]], TEST_ADMIN_ID);
    }

    return ['mix' => $mix, 'sugar' => $sugar];
};
$plain = static fn (string $html): string => str_replace(['%2F', '&amp;'], ['/', '&'], $html);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a mix is always counted by weight' => function (): void {
        $svc = new ProductService();
        foreach (['piece', 'ml'] as $base) {
            $e = assert_throws(DomainException::class, fn () => $svc->create(['name' => 'Pot ' . $base, 'category_id' => '', 'base_unit' => $base, 'kind' => 'intermediate']));
            assert_contains('by weight', $e->getMessage());
        }
        assert_true($svc->create(['name' => 'Pot', 'category_id' => '', 'base_unit' => 'g', 'kind' => 'intermediate']) > 0);

        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $r = $client->post('products/save', ['product_id' => '0', 'then' => 'stay', 'kind' => 'intermediate', 'name' => 'Strawberry pot', 'category_id' => '', 'base_unit' => 'piece',
            'main_unit' => 'n1', 'units[n1][id]' => '0', 'units[n1][type]' => 'Piece', 'units[n1][size]' => '', 'units[n1][size_unit]' => '', 'units[n1][barcodes]' => '']);
        assert_same(302, $r->status);
        assert_contains('A mix is counted by weight', $client->get('products/create')->body, 'the refusal is shown on the form');
    },

    'the product form: a new mix is weighed without asking; the counting hints are the factory\'s, not the hookah shop\'s' => function () use ($plain): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('products/create', ['kind' => 'intermediate'])->body;
        assert_contains('a mix is always weighed', $page);
        assert_contains('value="g" checked', $page, 'a new mix starts by weight');
        assert_contains('come from Make: each batch adds its weight', $page);
        foreach (['', 'material', 'intermediate'] as $kind) {
            $form = $client->get('products/create', $kind === '' ? [] : ['kind' => $kind])->body;
            foreach (['Tins, hoses', 'Charcoal', 'tobacco', 'Stock in the shop now'] as $old) {
                assert_not_contains($old, $form, "no \"{$old}\" left on the form");
            }
        }
        assert_contains('Sugar, gelatin, honey', $client->get('products/create', ['kind' => 'material'])->body);
        assert_contains('Save the mix first, then add its flavours', $page);

        $list = $plain($client->get('products', ['kind' => 'intermediate'])->body);
        assert_contains('href="index.php?r=products/create&kind=intermediate"', $list, 'Add a mix opens the form on Mix');
        assert_contains('Add a mix', $list);
        assert_contains('href="index.php?r=products/create&kind=material"', $plain($client->get('products', ['kind' => 'material'])->body));
    },

    'the Mixes tab and the mix\'s page list its flavours; Add a flavour opens a recipe for that mix' => function () use ($pot, $plain): void {
        $k = $pot();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $list = $plain($client->get('products', ['kind' => 'intermediate'])->body);
        assert_contains('Flavours:', $list);
        assert_contains('Strawberry', $list);
        assert_contains('Vanilla', $list);
        assert_contains('href="index.php?r=recipes/edit&product_id=' . $k['mix']['id'] . '"', $list);
        assert_not_contains('Flavours:', $plain($client->get('products', ['kind' => 'material'])->body), 'only a mix has flavours');

        $page = $plain($client->get('products/edit', ['id' => $k['mix']['id']])->body);
        assert_contains('Flavours', $page);
        assert_contains('>Strawberry<', $page);
        assert_contains('>Vanilla<', $page);
        assert_contains('Add a flavour', $page);
        assert_contains('href="index.php?r=recipes/edit&product_id=' . $k['mix']['id'] . '"', $page);
        assert_not_contains('<h2 class="h5">Flavours</h2>', $client->get('products/edit', ['id' => $k['sugar']['id']])->body, 'a raw material has none');

        $recipe = $client->get('recipes/edit', ['product_id' => $k['mix']['id']])->body;
        assert_contains('>Flavour</label>', $recipe);
        assert_contains('placeholder="e.g. Strawberry"', $recipe);
        assert_not_contains('value="Standard"', $recipe, 'a new recipe does not start as "Standard"');
        assert_contains('<th>Flavour</th>', $client->get('recipes')->body);
        assert_contains('>Flavour</label>', $client->get('production/new')->body);
    },

    'with one mix the user sees flavours only: Flavours page with the stock, Make asks flavour and quantity, no Mixes tab; a second mix brings the choice back' => function () use ($pot, $plain): void {
        $k = $pot();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $products = $plain($client->get('products')->body);
        assert_not_contains('r=products&kind=intermediate', $products, 'no Mixes tab for a single mix');
        assert_contains('r=products&kind=material', $products);
        assert_contains('<span>Flavours</span>', $products, 'the menu says Flavours');

        $list = $plain($client->get('recipes')->body);
        assert_contains('>Marshmallow mix</a> in stock: <strong>0 g</strong>', $list, 'the mix name opens its page');
        assert_contains('Add a flavour', $list);
        assert_contains('500 g Sugar', $list, 'each flavour lists what goes into one batch');
        assert_not_contains('<th>Makes</th>', $list);

        $new = $plain($client->get('recipes/edit')->body);
        assert_not_contains('What does it make?', $new);
        assert_contains('name="product_id" value="' . $k['mix']['id'] . '"', $new, 'the single mix is chosen without asking');
        assert_contains('Raw materials and packaging.', $new);

        $make = $client->get('production/new')->body;
        assert_not_contains('What did you make?', $make);
        assert_contains('Marshmallow mix in stock: <strong>0 g</strong>', $make);
        assert_contains('Marshmallow mix in stock: <strong>0 g</strong>', $client->get('packing/new')->body);

        // a second mix (a chocolate coating): the questions and the tab come back
        $svc = new ProductService();
        $choc = $svc->create(['name' => 'Chocolate coating', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'kind' => 'intermediate']);
        $unit = $svc->addUnit($choc, 'kg', '1000', true, false);
        (new RecipeService())->save(0, ['product_id' => $choc, 'name' => 'Dark', 'output_qty' => '1', 'output_unit_id' => $unit, 'notes' => '',
            'lines' => [['material_id' => $k['sugar']['id'], 'qty' => '0.2', 'unit_id' => $k['sugar']['unit']]]], TEST_ADMIN_ID);
        assert_contains('r=products&kind=intermediate', $plain($client->get('products')->body));
        assert_contains('<th>Makes</th>', $client->get('recipes')->body);
        assert_contains('What does it make?', $client->get('recipes/edit')->body);
        assert_contains('What did you make?', $client->get('production/new')->body);
    },

    'once a mix exists, Add product offers finished or raw material only; a new mix is one link away on Flavours; the screens never say "pot"' => function () use ($pot, $plain): void {
        $k = $pot();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        foreach ([[], ['kind' => 'material'], ['kind' => 'finished']] as $q) {
            $form = $client->get('products/create', $q)->body;
            assert_not_contains('id="kind-intermediate"', $form, 'no Mix choice while a mix exists');
            assert_contains('id="kind-material"', $form);
        }
        assert_contains('id="kind-intermediate"', $client->get('products/edit', ['id' => $k['mix']['id']])->body, 'the mix itself still says it is a mix');
        assert_not_contains('id="kind-intermediate"', $client->get('products/edit', ['id' => $k['sugar']['id']])->body);

        $flavours = $plain($client->get('recipes')->body);
        assert_contains('href="index.php?r=products/create&kind=intermediate"', $flavours);
        assert_contains('Add a new mix', $flavours);
        assert_contains('id="kind-intermediate"', $client->get('products/create', ['kind' => 'intermediate'])->body, 'the link opens the form on Mix');

        foreach ([['products', ['kind' => 'intermediate']], ['products/create', ['kind' => 'intermediate']], ['recipes', []], ['production/new', []], ['packing/new', []]] as [$route, $q]) {
            assert_same(0, preg_match('/\bpot\b/i', $client->get($route, $q)->body), "no \"pot\" on {$route}");
        }
        $e = assert_throws(DomainException::class, fn () => (new ProductService())->create(['name' => 'X', 'category_id' => '', 'base_unit' => 'piece', 'kind' => 'intermediate']));
        assert_same(0, preg_match('/\bpot\b/i', $e->getMessage()));
    },

    'a mix\'s form asks name, category and the warning only: its cost and its stock come from Make' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('products/create', ['kind' => 'intermediate'])->body;
        assert_contains('data-cost-new data-kind-only="finished material" hidden', $page, 'no cost to type for a mix');
        assert_contains('data-opening data-kind-only="finished material" hidden', $page, 'no opening stock for a mix');
        assert_contains('Warn me when stock goes below', $page);
        // never on the till: no photo, no barcodes, no target profit; the code is for lists and reports
        assert_contains('data-photo-card data-kind-only="finished material" hidden', $page);
        assert_contains('<th data-kind-only="finished material" hidden>Barcodes</th>', $page);
        assert_contains('data-target-card data-kind-only="finished" hidden', $page);
        assert_contains('Its code in lists and reports.', $page);
        $material = $client->get('products/create', ['kind' => 'material'])->body;
        assert_contains('data-cost-new data-kind-only="finished material">', $material, 'a raw material still takes its cost');
        assert_contains('data-opening data-kind-only="finished material">', $material);
        assert_contains('data-photo-card data-kind-only="finished material">', $material);
        assert_contains('<th data-kind-only="finished material">Barcodes</th>', $material);
        assert_contains('data-target-card data-kind-only="finished" hidden', $material, 'a raw material has no selling price either');
        assert_contains('data-target-card data-kind-only="finished">', $client->get('products/create')->body);

        // even if a cost and a stock are posted, a mix starts empty: only Make fills it, at the cost of what it used
        $r = $client->post('products/save', ['product_id' => '0', 'then' => 'stay', 'kind' => 'intermediate', 'name' => 'Marshmallow mix', 'category_id' => '', 'base_unit' => 'g',
            'main_unit' => 'n1', 'units[n1][id]' => '0', 'units[n1][type]' => 'kg', 'units[n1][size]' => '', 'units[n1][size_unit]' => '', 'units[n1][barcodes]' => '',
            'cost' => '5', 'cost_unit' => 'n1', 'opening_qty' => '2', 'opening_unit' => 'n1', 'min_qty' => '2', 'min_unit' => 'n1']);
        assert_same(302, $r->status);
        $mix = \App\Core\Database::pdo()->query("SELECT * FROM products WHERE name = 'Marshmallow mix'")->fetch();
        assert_same([0, '0.000000', 2000], [(int) $mix['stock_base'], $mix['cost_per_base'], (int) $mix['min_stock_base']], 'no stock, no cost; the warning is kept');
    },

    'packaging per piece: grouped by the material\'s category, packaging groups first; never the mix' => function () use ($pot): void {
        $k = $pot();
        $svc = new ProductService();
        $cats = new \App\Models\Category();
        $ingredients = $cats->create('Ingredients', '#e0a800', 2);
        $packaging = $cats->create('Packaging', '#2a9d8f', 3);   // after Ingredients in the owner's order, first in the list all the same
        $mk = static function (string $name, string $base, int $category) use ($svc): array {
            $id = $svc->create(['name' => $name, 'category_id' => (string) $category, 'base_unit' => $base, 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'kind' => 'material']);

            return ['id' => $id, 'unit' => $svc->addUnit($id, $base === 'piece' ? 'Piece' : 'kg', $base === 'piece' ? '1' : '1000', $base !== 'piece', false)];
        };
        $box = $mk('Jar 100g', 'piece', $packaging);
        $mk('stickers', 'g', $packaging);   // packaging bought by weight: still under Packaging, because that is its category
        $mk('Cocoa powder', 'g', $ingredients);
        $jar = $svc->create(['name' => 'Mixed Jar 100g', 'category_id' => '', 'base_unit' => 'piece', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'kind' => 'finished']);
        $svc->addUnit($jar, 'Piece', '1', false, false);

        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('products/edit', ['id' => $jar])->body;
        $at = static fn (string $s): int => (int) strpos($page, $s);
        assert_contains('<optgroup label="Packaging">', $page);
        assert_contains('<optgroup label="Ingredients">', $page);
        assert_contains('<optgroup label="No category">', $page, 'Sugar has no category');
        assert_true($at('<optgroup label="Packaging">') < $at('<optgroup label="Ingredients">') && $at('<optgroup label="Ingredients">') < $at('<optgroup label="No category">'), 'packaging first, no category last');
        assert_true($at('>Jar 100g</option>') < $at('<optgroup label="Ingredients">') && $at('>stickers</option>') < $at('<optgroup label="Ingredients">'), 'the jar and the stickers are under Packaging');
        assert_true($at('<optgroup label="Ingredients">') < $at('>Cocoa powder</option>') && $at('>Cocoa powder</option>') < $at('<optgroup label="No category">'), 'the cocoa is under Ingredients');
        assert_true($at('<optgroup label="No category">') < $at('>Sugar</option>'), 'the sugar is under No category');
        // the purchase form groups its products the same way (2026-10-17)
        $buy = $client->get('purchases/create')->body;
        $at2 = static fn (string $s): int => (int) strpos($buy, $s);
        assert_true($at2('<optgroup label="Packaging">') > 0 && $at2('<optgroup label="Packaging">') < $at2('<optgroup label="Ingredients">') && $at2('<optgroup label="Ingredients">') < $at2('<optgroup label="No category">'), 'purchases: packaging, ingredients, no category');
        assert_true($at2('>Jar 100g (P-') < $at2('<optgroup label="Ingredients">') && $at2('<optgroup label="Ingredients">') < $at2('>Cocoa powder (P-'), 'each under its group');
        assert_not_contains('Marshmallow mix</option>', $page, 'the mix is not offered as packaging');
        assert_not_contains('(mix)', $page);

        $e = assert_throws(DomainException::class, fn () => $svc->savePackaging($jar, [['material_id' => $k['mix']['id'], 'qty' => '100', 'unit_id' => $k['mix']['unit']]]));
        assert_contains('typed on the Pack page', $e->getMessage());
        $svc->savePackaging($jar, [['material_id' => $box['id'], 'qty' => '1', 'unit_id' => $box['unit']], ['material_id' => $k['sugar']['id'], 'qty' => '5', 'unit_id' => $k['sugar']['unit']]]);
        assert_same(2, count((new \App\Models\Product())->packaging($jar)), 'a jar and 5 g of sugar per piece');
    },

    'a flavour needs a name: an empty one is refused, never saved as "Standard"' => function () use ($pot): void {
        $k = $pot();
        $line = [['material_id' => $k['sugar']['id'], 'qty' => '0.5', 'unit_id' => $k['sugar']['unit']]];
        $e = assert_throws(DomainException::class, fn () => (new RecipeService())->save(0, ['product_id' => $k['mix']['id'], 'name' => '  ', 'output_qty' => '1', 'output_unit_id' => $k['mix']['unit'], 'notes' => '', 'lines' => $line], TEST_ADMIN_ID));
        assert_contains('Type the flavour', $e->getMessage());

        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('placeholder="e.g. Strawberry" required', $client->get('recipes/edit')->body);
        $client->post('recipes/save', ['id' => '0', 'product_id' => (string) $k['mix']['id'], 'name' => '', 'output_qty' => '1', 'output_unit_id' => (string) $k['mix']['unit'], 'notes' => '',
            'lines' => [['material_id' => (string) $k['sugar']['id'], 'qty' => '0.5', 'unit_id' => (string) $k['sugar']['unit']]]]);
        assert_contains('Type the flavour', $client->get('recipes/edit', ['product_id' => $k['mix']['id']])->body, 'the form says why');
        assert_same(2, (int) \App\Core\Database::pdo()->query('SELECT COUNT(*) FROM recipes')->fetchColumn(), 'only Strawberry and Vanilla');
    },
];
