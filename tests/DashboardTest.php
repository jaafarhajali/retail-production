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

    "the break-even by product: each product carries the fixed costs in proportion to the profit it earned; the estimate during the month, the actual once it is over" => function () use ($setup): void {
        $s = $setup();
        // No estimate typed yet: the actual so far (the $150 rent recorded today) stands in.
        $d = (new DashboardService())->summary();
        assert_same(["sales" => "290.00", "variable" => "68.00", "margin" => "222.00", "fixed" => "150.00", "profit" => "72.00"], $d["money"]);
        assert_same([null, "150.00", "150.00", "actual"], [$d["fixed"]["estimate"], $d["fixed"]["actual"], $d["fixed"]["used"], $d["fixed"]["uses"]]);
        assert_same(["Joy 100g", "Gold 100g"], array_column($d["targets"]["rows"], "name"), "the Stick earned nothing: no column; the biggest share first");

        // Estimate $150. Last month sold nothing, so the shares are this month's profit: Joy 250 − 60 = $190, Gold 40 − 8 = $32, of $222.
        // Joy carries 150 × 190/222 = $128.38 → at $1.90 a piece, 68; Gold $21.62 → at $3.20, 7. Both already sold: left 0.
        $v = (new DashboardService())->setFixedCosts(date("Y-m"), "150", TEST_ADMIN_ID);
        assert_same("150.00", $v);
        $d = (new DashboardService())->summary();
        $t = $d["targets"];
        assert_true($t["ready"]);
        assert_same(["150.00", null, "estimate"], [$d["fixed"]["estimate"], $d["fixed"]["from_month"], $d["fixed"]["uses"]]);
        assert_same([["Joy 100g", 85.6, "128.38", 68, 100, 0, "1.90"], ["Gold 100g", 14.4, "21.62", 7, 10, 0, "3.20"]],
            array_map(static fn (array $r): array => [$r["name"], $r["share_pct"], $r["carries"], $r["need"], $r["sold"], $r["left"], $r["margin_text"]], $t["rows"]));
        assert_same([75, 110, 0, "this"], [$t["need"], $t["sold"], $t["left"], $t["share_from"]]);
        assert_same("72.00", $d["money"]["profit"], "net profit = 290 − 68 − 150");

        // Estimate $1,500: Joy $1,283.78 → 676 (576 left), Gold $216.22 → 68 (58 left); a loss so far
        (new DashboardService())->setFixedCosts(date("Y-m"), "1,500", TEST_ADMIN_ID);
        $d = (new DashboardService())->summary();
        assert_same([[676, 576], [68, 58]], array_map(static fn (array $r): array => [$r["need"], $r["left"]], $d["targets"]["rows"]));
        assert_same([744, 634], [$d["targets"]["need"], $d["targets"]["left"]]);
        assert_same("-1278.00", $d["money"]["profit"]);
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'fixed_costs.set' AND amount = 1500")->fetchColumn());
        // the history: this month, estimated $1,500, $150 recorded so far
        assert_same([[date("F Y"), "1500.00", "150.00", "-1350.00", -90.0, false]], array_map(static fn (array $h): array => [$h["label"], $h["estimate"], $h["actual"], $h["diff"], $h["pct"], $h["final"]], $d["fixed"]["history"]));
        // a supplier payment is not a fixed cost
        $sup = (new \App\Services\PartyService())->saveSupplier(0, "Sugar Co", "", "", true);
        (new ExpenseService())->create(["kind" => "supplier", "supplier_id" => $sup, "category" => "", "description" => "", "usd" => "40", "lbp" => "", "expense_date" => date("Y-m-d"), "paid_from" => "outside"], TEST_ADMIN_ID);
        assert_same("150.00", (new DashboardService())->summary()["fixed"]["actual"]);

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

    'the estimate is carried to the next months; the shares come from last month\'s profit; a month that is over uses its actual and shows the variance' => function () use ($setup): void {
        $setup();
        $prev = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        (new FixedCost())->set($prev, '900.00', TEST_ADMIN_ID);
        $d = (new DashboardService())->summary();
        assert_same(['900.00', date('F Y', strtotime($prev . '-01')), 'estimate'], [$d['fixed']['estimate'], $d['fixed']['from_month'], $d['fixed']['uses']], 'carried over, and said so');
        assert_true($d['targets']['ready']);
        // last month: 50 Gold sold ($160 of profit) and nothing else → Gold carries 100%; Joy earned nothing last month: no target
        Database::pdo()->exec("UPDATE sales SET created_at = '{$prev}-15 10:00:00' WHERE price_level = 'retail'");
        Database::pdo()->exec("UPDATE sale_items SET qty = 50, base_qty = 50, line_total_usd = 200, line_cost_usd = 40 WHERE sale_id = (SELECT id FROM sales WHERE price_level = 'retail')");
        $t = (new DashboardService())->summary()['targets'];
        assert_same('last', $t['share_from']);
        assert_same([['Gold 100g', 100.0, 282, 0, 282]], array_map(static fn (array $r): array => [$r['name'], $r['share_pct'], $r['need'], $r['sold'], $r['left']], $t['rows']), '900 / (4.00 − 0.80) = 281.25 → 282 Gold, at last month\'s $3.20 a piece');

        // last month is over: $950 of expenses recorded in it → it uses the actual; the history shows + $50, + 5.6 %
        Database::pdo()->exec("INSERT INTO expenses (category, description, usd_paid, lbp_paid, amount_usd, exchange_rate, paid_from, user_id, expense_date, created_at)
                               VALUES ('Salaries', '', 950, 0, 950, 90000, 'outside', " . TEST_ADMIN_ID . ", '{$prev}-28', NOW())");
        $p = (new DashboardService())->summary($prev);
        assert_same(['900.00', '950.00', '950.00', 'actual', true], [$p['fixed']['estimate'], $p['fixed']['actual'], $p['fixed']['used'], $p['fixed']['uses'], $p['fixed']['over']]);
        $h = (new DashboardService())->summary()['fixed']['history'];
        assert_same([[date('F Y'), '900.00', '150.00', false], [date('F Y', strtotime($prev . '-01')), '900.00', '950.00', true]], array_map(static fn (array $r): array => [$r['label'], $r['estimate'], $r['actual'], $r['final']], $h));
        assert_same(['50.00', 5.6], [$h[1]['diff'], $h[1]['pct']]);
    },

    'the dashboard page: the targets table, the fixed costs box (administrator types, the others read), the money table; a cashier gets the plain page' => function () use ($setup): void {
        $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('dashboard')->body;
        foreach (['Break-even in ' . date('F Y'), 'Share of fixed costs', 'Need to sell', 'Sold', 'Left', 'Joy 100g', 'Gold 100g', 'All products', 'Fixed costs · ' . date('F Y'), 'Estimated', 'not set',
                  'Actual so far', 'Estimated fixed costs for', 'name="amount"', 'The month\'s money', 'Variable costs', 'Net profit', '$222.00', '85.6 %'] as $needle) {
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
