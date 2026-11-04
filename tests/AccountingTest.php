<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Models\Account;
use App\Models\OpeningBalance;
use App\Models\Register;
use App\Models\Setting;
use App\Services\AccountingService;
use App\Services\CashService;
use App\Services\ChartService;
use App\Services\ExpenseService;
use App\Services\InvoiceService;
use App\Services\JournalService;
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

/** The id of a built-in account by its key ('1100'). */
$acct = static fn (string $key): int => (int) Database::pdo()->query('SELECT id FROM accounts WHERE system_key = ' . Database::pdo()->quote($key))->fetchColumn();
/** The chart form as it is now: num[id] and name[id] of every account but the expense categories. */
$chartForm = static function (): array {
    $num = [];
    $name = [];
    foreach (Database::pdo()->query("SELECT id, number, name FROM accounts WHERE system_key IS NULL OR system_key NOT LIKE 'exp:%'")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $num[(int) $r['id']] = $r['number'];
        $name[(int) $r['id']] = $r['name'];
    }

    return [$num, $name];
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
        assert_same([['6101', 'Water', '10.00'], ['6102', 'Rent', '500.00']], array_map(static fn (array $x): array => [$x['code'], $x['name'], $x['amount']], $is['expenses']));
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
        assert_same(['500.00', ''], [$row('6102')['debit'], $row('6102')['credit']], 'rent: the second category used');
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

    "the owner's own chart: numbers and names on every statement, a number used twice is refused, a new category takes the next free number" => function () use ($month, $acct, $chartForm): void {
        $month();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $bankId = $acct('1100');
        $page = $client->get('accounts')->body;
        assert_contains('name="num[' . $bankId . ']"', $page);
        assert_contains('name="name[' . $bankId . ']"', $page);
        assert_contains('name="exp_cat[]" value="Water"', $page, 'the expense categories used are listed');
        [$num, $name] = $chartForm();
        $num[$bankId] = '512';
        $name[$bankId] = 'Banque';
        $r = $client->post('accounts/chart-save', ['num' => $num, 'name' => $name, 'exp_cat' => ['Water', 'Rent'], 'exp_num' => ['6101', '613']]);
        assert_same(302, $r->status, $r->body);
        assert_same(['512', 'Banque'], [(new Account())->find($bankId)['number'], (new Account())->find($bankId)['name']]);

        $svc = new AccountingService();
        $bank = array_values(array_filter($svc->balanceSheet(date('Y-m-d'))['assets'], static fn (array $a): bool => $a['key'] === '1100'))[0];
        assert_same(['512', 'Banque', '-425.00'], [$bank['code'], $bank['name'], $bank['amount']], 'same figure, the owner\'s number and name');
        $tb = $svc->trialBalance(date('Y-m-d'));
        assert_true($tb['balanced']);
        $codes = array_column($tb['rows'], 'code');
        $sorted = $codes;
        sort($sorted, SORT_STRING);
        assert_same($sorted, $codes, 'the trial balance follows the owner\'s numbers digit by digit, by class');
        assert_same(['1300', '512', '6101'], array_slice(array_values(array_intersect($codes, ['1300', '512', '6101'])), 0, 3), 'class 5 after class 1, before class 6');
        $rent = array_values(array_filter($tb['rows'], static fn (array $x): bool => $x['name'] === 'Rent'))[0];
        assert_same(['613', '500.00'], [$rent['code'], $rent['debit']]);
        $trial = $client->get('reports', ['type' => 'trial'])->body;
        assert_contains('Banque', $trial);
        assert_contains('613', $trial);

        // a number used twice: refused, nothing changes
        $equipmentId = $acct('1500');
        $num[$equipmentId] = '512';
        $client->post('accounts/chart-save', ['num' => $num, 'name' => $name, 'exp_cat' => ['Water', 'Rent'], 'exp_num' => ['6101', '613']]);
        assert_contains('alert-danger', $client->get('accounts')->body, 'the refusal is shown');
        assert_same('1500', (new Account())->find($equipmentId)['number'], 'equipment keeps its number');
        // a name left empty: refused
        $num[$equipmentId] = '1500';
        $name[$acct('2100')] = '  ';
        $client->post('accounts/chart-save', ['num' => $num, 'name' => $name, 'exp_cat' => ['Water', 'Rent'], 'exp_num' => ['6101', '613']]);
        assert_same('Loans', (new Account())->find($acct('2100'))['name']);

        // a new category: the first free number from 6101 (6101 is Water; Rent moved to 613, so 6102 is free)
        (new ExpenseService())->create(['kind' => 'expense', 'category' => 'Electricity', 'description' => '', 'usd' => '40', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside'], TEST_ADMIN_ID);
        $x = array_map(static fn (array $e): array => [$e['code'], $e['name']], (new AccountingService())->incomeStatement(date('Y-m-01'), date('Y-m-d'))['expenses']);
        assert_same([['6101', 'Water'], ['6102', 'Electricity'], ['613', 'Rent']], $x, 'by number; the new category takes 6102');
    },

    'the business grows: his own accounts, journal entries that balance, a second money account, a reversal; every statement still balances' => function () use ($month, $acct): void {
        $month();
        $today = date('Y-m-d');
        $first = date('Y-m-01');
        $chart = new ChartService();
        $vehicles = $chart->add('1510', 'Vehicles', 'asset', false, TEST_ADMIN_ID);
        $vanLoan = $chart->add('2110', 'Van loan', 'liability', false, TEST_ADMIN_ID);
        $depreciation = $chart->add('6810', 'Depreciation', 'expense', false, TEST_ADMIN_ID);
        $accumulated = $chart->add('1590', 'Accumulated depreciation', 'asset', false, TEST_ADMIN_ID);
        $interest = $chart->add('7100', 'Interest received', 'income', false, TEST_ADMIN_ID);
        $whish = $chart->add('5122', 'Whish', 'asset', true, TEST_ADMIN_ID);
        foreach ([['1510', 'Again', 'asset', 'taken by Vehicles'], ['1100', 'Bank 2', 'asset', 'taken by a built-in account'], ['6101', 'X', 'expense', 'taken by the Water category'],
                  ['1700', 'X', 'nonsense', 'a type'], ['1701', ' ', 'asset', 'a name'], ['12 34', 'X', 'asset', 'a number with a space']] as [$n, $nm, $t, $why]) {
            assert_throws(\DomainException::class, static fn () => $chart->add($n, $nm, $t, false, TEST_ADMIN_ID), 'refused: ' . $why);
        }

        $j = new JournalService();
        $line = static fn (int $account, string $debit, string $credit): array => ['account_id' => $account, 'debit' => $debit, 'credit' => $credit];
        $j->post($today, 'Van bought on a loan', [$line($vehicles, '15,000', ''), $line($vanLoan, '', '15000')], TEST_ADMIN_ID);
        $j->post($today, 'Depreciation of the van', [$line($depreciation, '250', ''), $line($accumulated, '', '250')], TEST_ADMIN_ID);
        $loanPayment = $j->post($today, 'First loan payment', [$line($vanLoan, '500', ''), $line($acct('1100'), '', '500')], TEST_ADMIN_ID);
        $j->post($today, 'Money moved to Whish', [$line($whish, '100', ''), $line($acct('1100'), '', '100')], TEST_ADMIN_ID);
        $j->post($today, 'Interest from the bank', [$line($acct('1100'), '5', ''), $line($interest, '', '5')], TEST_ADMIN_ID);
        foreach ([
            'debits 100, credits 90' => [$line($vehicles, '100', ''), $line($vanLoan, '', '90')],
            'one line only' => [$line($vehicles, '100', '')],
            'the stock is fed by the stock movements' => [$line($acct('1300'), '100', ''), $line($vanLoan, '', '100')],
            'sales come from the invoices' => [$line($acct('1100'), '100', ''), $line($acct('4000'), '', '100')],
            'both sides on one line' => [$line($vehicles, '100', '100'), $line($vanLoan, '', '100')],
            'a negative amount' => [$line($vehicles, '-100', ''), $line($vanLoan, '', '-100')],
        ] as $why => $lines) {
            assert_throws(\DomainException::class, static fn () => $j->post($today, 'Bad', $lines, TEST_ADMIN_ID), 'refused: ' . $why);
        }
        assert_throws(\DomainException::class, static fn () => $j->post($today, '', [$line($vehicles, '1', ''), $line($vanLoan, '', '1')], TEST_ADMIN_ID), 'refused: no description');

        $svc = new AccountingService();
        $is = $svc->incomeStatement($first, $today);
        assert_same('-607.00', $is['net'], '−362 as before, − 250 depreciation + 5 interest');
        assert_same([['7100', 'Interest received', '5.00']], array_map(static fn (array $x): array => [$x['code'], $x['name'], $x['amount']], $is['other_income']));
        assert_true(in_array(['6810', 'Depreciation', '250.00'], array_map(static fn (array $x): array => [$x['code'], $x['name'], $x['amount']], $is['expenses']), true), 'depreciation among the expenses');

        $bs = $svc->balanceSheet($today);
        $all = array_merge($bs['assets'], $bs['liabilities'], $bs['equity']);
        $get = static function (array $all, string $code): string {
            return array_values(array_filter($all, static fn (array $r): bool => $r['code'] === $code))[0]['amount'] ?? 'none';
        };
        assert_same(['-1020.00', '15000.00', '-250.00', '100.00', '14500.00'], [$get($all, '1100'), $get($all, '1510'), $get($all, '1590'), $get($all, '5122'), $get($all, '2110')], 'bank −425 − 500 − 100 + 5; the van; its depreciation; Whish; the loan left');
        assert_same('none', $get($all, '3900'), 'no Differences: every entry balances');
        assert_same($bs['total_assets'], $bs['total_right']);

        $tb = $svc->trialBalance($today);
        assert_true($tb['balanced'], 'debits = credits');
        $row = static fn (string $code): array => array_values(array_filter($tb['rows'], static fn (array $r): bool => $r['code'] === $code))[0] ?? ['debit' => 'none', 'credit' => 'none'];
        assert_same([['15000.00', ''], ['', '250.00'], ['', '14500.00'], ['', '5.00'], ['250.00', '']], [[$row('1510')['debit'], $row('1510')['credit']], [$row('1590')['debit'], $row('1590')['credit']], [$row('2110')['debit'], $row('2110')['credit']], [$row('7100')['debit'], $row('7100')['credit']], [$row('6810')['debit'], $row('6810')['credit']]]);

        $cf = $svc->cashFlow($first, $today);
        $bankIn = array_column($cf['bank']['in'], 'amount', 'what');
        $bankOut = array_column($cf['bank']['out'], 'amount', 'what');
        assert_same(['5.00', '600.00', '-1020.00'], [$bankIn['Journal entries'] ?? 'none', $bankOut['Journal entries'] ?? 'none', $cf['bank']['closing']]);
        $box = array_values(array_filter($cf['boxes'], static fn (array $b): bool => $b['title'] === '5122 Whish'))[0] ?? null;
        assert_true($box !== null, 'Whish has its own box in the cash flow');
        assert_same(['0.00', '100.00', '100.00'], [$box['opening'], $box['total_in'], $box['closing']]);
        assert_same('-920.00', $cf['closing_total'], 'drawers 0 + bank −1,020 + Whish 100: the move to Whish changes nothing in all cash');

        // a reversal undoes the loan payment; once only; a reversal is not reversed
        $rev = $j->reverse($loanPayment, TEST_ADMIN_ID);
        $bs = (new AccountingService())->balanceSheet($today);
        $all = array_merge($bs['assets'], $bs['liabilities'], $bs['equity']);
        assert_same(['-520.00', '15000.00'], [$get($all, '1100'), $get($all, '2110')], 'the bank and the loan as before the payment');
        assert_same($bs['total_assets'], $bs['total_right']);
        assert_throws(\DomainException::class, static fn () => $j->reverse($loanPayment, TEST_ADMIN_ID), 'reversed once only');
        assert_throws(\DomainException::class, static fn () => $j->reverse($rev, TEST_ADMIN_ID), 'a reversal is not reversed');

        // an account with entries stays; an unused one can go; a built-in one never goes
        assert_throws(\DomainException::class, static fn () => $chart->delete($vehicles), 'it has entries');
        $spare = $chart->add('1520', 'Furniture', 'asset', false, TEST_ADMIN_ID);
        $chart->delete($spare);
        assert_same(null, (new Account())->find($spare));
        assert_throws(\DomainException::class, static fn () => $chart->delete($acct('1500')), 'built-in');
    },

    'the pages: the chart, the journal, opening balances; a closed account takes no entry; a cashier is kept out' => function () use ($month, $acct, $chartForm): void {
        $month();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('r=accounts"', $client->get('dashboard')->body, 'Accounts in the menu');
        $r = $client->post('accounts/add', ['number' => '5121', 'name' => 'Bank Audi', 'type' => 'asset', 'is_money' => '1']);
        assert_same(302, $r->status, $r->body);
        $audi = (int) (new Account())->byNumber('5121')['id'];
        assert_same(1, (int) (new Account())->find($audi)['is_money']);
        $page = $client->get('accounts')->body;
        assert_contains('Bank Audi', $page);
        assert_contains('name="closed[' . $audi . ']"', $page, 'his own account can be closed');

        $journal = $client->get('accounts/journal')->body;
        assert_contains('name="line_account[]"', $journal);
        assert_contains('5121 · Bank Audi', $journal, 'his account is offered');
        assert_true(!str_contains($journal, '<option value="' . $acct('1300') . '"'), 'the stock account is not offered');
        $r = $client->post('accounts/journal-save', ['entry_date' => date('Y-m-d'), 'description' => 'Opened the Audi account',
            'line_account' => [$audi, $acct('1100'), ''], 'line_debit' => ['1,000', '', ''], 'line_credit' => ['', '1000', '']]);
        assert_same(302, $r->status, $r->body);
        $entry = (int) Database::pdo()->query("SELECT id FROM journal_entries WHERE description = 'Opened the Audi account'")->fetchColumn();
        assert_true($entry > 0, 'the entry is saved; the empty third line is skipped');
        assert_contains('Opened the Audi account', $client->get('accounts/journal')->body);
        $bad = $client->post('accounts/journal-save', ['entry_date' => date('Y-m-d'), 'description' => 'Bad', 'line_account' => [$audi, $acct('1100')], 'line_debit' => ['10', ''], 'line_credit' => ['', '9']]);
        assert_same(302, $bad->status);
        assert_contains('alert-danger', $client->get('accounts/journal')->body, 'an unbalanced entry is refused and said');
        assert_same(302, $client->post('accounts/journal-reverse', ['id' => $entry])->status);
        assert_same(1, (int) Database::pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE reverses_id = ' . $entry)->fetchColumn(), 'reversed');

        // closing his account: no more entries on it
        [$num, $name] = $chartForm();
        assert_same(302, $client->post('accounts/chart-save', ['num' => $num, 'name' => $name, 'closed' => [$audi => '1'], 'exp_cat' => ['Water', 'Rent'], 'exp_num' => ['6101', '6102']])->status);
        assert_same(0, (int) (new Account())->find($audi)['is_active']);
        assert_true(!str_contains($client->get('accounts/journal')->body, '5121 · Bank Audi</option>'), 'a closed account is not offered');
        assert_throws(\DomainException::class, static fn () => (new JournalService())->post(date('Y-m-d'), 'X', [['account_id' => $audi, 'debit' => '1', 'credit' => ''], ['account_id' => $acct('1100'), 'debit' => '', 'credit' => '1']], TEST_ADMIN_ID), 'closed');

        assert_contains('name="ob_1100"', $client->get('accounts/opening')->body);
        // Back returns to the report the page was opened from (2026-11-04)
        $balance = $client->get('reports', ['type' => 'balance'])->body;
        assert_contains('back=reports%3Ftype%3Dbalance', $balance, 'the report link says where it comes from');
        $opening = $client->get('accounts/opening', ['back' => 'reports?type=balance&from=2026-10-01&to=2026-10-31'])->body;
        assert_contains('class="back-link" href="index.php?r=reports&amp;type=balance&amp;from=2026-10-01&amp;to=2026-10-31"', $opening, 'Back goes to the balance sheet');
        assert_contains('name="back" value="reports?type=balance', $opening, 'Save keeps it');
        assert_contains('class="back-link" href="index.php?r=accounts"', $client->get('accounts/opening')->body, 'without it, Back goes to the Accounts section');
        assert_contains('class="back-link" href="index.php?r=accounts"', $client->get('accounts/opening', ['back' => 'http://evil.example/x'])->body, 'an outside address is ignored');
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        foreach (['accounts', 'accounts/journal', 'accounts/opening'] as $route) {
            assert_same(403, $cashier->get($route)->status, $route);
        }
        assert_same(403, $cashier->post('accounts/journal-save', ['entry_date' => date('Y-m-d'), 'description' => 'X'])->status);
    },
];
