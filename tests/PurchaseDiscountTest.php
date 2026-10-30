<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\PurchaseService;

/**
 * The supplier's discount on the bill (Aya, 2026-10-30): "10" dollars or "10%", spread across the lines in proportion
 * so each material goes into stock at its real net cost; the purchase keeps subtotal, discount and total; the supplier
 * is owed the net.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $box = make_product('Box 100g', 'piece', null, 'material');
    $boxU = $svc->addUnit($box, 'Piece', '1', false, true);
    $gelatin = make_product('Gelatin', 'g', null, 'material');
    $kg = $svc->addUnit($gelatin, 'kg', '1000', true, true);
    $supplier = (new PartyService())->saveSupplier(0, 'Sugar Co', '', '', true);

    return ['box' => $box, 'boxU' => $boxU, 'gelatin' => $gelatin, 'kg' => $kg, 'supplier' => $supplier];
};
$buy = static fn (array $s, string $discount, string $paid = ''): int => (new PurchaseService())->create($s['supplier'], 'SC-1', date('Y-m-d'),
    [['product_id' => $s['box'], 'unit_id' => $s['boxU'], 'qty' => '20', 'unit_cost' => '0.5'], ['product_id' => $s['gelatin'], 'unit_id' => $s['kg'], 'qty' => '30', 'unit_cost' => '3']],
    $paid, 'outside', '', TEST_ADMIN_ID, $discount);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a 10% discount on a $100 bill: $10 spread as $1 on the boxes and $9 on the gelatin; the costs are net; the supplier is owed $90' => function () use ($setup, $buy): void {
        $s = $setup();
        $id = $buy($s, '10%');
        $p = Database::pdo()->query('SELECT subtotal_usd, discount_usd, total_usd FROM purchases WHERE id = ' . $id)->fetch(PDO::FETCH_NUM);
        assert_same(['100.00', '10.00', '90.00'], $p);
        $items = Database::pdo()->query('SELECT unit_cost_usd, line_discount_usd, line_total_usd, cost_per_base FROM purchase_items WHERE purchase_id = ' . $id . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        assert_same([['0.50', '1.00', '9.00', '0.450000'], ['3.00', '9.00', '81.00', '0.002700']], $items, 'the typed cost stays, the line shows its share, the cost per base is net');
        assert_same(['0.450000', '0.002700'], [(new Product())->find($s['box'])['cost_per_base'], (new Product())->find($s['gelatin'])['cost_per_base']], 'stock came in at the net cost');
        $moves = Database::pdo()->query("SELECT cost_per_base FROM stock_movements WHERE type = 'purchase' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        assert_same(['0.450000', '0.002700'], $moves);
        assert_same('90.00', Database::pdo()->query('SELECT SUM(amount_usd) FROM supplier_ledger WHERE supplier_id = ' . $s['supplier'])->fetchColumn(), 'owed the net');
    },

    'a discount in dollars, with rounding left on the last line; none; too much; nonsense; paid now is capped at the net' => function () use ($setup, $buy): void {
        $s = $setup();
        $id = $buy($s, '7');   // 7% of 100: boxes 0.70, gelatin 6.30
        assert_same([['0.70', '9.30'], ['6.30', '83.70']], Database::pdo()->query('SELECT line_discount_usd, line_total_usd FROM purchase_items WHERE purchase_id = ' . $id . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        $id = $buy($s, '');
        assert_same(['100.00', '0.00', '100.00'], Database::pdo()->query('SELECT subtotal_usd, discount_usd, total_usd FROM purchases WHERE id = ' . $id)->fetch(PDO::FETCH_NUM));
        $e = assert_throws(DomainException::class, fn () => $buy($s, '150'));
        assert_contains('more than the purchase', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $buy($s, 'ten'));
        assert_contains('amount in dollars (10) or a percentage', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $buy($s, '10%', '95'));
        assert_contains('Paid now cannot exceed', $e->getMessage());
        assert_same(2, (int) Database::pdo()->query('SELECT COUNT(*) FROM purchases')->fetchColumn(), 'the refused ones wrote nothing');
    },

    'the form has the Discount box and the purchase page shows subtotal, discount and total' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $form = $client->get('purchases/create')->body;
        assert_contains('id="discount" name="discount"', $form);
        assert_contains('id="subtotal"', $form);
        $client->get('purchases/create');
        $r = $client->post('purchases/store', ['supplier_id' => (string) $s['supplier'], 'supplier_invoice_ref' => '', 'purchase_date' => date('Y-m-d'), 'paid_now' => '', 'notes' => '', 'discount' => '10%',
            'lines[0][product_id]' => (string) $s['box'], 'lines[0][unit_id]' => (string) $s['boxU'], 'lines[0][qty]' => '20', 'lines[0][unit_cost]' => '0.5',
            'lines[1][product_id]' => (string) $s['gelatin'], 'lines[1][unit_id]' => (string) $s['kg'], 'lines[1][qty]' => '30', 'lines[1][unit_cost]' => '3']);
        assert_same(302, $r->status, $r->body);
        $id = (int) Database::pdo()->query('SELECT MAX(id) FROM purchases')->fetchColumn();
        $page = $client->get('purchases/view', ['id' => $id])->body;
        foreach (['<dt>Subtotal</dt><dd>$100.00</dd>', '<dt>Discount</dt><dd>− $10.00', '<dd><strong>$90.00</strong></dd>', 'after −$9.00', '$0.002700/g'] as $needle) {
            assert_contains($needle, $page, $needle);
        }
    },
];
