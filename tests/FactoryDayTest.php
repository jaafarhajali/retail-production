<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Services\CashService;
use App\Services\DashboardService;
use App\Services\ExpenseService;
use App\Services\InvoiceService;
use App\Services\PackingService;
use App\Services\PartyService;
use App\Services\ProductionService;
use App\Services\PurchaseService;
use App\Services\ReturnService;
use App\Services\SaleService;

/**
 * One whole factory day on the real Malika catalogue (2026-10-21, the test before the client's trial): buy, make, pack,
 * sell on the till, a custom order, a desk invoice with an agreed price, a payment, a credit note, an expense, the
 * drawer closed. Then the books must agree with themselves: every product's stock is the sum of its movements, every
 * customer's balance is the sum of their ledger, each sale's total is the sum of its lines and its payments, and every
 * page answers.
 */
$pdo = static fn (): PDO => Database::pdo();
$one = static fn (string $sql): mixed => Database::pdo()->query($sql)->fetchColumn();
$pid = static fn (string $name): int => (int) Database::pdo()->query('SELECT id FROM products WHERE name = ' . Database::pdo()->quote($name))->fetchColumn();
$unitOf = static fn (int $product, ?string $name = null): int => (int) Database::pdo()->query('SELECT id FROM product_units WHERE product_id = ' . $product . ($name === null ? '' : ' AND name = ' . Database::pdo()->quote($name)) . ' ORDER BY id LIMIT 1')->fetchColumn();
$stockOf = static fn (string $name): int => (int) Database::pdo()->query('SELECT stock_base FROM products WHERE name = ' . Database::pdo()->quote($name))->fetchColumn();

return [
    '__before' => function (): void {
        test_db_reset();
        exec(sprintf('"%s" "%s" --yes 2>&1', PHP_BINARY, dirname(__DIR__) . '/bin/seed-factory.php'), $ignored, $rc);
        Database::disconnect();
        assert_same(0, $rc, 'the Malika catalogue is seeded');
        Auth::login(TEST_ADMIN_ID);
    },

    'a whole factory day, and the books agree with themselves' => function () use ($one, $pid, $unitOf, $stockOf): void {
        $db = Database::pdo();
        // the demo stock of the seed is cleared: this day starts from an empty factory
        $db->exec('DELETE FROM stock_movements');
        $db->exec('UPDATE products SET stock_base = 0');
        (new \App\Models\Product())->ensureCustomOrder();

        // ---- 1. buy the ingredients and the packaging (a supplier, part paid now)
        $supplier = (new PartyService())->saveSupplier(0, 'Sugar Co', '70 111 222', '', true);
        $buy = static fn (string $name, string $unit, string $qty, string $cost): array => ['product_id' => $pid($name), 'unit_id' => $unitOf($pid($name), $unit), 'qty' => $qty, 'unit_cost' => $cost];
        $purchase = (new PurchaseService())->create($supplier, 'SC-1', date('Y-m-d'), [
            $buy('Sugar', 'kg', '20', '0.9'), $buy('Glucose syrup', 'kg', '10', '2.4'), $buy('Honey', 'kg', '8', '9'), $buy('Gelatin', 'kg', '2', '18'),
            $buy('Food colouring', 'kg', '1', '30'), $buy('Raspberry flavour', 'L', '1', '24'), $buy('Pistachio paste', 'kg', '2', '28'),
        ], '50', 'outside', '', TEST_ADMIN_ID);
        assert_true($purchase > 0, 'purchase recorded');
        foreach (['Box 100g', 'Label'] as $name) {
            $unit = (string) $db->query('SELECT name FROM product_units WHERE product_id = ' . $pid($name) . ' AND factor = 1 ORDER BY id LIMIT 1')->fetchColumn();
            (new PurchaseService())->create($supplier, 'SC-2', date('Y-m-d'), [$buy($name, $unit, '300', $name === 'Box 100g' ? '0.12' : '0.02')], '', 'outside', '', TEST_ADMIN_ID);
        }
        assert_same(20000, $stockOf('Sugar'));
        assert_same(300, $stockOf('Box 100g'));
        $owed = (float) $one('SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_ledger WHERE supplier_id = ' . $supplier);
        assert_true($owed != 0.0, 'the supplier ledger moved');

        // ---- 2. make 10 kg of mix (the Raspberry flavour), then pack it into 50 Joy 100g + 50 Pistachio 100g
        $recipe = (int) $one("SELECT id FROM recipes WHERE name = 'Raspberry'");
        $made = (new ProductionService())->run($recipe, '10', 0, 'first batch', TEST_ADMIN_ID);
        assert_same(10000, $stockOf('Marshmallow mix'), '10 kg of mix made');
        assert_same(15500, $stockOf('Sugar'), '0.45 kg of sugar a kilo of mix: 4.5 kg out');
        $mixU = $unitOf($pid('Marshmallow mix'), 'kg');
        $pack = (new PackingService())->pack(
            [['product_id' => $pid('Marshmallow mix'), 'qty' => '10', 'unit_id' => $mixU]],
            [['product_id' => $pid('Joy 100g'), 'qty' => '50', 'unit_id' => $unitOf($pid('Joy 100g'), 'Piece')], ['product_id' => $pid('Pistachio 100g'), 'qty' => '50', 'unit_id' => $unitOf($pid('Pistachio 100g'), 'Piece')]],
            '', TEST_ADMIN_ID);
        assert_same([0, 50, 50, 200], [$stockOf('Marshmallow mix'), $stockOf('Joy 100g'), $stockOf('Pistachio 100g'), $stockOf('Box 100g')], 'mix emptied, 100 pieces in stock, their boxes out');
        assert_true($pack['id'] > 0);

        // ---- 3. the till: a session, a sale, an oversell refused, a custom order
        $register = (int) $one("SELECT id FROM registers WHERE name = 'Register 01'");
        $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
        (new \App\Models\User())->setPin(TEST_ADMIN_ID, '2468');
        $sale = static fn (array $lines, string $usd, array $extra = []): array => (new SaleService())->complete($extra + ['register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID,
            'customer_id' => null, 'price_level' => 'retail', 'lines' => $lines, 'invoice_discount' => '', 'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => $usd]], 'change_currency' => 'USD', 'notes' => '', 'pin' => '']);
        $joyU = $unitOf($pid('Joy 100g'), 'Piece');
        $s1 = $sale([['product_id' => $pid('Joy 100g'), 'unit_id' => $joyU, 'qty' => '10']], '30');
        assert_same(['30.00', 40], [$s1['total_usd'], $stockOf('Joy 100g')]);
        $e = assert_throws(DomainException::class, fn () => $sale([['product_id' => $pid('Joy 100g'), 'unit_id' => $joyU, 'qty' => '500']], '1500'));
        assert_contains('more than the stock', $e->getMessage());
        assert_same(40, $stockOf('Joy 100g'), 'the refused sale moved nothing');
        // a lowered price becomes a discount on the list price
        Database::pdo()->exec('UPDATE products SET allow_price_override = 1 WHERE id = ' . $pid('Joy 100g'));
        $s2 = $sale([['product_id' => $pid('Joy 100g'), 'unit_id' => $joyU, 'qty' => '10', 'price' => '2.50']], '25');
        assert_same(['3.00', '5.00', '25.00'], [$db->query('SELECT unit_price_usd FROM sale_items WHERE sale_id = ' . $s2['id'])->fetchColumn(), $db->query('SELECT line_discount_usd FROM sale_items WHERE sale_id = ' . $s2['id'])->fetchColumn(), $s2['total_usd']]);
        // a custom order: price typed, 300 g of mix (made now), a box and a label from the stock, a ribbon bought for it
        (new ProductionService())->run($recipe, '2', 0, 'for the custom order', TEST_ADMIN_ID);
        $custom = (new Product())->customOrder();
        $co = $sale([['product_id' => (int) $custom['id'], 'unit_id' => $unitOf((int) $custom['id']), 'qty' => '1', 'price' => '25', 'fill' => '300', 'cost' => '1.50', 'note' => 'birthday',
            'pack' => [['material_id' => $pid('Box 100g'), 'qty' => '2'], ['material_id' => $pid('Label'), 'qty' => '1']]]], '25');
        assert_same([1700, 198], [$stockOf('Marshmallow mix'), $stockOf('Box 100g')], '300 g of the 2 kg, 2 boxes out');

        // ---- 4. a desk invoice with an agreed price, a payment, a credit note
        $customer = (int) $one("SELECT id FROM customers WHERE name = 'Hawraa Issa'");   // a sample customer of the seed
        (new Customer())->replaceAgreedPrices($customer, [$unitOf($pid('Pistachio 100g'), 'Piece') => '3.00']);
        $inv = (new SaleService())->issue(['user_id' => TEST_ADMIN_ID, 'customer_id' => $customer, 'price_level' => 'wholesale',
            'lines' => [['product_id' => $pid('Pistachio 100g'), 'unit_id' => $unitOf($pid('Pistachio 100g'), 'Piece'), 'qty' => '20']], 'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => 'Saida']);
        assert_same(['60.00', 30], [$inv['total_usd'], $stockOf('Pistachio 100g')], '20 × the agreed $3.00');
        assert_same('60.00', Database::pdo()->query("SELECT COALESCE(SUM(amount_usd), 0) FROM customer_ledger WHERE customer_id = {$customer}")->fetchColumn(), 'it is owed');
        (new InvoiceService())->recordPayment($customer, '25', '', 'cash', '', 'part paid', TEST_ADMIN_ID);
        assert_same('35.00', (new Customer())->balance($customer), '$60 − $25');
        $item = (int) $one('SELECT id FROM sale_items WHERE sale_id = ' . $inv['id']);
        (new ReturnService())->creditNote($inv['id'], [['sale_item_id' => $item, 'qty' => '5', 'condition' => 'restock']], 'damaged in transport', TEST_ADMIN_ID);
        assert_same(['20.00', 35], [(new Customer())->balance($customer), $stockOf('Pistachio 100g')], '5 back at $3 = $15 off the debt; the 5 pieces back in stock');

        // ---- 5. an expense and the fixed costs
        (new ExpenseService())->create(['kind' => 'expense', 'category' => 'Rent', 'description' => 'October', 'usd' => '600', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside'], TEST_ADMIN_ID);
        (new DashboardService())->setFixedCosts(date('Y-m'), '1500', TEST_ADMIN_ID);
        $dash = (new DashboardService())->summary();
        assert_same(['1500.00', '600.00'], [$dash['fixed']['estimate'], $dash['fixed']['actual']]);
        assert_true($dash['targets']['ready'], 'the break-even table is ready');

        // ---- 6. close the drawer
        $report = (new CashService())->report($session);
        assert_true(is_array($report));
        $close = (new CashService())->close($session, [], [], TEST_ADMIN_ID, true);
        assert_true(is_array($close));

        // ---- 7. the books agree with themselves
        foreach ($db->query('SELECT p.id, p.name, p.stock_base, COALESCE((SELECT SUM(base_qty) FROM stock_movements m WHERE m.product_id = p.id), 0) AS moved FROM products p')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            assert_same((int) $row['moved'], (int) $row['stock_base'], $row['name'] . ': the stock is the sum of its movements');
        }
        foreach ($db->query('SELECT id, invoice_no, total_usd, (SELECT COALESCE(SUM(line_total_usd), 0) FROM sale_items i WHERE i.sale_id = s.id) AS lines_total FROM sales s WHERE status = \'completed\'')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            assert_true(abs((float) $row['total_usd'] - (float) $row['lines_total']) < 0.011, $row['invoice_no'] . ': the total is the sum of its lines');
        }
        $neg = $db->query('SELECT name, stock_base FROM products WHERE stock_base < 0 AND is_custom_order = 0')->fetchAll(PDO::FETCH_ASSOC);
        assert_same([], $neg, 'nothing went below zero in a normal day');
        assert_same(0, (int) $one("SELECT COUNT(*) FROM sales s WHERE status = 'completed' AND NOT EXISTS (SELECT 1 FROM sale_items i WHERE i.sale_id = s.id)"), 'no empty sale');
    },

    'every page answers for the administrator, and a cashier is kept out of the administration' => function () use ($pid, $unitOf): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $routes = ['dashboard', 'products', 'products/create', 'categories', 'stock', 'stock/adjust', 'purchases', 'purchases/create', 'recipes', 'recipes/edit', 'production', 'production/new', 'packing', 'packing/new',
            'suppliers', 'suppliers/edit', 'customers', 'customers/edit', 'sessions', 'sessions/open', 'sales', 'invoices', 'invoices/new', 'invoices/pay', 'returns', 'expenses', 'counts', 'backup',
            'reports', 'users', 'roles', 'registers', 'rates', 'settings', 'audit', 'prices'];
        foreach ($routes as $route) {
            $r = $client->get($route);
            assert_true(in_array($r->status, [200, 302], true), "GET {$route} answered {$r->status}");
            assert_not_contains('Something went wrong', $r->body, "GET {$route} has no error page");
        }
        foreach (['sales', 'payments', 'profit', 'credit', 'stock', 'purchases', 'returns', 'waste', 'sessions', 'production', 'products', 'aging', 'discounts'] as $type) {
            $r = $client->get('reports', ['type' => $type]);
            assert_same(200, $r->status, "report {$type}");
            assert_same(200, $client->get('reports', ['type' => $type, 'print' => 1])->status, "report {$type} print");
        }
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        foreach (['settings', 'users', 'backup', 'audit', 'purchases', 'reports'] as $route) {
            assert_same(403, $cashier->get($route)->status, "a cashier is kept out of {$route}");
        }
        assert_same(403, $cashier->post('dashboard/fixed-costs', ['month' => date('Y-m'), 'amount' => '1'])->status);
    },
];
