<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Gate;
use App\Core\RegisterDevice;
use App\Models\CashSession;
use App\Services\DashboardService;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $register = RegisterDevice::current();
        $this->render('dashboard/index', [
            'user'     => Auth::user(),
            'register' => $register,
            // Whoever has the drawer of this device's register: one register, one open session.
            'registerOpen' => $register === null ? null : (new CashSession())->openForRegister((int) $register['id']),
            'session'  => (new CashSession())->openForUser(Auth::id()),
            'dash'     => Gate::allows('report.sales') ? (new DashboardService())->summary() : null,   // the break-even (2026-10-20)
            'backup'   => Gate::allows('backup.manage') ? \App\Services\BackupService::status() : null,
        ], 'Dashboard');
    }

    /** The monthly fixed costs, typed on the dashboard by the administrator (2026-10-20). */
    public function saveFixedCosts(): void
    {
        $month = $this->input('month');
        try {
            $value = (new DashboardService())->setFixedCosts($month, $this->input('amount'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('dashboard', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Monthly fixed costs for ' . date('F Y', strtotime($month . '-01')) . ': ' . usd($value) . '.');
        redirect('dashboard');
    }
}
