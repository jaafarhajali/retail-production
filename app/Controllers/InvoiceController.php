<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Settings;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Services\InvoiceService;
use App\Services\SaleService;

/** Desk invoices (2026-10-14): the list with what each one still owes, and the screen that writes one. */
final class InvoiceController extends Controller
{
    public function index(): void
    {
        $status = in_array($this->query('status'), ['open', 'overdue', 'paid', 'all'], true) ? $this->query('status') : 'open';
        $filters = ['q' => mb_substr($this->query('q'), 0, 60), 'session_id' => 0, 'user_id' => 0, 'customer_id' => $this->queryInt('customer_id'),
                    'from' => $this->dateQuery('from'), 'to' => $this->dateQuery('to'), 'origin' => 'desk'];
        $pg = (new Sale())->search($filters, max(1, $this->queryInt('page', 1)), 50);
        $items = (new InvoiceService())->forSales(array_column($pg['rows'], 'customer_id'));
        $rows = [];
        $open = 0.0;
        foreach ($pg['rows'] as $s) {
            $it = $items[(int) $s['id']] ?? null;
            $s['remaining'] = $it['remaining'] ?? '0.00';
            $s['paid'] = $it['paid'] ?? $s['total_usd'];
            $s['pay_status'] = $it['status'] ?? 'paid';
            $open += (float) $s['remaining'];
            if ($status === 'all' || ($status === 'paid' && $s['pay_status'] === 'paid') || ($status === 'overdue' && $s['pay_status'] === 'overdue') || ($status === 'open' && $s['pay_status'] !== 'paid')) {
                $rows[] = $s;
            }
        }
        $this->render('invoices/index', ['rows' => $rows, 'pg' => $pg, 'filters' => $filters, 'status' => $status, 'open' => $open, 'customers' => (new Customer())->all(true)], 'Invoices');
    }

    public function create(): void
    {
        $products = new Product();
        $finished = array_values(array_filter($products->forPrint('finished'), static fn (array $p): bool => (int) $p['is_active'] === 1));
        $units = (new ProductUnit())->forProducts(array_map('intval', array_column($finished, 'id')));
        $catalogue = [];
        foreach ($finished as $p) {
            $list = [];
            foreach ($units[(int) $p['id']] ?? [] as $u) {
                $list[] = ['id' => (int) $u['id'], 'name' => $u['name'], 'factor' => (int) $u['factor'], 'fraction' => (int) $u['allows_fraction'] === 1,
                           'retail' => $u['retail_price'], 'wholesale' => $u['wholesale_price'], 'main' => (int) $u['is_default_sale'] === 1];
            }
            if ($list !== []) {
                $catalogue[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'stock' => (int) $p['stock_base'], 'units' => $list,
                    'filled' => $p['fill_mix_id'] !== null, 'fill' => $p['fill_base'] !== null ? (int) $p['fill_base'] : null];   // filled at the sale (2026-10-18)
            }
        }
        $svc = new InvoiceService();
        $model = new Customer();
        $customers = array_map(static fn (array $c): array => $c + ['terms_days' => $svc->termsDays($c), 'agreed' => $model->agreedPrices((int) $c['id'])], $model->all(true));
        $this->render('invoices/new', ['customers' => $customers, 'catalogue' => $catalogue, 'defaultTerms' => $svc->termsDays(null)], 'New invoice');
    }

    public function store(): void
    {
        $lines = [];
        foreach (is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [] as $l) {
            if (!is_array($l) || (int) ($l['product_id'] ?? 0) === 0) {
                continue;
            }
            $lines[] = ['product_id' => (int) $l['product_id'], 'unit_id' => (int) ($l['unit_id'] ?? 0), 'qty' => (string) ($l['qty'] ?? ''), 'discount' => (string) ($l['discount'] ?? ''), 'note' => (string) ($l['note'] ?? ''), 'fill' => (string) ($l['fill'] ?? '')];
        }
        try {
            $r = (new SaleService())->issue([
                'user_id' => Auth::id(), 'customer_id' => $this->inputInt('customer_id'), 'price_level' => $this->input('price_level'), 'lines' => $lines,
                'invoice_discount' => $this->input('invoice_discount'), 'notes' => $this->input('notes'), 'invoice_type' => $this->input('invoice_type'),
                'due_date' => $this->input('due_date'), 'ship_to' => $this->input('ship_to'),
            ]);
        } catch (\DomainException $e) {
            $this->failBack('invoices/new', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', $r['invoice_no'] . ' issued: ' . usd($r['total_usd']) . ', due ' . date('d/m/Y', strtotime($r['due_date'])) . '.' . ($r['warnings'] !== [] ? ' ' . implode(' ', $r['warnings']) : ''));
        redirect('sales/view', ['id' => $r['id']]);
    }

    /** Record a payment that arrived by bank, cheque or card. */
    public function pay(): void
    {
        $customerId = $this->queryInt('customer_id');
        $this->render('invoices/pay', [
            'customers' => (new Customer())->all(true), 'customerId' => $customerId, 'rate' => (new \App\Models\ExchangeRate())->current(),
            'outstanding' => $customerId > 0 ? (new InvoiceService())->outstanding($customerId) : ['items' => [], 'unapplied' => '0.00'],
        ], 'Record a payment');
    }

    public function payStore(): void
    {
        $customerId = $this->inputInt('customer_id');
        try {
            $r = (new InvoiceService())->recordPayment($customerId, $this->input('usd'), $this->input('lbp'), $this->input('method'), $this->input('reference'), $this->input('note'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('invoices/pay', $customerId > 0 ? ['customer_id' => $customerId] : [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', usd($r['paid_usd']) . ' received. ' . ((float) $r['balance'] > 0.004 ? 'Still owed: ' . usd($r['balance']) . '.' : 'Nothing owed now.'));
        redirect('invoices/pay', ['customer_id' => $customerId]);
    }

    /** A credit note against a desk invoice: what came back. */
    public function credit(): void
    {
        $sales = new Sale();
        $sale = $sales->find($this->queryInt('id')) ?? throw new \App\Core\HttpException(404);
        if ($sale['status'] !== 'completed' || $sale['customer_id'] === null) {
            throw new \DomainException('A credit note is written against an issued invoice with a customer.');
        }
        $this->render('invoices/credit', ['sale' => $sale, 'items' => $sales->items((int) $sale['id'])], 'Credit note · ' . $sale['invoice_no']);
    }

    public function creditStore(): void
    {
        $saleId = $this->inputInt('sale_id');
        $items = [];
        foreach (is_array($_POST['items'] ?? null) ? $_POST['items'] : [] as $itemId => $it) {
            if (is_array($it)) {
                $items[] = ['sale_item_id' => (int) $itemId, 'qty' => (string) ($it['qty'] ?? ''), 'condition' => (string) ($it['condition'] ?? 'restock')];
            }
        }
        try {
            $r = (new \App\Services\ReturnService())->creditNote($saleId, $items, $this->input('reason'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('invoices/credit', ['id' => $saleId], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Credit note ' . $r['return_no'] . ': ' . usd($r['total_usd']) . ' credited. ' . ((float) $r['balance'] > 0.004 ? 'Still owed: ' . usd($r['balance']) . '.' : 'Nothing owed now.'));
        redirect('returns/view', ['id' => $r['id']]);
    }

    private function dateQuery(string $key): string
    {
        $v = $this->query($key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
