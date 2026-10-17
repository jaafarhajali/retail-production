<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\View;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;

/** Sales list, invoice view, receipt printing. Cashiers see their own session's sales. */
final class SaleController extends Controller
{
    public function index(): void
    {
        $all = Gate::allows('report.sales') || Gate::allows('session.view_all');
        $mine = (new CashSession())->openForUser(Auth::id());
        $filters = [
            'q' => mb_substr($this->query('q'), 0, 60), 'session_id' => $all ? $this->queryInt('session_id') : (int) ($mine['id'] ?? -1),
            'user_id' => $all ? $this->queryInt('user_id') : 0, 'customer_id' => $this->queryInt('customer_id'),
            'from' => $this->dateQuery('from'), 'to' => $this->dateQuery('to'),
        ];
        $this->render('sales/index', ['pg' => (new Sale())->search($filters, max(1, $this->queryInt('page', 1))), 'filters' => $filters, 'all' => $all, 'users' => $all ? (new User())->all() : []], 'Sales');
    }

    public function view(): void
    {
        $sale = $this->load($this->queryInt('id'));
        $sales = new Sale();
        $this->render('sales/view', [
            'sale' => $sale, 'items' => $sales->items((int) $sale['id']), 'payments' => $sales->payments((int) $sale['id']), 'returns' => (new SaleReturn())->forSale((int) $sale['id']),
            'canReturn' => Gate::allows('return.create'), 'canReprint' => Gate::allows('sale.reprint'),
        ], $sale['invoice_no']);
    }

    /**
     * F4 (2026-10-13): the A4 print. ?type=official forces the company invoice, ?type=normal the delivery note;
     * without it the sale's own type decides. The official one needs the customer's TVA number and address.
     */
    public function print(): void
    {
        $sale = $this->load($this->queryInt('id'));
        $sales = new Sale();
        $type = in_array($this->query('type'), ['official', 'normal'], true) ? $this->query('type') : $sale['invoice_type'];
        $items = $sales->items((int) $sale['id']);
        // The Code column: the barcode of the unit sold, else any barcode of the product (a box line shows the piece's code, as on the owner's sample).
        $barcodes = [];
        $productIds = array_map('intval', array_column($items, 'product_id'));
        if ($productIds !== []) {
            $byProduct = [];
            foreach (\App\Core\Database::pdo()->query('SELECT u.product_id, b.product_unit_id, b.barcode FROM barcodes b JOIN product_units u ON u.id = b.product_unit_id WHERE u.product_id IN (' . implode(',', $productIds) . ') ORDER BY b.id') as $b) {
                $barcodes[(int) $b['product_unit_id']] ??= $b['barcode'];
                $byProduct[(int) $b['product_id']] ??= $b['barcode'];
            }
            foreach ($items as $i) {
                $barcodes[(int) $i['product_unit_id']] ??= $byProduct[(int) $i['product_id']] ?? '';
            }
        }
        $onCredit = $sales->paidByMethod((int) $sale['id'])['credit'];
        $credit = (float) $onCredit > 0 && $sale['customer_id'] !== null && $sale['status'] === 'completed'
            ? ['usd' => $onCredit, 'balance' => (new \App\Models\Customer())->balanceAfterSale((int) $sale['customer_id'], (int) $sale['id'])]
            : null;
        View::render($type === 'official' ? 'sales/invoice' : 'sales/delivery-note', [
            'pageTitle' => ($type === 'official' ? 'Official invoice ' : 'Regular invoice ') . $sale['invoice_no'], 'sale' => $sale, 'items' => $items, 'barcodes' => $barcodes, 'credit' => $credit,
            'customer' => $sale['customer_id'] === null ? null : (new \App\Models\Customer())->find((int) $sale['customer_id']), 'dueDate' => $sale['due_date'] ?? (new \App\Services\InvoiceService())->dueDate($sale['customer_id'] === null ? null : (new \App\Models\Customer())->find((int) $sale['customer_id']), '', $sale['created_at']), 'auto' => $this->query('auto') === '1',
        ], 'layouts/print');
    }

    public function receipt(): void
    {
        $sale = $this->load($this->queryInt('id'));
        $sales = new Sale();
        $onCredit = $sales->paidByMethod((int) $sale['id'])['credit'];
        $credit = (float) $onCredit > 0 && $sale['customer_id'] !== null && $sale['status'] === 'completed'
            ? ['usd' => $onCredit, 'balance' => (new \App\Models\Customer())->balanceAfterSale((int) $sale['customer_id'], (int) $sale['id'])]
            : null;
        View::render('sales/receipt', [
            'pageTitle' => $sale['invoice_no'], 'sale' => $sale, 'items' => $sales->items((int) $sale['id']), 'payments' => $sales->payments((int) $sale['id']),
            'credit' => $credit,
            'copy' => $this->query('copy') === '1', 'width' => $this->query('w') === '58' ? 'w58' : '', 'auto' => $this->query('auto') === '1',
        ], 'layouts/receipt');
    }

    private function load(int $id): array
    {
        $sale = (new Sale())->find($id) ?? throw new HttpException(404);
        if ((int) $sale['user_id'] !== Auth::id() && !Gate::allows('report.sales') && !Gate::allows('session.view_all') && !Gate::allows('sale.reprint')) {
            throw new HttpException(403);
        }

        return $sale;
    }

    private function dateQuery(string $key): string
    {
        $v = $this->query($key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
