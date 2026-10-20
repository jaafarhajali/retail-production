<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Models\FixedCost;
use App\Models\Register;
use App\Services\CashService;
use App\Services\DashboardService;
use App\Services\ExpenseService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/** The dashboard (2026-10-20): the break-even by product, the monthly fixed costs typed there, the month's money; the monthly product sales report (F5). */
$setup = static function (): array {
    $svc = new ProductService();
    $joy = make_product('Joy 100g', 'piece');
    $joyU = $svc->addUnit($joy, 'Piece', '1', false, true);
    $svc->setPrices($joyU, '3', '2.50');
    $svc->setCost($joy, '0.60', 1);
    (new StockService())->adjust($joy, $joyU, '200', 'opening', '', null, TEST_ADMIN_ID);
    $gold = make_product('Gold 100g', 'piece');
    $goldU = $svc->addUnit($gold, 'Piece', '1', false, true);
    $svc->setPrices($goldU, '4', '3.50');
    $svc->setCost($gold, '0.80', 1);
    (new StockService())->adjust($gold, $goldU, '100', 'opening', '', null, TEST_ADMIN_ID);
    $stick = make_product('Stick 25g', 'piece');   // never sold: no share, no column
    $svc->addUnit($stick, 'Piece', '1', false, true);
    $honey = make_product('Honey', 'g', null, 'material');
    $honeyU = $svc->addUnit($honey, 'kg', '1000', true, false);
    $svc->setCost($honey, '10', 1000);
    (new StockService())->adjust($honey, $honeyU, '5', 'opening', '', null, TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
    $customer = (new Customer())->create(['name' => 'Spinneys Saida', 'phone' => '07 225 999', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
    $sale = static fn (array $lines, array $payments, string $level = 'retail', ?int $cust = null): array => (new SaleService())->complete([
        'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => $cust, 'price_level' => $level, 'lines' => $lines, 'invoice_discount' => '',
        'payments' => $payments, 'change_currency' => 'USD', 'notes' => '', 'pin' => '']);
    // 100 Joy wholesale on credit ($250, cost 60), 10 Gold retail cash ($40, cost 8): sales 290, variable 68, 110 pieces
    $sale([['product_id' => $joy, 'unit_id' => $joyU, 'qty' => '100']], [['method' => 'credit', 'currency' => 'USD', 'amount' => '250']], 'wholesale', $customer);
    $sale([['product_id' => $gold, 'unit_id' => $goldU, 'qty' => '10']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '40']]);
    (new ExpenseService())->create(['kind' => 'expense', 'category' => 'Rent', 'description' => '', 'usd' => '150', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside'], TEST_ADMIN_ID);

    return ['joy' => $joy, 'joyU' => $joyU, 'gold' => $gold, 'stick' => $stick, 'customer' => $customer, 'register' => $register, 'session' => $session];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the break-even by product: the fixed costs split by each product\'s share of the pieces sold, weighed by what a piece earns; sold and left per product' => function () use ($setup): void {
        $s = $setup();
        $d = (new DashboardService())->summary();
        // the month's money needs no fixed costs; the targets do
        assert_same(['290.00', '68.00', '222.00', '0.00', '222.00'], array_values($d['money']));
        assert_same([null, null], [$d['fixed']['amount'], $d['fixed']['from_month']]);
        assert_false($d['targets']['ready'], 'no fixed costs typed yet');
        assert_same(['Joy 100g', 'Gold 100g'], array_column($d['targets']['rows'], 'name'), 'the Stick never sold: no column; best seller first');
        assert_same([100, 10, null], [$d['targets']['rows'][0]['sold'], $d['targets']['rows'][1]['sold'], $d['targets']['rows'][0]['need']]);

        // Fixed $150. No sale last month, so the shares come from this month: Joy 100/110, Gold 10/110.
        // Joy earns 2.50 − 0.60 = $1.90 a piece, Gold 4.00 − 0.80 = $3.20; a piece earns 1.90 × 100/110 + 3.20 × 10/110 = $2.018 on average.
        // 150 / 2.018 = 74.3 pieces: Joy ⌈74.3 × 100/110⌉ = 68, Gold ⌈74.3 × 10/110⌉ = 7 (68 × 1.90 + 7 × 3.20 = $151.60 ≥ 150). Both already sold: left 0.
        $v = (new DashboardService())->setFixedCosts(date('Y-m'), '150', TEST_ADMIN_ID);
        assert_same('150.00', $v);
        $d = (new DashboardService())->summary();
        $t = $d['targets'];
        assert_true($t['ready']);
        assert_same(['150.00', null], [$d['fixed']['amount'], $d['fixed']['from_month']]);
        assert_same([['Joy 100g', 68, 100, 0, '1.90', 91], ['Gold 100g', 7, 10, 0, '3.20', 9]], array_map(static fn (array $r): array => [$r['name'], $r['need'], $r['sold'], $r['left'], $r['margin_text'], $r['share_pct']], $t['rows']));
        assert_same([75, 110, 0, 'this', '2.02'], [$t['need'], $t['sold'], $t['left'], $t['share_from'], $t['per_piece']]);
        assert_same(['290.00', '68.00', '222.00', '150.00', '72.00'], array_values($d['money']), 'net profit = 290 − 68 − 150');

        // Fixed $1,500: 743.2 pieces → Joy 676 (576 left), Gold 68 (58 left); a loss so far
        (new DashboardService())->setFixedCosts(date('Y-m'), '1,500', TEST_ADMIN_ID);
        $d = (new DashboardService())->summary();
        assert_same([[676, 576], [68, 58]], array_map(static fn (array $r): array => [$r['need'], $r['left']], $d['targets']['rows']));
        assert_same([744, 634], [$d['targets']['need'], $d['targets']['left']]);
        assert_same('-1278.00', $d['money']['profit']);
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'fixed_costs.set' AND amount = 1500")->fetchColumn());

        // a return takes pieces off "Sold": 2 Gold back ($8 refunded from the drawer) → sold 8, sales 282
        $sale = (int) Database::pdo()->query("SELECT id FROM sales WHERE price_level = 'retail'")->fetchColumn();
        $item = (int) Database::pdo()->query("SELECT id FROM sale_items WHERE sale_id = {$sale}")->fetchColumn();
        (new \App\Services\ReturnService())->create($sale, [['sale_item_id' => $item, 'qty' => '2', 'condition' => 'restock']], 'USD', 'damaged', $s['session'], $s['register'], TEST_ADMIN_ID);
        $d = (new DashboardService())->summary();
        assert_same([100, 8], array_column($d['targets']['rows'], 'sold'));
        assert_same(['282.00', '66.40'], [$d['money']['sales'], $d['money']['variable']], '290 − 8 refunded; the 2 pieces\' cost (1.60) back too');

        // the amount is validated
        assert_throws(DomainException::class, fn () => (new DashboardService())->setFixedCosts(date('Y-m'), 'abc', TEST_ADMIN_ID));
        assert_throws(DomainException::class, fn () => (new DashboardService())->setFixedCosts('2026-1', '100', TEST_ADMIN_ID));
    },

    'a month without its own amount uses the latest earlier one; the shares come from last month when it sold something' => function () use ($setup): void {
        $setup();
        $prev = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        (new FixedCost())->set($prev, '900.00', TEST_ADMIN_ID);
        $d = (new DashboardService())->summary();
        assert_same(['900.00', date('F Y', strtotime($prev . '-01'))], [$d['fixed']['amount'], $d['fixed']['from_month']], 'carried over, and said so');
        assert_true($d['targets']['ready']);
        // last month: 50 Gold sold and nothing else → every target is Gold's; Joy, unsold last month, gets no target
        Database::pdo()->exec("UPDATE sales SET created_at = '{$prev}-15 10:00:00' WHERE price_level = 'retail'");
        Database::pdo()->exec("UPDATE sale_items SET qty = 50, base_qty = 50, line_total_usd = 200, line_cost_usd = 40 WHERE sale_id = (SELECT id FROM sales WHERE price_level = 'retail')");
        $t = (new DashboardService())->summary()['targets'];
        assert_same('last', $t['share_from']);
        assert_same([['Gold 100g', 100, 282, 0, 282]], array_map(static fn (array $r): array => [$r['name'], $r['share_pct'], $r['need'], $r['sold'], $r['left']], $t['rows']), '900 / (4.00 − 0.80) = 281.25 → 282 Gold; list price and cost, since none sold this month');
    },

    'the dashboard page: the targets table, the fixed costs box (administrator types, the others read), the money table; a cashier gets the plain page' => function () use ($setup): void {
        $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('dashboard')->body;
        foreach (['Break-even in ' . date('F Y'), 'Need to sell', 'Sold', 'Left', 'Joy 100g', 'Gold 100g', 'All products', 'Monthly fixed costs', 'not set', 'Type the monthly fixed costs below',
                  'name="amount"', 'The month\'s money', 'Variable costs', 'Net profit', '$222.00'] as $needle) {
            assert_contains($needle, $page, $needle);
        }
        foreach (['Finished stock', 'Who owes', 'Products sold in', 'dash-rate', 'This device', 'Stick 25g'] as $gone) {
            assert_not_contains($gone, $page, $gone . ' is not on the dashboard any more');
        }
        // typing the amount
        $r = $client->post('dashboard/fixed-costs', ['month' => date('Y-m'), 'amount' => '150']);
        assert_same(302, $r->status, $r->body);
        $page = $client->get('dashboard')->body;
        foreach (['$150.00', '75 pieces cover the fixed costs', '>68<', '>7<', 'done ✓', '$72.00'] as $needle) {
            assert_contains($needle, $page, $needle);
        }
        $bad = $client->post('dashboard/fixed-costs', ['month' => date('Y-m'), 'amount' => 'abc']);
        assert_same(302, $bad->status);
        assert_contains('type an amount in dollars', $client->get('dashboard')->body);
        // a cashier: no money, no form, the device panel instead; and may not set the amount
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        $plain = $cashier->get('dashboard')->body;
        assert_not_contains('Break-even', $plain);
        assert_not_contains('Net profit', $plain);
        assert_contains('This device', $plain);
        assert_same(403, $cashier->post('dashboard/fixed-costs', ['month' => date('Y-m'), 'amount' => '1'])->status);
    },

    'the Settings page no longer asks for fixed-cost categories' => function () use ($setup): void {
        $setup();
        $page = login_as('admin', TEST_ADMIN_PASSWORD)->get('settings')->body;
        assert_not_contains('fixed_cats', $page);
        assert_not_contains('Fixed costs', $page);
    },

    'the monthly product sales report: month picker, filters by level, product and customer; returns deducted' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('reports', ['type' => 'products', 'month' => date('Y-m')])->body;
        assert_contains('Joy 100g', $page);
        assert_contains('100 pieces', $page);
        assert_contains('$250.00', $page);
        assert_contains('name="month"', $page);
        $ws = $client->get('reports', ['type' => 'products', 'month' => date('Y-m'), 'level' => 'wholesale'])->body;
        assert_contains('Joy 100g', $ws);
        assert_not_contains('<td dir="auto">Gold 100g</td>', $ws, 'only the dropdown names it');
        $cust = $client->get('reports', ['type' => 'products', 'month' => date('Y-m'), 'customer_id' => $s['customer']])->body;
        assert_not_contains('<td dir="auto">Gold 100g</td>', $cust);
        $empty = $client->get('reports', ['type' => 'products', 'month' => date('Y-m', strtotime('-1 month'))])->body;
        assert_contains('Nothing sold in this period', $empty);
    },
];
