<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Settings;
use App\Models\OpeningBalance;
use App\Models\Setting;
use App\Services\AccountingService;
use App\Services\Pricing;

/** The Opening balances page (2026-10-30): the figures the accounts cannot derive, typed once by the administrator. */
final class AccountingController extends Controller
{
    public function opening(): void
    {
        $this->render('accounts/opening', [
            'typed' => (new OpeningBalance())->all(), 'chart' => AccountingService::CHART, 'codes' => AccountingService::TYPED,
            'fiscalMonth' => max(1, min(12, (int) Settings::get('fiscal_year_start_month', '1'))),
        ], 'Opening balances');
    }

    public function save(): void
    {
        try {
            $balances = new OpeningBalance();
            foreach (AccountingService::TYPED as $code) {
                $typed = trim($this->input('ob_' . $code));
                $amount = $typed === '' ? '0.00' : Pricing::parse($typed, true);
                if ($amount === null || (float) $amount < 0) {
                    throw new \DomainException(AccountingService::CHART[$code][0] . ': type an amount in dollars, or leave it empty.');
                }
                $balances->set($code, $amount, Auth::id());
            }
            $month = $this->inputInt('fiscal_year_start_month', 1);
            if ($month < 1 || $month > 12) {
                throw new \DomainException('Choose the first month of the fiscal year.');
            }
            (new Setting())->setMany(['fiscal_year_start_month' => (string) $month]);
            Settings::flush();
        } catch (\DomainException $e) {
            $this->failBack('accounts/opening', [], ['form' => $e->getMessage()]);
        }
        \App\Core\Audit::log('accounts.opening', 'opening_balances', null, ['month' => $month]);
        Flash::set('success', 'Opening balances saved.');
        redirect('accounts/opening');
    }
}
