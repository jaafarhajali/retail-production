<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Customer;
use App\Services\PartyService;

final class CustomerController extends Controller
{
    public function index(): void
    {
        $q = $this->query('q');
        $customers = new Customer();
        $this->render('customers/index', ['customers' => $q !== '' ? $customers->search($q) : $customers->all(), 'q' => $q], 'Customers');
    }

    public function edit(): void
    {
        $id = $this->queryInt('id');
        $customer = $id > 0 ? ((new Customer())->find($id) ?? throw new HttpException(404)) : null;
        $priceList = [];
        if ($id > 0) {   // every finished product and its units, with the agreed price if any (2026-10-14)
            $products = new \App\Models\Product();
            $finished = $products->forPrint('finished');
            $units = (new \App\Models\ProductUnit())->forProducts(array_map('intval', array_column($finished, 'id')));
            $agreed = (new Customer())->agreedPrices($id);
            foreach ($finished as $p) {
                foreach ($units[(int) $p['id']] ?? [] as $u) {
                    $priceList[] = ['unit_id' => (int) $u['id'], 'product' => $p['name'], 'unit' => $u['name'], 'retail' => $u['retail_price'], 'wholesale' => $u['wholesale_price'], 'agreed' => $agreed[(int) $u['id']] ?? ''];
                }
            }
        }
        $this->render('customers/edit', ['customer' => $customer, 'ledger' => $id > 0 ? (new Customer())->ledger($id) : [], 'priceList' => $priceList, 'canInvoice' => \App\Core\Gate::allows('invoice.manage')], $customer === null ? 'New customer' : $customer['name']);
    }

    /** The customer's agreed prices (2026-10-14). */
    public function prices(): void
    {
        $id = $this->inputInt('id');
        try {
            (new PartyService())->saveAgreedPrices($id, is_array($_POST['prices'] ?? null) ? $_POST['prices'] : []);
        } catch (\DomainException $e) {
            $this->failBack('customers/edit', ['id' => $id], ['prices' => $e->getMessage()]);
        }
        Flash::set('success', 'Agreed prices saved.');
        redirect('customers/edit', ['id' => $id]);
    }

    /** The statement of account, printed for the customer (2026-10-14). */
    public function statement(): void
    {
        $id = $this->queryInt('id');
        $customer = (new Customer())->find($id) ?? throw new HttpException(404);
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('from')) ? $this->query('from') : date('Y-m-01', strtotime('-2 months'));
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('to')) ? $this->query('to') : date('Y-m-d');
        \App\Core\View::render('customers/statement', (new \App\Services\InvoiceService())->statement($id, $from, $to) + [
            'customer' => $customer, 'from' => $from, 'to' => $to, 'pageTitle' => 'Statement · ' . $customer['name'], 'auto' => $this->query('auto') === '1',
        ], 'layouts/print');
    }

    public function save(): void
    {
        $id = $this->inputInt('id');
        try {
            $id = (new PartyService())->saveCustomer($id, [
                'name' => $this->input('name'), 'phone' => $this->input('phone'), 'notes' => $this->input('notes'), 'tva_number' => $this->input('tva_number'), 'address' => $this->input('address'),
                'default_price_level' => $this->input('default_price_level'), 'credit_limit_usd' => $this->input('credit_limit_usd'), 'payment_terms_days' => $this->input('payment_terms_days'),
                'is_active' => $id === 0 || isset($_POST['is_active']), 'allow_same_name' => isset($_POST['allow_same_name']),
            ]);
        } catch (\DomainException $e) {
            $this->failBack('customers/edit', $id > 0 ? ['id' => $id] : [], [$e->getCode() === PartyService::SAME_NAME ? 'same_name' : 'name' => $e->getMessage()]);
        }
        Flash::set('success', 'Customer saved.');
        redirect('customers/edit', ['id' => $id]);
    }

    public function adjust(): void
    {
        $id = $this->inputInt('id');
        try {
            (new PartyService())->adjustCustomer($id, $this->input('amount'), $this->input('note'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('customers/edit', ['id' => $id], ['amount' => $e->getMessage()]);
        }
        Flash::set('success', 'Balance adjusted.');
        redirect('customers/edit', ['id' => $id]);
    }
}
