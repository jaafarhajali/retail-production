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
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

/** Desk invoices, step 3 (2026-10-14): credit notes against a desk invoice, and the prices agreed with a customer. */
$setup = static function (): array {
    $svc = new ProductService();
    $joy = make_product('Joy 100g', 'piece');
    $piece = $svc->addUnit($joy, 'Piece', '1', false, true);
    $box = $svc->addUnit($joy, 'Box', '27', false, false);
    $svc->setPrices($piece, '3', '2.5');
    $svc->setPrices($box, '81', '67.5');
    (new StockService())->adjust($joy, $box, '10', 'opening', '', null, TEST_ADMIN_ID);
    $market = (new PartyService())->saveCustomer(0, ['name' => 'Al Amiliya', 'phone' => '', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '', 'payment_terms_days' => '30']);
    $issue = static fn (array $lines): array => (new SaleService())->issue([
        'user_id' => TEST_ADMIN_ID, 'customer_id' => $market, 'price_level' => 'wholesale', 'lines' => $lines, 'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '',
    ]);

    return ['joy' => $joy, 'piece' => $piece, 'box' => $box, 'market' => $market, 'issue' => $issue];
};
$stockOf = static fn (int $id): int => (int) (new Product())->find($id)['stock_base'];

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a credit note: the goods come back (or go to waste), the amount is credited at the invoice prices, no cash session; limits' => function () use ($setup, $stockOf): void {
        $s = $setup();
        $r = $s['issue']([['product_id' => $s['joy'], 'unit_id' => $s['box'], 'qty' => '4', 'discount' => '10']]);   // 4 boxes $270 − $10 = $260
        assert_same([270 - 108, '260.00'], [$stockOf($s['joy']), (new Customer())->balance($s['market'])]);
        $itemId = (int) Database::pdo()->query("SELECT id FROM sale_items WHERE sale_id = {$r['id']}")->fetchColumn();
        $svc = new ReturnService();
        $cn = $svc->creditNote($r['id'], [['sale_item_id' => $itemId, 'qty' => '1', 'condition' => 'restock'], ], 'short expiry', TEST_ADMIN_ID);
        assert_same(['RTN-000001', '65.00', '195.00'], [$cn['return_no'], $cn['total_usd'], $cn['balance']], 'one box of four: a quarter of $260');
        assert_same(270 - 108 + 27, $stockOf($s['joy']));
        $row = Database::pdo()->query("SELECT session_id, register_id, customer_id, total_usd, reason FROM returns WHERE id = {$cn['id']}")->fetch(PDO::FETCH_ASSOC);
        assert_same(['session_id' => null, 'register_id' => null, 'customer_id' => $s['market'], 'total_usd' => '65.00', 'reason' => 'short expiry'], $row);
        assert_same(['return_credit', '-65.00', $r['id'], $cn['id']], Database::pdo()->query("SELECT type, amount_usd, sale_id, return_id FROM customer_ledger WHERE type = 'return_credit'")->fetch(PDO::FETCH_NUM));
        assert_same(['debt_reduction', '65.00'], Database::pdo()->query("SELECT method, amount_usd FROM return_refunds WHERE return_id = {$cn['id']}")->fetch(PDO::FETCH_NUM));
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn(), 'no cash moved');
        $o = (new InvoiceService())->outstanding($s['market']);
        assert_same(['partly', '195.00'], [$o['items'][0]['status'], $o['items'][0]['remaining']]);
        // damaged: back into stock and straight out as waste
        $cn2 = $svc->creditNote($r['id'], [['sale_item_id' => $itemId, 'qty' => '1', 'condition' => 'waste']], 'crushed', TEST_ADMIN_ID);
        assert_same(270 - 108 + 27, $stockOf($s['joy']));
        assert_same('130.00', $cn2['balance']);
        assert_same(2, (int) Database::pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'waste' OR (type = 'return_restock' AND ref_id = {$cn2['id']})")->fetchColumn());
        // limits: no more than what is still out; nothing chosen; the return items keep count
        $e = assert_throws(DomainException::class, fn () => $svc->creditNote($r['id'], [['sale_item_id' => $itemId, 'qty' => '3', 'condition' => 'restock']], '', TEST_ADMIN_ID));
        assert_contains('Only 2 Box', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->creditNote($r['id'], [['sale_item_id' => $itemId, 'qty' => '', 'condition' => 'restock']], '', TEST_ADMIN_ID));
        assert_contains('at least one item', $e->getMessage());
        assert_same(2, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'return.credit_note'")->fetchColumn());
    },

    'agreed prices: saved on the customer, used on their desk invoice as the list price less a discount (2026-10-20), the till untouched' => function () use ($setup): void {
        $s = $setup();
        $parties = new PartyService();
        $parties->saveAgreedPrices($s['market'], [$s['box'] => '60', $s['piece'] => '']);
        assert_same([$s['box'] => '60.00'], (new Customer())->agreedPrices($s['market']));
        $r = $s['issue']([['product_id' => $s['joy'], 'unit_id' => $s['box'], 'qty' => '2'], ['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '10']]);
        assert_same('145.00', $r['total_usd'], '2 boxes at the agreed $60 + 10 pieces at the wholesale $2.50');
        assert_same([['67.50', '15.00'], ['2.50', '0.00']], Database::pdo()->query("SELECT unit_price_usd, line_discount_usd FROM sale_items WHERE sale_id = {$r['id']} ORDER BY id")->fetchAll(PDO::FETCH_NUM), 'the agreed $60 is the wholesale $67.50 less $7.50 a box (owner, 2026-10-20)');
        // rules: zero refused; empty clears
        $e = assert_throws(DomainException::class, fn () => $parties->saveAgreedPrices($s['market'], [$s['box'] => '0']));
        assert_contains('more than zero', $e->getMessage());
        $parties->saveAgreedPrices($s['market'], [$s['box'] => '']);
        assert_same([], (new Customer())->agreedPrices($s['market']));
    },

    'the pages: the agreed prices on the customer page, "(agreed)" on New invoice, the credit-note screen and its A4 print; a cashier may not' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('customers/edit', ['id' => $s['market']])->body;
        assert_contains('Agreed prices', $page);
        assert_contains('name="prices[' . $s['box'] . ']"', $page);
        assert_same(302, $client->post('customers/prices', ['id' => (string) $s['market'], 'prices' => [$s['box'] => '60', $s['piece'] => '']])->status);
        assert_contains('value="60.00"', $client->get('customers/edit', ['id' => $s['market']])->body);
        assert_contains('data-agreed="{&quot;' . $s['box'] . '&quot;:&quot;60.00&quot;}"', $client->get('invoices/new')->body);
        $r = $s['issue']([['product_id' => $s['joy'], 'unit_id' => $s['box'], 'qty' => '3']]);   // $180 at the agreed price
        $view = $client->get('sales/view', ['id' => $r['id']])->body;
        assert_contains('Credit note', $view);
        assert_not_contains('Return items', $view);
        $page = $client->get('invoices/credit', ['id' => $r['id']])->body;
        assert_contains('Issue the credit note', $page);
        assert_contains('data-left="3"', $page);
        $itemId = (int) Database::pdo()->query("SELECT id FROM sale_items WHERE sale_id = {$r['id']}")->fetchColumn();
        $res = $client->post('invoices/credit-store', ['sale_id' => (string) $r['id'], 'reason' => 'damaged in transport', 'items' => [$itemId => ['qty' => '1', 'condition' => 'waste']]]);
        assert_same(302, $res->status, $res->body);
        assert_contains('r=returns/view', $res->location());
        $view = $client->get('returns/view', ['id' => 1])->body;
        assert_contains('Print the credit note', $view);
        assert_contains('credited to the account', $view);
        $print = $client->get('returns/print', ['id' => 1])->body;
        foreach (['Credit Note', 'Credit To', 'Al Amiliya', 'RTN-000001', 'Against Invoice', 'INV-000001', 'damaged', '$60.00', 'Credited to your account', 'Balance after this credit', '$120.00', 'damaged in transport'] as $needle) {
            assert_contains($needle, $print, $needle);
        }
        assert_contains('partly paid', $client->get('invoices')->body);
        // refused with the message, back on the form
        $res = $client->post('invoices/credit-store', ['sale_id' => (string) $r['id'], 'reason' => '', 'items' => [$itemId => ['qty' => '5', 'condition' => 'restock']]]);
        assert_same(302, $res->status);
        assert_contains('Only 2 Box', $client->get('invoices/credit', ['id' => $r['id']])->body);
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        assert_same(403, $cashier->get('invoices/credit', ['id' => $r['id']])->status);
        assert_same(403, $cashier->post('customers/prices', ['id' => (string) $s['market']])->status);
    },
];
