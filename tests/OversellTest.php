<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Register;
use App\Models\User;
use App\Services\CashService;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * More than the stock (2026-10-20): Aya sold 200 Joy 200g with 30 in stock and the till only warned. Every piece comes
 * from Packing, so the stock is the real one: the till needs the administrator's PIN to force such a sale, a desk invoice
 * is refused outright. A box filled at the sale keeps the warning only (FilledBoxTest).
 */
$setup = static function (): array {
    $svc = new ProductService();
    $joy = make_product('Joy 200g', 'piece');
    $piece = $svc->addUnit($joy, 'Piece', '1', false, true);
    $svc->setPrices($piece, '6', '5');
    $svc->setCost($joy, '1.20', 1);
    (new StockService())->adjust($joy, $piece, '30', 'opening', '', null, TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
    (new User())->setPin(TEST_ADMIN_ID, '2468');
    $market = (new PartyService())->saveCustomer(0, ['name' => 'Spinneys', 'phone' => '', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '']);

    return ['joy' => $joy, 'piece' => $piece, 'register' => $register, 'session' => $session, 'market' => $market];
};
$sell = static fn (array $s, array $lines, string $pin = ''): array => (new SaleService())->complete([
    'register_id' => $s['register'], 'session_id' => $s['session'], 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
    'lines' => $lines, 'invoice_discount' => '', 'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '2000']], 'change_currency' => 'USD', 'notes' => '', 'pin' => $pin,
]);
$stock = static fn (int $id): int => (int) Database::pdo()->query("SELECT stock_base FROM products WHERE id = {$id}")->fetchColumn();

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the till: 200 asked with 30 in stock needs the administrator\'s PIN, even for the administrator; with it the sale goes through and the stock goes below zero' => function () use ($setup, $sell, $stock): void {
        $s = $setup();
        $e = assert_throws(DomainException::class, fn () => $sell($s, [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '200']]));
        assert_same('Needs an Admin PIN: more than the stock: Joy 200g (30 in stock, 200 asked).', $e->getMessage());
        assert_same(30, $stock($s['joy']), 'nothing moved');
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        // the same product on two lines is added up: 20 + 20 is 40
        $e = assert_throws(DomainException::class, fn () => $sell($s, [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '20'], ['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '20']]));
        assert_contains('(30 in stock, 40 asked)', $e->getMessage());
        // exactly the stock: no PIN
        $r = $sell($s, [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '30']]);
        assert_same([], $r['warnings']);
        assert_same(0, $stock($s['joy']));
        // forced with the PIN: sold, warned, audited
        $r = $sell($s, [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '5']], '2468');
        assert_same(['Joy 200g is now below zero stock.'], $r['warnings']);
        assert_same(-5, $stock($s['joy']));
        $over = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'pin.override' ORDER BY id DESC LIMIT 1")->fetchColumn();
        assert_contains('more than the stock', (string) $over);
        // the till's JSON says needs_pin, so the PIN dialog opens
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        $client->post('registers/bind', ['id' => $s['register']]);
        $j = $client->postJson('pos/complete', ['price_level' => 'retail', 'lines' => [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => '1']],
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '6']], 'change_currency' => 'USD']);
        assert_same(422, $j->status, $j->body);
        assert_true(json_decode($j->body, true)['needs_pin'] === true, $j->body);
    },

    'a desk invoice beyond the stock is refused, PIN or not: the pieces were packed or they do not exist' => function () use ($setup, $stock): void {
        $s = $setup();
        $issue = static fn (string $qty): array => (new SaleService())->issue([
            'user_id' => TEST_ADMIN_ID, 'customer_id' => $s['market'], 'price_level' => 'wholesale', 'lines' => [['product_id' => $s['joy'], 'unit_id' => $s['piece'], 'qty' => $qty]],
            'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '', 'pin' => '2468',
        ]);
        $e = assert_throws(DomainException::class, fn () => $issue('200'));
        assert_same('Not enough in stock: Joy 200g (30 in stock, 200 asked). Pack more first.', $e->getMessage());
        assert_same(30, $stock($s['joy']));
        $r = $issue('30');
        assert_true($r['id'] > 0);
        assert_same(0, $stock($s['joy']));
    },
];
