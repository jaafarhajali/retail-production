<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * Phase F3 (2026-10-13): the delivery invoice. Hold = a numbered invoice for a customer, printed, no money, no stock.
 * Finish = what came back off the lines at the invoice's prices, net stock out, cash in, the rest on the account.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $joy = make_product('Joy 100g', 'piece');
    $joyU = $svc->addUnit($joy, 'Piece', '1', false, true);
    $svc->setPrices($joyU, '3', '2.50');
    $svc->setCost($joy, '0.60', 1);
    (new StockService())->adjust($joy, $joyU, '100', 'opening', '', null, TEST_ADMIN_ID);
    $pist = make_product('Pistachio 100g', 'piece');
    $pistU = $svc->addUnit($pist, 'Piece', '1', false, true);
    $svc->setPrices($pistU, '4', '3.25');
    $svc->setCost($pist, '0.80', 1);
    (new StockService())->adjust($pist, $pistU, '50', 'opening', '', null, TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
    $customer = (new Customer())->create(['name' => 'Spinneys Saida', 'phone' => '07 225 999', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);

    return ['joy' => $joy, 'joyU' => $joyU, 'pist' => $pist, 'pistU' => $pistU, 'register' => $register, 'session' => $session, 'customer' => $customer];
};
$hold = static fn (array $s, array $extra = []): array => (new SaleService())->hold([
    'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => array_key_exists('customer_id', $extra) ? $extra['customer_id'] : $s['customer'],
    'price_level' => 'wholesale', 'lines' => [['product_id' => $s['joy'], 'unit_id' => $s['joyU'], 'qty' => '20'], ['product_id' => $s['pist'], 'unit_id' => $s['pistU'], 'qty' => '10']],
    'invoice_discount' => $extra['invoice_discount'] ?? '', 'notes' => '', 'pin' => '',
]);
$stockOf = static fn (int $id): int => (int) (new Product())->find($id)['stock_base'];

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'hold: a numbered invoice for the customer with its lines, and nothing else moves' => function () use ($setup, $hold, $stockOf): void {
        $s = $setup();
        $r = $hold($s);   // 20 × 2.50 + 10 × 3.25 = 82.50
        assert_same(['INV-000001', '82.50'], [$r['invoice_no'], $r['total_usd']]);
        $sale = (new Sale())->find($r['id']);
        assert_same('on_hold', $sale['status']);
        assert_true($sale['held_at'] !== null);
        assert_same(2, (int) Database::pdo()->query('SELECT COUNT(*) FROM sale_items')->fetchColumn());
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM sale_payments')->fetchColumn());
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'sale'")->fetchColumn(), 'the goods are still in stock');
        assert_same([100, 50], [$stockOf($s['joy']), $stockOf($s['pist'])]);
        assert_same('0.00', (new Customer())->balance($s['customer']), 'nothing on the account yet');
        assert_same(1, count((new Sale())->onHold()));
        $e = assert_throws(DomainException::class, fn () => $hold($s, ['customer_id' => null]));
        assert_contains('needs the customer', $e->getMessage());
    },

    'finish: 3 Joy came back, $70 received of $75: net stock out, cash in, $5 on the account, the printed figures kept' => function () use ($setup, $hold, $stockOf): void {
        $s = $setup();
        $r = $hold($s);
        $joyItem = (int) Database::pdo()->query("SELECT id FROM sale_items WHERE product_id = {$s['joy']}")->fetchColumn();
        $svc = new SaleService();
        $f = $svc->settle($r['id'], [$joyItem => '3'], [['method' => 'cash', 'currency' => 'USD', 'amount' => '70'], ['method' => 'credit', 'currency' => 'USD', 'amount' => '5']], 'LBP', false, '', TEST_ADMIN_ID, $s['session'], $s['register']);
        assert_same(['82.50', '7.50', '75.00', '70.00', '5.00'], [$f['original_usd'], $f['returned_usd'], $f['net_usd'], $f['paid_usd'], $f['balance_usd']]);
        $sale = (new Sale())->find($r['id']);
        assert_same(['completed', '75.00', '75.00'], [$sale['status'], $sale['subtotal_usd'], $sale['total_usd']]);
        assert_true($sale['settled_at'] !== null);
        assert_same([83, 40], [$stockOf($s['joy']), $stockOf($s['pist'])], '17 Joy and 10 Pistachio left the stock, the 3 returned never did');
        $items = Database::pdo()->query('SELECT product_id, qty, base_qty, line_total_usd FROM sale_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        assert_same([['product_id' => $s['joy'], 'qty' => '17.000', 'base_qty' => 17, 'line_total_usd' => '42.50'], ['product_id' => $s['pist'], 'qty' => '10.000', 'base_qty' => 10, 'line_total_usd' => '32.50']], $items);
        assert_same(['USD' => '170.00', 'LBP' => '0'], (new CashSession())->expected($s['session']), '$100 float + $70');
        assert_same('5.00', (new Customer())->balance($s['customer']));
        $st = (new Sale())->settlement($r['id']);
        assert_same(['82.50', '7.50', '75.00', '70.00', '5.00'], [$st['original_usd'], $st['returned_usd'], $st['net_usd'], $st['paid_usd'], $st['balance_usd']]);
        assert_same([['product_name' => 'Joy 100g', 'qty' => '3.000', 'base_qty' => 3, 'value_usd' => '7.50']], array_map(static fn (array $x): array => ['product_name' => $x['product_name'], 'qty' => $x['qty'], 'base_qty' => (int) $x['base_qty'], 'value_usd' => $x['value_usd']], $st['returns']));
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'sale.settled'")->fetchColumn());
        // once finished it cannot be finished again; a quantity above what went out is refused
        $e = assert_throws(DomainException::class, fn () => $svc->settle($r['id'], [], [], 'LBP', false, '', TEST_ADMIN_ID, $s['session'], $s['register']));
        assert_contains('already finished', $e->getMessage());
        $r2 = $hold($s);
        $e = assert_throws(DomainException::class, fn () => $svc->settle($r2['id'], [$joyItem + 2 => '25'], [], 'LBP', false, '', TEST_ADMIN_ID, $s['session'], $s['register']));
        assert_contains('no more than that can come back', $e->getMessage());
    },

    'finish with everything paid in LBP and an invoice discount: the discount shrinks with the returns' => function () use ($setup, $hold): void {
        $s = $setup();
        $r = $hold($s, ['invoice_discount' => '8.25']);   // 10 % of 82.50 → total 74.25
        assert_same('74.25', $r['total_usd']);
        $pistItem = (int) Database::pdo()->query("SELECT id FROM sale_items WHERE product_id = {$s['pist']}")->fetchColumn();
        // all 10 Pistachio come back: subtotal 50, discount 8.25 × 50/82.5 = 5.00, net 45
        $f = (new SaleService())->settle($r['id'], [$pistItem => '10'], [['method' => 'cash', 'currency' => 'LBP', 'amount' => '4,050,000']], 'LBP', false, '', TEST_ADMIN_ID, $s['session'], $s['register']);
        assert_same(['45.00', '0.00'], [$f['net_usd'], $f['balance_usd']]);
        assert_same(['USD' => '100.00', 'LBP' => '4050000'], (new CashSession())->expected($s['session']));
        assert_same('5.00', (new Sale())->find($r['id'])['discount_usd']);
    },

    'on the till: hold as invoice, the list, the receipt says delivery note, finish; the sales list shows on hold' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $client->get('pos');
        $lines = [['product_id' => $s['joy'], 'unit_id' => $s['joyU'], 'qty' => '20']];
        $r = $client->postJson('pos/hold-invoice', ['customer_id' => null, 'price_level' => 'wholesale', 'lines' => $lines, 'invoice_discount' => '', 'notes' => '', 'pin' => '']);
        assert_same(422, $r->status);
        $r = $client->postJson('pos/hold-invoice', ['customer_id' => $s['customer'], 'price_level' => 'wholesale', 'lines' => $lines, 'invoice_discount' => '', 'notes' => '', 'pin' => '']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same('INV-000001', $j['invoice_no']);
        assert_contains('DELIVERY NOTE', $client->get('sales/receipt', ['id' => $j['id']])->body);
        assert_contains('on hold', $client->get('sales')->body);
        $held = json_decode($client->get('pos/held-invoices')->body, true)['invoices'];
        assert_same(['INV-000001', 'Spinneys Saida', '50.00'], [$held[0]['invoice_no'], $held[0]['customer'], $held[0]['total']]);
        $one = json_decode($client->get('pos/held-invoice', ['id' => $j['id']])->body, true);
        assert_same('20', $one['lines'][0]['qty']);
        $itemId = $one['lines'][0]['id'];
        $r = $client->postJson('pos/settle', ['sale_id' => $j['id'], 'returns' => [$itemId => '2'], 'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '45']], 'change_currency' => 'LBP', 'allow_short_drawer' => false, 'pin' => '']);
        assert_same(200, $r->status, $r->body);
        $f = json_decode($r->body, true);
        assert_same(['50.00', '5.00', '45.00', '45.00', '0.00'], [$f['original_usd'], $f['returned_usd'], $f['net_usd'], $f['paid_usd'], $f['balance_usd']]);
        assert_same([], json_decode($client->get('pos/held-invoices')->body, true)['invoices']);
        $page = $client->get('sales/view', ['id' => $j['id']])->body;
        assert_contains('Came back', $page);
        assert_contains('$5.00', $page);
        $page = $client->get('customers/edit', ['id' => $s['customer']])->body;
        assert_contains('Total sales', $page);
    },
];
