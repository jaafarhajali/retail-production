<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Models\Product;
use App\Services\InvoiceService;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * Desk invoices (2026-10-14): a B2B invoice written by the owner without a cash session. The goods leave the stock,
 * the amount goes on the customer's account with a due date from their payment terms, and payments are applied to
 * the oldest invoice first.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $joy = make_product('Joy 100g', 'piece');
    $piece = $svc->addUnit($joy, 'Piece', '1', false, true);
    $box = $svc->addUnit($joy, 'Box', '27', false, false);
    $svc->setPrices($piece, '3', '2.5');
    $svc->setPrices($box, '81', '67.5');
    $svc->setCost($joy, '0.60', 1);
    (new StockService())->adjust($joy, $box, '10', 'opening', '', null, TEST_ADMIN_ID);
    $parties = new PartyService();
    $market = $parties->saveCustomer(0, ['name' => 'شركة متاجر العاملية ش.م.ل', 'phone' => '07/225999', 'tva_number' => '2941978-601', 'address' => 'صيدا - الغازية', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '', 'payment_terms_days' => '30']);
    $shop = $parties->saveCustomer(0, ['name' => 'Corner shop', 'phone' => '', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '']);

    return ['joy' => $joy, 'piece' => $piece, 'box' => $box, 'market' => $market, 'shop' => $shop];
};
$issue = static fn (array $s, int $customer, array $lines, array $over = []): array => (new SaleService())->issue($over + [
    'user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale', 'lines' => $lines, 'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '',
]);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'issuing: no session, stock out, the whole amount on the account, due after the customer\'s terms (30 days) or the default (45)' => function () use ($setup, $issue): void {
        $s = $setup();
        $r = $issue($s, $s['market'], [['product_id' => $s['joy'], 'unit_id' => $s['box'], 'qty' => '2', 'discount' => '5']], ['ship_to' => 'Branch 2, Saida']);
        assert_same(['INV-000001', '130.00', date('Y-m-d', strtotime('+30 days'))], [$r['invoice_no'], $r['total_usd'], $r['due_date']], '2 boxes at $67.50 less $5');
        $row = Database::pdo()->query("SELECT origin, session_id, register_id, status, due_date, ship_to, total_usd, invoice_type FROM sales WHERE id = {$r['id']}")->fetch(PDO::FETCH_ASSOC);
        assert_same(['origin' => 'desk', 'session_id' => null, 'register_id' => null, 'status' => 'completed', 'due_date' => date('Y-m-d', strtotime('+30 days')), 'ship_to' => 'Branch 2, Saida', 'total_usd' => '130.00', 'invoice_type' => 'official'], $row);
        assert_same(270 - 54, (int) (new Product())->find($s['joy'])['stock_base']);
        assert_same('130.00', (new Customer())->balance($s['market']));
        assert_same(['sale_credit', '130.00', null], Database::pdo()->query("SELECT type, amount_usd, session_id FROM customer_ledger WHERE sale_id = {$r['id']}")->fetch(PDO::FETCH_NUM));
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'invoice.issued'")->fetchColumn());
        // the default terms when the customer has none; a typed due date wins
        $r2 = $issue($s, $s['shop'], [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '10']]);
        assert_same(date('Y-m-d', strtotime('+45 days')), $r2['due_date']);
        $r3 = $issue($s, $s['shop'], [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '1']], ['due_date' => '2026-01-31']);
        assert_same('2026-01-31', $r3['due_date']);
        // rules: a customer is required; a bad date is refused; nothing written on refusal
        $e = assert_throws(DomainException::class, fn () => $issue($s, 0, [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '1']]));
        assert_contains('needs its customer', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $issue($s, $s['shop'], [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '1']], ['due_date' => 'soon']));
        assert_contains('due date', $e->getMessage());
        assert_same(3, (int) Database::pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    },

    'what is still owed, invoice by invoice: payments go to the oldest first; partly paid, paid, overdue' => function () use ($setup, $issue): void {
        $s = $setup();
        $a = $issue($s, $s['shop'], [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '40']]);                                   // $100, due in 45 days
        $b = $issue($s, $s['shop'], [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '20']], ['due_date' => '2026-01-31']);    // $50, overdue
        $svc = new InvoiceService();
        $o = $svc->outstanding($s['shop']);
        assert_same([['INV-000001', '100.00', '100.00', 'unpaid'], ['INV-000002', '50.00', '50.00', 'overdue']], array_map(static fn (array $i): array => [$i['invoice_no'], $i['amount'], $i['remaining'], $i['status']], $o['items']));
        // $120 paid: the first is paid, the second has $30 left
        (new Customer())->addLedger(['customer_id' => $s['shop'], 'type' => 'payment', 'amount_usd' => '-120.00', 'currency' => 'USD', 'amount_original' => '120.00', 'user_id' => TEST_ADMIN_ID, 'note' => 'transfer']);
        $o = $svc->outstanding($s['shop']);
        assert_same([['100.00', '0.00', 'paid'], ['20.00', '30.00', 'overdue']], array_map(static fn (array $i): array => [$i['paid'], $i['remaining'], $i['status']], $o['items']));
        assert_same('0.00', $o['unapplied']);
        // a return credit counts as a payment; paying more than owed is kept for the next invoice
        (new Customer())->addLedger(['customer_id' => $s['shop'], 'type' => 'return_credit', 'amount_usd' => '-10.00', 'user_id' => TEST_ADMIN_ID]);
        (new Customer())->addLedger(['customer_id' => $s['shop'], 'type' => 'payment', 'amount_usd' => '-25.00', 'user_id' => TEST_ADMIN_ID]);
        $o = $svc->outstanding($s['shop']);
        assert_same(['paid', 'paid', '5.00'], [$o['items'][0]['status'], $o['items'][1]['status'], $o['unapplied']]);
        $c = $issue($s, $s['shop'], [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '4']]);   // $10: the $5 carried over is used at once
        $o = $svc->outstanding($s['shop']);
        assert_same(['10.00', '5.00', 'partly', '0.00'], [$o['items'][2]['amount'], $o['items'][2]['remaining'], $o['items'][2]['status'], $o['unapplied']]);
        assert_same([(int) $a['id'], (int) $b['id'], (int) $c['id']], array_keys($svc->forSales([$s['shop'], $s['market']])));
        assert_same('5.00', (new Customer())->balance($s['shop']), 'the ledger and the items agree');
    },

    'the pages: the list with its statuses, the New invoice screen, issuing from the form, the view and the print with the due date; a cashier may not' => function () use ($setup, $issue): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('invoices')->body;
        assert_contains('New invoice', $page);
        assert_contains('No invoices here', $page);
        $page = $client->get('invoices/new')->body;
        assert_contains('Issue the invoice', $page);
        assert_contains('شركة متاجر العاملية', $page);
        assert_contains('data-terms="30"', $page);
        assert_contains('"wholesale":"67.50"', $page);
        $r = $client->post('invoices/store', ['customer_id' => (string) $s['market'], 'price_level' => 'wholesale', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '', 'notes' => 'leave at the back door', 'invoice_discount' => '10',
            'lines' => [['product_id' => (string) $s['joy'], 'unit_id' => (string) $s['box'], 'qty' => '3', 'pct' => '', 'discount' => ''], ['product_id' => '', 'unit_id' => '', 'qty' => '', 'pct' => '', 'discount' => '']]]);
        assert_same(302, $r->status, $r->body);
        assert_contains('r=sales/view', $r->location());
        $id = (int) Database::pdo()->query("SELECT id FROM sales WHERE origin = 'desk'")->fetchColumn();
        assert_same('192.50', Database::pdo()->query("SELECT total_usd FROM sales WHERE id = {$id}")->fetchColumn(), '3 × $67.50 − $10');
        $view = $client->get('sales/view', ['id' => $id])->body;
        assert_not_contains('Change given', $view, 'a desk invoice has no drawer, so no change row (2026-10-17)');
        assert_contains('Desk invoice', $view);
        assert_contains('Due', $view);
        assert_contains(date('d/m/Y', strtotime('+30 days')), $view);
        $print = $client->get('sales/print', ['id' => $id])->body;
        assert_contains(date('Y-m-d', strtotime('+30 days')), $print, 'the due date on the official invoice');
        assert_contains('leave at the back door', $print);
        $list = $client->get('invoices')->body;
        assert_contains('INV-000001', $list);
        assert_contains('unpaid', $list);
        assert_contains('$192.50', $list);
        assert_not_contains('INV-000001', $client->get('invoices', ['status' => 'paid'])->body);
        // a refused invoice comes back to the form with the message
        $r = $client->post('invoices/store', ['customer_id' => '', 'price_level' => 'wholesale', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '', 'notes' => '', 'invoice_discount' => '', 'lines' => [['product_id' => (string) $s['joy'], 'unit_id' => (string) $s['piece'], 'qty' => '1']]]);
        assert_same(302, $r->status);
        assert_contains('needs its customer', $client->get('invoices/new')->body);
        // the sales list and the session pages still work with a sale that has no session
        assert_contains('INV-000001', $client->get('sales')->body);
        assert_same(200, $client->get('reports', ['type' => 'sales'])->status);
        assert_same(200, $client->get('reports', ['type' => 'credit'])->status);
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        assert_same(403, $cashier->get('invoices')->status);
        assert_same(403, $cashier->get('invoices/new')->status);
    },
];
