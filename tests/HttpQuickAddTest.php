<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Services\PartyService;
use App\Services\ProductService;

/**
 * 2026-10-30 (Aya): "+ New supplier" on the purchase form and "+ New customer" on the invoice form open a small form in
 * the same page; saved in place, the new name goes straight into the box, selected.
 */
return [
    '__before' => 'test_db_reset',

    'the forms carry the in-page dialogs; the quick routes save and answer JSON; a refused save says why; a cashier may not' => function (): void {
        $svc = new ProductService();
        $joy = make_product('Joy 100g', 'piece');
        $svc->setPrices($svc->addUnit($joy, 'Piece', '1', false, true), '3', '2.5');
        (new PartyService())->saveCustomer(0, ['name' => 'Hawraa Issa', 'phone' => '', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '']);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $purchase = $client->get('purchases/create')->body;
        assert_contains('data-bs-target="#m-new-supplier">+ New supplier</button>', $purchase);
        assert_contains('data-quick-add="index.php?r=suppliers/quick" data-quick-target="supplier_id"', $purchase);
        assert_not_contains('target="_blank"', $purchase, 'no new tab any more');
        $invoice = $client->get('invoices/new')->body;
        assert_contains('data-bs-target="#m-new-customer">+ New customer</button>', $invoice);
        assert_contains('data-quick-add="index.php?r=customers/quick" data-quick-target="customer_id"', $invoice);
        assert_contains('form[data-quick-add]', (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js'), 'the script that posts the dialog');

        // a supplier, saved in place
        $client->get('purchases/create');
        $r = $client->post('suppliers/quick', ['name' => 'Box Factory', 'phone' => '70 111 222', 'notes' => '']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same('Box Factory', $j['name']);
        assert_same('Box Factory', Database::pdo()->query('SELECT name FROM suppliers WHERE id = ' . (int) $j['id'])->fetchColumn());
        $bad = $client->post('suppliers/quick', ['name' => '', 'phone' => '', 'notes' => '']);
        assert_same(422, $bad->status);
        assert_true(json_decode($bad->body, true)['error'] !== '', 'the reason comes back');
        assert_same(1, (int) Database::pdo()->query('SELECT COUNT(*) FROM suppliers')->fetchColumn(), 'nothing saved on a refusal');

        // a customer, with what the invoice form's box needs: level, terms, address, balance, agreed prices
        $r = $client->post('customers/quick', ['name' => 'Spinneys Saida', 'phone' => '07 225 999', 'address' => 'Saida', 'default_price_level' => 'retail', 'tva_number' => '12345']);
        assert_same(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        assert_same(['Spinneys Saida', 'retail', 'Saida', '0.00', []], [$j['name'], $j['level'], $j['address'], $j['balance'], $j['agreed']]);
        assert_true($j['terms'] > 0, 'the default days to pay');
        assert_same('12345', Database::pdo()->query('SELECT tva_number FROM customers WHERE id = ' . (int) $j['id'])->fetchColumn());
        $dup = $client->post('customers/quick', ['name' => 'Other', 'phone' => '07 225 999', 'address' => '', 'default_price_level' => 'wholesale', 'tva_number' => '']);
        assert_same(422, $dup->status, 'the same phone belongs to Spinneys');

        // a cashier has neither dialog nor route
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        assert_same(403, $cashier->post('suppliers/quick', ['name' => 'X', 'phone' => '', 'notes' => ''])->status);
        assert_same(403, $cashier->post('customers/quick', ['name' => 'X', 'phone' => '', 'address' => '', 'default_price_level' => 'retail', 'tva_number' => ''])->status);
    },
];
