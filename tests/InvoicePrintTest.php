<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Core\Settings;
use App\Models\Customer;
use App\Models\Register;
use App\Models\Setting;
use App\Services\CashService;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/** Phase F4 (2026-10-13): the official A4 invoice and the delivery note, the customer's TVA number and address, the company's MOF and email. */
$setup = static function (): array {
    $svc = new ProductService();
    $stick = make_product('Marshmallow Stick', 'piece');
    $piece = $svc->addUnit($stick, 'Piece', '1', false, true);
    $box = $svc->addUnit($stick, 'Box', '100', false, false);
    $svc->setPrices($piece, '0.80', '0.65');
    $svc->setPrices($box, '80', '65');
    $svc->addBarcode($piece, '850009611772');
    (new StockService())->adjust($stick, $box, '5', 'opening', '', null, TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
    $customer = (new PartyService())->saveCustomer(0, ['name' => 'شركة متاجر العاملية ش.م.ل', 'phone' => '07/225999', 'tva_number' => '2941978-601', 'address' => 'صيدا - الغازية', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '']);
    (new Setting())->setMany(['shop_name' => 'Malika For Trading', 'shop_address' => 'Lebanon / Arab Salim / main road', 'shop_phone' => '+961 70316708', 'company_mof' => '3900717', 'company_email' => 'malikamarshmallow@gmail.com']);
    Settings::flush();

    return ['stick' => $stick, 'piece' => $piece, 'box' => $box, 'register' => $register, 'session' => $session, 'customer' => $customer];
};

return [
    '__before' => function (): void {
        test_db_reset();
        \App\Core\Auth::login(TEST_ADMIN_ID);
    },

    'the customer keeps a TVA number and an address; the page saves them' => function () use ($setup): void {
        $s = $setup();
        $c = (new Customer())->find($s['customer']);
        assert_same(['2941978-601', 'صيدا - الغازية'], [$c['tva_number'], $c['address']]);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('customers/edit', ['id' => $s['customer']])->body;
        assert_contains('name="tva_number"', $page);
        assert_contains('2941978-601', $page);
        $client->post('customers/save', ['id' => (string) $s['customer'], 'name' => 'شركة متاجر العاملية ش.م.ل', 'phone' => '07/225999', 'tva_number' => '111', 'address' => 'Saida', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '', 'is_active' => '1']);
        assert_same(['111', 'Saida'], [(new Customer())->find($s['customer'])['tva_number'], (new Customer())->find($s['customer'])['address']]);
    },

    'the official invoice: company header with MOF, Bill To with TVA, due date 45 days later, boxes and quantity per box, signatures' => function () use ($setup): void {
        $s = $setup();
        $r = (new SaleService())->hold(['register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => $s['customer'], 'price_level' => 'wholesale',
            'lines' => [['product_id' => $s['stick'], 'unit_id' => $s['box'], 'qty' => '1']], 'invoice_discount' => '', 'notes' => '', 'pin' => '', 'invoice_type' => 'official']);
        assert_same('official', Database::pdo()->query("SELECT invoice_type FROM sales WHERE id = {$r['id']}")->fetchColumn());
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('sales/print', ['id' => $r['id']])->body;
        foreach (['Malika For Trading', 'MOF: 3900717', 'malikamarshmallow@gmail.com', 'Bill To', '2941978-601', 'صيدا - الغازية', '07/225999', 'Invoice No.', 'Due Date',
                  date('Y-m-d', strtotime('+45 days')), '850009611772', 'Total Qty', 'Recipient : المستلم', 'Deliverer : المسلّم', 'Thank you for your business!', 'out with the seller'] as $needle) {
            assert_contains($needle, $page, $needle);
        }
        // 1 box of 100 at $65 (wholesale $0.65 a stick): Box 1, Qty in Box 100, Total Qty 100, unit price $0.65, amount $65.00
        assert_contains('<td>1</td>', $page);
        assert_contains('<td>100</td>', $page);
        assert_contains('$0.65', $page);
        assert_contains('$65.00', $page);
        // the same sale can also be printed as a delivery note, and the other way round
        $note = $client->get('sales/print', ['id' => $r['id'], 'type' => 'normal'])->body;
        assert_contains('Delivery Note', $note);
        assert_contains('Shipping full address', $note);
        assert_not_contains('Due Date', $note);
    },

    'the delivery note: number, date, invoice to, location, lines, totals, no delivery charge' => function () use ($setup): void {
        $s = $setup();
        $r = (new SaleService())->complete(['register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => $s['customer'], 'price_level' => 'retail',
            'lines' => [['product_id' => $s['stick'], 'unit_id' => $s['piece'], 'qty' => '10']], 'invoice_discount' => '', 'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '8']],
            'change_currency' => 'USD', 'notes' => '', 'pin' => '']);
        assert_same('normal', Database::pdo()->query("SELECT invoice_type FROM sales WHERE id = {$r['id']}")->fetchColumn());
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('sales/print', ['id' => $r['id']])->body;
        foreach (['INVOICE', 'Delivery Note', $r['invoice_no'], 'Invoice to', '07/225999', 'Location', 'Shipping full address', 'Marshmallow Stick', '$0.80', '$8.00', 'Sub Total', 'MOF: 3900717'] as $needle) {
            assert_contains($needle, $page, $needle);
        }
        assert_not_contains('Delivery charge', $page);
        $view = $client->get('sales/view', ['id' => $r['id']])->body;
        assert_contains('Official invoice', $view);
        assert_contains('Delivery note', $view);
    },

    'the Settings page saves the MOF number and the email; the till passes the type chosen on Hold' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('settings');
        $r = $client->post('settings/save', ['shop_name' => 'Malika For Trading', 'shop_address' => '', 'shop_phone' => '', 'receipt_header' => '', 'receipt_footer' => '', 'company_mof' => '12345', 'company_email' => 'a@b.c',
            'lbp_rounding_step' => '5000', 'max_cashier_discount_pct' => '0', 'usd_denominations' => '100,50,20,10,5,1', 'lbp_denominations' => '100000,50000,20000,10000,5000,1000']);
        assert_same(302, $r->status);
        Settings::flush();
        assert_same(['12345', 'a@b.c'], [Settings::get('company_mof'), Settings::get('company_email')]);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $client->get('pos');
        $r = $client->postJson('pos/hold-invoice', ['customer_id' => $s['customer'], 'price_level' => 'wholesale', 'lines' => [['product_id' => $s['stick'], 'unit_id' => $s['box'], 'qty' => '2']], 'invoice_discount' => '', 'notes' => '', 'pin' => '', 'invoice_type' => 'official']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_contains('r=sales/print', $j['receipt']);
        assert_same('official', json_decode($client->get('pos/held-invoices')->body, true)['invoices'][0]['type']);
    },
];
