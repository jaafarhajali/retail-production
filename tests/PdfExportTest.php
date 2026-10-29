<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Models\Register;
use App\Services\CashService;
use App\Services\PartyService;
use App\Services\PdfService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * PDF export (owner, 2026-10-29): the Official and Regular invoice prints, the statement of account and every report
 * can be saved as a PDF file. The print page is rendered to a file by Chrome or Edge, headless, so the Arabic names,
 * the logo and the stylesheet come out exactly as on paper.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $joy = make_product('Joy 100g', 'piece');
    $piece = $svc->addUnit($joy, 'Piece', '1', false, true);
    $svc->setPrices($piece, '3', '2.50');
    (new StockService())->adjust($joy, $piece, '50', 'opening', '', null, TEST_ADMIN_ID);
    $register = (new Register())->create('Register 01');
    $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
    $market = (new PartyService())->saveCustomer(0, ['name' => 'شركة متاجر العاملية', 'phone' => '07/225999', 'tva_number' => '2941978-601', 'address' => 'صيدا', 'notes' => '', 'default_price_level' => 'wholesale', 'credit_limit_usd' => '', 'terms_days' => '']);
    $inv = (new SaleService())->issue(['user_id' => TEST_ADMIN_ID, 'customer_id' => $market, 'price_level' => 'wholesale',
        'lines' => [['product_id' => $joy, 'unit_id' => $piece, 'qty' => '10']], 'invoice_discount' => '', 'notes' => '', 'invoice_type' => 'official', 'due_date' => '', 'ship_to' => '']);

    return ['sale' => $inv['id'], 'customer' => $market];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a browser is found and prints a page to a real PDF file' => function (): void {
        assert_true(PdfService::browser() !== null, 'Chrome or Edge is installed on this machine');
        $path = PdfService::fromHtml('<!doctype html><html><head><meta charset="utf-8"></head><body><h1>Malika</h1><p dir="auto">شركة متاجر العاملية</p></body></html>');
        assert_true(is_file($path) && filesize($path) > 500, 'a file came out');
        assert_same('%PDF', (string) file_get_contents($path, false, null, 0, 4), 'and it is a PDF');
        unlink($path);
    },

    'the invoice prints, the statement and the reports download as PDF; the print bar offers it' => function () use ($setup): void {
        $s = $setup();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        // the print bar: Print, and Save as PDF beside it, pointing at the same page with pdf=1 and no auto-print
        $page = $client->get('sales/print', ['id' => $s['sale'], 'auto' => 1])->body;
        assert_contains('Save as PDF', $page);
        assert_contains('r=sales/print&amp;id=' . $s['sale'] . '&amp;pdf=1', $page);
        assert_not_contains('auto=1&amp;pdf=1', $page, 'the PDF link drops the auto-print flag');
        // the Official invoice as a file
        $r = $client->get('sales/print', ['id' => $s['sale'], 'pdf' => 1]);
        assert_same(200, $r->status, substr($r->body, 0, 200));
        assert_contains('application/pdf', $r->headers['content-type'] ?? '');
        assert_same('%PDF', substr($r->body, 0, 4));
        assert_contains('INV-000001', $r->headers['content-disposition'] ?? '', 'the file is named after the invoice');
        // the Regular invoice too
        $r = $client->get('sales/print', ['id' => $s['sale'], 'type' => 'normal', 'pdf' => 1]);
        assert_same(['200', '%PDF'], [(string) $r->status, substr($r->body, 0, 4)]);
        assert_contains('Regular', $r->headers['content-disposition'] ?? '');
        // the statement of account
        $r = $client->get('customers/statement', ['id' => $s['customer'], 'pdf' => 1]);
        assert_same(['200', '%PDF'], [(string) $r->status, substr($r->body, 0, 4)]);
        assert_contains('Statement', $r->headers['content-disposition'] ?? '');
        // a report, and its page has the button next to Print
        assert_contains('pdf=1', $client->get('reports', ['type' => 'sales'])->body);
        $r = $client->get('reports', ['type' => 'sales', 'pdf' => 1]);
        assert_same(['200', '%PDF'], [(string) $r->status, substr($r->body, 0, 4)]);
        assert_contains('Sales report', $r->headers['content-disposition'] ?? '');
        // nothing is left behind in the temp folder
        assert_same([], glob(STORAGE_PATH . '/tmp/pdf-*') ?: [], 'temporary files are removed');
    },
];
