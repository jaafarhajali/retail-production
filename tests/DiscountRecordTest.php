<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Customer;
use App\Models\Register;
use App\Services\CashService;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * The price is never lowered on the record (owner, 2026-10-20): a price typed under the list price at the till, or a
 * customer's agreed price on an invoice, keeps the list price on the line and the difference becomes its discount.
 * The printed invoices show a Discount column; the Discounts report adds every reduction up.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $joy = make_product('Joy 100g', 'piece');
    Database::pdo()->exec("UPDATE products SET allow_price_override = 1 WHERE id = {$joy}");
    $piece = $svc->addUnit($joy, 'Piece', '1', false, true);
    $svc->setPrices($piece, '3', '2.50');
    $svc->setCost($joy, '1.20', 1);
    (new StockService())->adjust($joy, $piece, '500', 'opening', '', null, TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
    $market = (new PartyService())->saveCustomer(0, ['name' => 'Spinneys', 'phone' => '', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '']);
    (new Customer())->replaceAgreedPrices($market, [$piece => '2.25']);

    return ['joy' => $joy, 'piece' => $piece, 'register' => $register, 'session' => $session, 'market' => $market];
};
$sell = static fn (array $s, string $qty, string $price): array => (new SaleService())->complete([
    'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
    'lines' => [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => $qty, 'price' => $price]], 'invoice_discount' => '',
    'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '500']], 'change_currency' => 'USD', 'notes' => '', 'pin' => '',
]);
$issue = static fn (array $s, string $qty, string $discount = ''): array => (new SaleService())->issue([
    'user_id' => TEST_ADMIN_ID, 'customer_id' => $s['market'], 'price_level' => 'wholesale', 'lines' => [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => $qty, 'discount' => $discount]],
    'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '',
]);
$line = static fn (int $saleId): array => Database::pdo()->query("SELECT unit_price_usd, line_discount_usd, line_total_usd, price_overridden FROM sale_items WHERE sale_id = {$saleId}")->fetch(PDO::FETCH_ASSOC);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the till: 10 Joy at $2.40 instead of $3.00 is recorded as $3.00 with $6.00 of discount; a price above the list stays a price' => function () use ($setup, $sell, $line): void {
        $s = $setup();
        $r = $sell($s, '10', '2.40');
        assert_same('24.00', $r['total_usd'], 'the customer pays what was typed');
        assert_same(['unit_price_usd' => '3.00', 'line_discount_usd' => '6.00', 'line_total_usd' => '24.00', 'price_overridden' => 1], $line($r['id']));
        $up = $sell($s, '2', '3.50');
        assert_same(['unit_price_usd' => '3.50', 'line_discount_usd' => '0.00', 'line_total_usd' => '7.00', 'price_overridden' => 1], $line($up['id']));
        $audit = (string) Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'sale.price_override' ORDER BY id LIMIT 1")->fetchColumn();
        assert_contains('"price":"2.40"', $audit);
        assert_contains('"list":"3.00"', $audit);
    },

    'a desk invoice at the agreed $2.25 shows the $2.50 wholesale price and $0.25 a piece of discount; a typed discount adds to it' => function () use ($setup, $issue, $line): void {
        $s = $setup();
        $r = $issue($s, '100');
        assert_same('225.00', $r['total_usd']);
        assert_same(['unit_price_usd' => '2.50', 'line_discount_usd' => '25.00', 'line_total_usd' => '225.00', 'price_overridden' => 0], $line($r['id']));
        $r2 = $issue($s, '100', '22.50');   // 10% more on the $225
        assert_same(['unit_price_usd' => '2.50', 'line_discount_usd' => '47.50', 'line_total_usd' => '202.50', 'price_overridden' => 0], $line($r2['id']));

        // the prints: the list price, the discount column, Subtotal − Discount = Total
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $official = $client->get('sales/print', ['id' => $r['id']])->body;
        foreach (['<th>Discount</th>', '$2.50', '-$25.00', '$225.00', '<td>Subtotal</td><td>$250.00</td>'] as $needle) {
            assert_contains($needle, $official, $needle);
        }
        $regular = $client->get('sales/print', ['id' => $r2['id'], 'type' => 'normal'])->body;
        foreach (['<th>Discount</th>', '-$47.50', '<td>Sub Total</td><td>$250.00</td>', '$202.50'] as $needle) {
            assert_contains($needle, $regular, $needle);
        }

        // the Discounts report: $500 at list, $72.50 of discounts, by customer, product and invoice
        $d = (new ReportService())->discounts(date('Y-m-01'), date('Y-m-d'));
        assert_same(['500.00', '72.50', '427.50', 14.5], [$d['total']['list'], $d['total']['discount'], $d['total']['charged'], $d['total']['pct']]);
        assert_same(['Spinneys'], array_column($d['customers'], 'name'));
        assert_same(2, count($d['sales']));
        $page = $client->get('reports', ['type' => 'discounts'])->body;
        foreach (['Discounts', 'At list price', '$72.50', 'Spinneys', 'Joy 100g', 'By invoice'] as $needle) {
            assert_contains($needle, $page, $needle);
        }
        assert_same(200, $client->get('reports', ['type' => 'discounts', 'print' => 1])->status);
    },
];
