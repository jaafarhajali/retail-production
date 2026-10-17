<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\StockService;

/**
 * 2026-10-18 (Aya): the Stock page shows only what really exists. The Type filter offers the kinds of movement that
 * happened, in plain words; the Product filter lists the products that have stock history; the Mixes tab shows only
 * when there is more than one mix, as on Products.
 */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the filters list what happened, in plain words; a product with no history is not offered' => function (): void {
        $svc = new ProductService();
        $sugar = make_product('Sugar', 'g', null, 'material');
        $kg = $svc->addUnit($sugar, 'kg', '1000', true, true);
        make_product('Never moved', 'piece');
        (new StockService())->adjust($sugar, $kg, '10', 'opening', '', null, TEST_ADMIN_ID);
        (new PurchaseService())->create(null, '', date('Y-m-d'), [['product_id' => $sugar, 'unit_id' => $kg, 'qty' => '5', 'unit_cost' => '0.9']], '', 'outside', '', TEST_ADMIN_ID);

        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('stock')->body;
        assert_contains('<option value="opening" >Opening stock</option>', $page);
        assert_contains('<option value="purchase" >Purchase</option>', $page);
        foreach (['sale_void', 'Cancelled sale', 'stocktake', 'production_in', 'return_restock'] as $absent) {
            assert_not_contains('value="' . $absent . '"', $page, "{$absent} never happened");
        }
        assert_not_contains('sale void', $page, 'no raw codes');
        assert_contains('>Sugar</option>', $page);
        assert_not_contains('>Never moved</option>', $page, 'no history, not offered');
        assert_contains('<td class="text-nowrap">Opening stock</td>', $page, 'the list says it in words too');
        assert_contains('<td class="text-nowrap">Purchase</td>', $page);
        assert_not_contains('kind=intermediate', $page, 'no Mixes tab without two mixes');
        // a filter on a type still works, and an unknown one is ignored
        assert_contains('<td class="text-nowrap">Purchase</td>', $client->get('stock', ['type' => 'purchase'])->body);
        assert_not_contains('<td class="text-nowrap">Opening stock</td>', $client->get('stock', ['type' => 'purchase'])->body);
        assert_contains('<td class="text-nowrap">Opening stock</td>', $client->get('stock', ['type' => 'nonsense'])->body);
    },
];
