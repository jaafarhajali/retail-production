<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\View;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\ReportService;

/** One page, several reports; each checks its own permission key (spec §16). */
final class ReportController extends Controller
{
    private const TYPES = [
        'sales' => 'report.sales', 'payments' => 'report.sales', 'profit' => 'report.profit', 'credit' => 'report.sales',
        'stock' => 'report.stock', 'purchases' => 'report.stock', 'returns' => 'report.sales', 'waste' => 'report.stock', 'sessions' => 'report.cash',
        'production' => 'report.production', 'products' => 'report.sales', 'aging' => 'report.sales',
    ];

    public function index(): void
    {
        $type = $this->query('type') ?: 'sales';
        if (!isset(self::TYPES[$type])) {
            throw new HttpException(404);
        }
        if (!Gate::allows(self::TYPES[$type])) {
            throw new HttpException(403);
        }
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('from')) ? $this->query('from') : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('to')) ? $this->query('to') : date('Y-m-d');
        $month = preg_match('/^\d{4}-\d{2}$/', $this->query('month')) ? $this->query('month') : '';
        if ($month !== '' && $this->query('from') === '') {   // F5: the products report is picked by month; a from/to range still works
            $from = $month . '-01';
            $to = date('Y-m-t', strtotime($from));
        }
        $by = in_array($this->query('by'), ['day', 'cashier', 'product', 'level'], true) ? $this->query('by') : 'day';
        $r = new ReportService();
        $data = match ($type) {
            'sales' => ['rows' => $r->sales($from, $to, $by)],
            'payments' => ['rows' => $r->payments($from, $to)],
            'profit' => ['profit' => $r->profit($from, $to)],
            'credit' => ['rows' => $r->credit()],
            'stock' => $this->stockData(),
            'purchases' => ['rows' => $r->purchases($from, $to)],
            'returns' => ['rows' => $r->returns($from, $to)],
            'waste' => ['rows' => $r->waste($from, $to)],
            'sessions' => ['rows' => $r->sessions($from, $to)],
            'production' => ['rows' => (new \App\Models\ProductionRun())->byProduct($from, $to), 'consumed' => (new \App\Models\ProductionRun())->consumed($from, $to)],
            'products' => $this->productsData($r, $from, $to),
            'aging' => ['rows' => (new \App\Services\InvoiceService())->aging()],
        };
        $available = array_keys(array_filter(self::TYPES, static fn (string $perm): bool => Gate::allows($perm)));
        $vars = $data + ['type' => $type, 'from' => $from, 'to' => $to, 'by' => $by, 'month' => $month !== '' ? $month : substr($from, 0, 7), 'available' => $available, 'showCost' => Gate::allows('product.view_cost')];
        if ($this->query('print') === '1') {
            View::render('reports/' . $type, $vars + ['pageTitle' => ucfirst($type) . ' report', 'printing' => true], 'layouts/print');

            return;
        }
        $this->render('reports/index', $vars, 'Reports');
    }

    /** F5: every product sold in the period, net of returns, with the filters the owner asked for. */
    private function productsData(ReportService $r, string $from, string $to): array
    {
        $filters = [
            'level' => in_array($this->query('level'), ['retail', 'wholesale'], true) ? $this->query('level') : '',
            'product_id' => $this->queryInt('product_id'), 'customer_id' => $this->queryInt('customer_id'), 'user_id' => $this->queryInt('user_id'),
        ];
        $text = [];
        if ($filters['level'] !== '') { $text[] = $filters['level']; }
        if ($filters['product_id'] > 0) { $text[] = (string) ((new Product())->find($filters['product_id'])['name'] ?? ''); }
        if ($filters['customer_id'] > 0) { $text[] = (string) ((new \App\Models\Customer())->find($filters['customer_id'])['name'] ?? ''); }
        if ($filters['user_id'] > 0) { $text[] = (string) ((new \App\Models\User())->find($filters['user_id'])['username'] ?? ''); }

        return ['rows' => $r->productSales($from, $to, $filters), 'filters' => $filters, 'filterText' => implode(', ', array_filter($text)),
                'productList' => (new Product())->forPrint('finished'), 'customerList' => (new \App\Models\Customer())->all(true), 'userList' => (new \App\Models\User())->all()];
    }

    private function stockData(): array
    {
        $products = (new Product())->forPrint();

        return ['rows' => $products, 'unitsById' => (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id')))];
    }
}
