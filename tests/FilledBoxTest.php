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
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-10-18 (owner, through Aya): a box filled at the sale. The owner weighs a custom box when he fills it; boxes
 * may come in sizes (0.5 kg, 1 kg). The sale takes the grams of mix out of the mix's stock, with the box's packaging,
 * and the line costs that mix and that packaging; the box keeps no stock of its own; a returned box puts nothing
 * back. The usual weight is offered and can be changed; without one, the grams must be typed.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $stock = new StockService();
    // the mix: 5 kg at $4.00 per kg; a gift box at $0.50
    $mix = $svc->create(['name' => 'Marshmallow mix', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'kind' => 'intermediate']);
    $mixKg = $svc->addUnit($mix, 'kg', '1000', true, true);
    $stock->adjust($mix, $mixKg, '5', 'opening', '', null, TEST_ADMIN_ID);
    $svc->setCost($mix, '4', 1000);
    $gift = make_product('Gift box', 'piece', null, 'material');
    $giftU = $svc->addUnit($gift, 'Piece', '1', false, true);
    $stock->adjust($gift, $giftU, '10', 'opening', '', null, TEST_ADMIN_ID);
    $svc->setCost($gift, '0.50', 1);
    // Custom Box 0.5 kg: usual 500 g, $9 retail / $8 wholesale, one gift box per piece
    $half = $svc->create(['name' => 'Custom Box 0.5 kg', 'category_id' => '', 'base_unit' => 'piece', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
        'show_on_pos_grid' => true, 'allow_price_override' => false, 'kind' => 'finished', 'fill_mix_id' => $mix, 'fill_grams' => '500']);
    $halfU = $svc->addUnit($half, 'Piece', '1', false, true);
    $svc->setPrices($halfU, '9', '8');
    $svc->savePackaging($half, [['material_id' => $gift, 'qty' => '1', 'unit_id' => $giftU]]);
    // the Customized Box: no usual weight, the price typed at the sale
    $any = $svc->create(['name' => 'Customized Box', 'category_id' => '', 'base_unit' => 'piece', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
        'show_on_pos_grid' => true, 'allow_price_override' => true, 'kind' => 'finished', 'fill_mix_id' => $mix]);
    $anyU = $svc->addUnit($any, 'Piece', '1', false, true);
    $svc->setPrices($anyU, '10', '8');
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');

    return ['mix' => $mix, 'gift' => $gift, 'half' => $half, 'halfU' => $halfU, 'any' => $any, 'anyU' => $anyU, 'register' => $register, 'session' => $session];
};
$stockOf = static fn (int $id): int => (int) (new Product())->find($id)['stock_base'];
$sell = static fn (array $s, array $lines, string $amount): array => (new SaleService())->complete(['register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID,
    'customer_id' => null, 'price_level' => 'retail', 'lines' => $lines, 'invoice_discount' => '', 'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => $amount]],
    'change_currency' => 'USD', 'notes' => '', 'pin' => '']);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the product: filled from a mix, by piece, usual weight optional, no stock of its own; refused by weight or from a raw material' => function () use ($setup): void {
        $s = $setup();
        $half = (new Product())->find($s['half']);
        assert_same([0, $s['mix'], 500], [(int) $half['track_stock'], (int) $half['fill_mix_id'], (int) $half['fill_base']]);
        assert_same(null, (new Product())->find($s['any'])['fill_base'], 'no usual weight: typed each time');
        $svc = new ProductService();
        $e = assert_throws(DomainException::class, fn () => $svc->create(['name' => 'Loose', 'category_id' => '', 'base_unit' => 'g', 'kind' => 'finished', 'fill_mix_id' => $s['mix']]));
        assert_contains('sold by piece', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->create(['name' => 'Odd', 'category_id' => '', 'base_unit' => 'piece', 'kind' => 'finished', 'fill_mix_id' => $s['gift']]));
        assert_contains('filled from a mix', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->create(['name' => 'Odd 2', 'category_id' => '', 'base_unit' => 'piece', 'kind' => 'finished', 'fill_mix_id' => $s['mix'], 'fill_grams' => 'half']));
        assert_contains('number of grams', $e->getMessage());
    },

    'a sale takes the weighed grams out of the mix with the packaging, and costs them; the usual weight when none is typed' => function () use ($setup, $stockOf, $sell): void {
        $s = $setup();
        $r = $sell($s, [['product_id' => $s['half'], 'unit_id' => $s['halfU'], 'qty' => '1', 'fill' => '520', 'note' => 'strawberry + mango']], '9');
        assert_same([4480, 9, 0], [$stockOf($s['mix']), $stockOf($s['gift']), $stockOf($s['half'])], '520 g and one gift box out; the box has no stock');
        $item = Database::pdo()->query('SELECT fill_mix_id, fill_base, cost_per_base, line_cost_usd, note FROM sale_items WHERE sale_id = ' . $r['id'])->fetch(PDO::FETCH_ASSOC);
        assert_same([$s['mix'], 520, '2.580000', '2.58', 'strawberry + mango'], [(int) $item['fill_mix_id'], (int) $item['fill_base'], $item['cost_per_base'], $item['line_cost_usd'], $item['note']],
            '520 g × $4/kg = $2.08 + gift box $0.50');
        assert_same('2.58', Database::pdo()->query('SELECT cost_total_usd FROM sales WHERE id = ' . $r['id'])->fetchColumn());
        $moves = Database::pdo()->query("SELECT product_id, base_qty FROM stock_movements WHERE type = 'sale' ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        assert_same([[$s['mix'], -520], [$s['gift'], -1]], array_map(static fn (array $m): array => [(int) $m[0], (int) $m[1]], $moves));
        // two boxes, no grams typed: the usual 500 g each
        $sell($s, [['product_id' => $s['half'], 'unit_id' => $s['halfU'], 'qty' => '2']], '18');
        assert_same([3480, 7], [$stockOf($s['mix']), $stockOf($s['gift'])]);
        // the Customized Box has no usual weight: the grams are required
        $e = assert_throws(DomainException::class, fn () => $sell($s, [['product_id' => $s['any'], 'unit_id' => $s['anyU'], 'qty' => '1', 'price' => '12']], '12'));
        assert_contains('Type how many grams', $e->getMessage());
        $r = $sell($s, [['product_id' => $s['any'], 'unit_id' => $s['anyU'], 'qty' => '1', 'price' => '12', 'fill' => '300']], '12');
        assert_same(3180, $stockOf($s['mix']));
        assert_same('1.20', Database::pdo()->query('SELECT line_cost_usd FROM sale_items WHERE sale_id = ' . $r['id'])->fetchColumn(), 'no packaging on it: 300 g × $4/kg');
    },

    'a desk invoice and a held invoice take the grams out too; a mix that runs short is warned about' => function () use ($setup, $stockOf): void {
        $s = $setup();
        $customer = (new Customer())->create(['name' => 'Hawraa Issa', 'phone' => '', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
        $r = (new SaleService())->issue(['user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale',
            'lines' => [['product_id' => $s['half'], 'unit_id' => $s['halfU'], 'qty' => '2', 'fill' => '510']], 'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official']);
        assert_same(['16.00', [3980, 8]], [$r['total_usd'], [$stockOf($s['mix']), $stockOf($s['gift'])]], '2 × 510 g, 2 gift boxes, at $8 each');
        // held at the till, finished with one box back: one box leaves at the finish
        $h = (new SaleService())->hold(['register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale',
            'lines' => [['product_id' => $s['half'], 'unit_id' => $s['halfU'], 'qty' => '2', 'fill' => '500']], 'invoice_discount' => '', 'notes' => '', 'pin' => '']);
        assert_same(3980, $stockOf($s['mix']), 'nothing leaves while it is out with the seller');
        $item = (int) Database::pdo()->query('SELECT id FROM sale_items WHERE sale_id = ' . $h['id'])->fetchColumn();
        (new SaleService())->settle($h['id'], [$item => '1'], [['method' => 'cash', 'currency' => 'USD', 'amount' => '8']], 'USD', false, '', TEST_ADMIN_ID, $s['session'], $s['register']);
        assert_same([3480, 7], [$stockOf($s['mix']), $stockOf($s['gift'])]);
        // 4 kg asked for, 3.48 kg in stock: the sale goes through with a warning
        $r = (new SaleService())->issue(['user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale',
            'lines' => [['product_id' => $s['any'], 'unit_id' => $s['anyU'], 'qty' => '1', 'fill' => '4000']], 'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'normal']);
        assert_contains('Marshmallow mix is now below zero stock.', implode(' ', $r['warnings']));
        assert_same(-520, $stockOf($s['mix']));
    },

    'a returned box puts nothing back in stock: its content cannot go back into the mix' => function () use ($setup, $stockOf): void {
        $s = $setup();
        $customer = (new Customer())->create(['name' => 'Hawraa Issa', 'phone' => '', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
        $r = (new SaleService())->issue(['user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale',
            'lines' => [['product_id' => $s['half'], 'unit_id' => $s['halfU'], 'qty' => '1', 'fill' => '500']], 'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'normal']);
        $item = (int) Database::pdo()->query('SELECT id FROM sale_items WHERE sale_id = ' . $r['id'])->fetchColumn();
        $before = (int) Database::pdo()->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
        $cn = (new ReturnService())->creditNote($r['id'], [['sale_item_id' => $item, 'qty' => '1', 'condition' => 'restock']], 'not wanted', TEST_ADMIN_ID);
        assert_same('8.00', $cn['total_usd'], 'credited at the invoice price');
        assert_same($before, (int) Database::pdo()->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn(), 'no stock movement at all');
        assert_same([4500, 0], [$stockOf($s['mix']), $stockOf($s['half'])]);
    },

    'the screens: the product switch with the usual weight, the till and the invoice form know the box is weighed' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('products/edit', ['id' => $s['half']])->body;
        assert_contains('id="fill_on" name="fill_on" value="1" checked', $page);
        assert_contains('for="fill_on">Filled at the sale', $page);
        assert_contains('id="fill_grams" name="fill_grams" inputmode="numeric" value="500"', $page);
        assert_not_contains('name="no_stock"', $page, 'the old switch is gone');
        // a product saved from the form with the switch on
        $client->post('products/save', ['product_id' => '0', 'then' => 'stay', 'kind' => 'finished', 'name' => 'Custom Box 1 kg', 'category_id' => '', 'base_unit' => 'piece',
            'main_unit' => 'n1', 'units[n1][id]' => '0', 'units[n1][type]' => 'Piece', 'units[n1][size]' => '', 'units[n1][size_unit]' => '', 'units[n1][retail]' => '17', 'units[n1][wholesale]' => '15', 'units[n1][barcodes]' => '',
            'fill_on' => '1', 'fill_mix_id' => (string) $s['mix'], 'fill_grams' => '1000', 'show_on_pos_grid' => '1']);
        $kg = Database::pdo()->query("SELECT track_stock, fill_mix_id, fill_base FROM products WHERE name = 'Custom Box 1 kg'")->fetch(PDO::FETCH_NUM);
        assert_same([0, $s['mix'], 1000], array_map('intval', $kg));
        // the till
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        assert_contains('id="line-fill"', $client->get('pos')->body);
        $data = json_decode($client->get('pos/data')->body, true);
        $byName = array_column($data['products'], null, 'name');
        assert_same([true, 500], [$byName['Custom Box 0.5 kg']['filled'], $byName['Custom Box 0.5 kg']['fill']]);
        assert_same([true, null], [$byName['Customized Box']['filled'], $byName['Customized Box']['fill']]);
        // the invoice form (needs a customer)
        (new Customer())->create(['name' => 'Hawraa Issa', 'phone' => '', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
        $form = $client->get('invoices/new')->body;
        assert_contains('name="lines[IDX][fill]"', $form);
        assert_contains('"filled":true,"fill":500', $form);
    },
];
