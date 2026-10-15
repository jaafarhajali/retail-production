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
        assert_contains('Later, stock comes from Production.', $page);
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
        assert_not_contains('Flavours', $client->get('products/edit', ['id' => $k['sugar']['id']])->body, 'a raw material has none');

        $recipe = $client->get('recipes/edit', ['product_id' => $k['mix']['id']])->body;
        assert_contains('>Flavour</label>', $recipe);
        assert_contains('placeholder="e.g. Strawberry"', $recipe);
        assert_not_contains('value="Standard"', $recipe, 'a new recipe does not start as "Standard"');
        assert_contains('<th>Flavour</th>', $client->get('recipes')->body);
        assert_contains('>Flavour</label>', $client->get('production/new')->body);
    },
];
