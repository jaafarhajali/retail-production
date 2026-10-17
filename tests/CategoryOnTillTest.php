<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Category;
use App\Models\Register;
use App\Services\CategoryService;
use App\Services\ProductService;

/**
 * 2026-10-18 (Aya): a category says whether it is sold on the till. Ingredients and Packaging are bought and packed,
 * never sold, so the till does not show them as tabs. The switch is on the category's page; the till also skips a
 * category marked for it that holds nothing it can sell.
 */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the switch: on by default, off on request, kept on update; the till lists only categories marked for it that hold a sellable product' => function (): void {
        $svc = new CategoryService();
        $marsh = $svc->create('Marshmallow', '#c084a8', '1');
        $ingredients = $svc->create('Ingredients', '#d8a200', '2', false);
        $empty = $svc->create('Jars', '#3fa39a', '3');   // marked for the till, nothing in it yet
        $rows = Database::pdo()->query('SELECT name, on_till FROM categories ORDER BY sort_order')->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_same(['Marshmallow' => 1, 'Ingredients' => 0, 'Jars' => 1], array_map('intval', $rows));

        $products = new ProductService();
        $joy = $products->create(['name' => 'Joy 100g', 'category_id' => (string) $marsh, 'base_unit' => 'piece', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'show_on_pos_grid' => true, 'kind' => 'finished']);
        $products->setPrices($products->addUnit($joy, 'Piece', '1', false, false), '3', '2.5');
        $sugar = $products->create(['name' => 'Sugar', 'category_id' => (string) $ingredients, 'base_unit' => 'g', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'kind' => 'material']);
        $products->addUnit($sugar, 'kg', '1000', true, false);
        assert_same(['Marshmallow'], array_column((new Category())->forTill(), 'name'), 'Ingredients is off; Jars has nothing to sell');

        // Jars gets a product: it appears; switched off on its page: it goes
        $jar = $products->create(['name' => 'Mixed Jar 100g', 'category_id' => (string) $empty, 'base_unit' => 'piece', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'show_on_pos_grid' => true, 'kind' => 'finished']);
        $products->setPrices($products->addUnit($jar, 'Piece', '1', false, false), '3.5', '3');
        assert_same(['Marshmallow', 'Jars'], array_column((new Category())->forTill(), 'name'));
        $svc->update($empty, 'Jars', '#3fa39a', '3', true, false);
        assert_same(['Marshmallow'], array_column((new Category())->forTill(), 'name'));
        $svc->update($empty, 'Jars', '#3fa39a', '3', true, true);
        assert_same(1, (int) Database::pdo()->query("SELECT on_till FROM categories WHERE id = {$empty}")->fetchColumn());

        // the till's data follows
        $register = (new Register())->create('Register 01');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $register]);
        $data = json_decode($client->get('pos/data')->body, true);
        assert_same(['Marshmallow', 'Jars'], array_column($data['categories'], 'name'));

        // the pages: the column on the list, the switch on the form and on the edit page; the form posts it
        $list = $client->get('categories')->body;
        assert_contains('<th>On the till</th>', $list);
        assert_same(2, substr_count($list, '>sold</span>'));
        assert_same(1, substr_count($list, '>not sold</span>'));
        assert_contains('id="on_till" name="on_till" value="1" checked', $list, 'on by default for a new category');
        $client->post('categories/store', ['name' => 'Gift boxes', 'color' => '#aa0000', 'sort_order' => '4']);   // no on_till posted: the switch was off
        assert_same(0, (int) Database::pdo()->query("SELECT on_till FROM categories WHERE name = 'Gift boxes'")->fetchColumn());
        $client->post('categories/store', ['name' => 'Bags', 'color' => '#aa0000', 'sort_order' => '5', 'on_till' => '1']);
        assert_same(1, (int) Database::pdo()->query("SELECT on_till FROM categories WHERE name = 'Bags'")->fetchColumn());
        $edit = $client->get('categories/edit', ['id' => $ingredients])->body;
        assert_contains('for="on_till">Sold on the till', $edit);
        assert_not_contains('id="on_till" name="on_till" value="1" checked', $edit, 'Ingredients shows off');
        $client->post('categories/update', ['id' => (string) $ingredients, 'name' => 'Ingredients', 'color' => '#d8a200', 'sort_order' => '2', 'is_active' => '1', 'on_till' => '1']);
        assert_same(1, (int) Database::pdo()->query("SELECT on_till FROM categories WHERE id = {$ingredients}")->fetchColumn());
        assert_same(['Marshmallow', 'Jars'], array_column((new Category())->forTill(), 'name'), 'on, but nothing to sell in it: still not a tab');
    },
];
