<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Settings;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\OpeningBalance;
use App\Models\Setting;
use App\Services\AccountingService;
use App\Services\ChartService;
use App\Services\JournalService;
use App\Services\Pricing;

/**
 * Accounts (administrator): the chart of accounts the owner keeps and grows (2026-11-03), the journal entries for what no
 * screen records, and the opening balances the records cannot know (2026-10-30).
 */
final class AccountingController extends Controller
{
    // ---------------------------------------------------------------- the chart of accounts

    public function chart(): void
    {
        $svc = new AccountingService();
        $used = [];
        foreach ((new Account())->own() as $a) {
            $used[(int) $a['id']] = (new Account())->hasLines((int) $a['id']);
        }
        $this->render('accounts/chart', [
            'accounts' => (new Account())->all(), 'expenseAccounts' => $svc->expenseAccounts(), 'used' => $used, 'types' => ChartService::TYPES,
        ], 'Chart of accounts');
    }

    public function saveChart(): void
    {
        $arr = static fn (string $key): array => is_array($_POST[$key] ?? null) ? $_POST[$key] : [];
        try {
            (new ChartService())->save($arr('num'), $arr('name'), $arr('closed'), $arr('exp_cat'), $arr('exp_num'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('accounts', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Chart of accounts saved.');
        redirect('accounts');
    }

    public function add(): void
    {
        try {
            $id = (new ChartService())->add($this->input('number'), $this->input('name'), $this->input('type'), isset($_POST['is_money']), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('accounts', [], ['add' => $e->getMessage()]);
        }
        $a = (new Account())->find($id);
        Flash::set('success', 'Account ' . $a['number'] . ' ' . $a['name'] . ' added.');
        redirect('accounts');
    }

    public function delete(): void
    {
        try {
            (new ChartService())->delete($this->inputInt('id'));
        } catch (\DomainException $e) {
            $this->failBack('accounts', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Account deleted.');
        redirect('accounts');
    }

    // ---------------------------------------------------------------- journal entries

    public function journal(): void
    {
        $from = $this->query('from') !== '' ? $this->query('from') : date('Y-m-01', strtotime('-2 months'));
        $to = $this->query('to') !== '' ? $this->query('to') : date('Y-m-d');
        $postable = array_values(array_filter((new Account())->all(), static fn (array $a): bool => AccountingService::canPost($a) && (int) $a['is_active'] === 1));
        $this->render('accounts/journal', [
            'entries' => (new JournalEntry())->recent($from, $to), 'postable' => $postable, 'types' => ChartService::TYPES, 'from' => $from, 'to' => $to,
        ], 'Journal entries');
    }

    public function journalSave(): void
    {
        $accounts = is_array($_POST['line_account'] ?? null) ? array_values($_POST['line_account']) : [];
        $debits = is_array($_POST['line_debit'] ?? null) ? array_values($_POST['line_debit']) : [];
        $credits = is_array($_POST['line_credit'] ?? null) ? array_values($_POST['line_credit']) : [];
        $lines = [];
        foreach ($accounts as $i => $account) {
            $lines[] = ['account_id' => (int) $account, 'debit' => (string) ($debits[$i] ?? ''), 'credit' => (string) ($credits[$i] ?? '')];
        }
        try {
            $id = (new JournalService())->post($this->input('entry_date'), $this->input('description'), $lines, Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('accounts/journal', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Entry #' . $id . ' saved.');
        redirect('accounts/journal');
    }

    public function journalReverse(): void
    {
        try {
            $id = (new JournalService())->reverse($this->inputInt('id'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('accounts/journal', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Entry #' . $this->inputInt('id') . ' reversed by entry #' . $id . '.');
        redirect('accounts/journal');
    }

    // ---------------------------------------------------------------- opening balances

    public function opening(): void
    {
        $this->render('accounts/opening', [
            'typed' => (new OpeningBalance())->all(), 'chart' => (new AccountingService())->chart(), 'codes' => AccountingService::TYPED,
            'fiscalMonth' => max(1, min(12, (int) Settings::get('fiscal_year_start_month', '1'))),
        ], 'Opening balances');
    }

    public function save(): void
    {
        $chart = (new AccountingService())->chart();
        try {
            $amounts = [];
            foreach (AccountingService::TYPED as $code) {
                $typed = trim($this->input('ob_' . $code));
                try {
                    $amounts[$code] = $typed === '' ? '0.00' : (string) Pricing::parse($typed);
                } catch (\DomainException) {
                    throw new \DomainException($chart[$code]['name'] . ': type an amount in dollars, or leave it empty.');
                }
            }
            $month = $this->inputInt('fiscal_year_start_month', 1);
            if ($month < 1 || $month > 12) {
                throw new \DomainException('Choose the first month of the fiscal year.');
            }
            $balances = new OpeningBalance();
            foreach ($amounts as $code => $amount) {
                $balances->set($code, $amount, Auth::id());
            }
            (new Setting())->setMany(['fiscal_year_start_month' => (string) $month]);
            Settings::flush();
        } catch (\DomainException $e) {
            $this->failBack('accounts/opening', [], ['form' => $e->getMessage()]);
        }
        Audit::log('accounts.opening', 'opening_balances', null, ['month' => $month]);
        Flash::set('success', 'Opening balances saved.');
        redirect('accounts/opening');
    }
}
