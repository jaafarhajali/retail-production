<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Services\PartyService;
use App\Services\ProductService;

/** 2026-10-30 (Aya): a "+ New supplier" link on the purchase form and "+ New customer" on the invoice form, opening in a new tab; the select refreshes when the tab is back. */
return [
    '__before' => 'test_db_reset',

    'the purchase form links to a new supplier, the invoice form to a new customer, and both selects refresh on focus' => function (): void {
        $svc = new ProductService();
        $joy = make_product('Joy 100g', 'piece');
        $svc->setPrices($svc->addUnit($joy, 'Piece', '1', false, true), '3', '2.5');
        (new PartyService())->saveCustomer(0, ['name' => 'Hawraa Issa', 'phone' => '', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '']);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $purchase = $client->get('purchases/create')->body;
        assert_contains('href="index.php?r=suppliers/edit" target="_blank"', $purchase);
        assert_contains('+ New supplier', $purchase);
        assert_contains('id="supplier_id" name="supplier_id" data-refresh-on-focus="index.php?r=purchases/create"', $purchase);
        $invoice = $client->get('invoices/new')->body;
        assert_contains('href="index.php?r=customers/edit" target="_blank"', $invoice);
        assert_contains('+ New customer', $invoice);
        assert_contains('data-refresh-on-focus="index.php?r=invoices/new"', $invoice);
        assert_contains('data-refresh-on-focus', (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js'), 'the script that refreshes the select');
    },
];
