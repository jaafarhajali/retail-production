<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Settings;
use App\Models\Customer;
use App\Models\Register;
use App\Models\Setting;
use App\Services\CashService;
use App\Services\DashboardService;
use App\Services\ExpenseService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/** Phase F5 (2026-10-13): the factory dashboard, the monthly product sales report, break-even from the fixed-cost categories. */
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
    // 100 Joy wholesale on credit ($250), 10 Gold retail cash ($40): revenue 290, cost 68, 110 pieces
    $sale([['product_id' => $joy, 'unit_id' => $joyU, 'qty' => '100']], [['method' => 'credit', 'currency' => 'USD', 'amount' => '250']], 'wholesale', $customer);
    $sale([['product_id' => $gold, 'unit_id' => $goldU, 'qty' => '10']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '40']]);
    (new ExpenseService())->create(['kind' => 'expense', 'category' => 'Rent', 'description' => '', 'usd' => '150', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside'], TEST_ADMIN_ID);
    (new ExpenseService())->create(['kind' => 'expense', 'category' => 'Delivery fuel', 'description' => '', 'usd' => '30', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside'], TEST_ADMIN_ID);

    return ['joy' => $joy, 'joyU' => $joyU, 'gold' => $gold, 'customer' => $customer, 'register' => $register, 'session' => $session];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the summary: sales, splits, who owes, the two stocks, and break-even once the fixed categories are set' => function () use ($setup): void {
        $s = $setup();
        $d = (new DashboardService())->summary();
        assert_same(['290.00', 2, '250.00', '40.00', '40.00', '250.00', 1, 1, 110], array_values(array_intersect_key($d['month_sales'], array_flip(['total', 'invoices', 'wholesale', 'retail', 'paid', 'credit', 'paid_invoices', 'unpaid_invoices', 'units']))));
        assert_same('290.00', $d['today']['total']);
        assert_same(['250.00', 1], [$d['owed']['total'], $d['owed']['customers']]);
        assert_same('Spinneys Saida', $d['owed']['top'][0]['name']);
        assert_same('132.00', $d['stock']['finished']['value_usd'], '100 Joy × 0.60 + 90 Gold × 0.80');
        assert_same('50.00', $d['stock']['material']['value_usd']);
        assert_same(['Joy 100g', 'Gold 100g'], array_column($d['products'], 'product_name'));
        assert_false($d['break_even']['ready'], 'no fixed categories yet');
        (new Setting())->setMany(['fixed_expense_categories' => 'Rent']);
        Settings::flush();
        $be = (new DashboardService())->summary()['break_even'];
        // margin = (290 − 68) / 110 = $2.018 a piece; break-even = 150 / 2.018 = 75 pieces; sold 110 → past it; profit 290 − 68 − 150 = 72
        assert_same(['150.00', '2.02', 75, 110, 0, '72.00', 147], [$be['fixed_usd'], $be['margin'], $be['bep_units'], $be['units_sold'], $be['remaining_units'], $be['profit'], $be['pct']]);
        assert_true($be['ready']);
    },

    'the dashboard page shows the flow, the month, who owes and break-even; a cashier gets the plain page' => function () use ($setup): void {
        $s = $setup();
        (new Setting())->setMany(['fixed_expense_categories' => 'Rent']);
        Settings::flush();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('dashboard')->body;
        foreach (['Raw materials', 'Production', 'Finished stock', 'Out with the seller', 'Owed by customers', 'Sales in ' . date('F Y'), '$290.00', 'Wholesale $250.00', 'On credit $250.00', 'Who owes', 'Spinneys Saida', 'Break-even this month', '75 pieces', 'Profit so far $72.00', 'Products sold in', 'Joy 100g'] as $needle) {
            assert_contains($needle, $page, $needle);
        }
        make_user('cashier1');
        $plain = login_as('cashier1', 'password123')->get('dashboard')->body;
        assert_not_contains('Break-even', $plain);
        assert_contains('This device', $plain);
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

    'the Settings page lets the owner tick the fixed-cost categories' => function () use ($setup): void {
        $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('settings')->body;
        assert_contains('name="fixed_cats[]" value="Rent"', $page);
        $r = $client->post('settings/save', ['shop_name' => 'Malika', 'shop_address' => '', 'shop_phone' => '', 'receipt_header' => '', 'receipt_footer' => '', 'company_mof' => '', 'company_email' => '',
            'lbp_rounding_step' => '5000', 'max_cashier_discount_pct' => '0', 'usd_denominations' => '100,50,20,10,5,1', 'lbp_denominations' => '100000,50000,20000,10000,5000,1000', 'fixed_cats' => ['Rent', 'Delivery fuel']]);
        assert_same(302, $r->status);
        Settings::flush();
        assert_same('Rent,Delivery fuel', Settings::get('fixed_expense_categories'));
        assert_same('180.00', (new DashboardService())->summary()['break_even']['fixed_usd']);
    },
];
