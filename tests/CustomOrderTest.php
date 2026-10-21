<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Services\CashService;
use App\Services\CustomOrderService;
use App\Services\ProductService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * The custom order (owner, 2026-10-21): "neither the weight of the marshmallow nor the box is standard; I enter the cost
 * for each order", and the marshmallow comes out of the mix. One built-in product, never listed (migration 025), a
 * button on the till and a line on the invoice form: the price, the grams of marshmallow, the packaging taken from the
 * stock and the other costs are typed at each sale. The line costs mix + packaging + extras; the grams and the
 * packaging leave the stock; the box has no stock of its own and a return puts nothing back.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $stock = new StockService();
    // the mix: 5 kg at $4.00 per kg ($0.004 a gram); a gift box at $0.50, a label at $0.02
    $mix = $svc->create(['name' => 'Marshmallow mix', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'kind' => 'intermediate']);
    $mixKg = $svc->addUnit($mix, 'kg', '1000', true, true);
    $stock->adjust($mix, $mixKg, '5', 'opening', '', null, TEST_ADMIN_ID);
    $svc->setCost($mix, '4', 1000);
    $gift = make_product('Gift box', 'piece', null, 'material');
    $giftU = $svc->addUnit($gift, 'Piece', '1', false, true);
    $stock->adjust($gift, $giftU, '10', 'opening', '', null, TEST_ADMIN_ID);
    $svc->setCost($gift, '0.50', 1);
    $label = make_product('Label', 'piece', null, 'material');
    $labelU = $svc->addUnit($label, 'Piece', '1', false, true);
    $stock->adjust($label, $labelU, '100', 'opening', '', null, TEST_ADMIN_ID);
    $svc->setCost($label, '0.02', 1);
    $joy = make_product('Joy 100g', 'piece');
    $svc->setPrices($svc->addUnit($joy, 'Piece', '1', false, true), '3', '2.5');   // priced, so the invoice form renders its lines
    $custom = (new Product())->customOrder();
    $unit = (new ProductUnit())->forProduct((int) $custom['id'])[0];
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');

    return ['mix' => $mix, 'gift' => $gift, 'label' => $label, 'joy' => $joy, 'custom' => (int) $custom['id'], 'unit' => (int) $unit['id'], 'register' => $register, 'session' => $session];
};
$stockOf = static fn (int $id): int => (int) (new Product())->find($id)['stock_base'];
$line = static fn (array $s, array $extra = []): array => $extra + ['product_id' => $s['custom'], 'unit_id' => $s['unit'], 'qty' => '1', 'price' => '25', 'fill' => '650',
    'pack' => [['material_id' => $s['gift'], 'qty' => '1'], ['material_id' => $s['label'], 'qty' => '2']], 'cost' => '2', 'note' => 'gift box, 4 flavours'];
$sell = static fn (array $s, array $lines, string $amount): array => (new SaleService())->complete(['register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID,
    'customer_id' => null, 'price_level' => 'retail', 'lines' => $lines, 'invoice_discount' => '', 'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => $amount]],
    'change_currency' => 'USD', 'notes' => '', 'pin' => '']);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the built-in product is there after the migrations, with its unit and the mix, and is never listed' => function () use ($setup): void {
        $s = $setup();
        $custom = (new Product())->find($s['custom']);
        assert_same(['Custom order', 'CUSTOM', 'finished', 'piece', 0, 1, 1], [$custom['name'], $custom['internal_code'], $custom['kind'], $custom['base_unit'], (int) $custom['track_stock'], (int) $custom['allow_price_override'], (int) $custom['is_custom_order']]);
        $p = CustomOrderService::payload();
        assert_same([$s['custom'], $s['unit'], 'Marshmallow mix', 5000], [$p['id'], $p['unit'], $p['mix']['name'], $p['mix']['stock']], 'the mix made after the migration is found by itself');
        assert_same(['Gift box', 'Label'], array_column($p['materials'], 'name'));
        $products = new Product();
        assert_not_contains('Custom order', implode(',', array_column($products->forPrint(), 'name')));
        assert_same(['finished' => 1, 'intermediate' => 1, 'material' => 2], $products->kindCounts());
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_not_contains('Custom order', $client->get('products')->body);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $data = json_decode($client->get('pos/data')->body, true);
        assert_not_contains('Custom order', implode(',', array_column($data['products'], 'name')));
        assert_same($s['custom'], $data['custom']['id']);
    },

    'a sale: the price, the grams, the packaging and the extras typed; the line costs them all, the mix and the packaging leave the stock' => function () use ($setup, $stockOf, $line, $sell): void {
        $s = $setup();
        $r = $sell($s, [$line($s)], '25');
        assert_same(['25.00', []], [$r['total_usd'], $r['warnings']]);
        $item = Database::pdo()->query('SELECT id, product_name, unit_price_usd, line_discount_usd, cost_per_base, line_cost_usd, fill_mix_id, fill_base, note FROM sale_items WHERE sale_id = ' . $r['id'])->fetch(PDO::FETCH_ASSOC);
        // 650 g × $0.004 = $2.60, a gift box $0.50, two labels $0.04, extras $2.00 → $5.14
        assert_same(['Custom order', '25.00', '0.00', '5.140000', '5.14', $s['mix'], 650, 'gift box, 4 flavours'],
            [$item['product_name'], $item['unit_price_usd'], $item['line_discount_usd'], $item['cost_per_base'], $item['line_cost_usd'], (int) $item['fill_mix_id'], (int) $item['fill_base'], $item['note']]);
        assert_same([4350, 9, 98, 0], [$stockOf($s['mix']), $stockOf($s['gift']), $stockOf($s['label']), $stockOf($s['custom'])], '650 g, a gift box and two labels out; the order has no stock');
        $moves = Database::pdo()->query("SELECT product_id, base_qty FROM stock_movements WHERE type = 'sale' ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        assert_same([[$s['mix'], -650], [$s['gift'], -1], [$s['label'], -2]], array_map(static fn (array $m): array => [(int) $m[0], (int) $m[1]], $moves));
        assert_same([[$s['gift'], 1, '0.500000'], [$s['label'], 2, '0.020000']], array_map(static fn (array $k): array => [(int) $k[0], (int) $k[1], $k[2]],
            Database::pdo()->query('SELECT material_id, qty_base, cost_per_base FROM sale_item_packaging WHERE sale_item_id = ' . $item['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM)), 'what it took, per piece, kept with the line');
        // two of the same, no packaging, no extras: the cost is per piece
        $r2 = $sell($s, [$line($s, ['qty' => '2', 'price' => '20', 'fill' => '300', 'pack' => [], 'cost' => ''])], '40');
        assert_same(['40.00', '2.40'], [$r2['total_usd'], Database::pdo()->query('SELECT line_cost_usd FROM sale_items WHERE sale_id = ' . $r2['id'])->fetchColumn()], '2 × 300 g × $0.004');
        assert_same(3750, $stockOf($s['mix']));
        // what must be typed
        $e = assert_throws(DomainException::class, fn () => $sell($s, [$line($s, ['price' => ''])], '25'));
        assert_contains('Type the price of the custom order', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $sell($s, [$line($s, ['fill' => ''])], '25'));
        assert_contains('grams of marshmallow', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $sell($s, [$line($s, ['pack' => [['material_id' => $s['joy'], 'qty' => '1']]])], '25'));
        assert_contains('Choose the packaging', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $sell($s, [$line($s, ['pack' => [['material_id' => $s['gift'], 'qty' => '0']]])], '25'));
        assert_contains('quantity of Gift box', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $sell($s, [$line($s, ['cost' => 'abc'])], '25'));
        assert_contains('Other costs', $e->getMessage());
        assert_same(3750, $stockOf($s['mix']), 'nothing moved on the refused ones');
    },

    'held and finished with one of two back; a desk invoice with the mix short is warned about; a return puts nothing back' => function () use ($setup, $stockOf, $line): void {
        $s = $setup();
        $customer = (new Customer())->create(['name' => 'Hawraa Issa', 'phone' => '', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
        $h = (new SaleService())->hold(['register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale',
            'lines' => [$line($s, ['qty' => '2', 'price' => '20', 'fill' => '300', 'pack' => [['material_id' => $s['gift'], 'qty' => '1']], 'cost' => ''])], 'invoice_discount' => '', 'notes' => '', 'pin' => '']);
        assert_same([5000, 10], [$stockOf($s['mix']), $stockOf($s['gift'])], 'nothing leaves while it is out with the seller');
        $item = (int) Database::pdo()->query('SELECT id FROM sale_items WHERE sale_id = ' . $h['id'])->fetchColumn();
        (new SaleService())->settle($h['id'], [$item => '1'], [['method' => 'cash', 'currency' => 'USD', 'amount' => '20']], 'USD', false, '', TEST_ADMIN_ID, $s['session'], $s['register']);
        assert_same([4700, 9], [$stockOf($s['mix']), $stockOf($s['gift'])], 'one kept: 300 g and one gift box out');
        assert_same('1.70', Database::pdo()->query('SELECT line_cost_usd FROM sale_items WHERE id = ' . $item)->fetchColumn(), '300 g × $0.004 + $0.50');
        // a desk invoice: 6 kg asked for, 4.7 kg in stock → warned, not refused
        $r = (new SaleService())->issue(['user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale',
            'lines' => [$line($s, ['price' => '60', 'fill' => '6000', 'pack' => [['material_id' => $s['gift'], 'qty' => '20']], 'cost' => '5'])], 'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '']);
        assert_same(['60.00', ['Marshmallow mix is now below zero stock.', 'Gift box is now below zero stock.']], [$r['total_usd'], $r['warnings']]);
        assert_same([-1300, -11], [$stockOf($s['mix']), $stockOf($s['gift'])]);
        // a credit note on it: money back, no stock movement
        $item2 = (int) Database::pdo()->query('SELECT id FROM sale_items WHERE sale_id = ' . $r['id'])->fetchColumn();
        $before = (int) Database::pdo()->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
        $cn = (new ReturnService())->creditNote($r['id'], [['sale_item_id' => $item2, 'qty' => '1', 'condition' => 'restock']], 'not wanted', TEST_ADMIN_ID);
        assert_same('60.00', $cn['total_usd']);
        assert_same($before, (int) Database::pdo()->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn(), 'nothing goes back into the mix or the packaging');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $print = $client->get('sales/print', ['id' => $r['id']])->body;
        assert_contains('Custom order', $print);
        assert_contains('gift box, 4 flavours', $print);
        assert_not_contains('5.14', $print, 'the cost is never printed');
    },

    'the screens: the till has the Custom order button and dialog, the invoice form the custom line, the product page no switch; the till posts one' => function () use ($setup, $stockOf): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_not_contains('name="fill_on"', $client->get('products/create')->body);
        $customer = (new Customer())->create(['name' => 'Spinneys', 'phone' => '', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
        $form = $client->get('invoices/new')->body;
        assert_contains('id="add-custom"', $form);
        assert_contains('id="custom-tpl"', $form);
        assert_contains('"materials":[{"id":' . $s['gift'], $form);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $pos = $client->get('pos')->body;
        assert_contains('id="m-custom"', $pos);
        assert_contains('id="custom-pack-add"', $pos);
        // the till sends the packaging as a list (a broken edit once sent it as text: 2026-10-21, INV-000003 took no box and no label)
        assert_contains("fill: l.fill ? String(l.fill) : '', pack: (l.pack || []).map(function (k) { return { material_id: k.m, qty: String(k.qty) }; }) };", (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/pos.js'));
        $r = $client->postJson('pos/complete', ['price_level' => 'retail', 'lines' => [['product_id' => $s['custom'], 'unit_id' => $s['unit'], 'qty' => '1', 'price' => '25', 'fill' => '650', 'cost' => '2', 'note' => 'birthday',
            'pack' => [['material_id' => $s['gift'], 'qty' => '1']]]], 'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '25']], 'change_currency' => 'USD']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same('INV-000001', $j['invoice_no']);
        assert_same([4350, 9], [$stockOf($s['mix']), $stockOf($s['gift'])]);
        assert_contains('birthday', $client->get('sales/receipt', ['id' => $j['id']])->body);
        // the form, posted with a custom line
        $client->get('invoices/new');
        $post = $client->post('invoices/store', ['customer_id' => (string) $customer, 'price_level' => 'wholesale', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '', 'notes' => '', 'invoice_discount' => '',
            'lines[0][product_id]' => (string) $s['custom'], 'lines[0][unit_id]' => (string) $s['unit'], 'lines[0][qty]' => '1', 'lines[0][price]' => '30', 'lines[0][fill]' => '500', 'lines[0][cost]' => '',
            'lines[0][note]' => 'wedding', 'lines[0][pack][0][material_id]' => (string) $s['label'], 'lines[0][pack][0][qty]' => '3', 'lines[0][discount]' => '']);
        assert_same(302, $post->status, $post->body);
        assert_same([3850, 97], [$stockOf($s['mix']), $stockOf($s['label'])], '500 g and three labels out');
        assert_same('2.06', Database::pdo()->query("SELECT line_cost_usd FROM sale_items WHERE note = 'wedding'")->fetchColumn(), '500 g × $0.004 + 3 × $0.02');
    },
];
