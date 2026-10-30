<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Models\OpeningBalance;
use App\Models\Register;
use App\Models\Setting;
use App\Services\AccountingService;
use App\Services\CashService;
use App\Services\ExpenseService;
use App\Services\InvoiceService;
use App\Services\PackingService;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\ProductionService;
use App\Services\PurchaseService;
use App\Services\RecipeService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * The accounting side (owner, 2026-10-30): trial balance, income statement, balance sheet and cash flow, derived from
 * the records. One small factory month, then every statement is checked figure by figure, and the trial balance and the
 * balance sheet must balance to the cent.
 */
$month = static function (): array {
    $svc = new ProductService();
    $stock = new StockService();
    $mk = static function (string $name, string $kind, string $base, string $unit, int $factor, bool $fraction) use ($svc): array {
        $id = $svc->create(['name' => $name, 'category_id' => '', 'base_unit' => $base, 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'show_on_pos_grid' => $kind === 'finished', 'allow_price_override' => false, 'kind' => $kind]);
        return ['id' => $id, 'unit' => $svc->addUnit($id, $unit, (string) $factor, $fraction, true)];
    };
    $sugar = $mk('Sugar', 'material', 'g', 'kg', 1000, true);
    $box = $mk('Box', 'material', 'piece', 'Piece', 1, false);
    $mix = $mk('Marshmallow mix', 'intermediate', 'g', 'kg', 1000, true);
    $joy = $mk('Joy 100g', 'finished', 'piece', 'Piece', 1, false);
    $svc->setPrices($joy['unit'], '3', '2.50');
    $svc->savePackaging($joy['id'], [['material_id' => $box['id'], 'qty' => '1', 'unit_id' => $box['unit']]]);
    $supplier = (new PartyService())->saveSupplier(0, 'Sugar Co', '', '', true);
    $customer = (new PartyService())->saveCustomer(0, ['name' => 'Spinneys', 'phone' => '', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '']);
    // 1. bought 20 kg of sugar at $1 and 100 boxes at $0.10 = $30, $5 paid now: suppliers owed $25, bank −5, stock +30
    (new PurchaseService())->create($supplier, 'SC-1', date('Y-m-d'), [['product_id' => $sugar['id'], 'unit_id' => $sugar['unit'], 'qty' => '20', 'unit_cost' => '1'], ['product_id' => $box['id'], 'unit_id' => $box['unit'], 'qty' => '100', 'unit_cost' => '0.1']], '5', 'outside', '', TEST_ADMIN_ID);
    // 2. 10 kg of mix from 10 kg of sugar ($10), packed into 100 Joy with 100 boxes: stock stays $30, split differently
    $recipe = (new RecipeService())->save(0, ['product_id' => $mix['id'], 'name' => 'Plain', 'output_qty' => '1', 'output_unit_id' => $mix['unit'], 'lines' => [['material_id' => $sugar['id'], 'qty' => '1', 'unit_id' => $sugar['unit']]]], TEST_ADMIN_ID);
    (new ProductionService())->run($recipe, '10', 0, '', TEST_ADMIN_ID);
    (new PackingService())->pack([['product_id' => $mix['id'], 'qty' => '10', 'unit_id' => $mix['unit']]], [['product_id' => $joy['id'], 'qty' => '100', 'unit_id' => $joy['unit']]], '', TEST_ADMIN_ID);
    // 3. the till: $100 float, 20 Joy sold for $60 cash (cost 20 × $0.20 = $4), water $10 from the drawer
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
    (new SaleService())->complete(['register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
        'lines' => [['product_id' => $joy['id'], 'unit_id' => $joy['unit'], 'qty' => '20']], 'invoice_discount' => '', 'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '60']], 'change_currency' => 'USD', 'notes' => '', 'pin' => '']);
    (new ExpenseService())->create(['kind' => 'expense', 'category' => 'Water', 'description' => '', 'usd' => '10', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'drawer'], TEST_ADMIN_ID);
    // 4. an invoice on credit: 40 Joy at $2.50 = $100 (cost $8); $30 paid by transfer
    (new SaleService())->issue(['user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale', 'lines' => [['product_id' => $joy['id'], 'unit_id' => $joy['unit'], 'qty' => '40']],
        'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '']);
    (new InvoiceService())->recordPayment($customer, '30', '', 'transfer', 'TRF 1', '', TEST_ADMIN_ID);
    // 5. rent $500 paid outside; the drawer counted: $150 expected ($100 + $60 − $10), $150 counted
    (new ExpenseService())->create(['kind' => 'expense', 'category' => 'Rent', 'description' => '', 'usd' => '500', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside'], TEST_ADMIN_ID);
    (new CashService())->close($session, [100 => 1, 50 => 1], [], TEST_ADMIN_ID, true);

    return ['customer' => $customer, 'supplier' => $supplier, 'joy' => $joy, 'register' => $register];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the income statement: sales 160, cost of goods sold 12, water 10, rent 500 → a loss of 362' => function () use ($month): void {
        $month();
        $a = new AccountingService();
        $is = $a->incomeStatement(date('Y-m-01'), date('Y-m-d'));
        assert_same(['160.00', '0.00', '12.00', '148.00', '510.00', '-362.00'], [$is['sales'], $is['returns'], $is['cogs'], $is['gross'], $is['total_expenses'], $is['net']]);
        assert_same([['6101', 'Rent', '500.00'], ['6102', 'Water', '10.00']], array_map(static fn (array $x): array => [$x['code'], $x['name'], $x['amount']], $is['expenses']));
    },

    'the balance sheet balances, with and without typed openings; the trial balance too; the cash flow agrees with both' => function () use ($month): void {
        $s = $month();
        $a = new AccountingService();
        $today = date('Y-m-d');
        // nothing typed yet: cash 0 (the drawer was counted), bank = counted 150 − float 100 + transfer 30 − paid now 5 − rent 500 = −425,
        // customers 70, stock 18 (10 kg of sugar at $1 + 40 Joy at $0.20), suppliers 25
        $bs = $a->balanceSheet($today);
        $get = static fn (array $side, string $code): string => array_values(array_filter($side, static fn (array $r): bool => $r['code'] === $code))[0]['amount'] ?? 'none';
        assert_same(['0.00', '-425.00', '70.00', '18.00'], [$get($bs['assets'], '1000'), $get($bs['assets'], '1100'), $get($bs['assets'], '1200'), $get($bs['assets'], '1300')], 'cash, bank, customers, stock');
        assert_same('25.00', $get($bs['liabilities'], '2000'));
        assert_same(['0.00', '-362.00'], [$get($bs['equity'], '3000'), $get($bs['equity'], '3200')]);
        assert_same($bs['total_assets'], $bs['total_right'], 'assets = liabilities + capital');
        assert_same('0.00', $bs['difference'], 'nothing unexplained: everything came from the records');
        assert_same('none', $get($bs['equity'], '3900'), 'no Differences line when there is none');
        // the owner types the openings: bank 2,000, equipment 8,000, loan 5,000, capital 5,000 (they balance among themselves)
        $ob = new OpeningBalance();
        foreach (['1100' => '2000', '1500' => '8000', '2100' => '5000', '3000' => '5000'] as $code => $amount) {
            $ob->set($code, $amount, TEST_ADMIN_ID);
        }
        $bs = $a->balanceSheet($today);
        assert_same(['1575.00', '8000.00', '5000.00', '5000.00'], [$get($bs['assets'], '1100'), $get($bs['assets'], '1500'), $get($bs['liabilities'], '2100'), $get($bs['equity'], '3000')]);
        assert_same($bs['total_assets'], $bs['total_right']);
        assert_same('0.00', $bs['difference']);
        // a wrong typing shows up as Differences, never hidden
        $ob->set('3000', '6000', TEST_ADMIN_ID);
        $bs = $a->balanceSheet($today);
        assert_same('-1000.00', $get($bs['equity'], '3900'));
        assert_same($bs['total_assets'], $bs['total_right'], 'still balanced, thanks to the Differences line');
        $ob->set('3000', '5000', TEST_ADMIN_ID);

        $tb = $a->trialBalance($today);
        assert_true($tb['balanced'], 'debits = credits');
        assert_same($tb['total_debit'], $tb['total_credit']);
        $row = static fn (string $code): array => array_values(array_filter($tb['rows'], static fn (array $r): bool => $r['code'] === $code))[0] ?? ['debit' => 'none', 'credit' => 'none'];
        assert_same(['1575.00', ''], [$row('1100')['debit'], $row('1100')['credit']]);
        assert_same(['', '25.00'], [$row('2000')['debit'], $row('2000')['credit']]);
        assert_same(['', '160.00'], [$row('4000')['debit'], $row('4000')['credit']]);
        assert_same(['12.00', ''], [$row('5000')['debit'], $row('5000')['credit']]);
        assert_same(['500.00', ''], [$row('6101')['debit'], $row('6101')['credit']]);
        assert_same('none', $row('1000')['debit'], 'an account at zero is not listed');

        $cf = $a->cashFlow(date('Y-m-01'), $today);
        assert_same(['0.00', '0.00', '2000.00', '1575.00'], [$cf['drawers']['opening'], $cf['drawers']['closing'], $cf['bank']['opening'], $cf['bank']['closing']]);
        assert_same('-425.00', $cf['net_change']);
        $what = static fn (array $side): array => array_combine(array_column($side, 'what'), array_column($side, 'amount'));
        assert_same(['Cash sales' => '60.00', 'Cash put in' => '100.00'], $what($cf['drawers']['in']));
        assert_same(['Expenses paid from the drawer' => '10.00', 'Cash counted and taken at closing' => '150.00'], $what($cf['drawers']['out']));
        assert_same(['Customer payments by card, Whish, transfer, cheque' => '30.00', 'Cash counted and taken from the drawers' => '150.00'], $what($cf['bank']['in']));
        assert_same(['Floats put into the drawers' => '100.00', 'Expenses paid outside the drawer' => '500.00', 'Supplier payments' => '5.00'], $what($cf['bank']['out']));
    },

    'the fiscal year: the period profit runs from its first month; earlier profit is retained earnings' => function () use ($month): void {
        $month();
        (new Setting())->setMany(['fiscal_year_start_month' => '1']);
        Settings::flush();
        $a = new AccountingService();
        assert_same(date('Y') . '-01-01', $a->fiscalYearStart(date('Y-m-d')));
        $next = (int) date('n') === 12 ? 1 : (int) date('n') + 1;   // a year starting next month: this month belongs to the year before
        (new Setting())->setMany(['fiscal_year_start_month' => (string) $next]);
        Settings::flush();
        $a = new AccountingService();
        assert_same(sprintf('%04d-%02d-01', (int) date('Y') - 1, $next), $a->fiscalYearStart(date('Y-m-d')));
        $bs = $a->balanceSheet(date('Y-m-d'));
        assert_same('-362.00', array_values(array_filter($bs['equity'], static fn (array $r): bool => $r['code'] === '3200'))[0]['amount'], 'still the period');
        (new Setting())->setMany(['fiscal_year_start_month' => '1']);
        Settings::flush();
    },

    'the pages: the four report tabs, print and PDF; the Opening balances page saves; a cashier is kept out' => function () use ($month): void {
        $month();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        foreach (['income' => ['Income statement', 'Net loss', '$362.00'], 'cashflow' => ['Cash flow', 'Cash counted and taken at closing', '$150.00'], 'balance' => ['Balance sheet', 'Total assets', 'Opening balances and fiscal year'], 'trial' => ['Trial balance', 'Cost of goods sold', 'The two columns are equal']] as $type => $needles) {
            $page = $client->get('reports', ['type' => $type])->body;
            foreach ($needles as $n) {
                assert_contains($n, $page, "{$type}: {$n}");
            }
            assert_same(200, $client->get('reports', ['type' => $type, 'print' => 1])->status, "{$type} print");
            $pdf = $client->get('reports', ['type' => $type, 'pdf' => 1]);
            assert_same(['200', '%PDF'], [(string) $pdf->status, substr($pdf->body, 0, 4)], "{$type} pdf");
        }
        $page = $client->get('accounts/opening')->body;
        assert_contains('name="ob_1100"', $page);
        assert_contains('name="fiscal_year_start_month"', $page);
        $r = $client->post('accounts/opening-save', ['ob_1100' => '2,000', 'ob_1500' => '8000', 'ob_1600' => '', 'ob_2100' => '5000', 'ob_2200' => '', 'ob_3000' => '5000', 'fiscal_year_start_month' => '1']);
        assert_same(302, $r->status, $r->body);
        assert_same(['2000.00', '8000.00', '0.00', '5000.00'], [(new OpeningBalance())->all()['1100'], (new OpeningBalance())->all()['1500'], (new OpeningBalance())->all()['1600'], (new OpeningBalance())->all()['2100']]);
        assert_contains('$9,663.00', $client->get('reports', ['type' => 'balance'])->body, 'total assets: bank 1,575 + equipment 8,000 + customers 70 + stock 18');
        $bad = $client->post('accounts/opening-save', ['ob_1100' => 'abc', 'fiscal_year_start_month' => '1']);
        assert_same(302, $bad->status);
        assert_contains('alert-danger', $client->get('accounts/opening')->body, 'the refusal is shown');
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        assert_same(403, $cashier->get('accounts/opening')->status);
        assert_same(403, $cashier->get('reports', ['type' => 'trial'])->status);
    },
];
