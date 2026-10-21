<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Settings;

/** bin/seed-factory.php fills an empty database with the Malika catalogue: company, finished products with the price list, materials, the mix. */
return [
    '__before' => 'test_db_reset',

    'the factory seed creates the company, 10 finished products, 12 materials and the mix, and refuses to run twice without --force' => function (): void {
        $run = static function (string $args = '--yes'): array {
            exec(sprintf('"%s" "%s" %s 2>&1', PHP_BINARY, BASE_PATH . '/bin/seed-factory.php', $args), $out, $rc);   // the test env is inherited: RETAIL_POS_ENV=test

            return [$rc, implode("\n", $out)];
        };
        [$rc, $text] = $run();
        assert_same(0, $rc, $text);
        assert_contains('Finished products: 9', $text);
        assert_contains('Raw materials and packaging: 12', $text);
        $pdo = Database::pdo();
        $kinds = [];
        foreach ($pdo->query('SELECT kind, COUNT(*) AS n FROM products GROUP BY kind') as $r) {
            $kinds[$r['kind']] = (int) $r['n'];
        }
        assert_same(['finished' => 10, 'intermediate' => 1, 'material' => 12], $kinds);
        Settings::flush();
        assert_same('Malika For Trading', Settings::get('shop_name'));
        assert_same('3900717', Settings::get('company_mof'));
        $joy = $pdo->query("SELECT pu.retail_price, pu.wholesale_price FROM product_units pu JOIN products p ON p.id = pu.product_id WHERE p.name = 'Joy 100g' AND pu.factor = 1")->fetch(PDO::FETCH_ASSOC);
        assert_same(['retail_price' => '3.00', 'wholesale_price' => '2.50'], $joy);
        assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM barcodes WHERE barcode = '850009611789'")->fetchColumn());
        assert_same($pdo->query("SELECT id FROM products WHERE name = 'Marshmallow mix'")->fetchColumn(), $pdo->query("SELECT fill_mix_id FROM products WHERE is_custom_order = 1")->fetchColumn(), 'the built-in Custom order is filled from the mix');
        assert_same(20000, (int) $pdo->query("SELECT stock_base FROM products WHERE name = 'Honey'")->fetchColumn(), '4 buckets of 5 kg');
        assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM product_units pu JOIN products p ON p.id = pu.product_id WHERE p.kind <> 'finished' AND pu.retail_price IS NOT NULL")->fetchColumn(), 'no material carries a price');
        [$rc, $text] = $run();
        assert_same(1, $rc);
        assert_contains('already has', $text);
    },
];
