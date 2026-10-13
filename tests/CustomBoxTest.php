<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Register;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/** Phase F6 (2026-10-13): the Customized Box is assembled on the spot and priced at the sale; a line carries a note that prints. */
$setup = static function (): array {
    $svc = new ProductService();
    $box = $svc->create(['name' => 'Customized Box', 'category_id' => '', 'base_unit' => 'piece', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
        'show_on_pos_grid' => true, 'allow_price_override' => true, 'kind' => 'finished', 'track_stock' => false]);
    $boxU = $svc->addUnit($box, 'Piece', '1', false, true);
    $svc->setPrices($boxU, '10', '8');
    $joy = make_product('Joy 100g', 'piece');
    $joyU = $svc->addUnit($joy, 'Piece', '1', false, true);
    $svc->setPrices($joyU, '3', '2.50');
    (new StockService())->adjust($joy, $joyU, '50', 'opening', '', null, TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');

    return ['box' => $box, 'boxU' => $boxU, 'joy' => $joy, 'joyU' => $joyU, 'register' => $register, 'session' => $session];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'selling a Customized Box at a typed price with a note moves no stock of its own; the Joy on the same sale does' => function () use ($setup): void {
        $s = $setup();
        $r = (new SaleService())->complete(['register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $s['box'], 'unit_id' => $s['boxU'], 'qty' => '1', 'price' => '25', 'note' => '1 kg, raspberry and pistachio, gold ribbon'], ['product_id' => $s['joy'], 'unit_id' => $s['joyU'], 'qty' => '2']],
            'invoice_discount' => '', 'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '31']], 'change_currency' => 'USD', 'notes' => '', 'pin' => '']);
        assert_same('31.00', $r['total_usd']);
        assert_same(0, (int) (new Product())->find($s['box'])['stock_base'], 'the box has no stock of its own');
        assert_same(48, (int) (new Product())->find($s['joy'])['stock_base']);
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'sale'")->fetchColumn(), 'only the Joy moved');
        $items = Database::pdo()->query('SELECT product_name, note, unit_price_usd FROM sale_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        assert_same([['product_name' => 'Customized Box', 'note' => '1 kg, raspberry and pistachio, gold ribbon', 'unit_price_usd' => '25.00'], ['product_name' => 'Joy 100g', 'note' => null, 'unit_price_usd' => '3.00']], $items);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('raspberry and pistachio', $client->get('sales/receipt', ['id' => $r['id']])->body);
        assert_contains('raspberry and pistachio', $client->get('sales/print', ['id' => $r['id'], 'type' => 'normal'])->body);
        assert_contains('raspberry and pistachio', $client->get('sales/print', ['id' => $r['id'], 'type' => 'official'])->body);
    },

    'a held invoice keeps the note, and a raw material is always counted whatever the form says' => function () use ($setup): void {
        $s = $setup();
        $customer = (new Customer())->create(['name' => 'Hawraa Issa', 'phone' => '76 357 563', 'notes' => null, 'default_price_level' => 'retail', 'credit_limit_usd' => null]);
        $r = (new SaleService())->hold(['register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'retail',
            'lines' => [['product_id' => $s['box'], 'unit_id' => $s['boxU'], 'qty' => '2', 'price' => '20', 'note' => 'birthday, pink']], 'invoice_discount' => '', 'notes' => '', 'pin' => '']);
        assert_same('birthday, pink', Database::pdo()->query('SELECT note FROM sale_items')->fetchColumn());
        $item = (int) Database::pdo()->query('SELECT id FROM sale_items')->fetchColumn();
        (new SaleService())->settle($r['id'], [$item => '1'], [['method' => 'cash', 'currency' => 'USD', 'amount' => '20']], 'USD', false, '', TEST_ADMIN_ID, $s['session'], $s['register']);
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'sale'")->fetchColumn(), 'the box moves no stock even when finished');
        $honey = (new ProductService())->create(['name' => 'Honey', 'category_id' => '', 'base_unit' => 'g', 'kind' => 'material', 'track_stock' => false]);
        assert_same(1, (int) (new Product())->find($honey)['track_stock']);
    },

    'the product page has the switch and the till line dialog has the note box' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('products/edit', ['id' => $s['box']])->body;
        assert_contains('id="no_stock" name="no_stock" value="1" checked', $page);
        $page = $client->get('products/edit', ['id' => $s['joy']])->body;
        assert_contains('id="no_stock" name="no_stock" value="1"  ', $page);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        assert_contains('id="line-note"', $client->get('pos')->body);
    },
];
