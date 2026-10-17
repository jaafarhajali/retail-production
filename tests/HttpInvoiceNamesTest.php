<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

/** 2026-10-17 (asked by Aya): the two invoice types are called "Regular invoice" and "Official invoice" everywhere, nothing else. */
return [
    '__before' => 'test_db_reset',

    'the invoice form and the settings page use the two names only' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $svc = new \App\Services\ProductService();
        $joy = $svc->create(['name' => 'Joy 100g', 'category_id' => '', 'base_unit' => 'piece', 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'kind' => 'finished']);
        $svc->setPrices($svc->addUnit($joy, 'Piece', '1', false, false), '3', '2.5');   // the form needs a priced product and a customer to show its buttons
        (new \App\Models\Customer())->create(['name' => 'Spinneys Saida', 'phone' => '07 225 999', 'notes' => null, 'default_price_level' => 'wholesale', 'credit_limit_usd' => null]);
        foreach (['invoices/new', 'settings'] as $route) {
            $page = $client->get($route)->body;
            assert_same(0, preg_match('/delivery note|receipt \/ /i', $page), "no old name on {$route}");
        }
        $form = $client->get('invoices/new')->body;
        assert_contains('>Regular invoice</label>', $form);
        assert_contains('>Official invoice</label>', $form);
        assert_contains('Printed on the regular and the official invoice.', $client->get('settings')->body);
    },
];
