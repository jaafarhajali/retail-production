<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Services\InvoiceService;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/** Desk invoices, step 2 (2026-10-14): payments received at the desk, the statement of account, the aging report. */
$setup = static function (): array {
    $svc = new ProductService();
    $joy = make_product('Joy 100g', 'piece');
    $piece = $svc->addUnit($joy, 'Piece', '1', false, true);
    $svc->setPrices($piece, '3', '2.5');
    (new StockService())->adjust($joy, $piece, '1000', 'opening', '', null, TEST_ADMIN_ID);
    $parties = new PartyService();
    $market = $parties->saveCustomer(0, ['name' => 'Al Amiliya', 'phone' => '', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '', 'payment_terms_days' => '30']);
    $shop = $parties->saveCustomer(0, ['name' => 'Corner shop', 'phone' => '', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '']);
    $issue = static fn (int $customer, string $pieces, string $due = ''): array => (new SaleService())->issue([
        'user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale', 'lines' => [['product_id' => $joy, 'unit_id' => $piece, 'qty' => $pieces]], 'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official', 'due_date' => $due, 'ship_to' => '',
    ]);

    return ['joy' => $joy, 'piece' => $piece, 'market' => $market, 'shop' => $shop, 'issue' => $issue];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a payment at the desk: USD and LBP at the rate, the method and the reference on the ledger, applied to the oldest invoice; cash needs an open session' => function () use ($setup): void {
        $s = $setup();
        $s['issue']($s['market'], '40');   // $100
        $s['issue']($s['market'], '20');   // $50
        $svc = new InvoiceService();
        $r = $svc->recordPayment($s['market'], '100', '', 'transfer', 'TRF 4471', 'October', TEST_ADMIN_ID);
        assert_same(['100.00', '50.00'], [$r['paid_usd'], $r['balance']]);
        $row = Database::pdo()->query("SELECT type, amount_usd, currency, method, reference, note, session_id FROM customer_ledger WHERE type = 'payment'")->fetch(PDO::FETCH_ASSOC);
        assert_same(['type' => 'payment', 'amount_usd' => '-100.00', 'currency' => 'USD', 'method' => 'transfer', 'reference' => 'TRF 4471', 'note' => 'October', 'session_id' => null], $row);
        $o = $svc->outstanding($s['market']);
        assert_same([['paid', '0.00'], ['unpaid', '50.00']], array_map(static fn (array $i): array => [$i['status'], $i['remaining']], $o['items']));
        // 900,000 LBP at 90,000 = $10, by cheque
        $r = $svc->recordPayment($s['market'], '', '900000', 'cheque', '0012', '', TEST_ADMIN_ID);
        assert_same(['10.00', '40.00'], [$r['paid_usd'], $r['balance']]);
        assert_same(['LBP', '900000.00', '-10.00'], Database::pdo()->query("SELECT currency, amount_original, amount_usd FROM customer_ledger WHERE method = 'cheque'")->fetch(PDO::FETCH_NUM));
        assert_same('partly', $svc->outstanding($s['market'])['items'][1]['status']);
        assert_same(2, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'payment.received'")->fetchColumn());
        // refused: cash (the till), nothing typed, no customer
        $e = assert_throws(DomainException::class, fn () => $svc->recordPayment($s['market'], '5', '', 'cash', '', '', TEST_ADMIN_ID));
        assert_contains('Open one first', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->recordPayment($s['market'], '', '', 'transfer', '', '', TEST_ADMIN_ID));
        assert_contains('Type what was received', $e->getMessage());
        assert_throws(DomainException::class, fn () => $svc->recordPayment(0, '5', '', 'transfer', '', '', TEST_ADMIN_ID));
        assert_same('40.00', (new Customer())->balance($s['market']));
    },

    'cash on delivery (2026-10-17): recorded at the desk when the owner has a cash session open; it goes into that drawer' => function () use ($setup): void {
        $s = $setup();
        $s['issue']($s['shop'], '40');   // $100
        $svc = new InvoiceService();
        $e = assert_throws(DomainException::class, fn () => $svc->recordPayment($s['shop'], '30', '', 'cash', '', '', TEST_ADMIN_ID));
        assert_contains('Open one first', $e->getMessage());
        $register = (new \App\Models\Register())->create('Laptop');
        $session = (new \App\Services\CashService())->open($register, TEST_ADMIN_ID, '100', '0');
        $r = $svc->recordPayment($s['shop'], '30', '', 'cash', '', '', TEST_ADMIN_ID);
        assert_same(['30.00', '70.00'], [$r['paid_usd'], $r['balance']]);
        $row = Database::pdo()->query("SELECT amount_usd, session_id FROM customer_ledger WHERE type = 'payment'")->fetch(PDO::FETCH_ASSOC);
        assert_same(['amount_usd' => '-30.00', 'session_id' => $session], ['amount_usd' => $row['amount_usd'], 'session_id' => (int) $row['session_id']], 'the payment belongs to the session');
        assert_same(['debt_collection', 'USD', '30.00'], Database::pdo()->query("SELECT type, currency, amount FROM cash_movements WHERE type = 'debt_collection'")->fetch(PDO::FETCH_NUM), 'the cash is in the drawer');
        assert_same('130.00', (new \App\Models\CashSession())->expected($session)['USD']);
        assert_same('partly', $svc->outstanding($s['shop'])['items'][0]['status']);

        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $form = $client->get('invoices/pay', ['customer_id' => $s['shop']])->body;
        assert_contains('<option value="cash"', $form);
        assert_contains('>Card / Whish</option>', $form, 'Whish is a card payment in Lebanon');
        assert_not_contains('collected at the till', $form);
        assert_not_contains('Cheque', $form, 'cash and card only (2026-10-17)');
        assert_not_contains('Bank transfer</option>', $form);
    },

    'the statement: opening balance, the rows of the period with a running balance, the closing balance, the open invoices' => function () use ($setup): void {
        $s = $setup();
        $a = $s['issue']($s['shop'], '40');   // $100 today
        $svc = new InvoiceService();
        $svc->recordPayment($s['shop'], '30', '', 'transfer', 'T1', '', TEST_ADMIN_ID);
        $s['issue']($s['shop'], '10');   // $25
        // the period starts tomorrow: everything is in the opening balance, nothing in the rows
        $st = $svc->statement($s['shop'], date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+2 days')));
        assert_same(['95.00', [], '95.00', '95.00'], [$st['opening'], $st['rows'], $st['closing'], $st['balanceNow']]);
        // the period is today: opening 0, three rows, debits 125, credits 30, closing 95; two invoices open (the first partly paid)
        $st = $svc->statement($s['shop'], date('Y-m-d'), date('Y-m-d'));
        assert_same(['0.00', 3, '125.00', '30.00', '95.00'], [$st['opening'], count($st['rows']), $st['debits'], $st['credits'], $st['closing']]);
        assert_same(['Invoice INV-000001', 'Payment by bank transfer', 'Invoice INV-000002'], array_column($st['rows'], 'label'));
        assert_same('T1', $st['rows'][1]['reference']);
        assert_same([['INV-000001', '70.00', 'partly'], ['INV-000002', '25.00', 'unpaid']], array_map(static fn (array $i): array => [$i['invoice_no'], $i['remaining'], $i['status']], $st['open']));
    },

    'the aging: not yet due, 1–30, 31–60, over 60 days past due; a till credit is due after the terms' => function () use ($setup): void {
        $s = $setup();
        $s['issue']($s['market'], '40');                 // $100 due in 30 days: not yet due
        $s['issue']($s['market'], '20', '2026-10-01');   // $50, 13 days late on 2026-10-14
        $s['issue']($s['shop'], '10', '2026-08-20');     // $25, 55 days late
        $s['issue']($s['shop'], '4', '2026-06-01');      // $10, over 60
        (new Customer())->addLedger(['customer_id' => $s['shop'], 'type' => 'sale_credit', 'amount_usd' => '20.00', 'user_id' => TEST_ADMIN_ID, 'note' => 'till']);   // no due date: 45 days after today
        $rows = (new InvoiceService())->aging('2026-10-14');
        assert_same(['Al Amiliya', 'Corner shop'], array_column($rows, 'name'));
        assert_same(['100.00', '50.00', '0.00', '0.00', '150.00', 13], [$rows[0]['current'], $rows[0]['b30'], $rows[0]['b60'], $rows[0]['b90'], $rows[0]['total'], $rows[0]['oldest_days']]);
        assert_same(['20.00', '0.00', '25.00', '10.00', '55.00', 135], [$rows[1]['current'], $rows[1]['b30'], $rows[1]['b60'], $rows[1]['b90'], $rows[1]['total'], $rows[1]['oldest_days']]);
        // a payment of $35 clears the oldest two of the corner shop (the $25 and the $10), the till credit stays
        (new InvoiceService())->recordPayment($s['shop'], '35', '', 'card', '', '', TEST_ADMIN_ID);
        $rows = (new InvoiceService())->aging('2026-10-14');
        assert_same(['20.00', '0.00', '0.00', '20.00'], [$rows[1]['current'], $rows[1]['b60'], $rows[1]['b90'], $rows[1]['total']]);
    },

    'the pages: Record a payment with the open invoices, the statement print, the aging report, the links; a cashier may not' => function () use ($setup): void {
        $s = $setup();
        $s['issue']($s['market'], '40');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('invoices/pay', ['customer_id' => $s['market']])->body;
        assert_contains('Record the payment', $page);
        assert_contains('INV-000001', $page);
        assert_contains('$100.00', $page);
        $r = $client->post('invoices/pay-store', ['customer_id' => (string) $s['market'], 'usd' => '60', 'lbp' => '', 'method' => 'transfer', 'reference' => 'TRF 9', 'note' => '']);
        assert_same(302, $r->status, $r->body);
        $page = $client->get('invoices/pay', ['customer_id' => $s['market']])->body;
        assert_contains('$60.00 received', $page);
        assert_contains('Still owed: $40.00', $page);
        assert_contains('partly paid', $page);
        assert_contains('partly paid', $client->get('invoices')->body);
        $view = $client->get('sales/view', ['id' => 1])->body;
        assert_contains('Partly paid', $view);
        assert_contains('left $40.00', $view);
        $print = $client->get('customers/statement', ['id' => $s['market']])->body;
        foreach (['Statement of Account', 'Al Amiliya', 'Opening balance', 'Invoice INV-000001', 'Payment by bank transfer', 'TRF 9', 'Closing balance', '$40.00', 'Open invoices'] as $needle) {
            assert_contains($needle, $print, $needle);
        }
        $report = $client->get('reports', ['type' => 'aging'])->body;
        assert_contains('Customer debts', $report);
        assert_contains('Al Amiliya', $report);
        assert_contains('$40.00', $report);
        assert_same(200, $client->get('reports', ['type' => 'aging', 'print' => 1])->status);
        assert_contains('Statement of account', $client->get('customers/edit', ['id' => $s['market']])->body);
        // refused with the message, back on the form
        $r = $client->post('invoices/pay-store', ['customer_id' => (string) $s['market'], 'usd' => '', 'lbp' => '', 'method' => 'transfer', 'reference' => '', 'note' => '']);
        assert_same(302, $r->status);
        assert_contains('Type what was received', $client->get('invoices/pay', ['customer_id' => $s['market']])->body);
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        assert_same(403, $cashier->get('invoices/pay')->status);
        assert_same(403, $cashier->get('customers/statement', ['id' => $s['market']])->status);
    },
];
