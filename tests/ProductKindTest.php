<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;
use App\Models\Register;
use App\Services\CashService;
use App\Services\CountService;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * Phase F1 (2026-10-12): a product is finished (sold), a mix (made, used in finished products) or a raw material
 * (bought, consumed). Every screen shows the right kind; purchases take materials, the till sells finished products.
 */
$make = static function (string $name, string $kind, string $base = 'g', ?string $retail = '9'): array {
    $svc = new ProductService();
    $id = $svc->create(['name' => $name, 'category_id' => '', 'base_unit' => $base, 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
        'show_on_pos_grid' => true, 'allow_price_override' => false, 'kind' => $kind]);
    $unit = $svc->addUnit($id, $base === 'piece' ? 'Piece' : 'kg', $base === 'piece' ? '1' : '1000', $base !== 'piece', false);
    if ($retail !== null) {
        $svc->setPrices($unit, $retail, '');
    }
    $svc->setCost($id, '2', $base === 'piece' ? 1 : 1000);

    return ['id' => $id, 'unit' => $unit];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a product has a kind; finished is the default; a material or a mix never carries selling prices or a till button' => function () use ($make): void {
        $joy = $make('Joy 100g', 'finished', 'piece');
        $honey = $make('Honey', 'material');
        $mix = $make('Marshmallow mix', 'intermediate');
        $rows = Database::pdo()->query('SELECT name, kind, show_on_pos_grid FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        assert_same([['name' => 'Joy 100g', 'kind' => 'finished', 'show_on_pos_grid' => 1], ['name' => 'Honey', 'kind' => 'material', 'show_on_pos_grid' => 0], ['name' => 'Marshmallow mix', 'kind' => 'intermediate', 'show_on_pos_grid' => 0]], $rows);
        assert_same(null, Database::pdo()->query("SELECT retail_price FROM product_units WHERE id = {$honey['unit']}")->fetchColumn(), 'a material keeps no price');
        assert_same('9.00', Database::pdo()->query("SELECT retail_price FROM product_units WHERE id = {$joy['unit']}")->fetchColumn());
        $e = assert_throws(DomainException::class, fn () => (new ProductService())->create(['name' => 'X', 'category_id' => '', 'base_unit' => 'g', 'kind' => 'service']));
        assert_contains('what it is', $e->getMessage());
        assert_same(['finished' => 1, 'intermediate' => 1, 'material' => 1], (new Product())->kindCounts());
        assert_same(['Joy 100g'], array_column((new Product())->forPrint('finished'), 'name'));
        assert_same(['Honey'], array_column((new Product())->forPrint('material'), 'name'));
    },

    'the till sells finished products only; a purchase takes materials only; a return without invoice lists finished only' => function () use ($make): void {
        $joy = $make('Joy 100g', 'finished', 'piece');
        (new StockService())->adjust($joy['id'], $joy['unit'], '5', 'opening', '', null, TEST_ADMIN_ID);   // a sale beyond the stock needs a PIN (2026-10-20)
        $honey = $make('Honey', 'material');
        (new StockService())->adjust($honey['id'], $honey['unit'], '5', 'opening', '', null, TEST_ADMIN_ID);
        $register = (new Register())->create('Register 01');
        $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
        $sale = static fn (int $p, int $u): array => (new SaleService())->complete(['register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null,
            'price_level' => 'retail', 'lines' => [['product_id' => $p, 'unit_id' => $u, 'qty' => '1']], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '9']], 'change_currency' => 'USD', 'notes' => '', 'pin' => '']);
        $e = assert_throws(DomainException::class, fn () => $sale($honey['id'], $honey['unit']));
        assert_contains('raw material', $e->getMessage());
        $sale($joy['id'], $joy['unit']);
        $buy = static fn (int $p, int $u): int => (new PurchaseService())->create(null, '', date('Y-m-d'), [['product_id' => $p, 'unit_id' => $u, 'qty' => '2', 'unit_cost' => '3']], '', 'outside', '', TEST_ADMIN_ID);
        $e = assert_throws(DomainException::class, fn () => $buy($joy['id'], $joy['unit']));
        assert_contains('not bought', $e->getMessage());
        $buy($honey['id'], $honey['unit']);
        assert_same(7000, (int) (new Product())->find($honey['id'])['stock_base'], '5 kg + 2 kg of honey');
        $e = assert_throws(DomainException::class, fn () => (new ReturnService())->createWithoutSale([['product_id' => $honey['id'], 'unit_id' => $honey['unit'], 'qty' => '1', 'price' => '', 'condition' => 'restock']], null, '', $session, $register, TEST_ADMIN_ID));
        assert_contains('raw material', $e->getMessage());
        // the till catalogue and the purchase form each see their own kind
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $register]);
        $catalog = json_decode($client->get('pos/data')->body, true);
        assert_same(['Joy 100g'], array_column($catalog['products'], 'name'));
        $page = $client->get('purchases/create')->body;
        assert_contains('Honey', $page);
        assert_not_contains('Joy 100g', $page);
    },

    'the product page has the kind choice; the lists have Finished / Mixes / Raw materials tabs; the dashboard splits low stock' => function () use ($make): void {
        $joy = $make('Joy 100g', 'finished', 'piece');
        $honey = $make('Honey', 'material');
        (new ProductService())->setMinStock($honey['id'], '10', $honey['unit']);   // 0 in stock: low
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('products/create')->body;
        assert_contains('id="kind-finished" value="finished"', $page);
        assert_contains('id="kind-material" value="material"', $page);
        $form = ['product_id' => '0', 'then' => 'stay', 'name' => 'Sugar', 'kind' => 'material', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '',
            'target_margin_pct' => '', 'show_on_pos_grid' => '1', 'main_unit' => 'n1',
            'units[n1][id]' => '0', 'units[n1][type]' => 'Bag', 'units[n1][size]' => '25', 'units[n1][size_unit]' => 'kg', 'units[n1][retail]' => '', 'units[n1][wholesale]' => '', 'units[n1][barcodes]' => '',
            'cost' => '20', 'cost_unit' => 'n1', 'min_qty' => '', 'min_unit' => 'n1', 'opening_qty' => '2', 'opening_unit' => 'n1'];
        assert_same(302, $client->post('products/save', $form)->status);
        $sugar = Database::pdo()->query("SELECT kind, show_on_pos_grid, stock_base FROM products WHERE name = 'Sugar'")->fetch(PDO::FETCH_ASSOC);
        assert_same(['kind' => 'material', 'show_on_pos_grid' => 0, 'stock_base' => 50000], $sugar);
        $list = $client->get('products')->body;
        assert_contains('Joy 100g', $list);
        assert_not_contains('Honey', $list, 'the default tab is Finished');
        $materials = $client->get('products', ['kind' => 'material'])->body;
        assert_contains('Honey', $materials);
        assert_contains('Sugar', $materials);
        assert_not_contains('Joy 100g', $materials);
        assert_contains('Raw materials', $list);
        // the dashboard shows the break-even only (2026-10-20); the low products are found with the Products list's stock filter
        $low = $client->get('products', ['kind' => 'material', 'stock' => 'low'])->body;
        assert_contains('Honey', $low);
        assert_not_contains('<td dir="auto">Sugar</td>', $low);
    },

    'stocktaking counts one kind' => function () use ($make): void {
        $make('Joy 100g', 'finished', 'piece');
        $honey = $make('Honey', 'material');
        $id = (new CountService())->start('material', 0, '', TEST_ADMIN_ID);
        $lines = Database::pdo()->query("SELECT product_id FROM stock_count_lines WHERE count_id = {$id}")->fetchAll(PDO::FETCH_COLUMN);
        assert_same([$honey['id']], array_map('intval', $lines));
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('Raw materials', $client->get('counts')->body);
    },
];
