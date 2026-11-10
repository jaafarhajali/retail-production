<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Models\Product;
use App\Models\Register;
use App\Services\AccountingService;
use App\Services\BackupService;
use App\Services\CashService;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\SaleService;

/** The review of 2026-11-10: what the new code of October and November got slightly wrong, pinned down. */
$mk = static function (string $name, string $kind, string $base, string $unit, int $factor, bool $fraction): array {
    $svc = new ProductService();
    $id = $svc->create(['name' => $name, 'category_id' => '', 'base_unit' => $base, 'internal_code' => '', 'description' => '', 'target_margin_pct' => '', 'show_on_pos_grid' => $kind === 'finished', 'allow_price_override' => false, 'kind' => $kind]);

    return ['id' => $id, 'unit' => $svc->addUnit($id, $unit, (string) $factor, $fraction, true)];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a supplier discount never leaves a free sample with a negative cost: the rounding remainder goes to the biggest line' => function () use ($mk): void {
        $a = $mk('Sugar', 'material', 'piece', 'Piece', 1, false);
        $b = $mk('Gelatin', 'material', 'piece', 'Piece', 1, false);
        $c = $mk('Colour', 'material', 'piece', 'Piece', 1, false);
        $free = $mk('Sample', 'material', 'piece', 'Piece', 1, false);
        $supplier = (new PartyService())->saveSupplier(0, 'Sugar Co', '', '', true);
        // three lines of $1.00 and a free sample last, $1.00 off: 0.33 + 0.33 + 0.34, nothing on the sample
        (new PurchaseService())->create($supplier, 'SC-9', date('Y-m-d'), [
            ['product_id' => $a['id'], 'unit_id' => $a['unit'], 'qty' => '1', 'unit_cost' => '1'], ['product_id' => $b['id'], 'unit_id' => $b['unit'], 'qty' => '1', 'unit_cost' => '1'],
            ['product_id' => $c['id'], 'unit_id' => $c['unit'], 'qty' => '1', 'unit_cost' => '1'], ['product_id' => $free['id'], 'unit_id' => $free['unit'], 'qty' => '1', 'unit_cost' => '0'],
        ], '0', 'outside', '', TEST_ADMIN_ID, '1');
        $costs = array_map(static fn (int $id): string => (new Product())->find($id)['cost_per_base'], [$a['id'], $b['id'], $c['id'], $free['id']]);
        assert_same(['0.660000', '0.670000', '0.670000', '0.000000'], $costs, 'the biggest line (the first of equals) takes the remainder; the free line stays at zero');
        assert_same('2.00', Database::pdo()->query('SELECT total_usd FROM purchases')->fetchColumn());
        assert_same(['0.34', '0.33', '0.33', '0.00'], Database::pdo()->query('SELECT line_discount_usd FROM purchase_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    },

    'a card payment at the till reaches the bank on the balance sheet, so the sheet balances without a "Differences" line' => function () use ($mk): void {
        $joy = $mk('Joy 100g', 'finished', 'piece', 'Piece', 1, false);
        (new ProductService())->setPrices($joy['unit'], '3', '2.5');
        (new ProductService())->setCost($joy['id'], '0.20', 1);
        (new \App\Services\StockService())->adjust($joy['id'], $joy['unit'], '100', 'opening', '', '0.20', TEST_ADMIN_ID);
        $register = (new Register())->create('Register 01');
        $session = (new CashService())->open($register, TEST_ADMIN_ID, '0', '0');
        (new SaleService())->complete(['register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $joy['id'], 'unit_id' => $joy['unit'], 'qty' => '20']], 'invoice_discount' => '', 'payments' => [['method' => 'card', 'currency' => 'USD', 'amount' => '60']], 'change_currency' => 'USD', 'notes' => '', 'pin' => '']);
        $bs = (new AccountingService())->balanceSheet(date('Y-m-d'));
        $byKey = static fn (array $rows): array => array_column($rows, 'amount', 'key');
        assert_same('60.00', $byKey($bs['assets'])['1100'], 'the $60 paid by card sits in the bank');
        assert_same('0.00', $byKey($bs['assets'])['1000'], 'nothing in the drawer');
        assert_same(false, isset($byKey($bs['equity'])['3900']), 'no unexplained difference');
        $cf = (new AccountingService())->cashFlow(date('Y-m-01'), date('Y-m-d'));
        assert_contains('60.00', json_encode($cf), 'the card receipt shows in the cash flow');
    },

    'the backup service sees a time changed on the Backups page without a restart' => function (): void {
        $svc = new BackupService();
        assert_contains('nothing due (daily backup at 02:00)', $svc->serviceTick((int) strtotime(date('Y-m-d') . ' 01:00')), 'the default time, read into the cache');
        // the administrator saves 23:30 from another process (the web server): this process still holds the old cache
        Database::pdo()->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('backup_daily_time', '23:30') ON DUPLICATE KEY UPDATE setting_value = '23:30'");
        assert_contains('daily backup at 23:30', $svc->serviceTick((int) strtotime(date('Y-m-d') . ' 01:00')), 'the next tick reads the settings again');
        Settings::flush();
    },
];
