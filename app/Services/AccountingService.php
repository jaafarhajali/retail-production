<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;
use App\Models\ExchangeRate;
use App\Models\OpeningBalance;

/**
 * The accounting side (owner, 2026-10-30): a fixed chart of accounts, and the four statements built from the records
 * the system already keeps. Nothing is posted twice: every figure is read from sales, returns, purchases, expenses, the
 * customer and supplier ledgers, the stock movements and the cash sessions, at report time. What the system cannot
 * know (bank at the start, equipment, loans, capital) the owner types once on the Opening balances page.
 *
 * Money is in USD; a lira amount is converted at the rate of now (the drawers) or the rate stored on the row.
 * Cash: a drawer holds its movements while it is open; when it is counted and closed, the counted cash goes to
 * "Bank and owner's money" (1100), which is every dollar outside the drawers. Stock is valued at the cost each movement
 * carried, so the stock account and the cost of goods sold agree to the cent.
 */
final class AccountingService
{
    /** code => [name, type]; types: asset, liability, equity, income, expense. Expense categories are added at 61xx. */
    public const CHART = [
        '1000' => ['Cash in drawers', 'asset'],
        '1100' => ["Bank and owner's money", 'asset'],
        '1200' => ['Customers (what they owe)', 'asset'],
        '1300' => ['Stock at cost', 'asset'],
        '1500' => ['Equipment', 'asset'],
        '1600' => ['Other assets', 'asset'],
        '2000' => ['Suppliers (what we owe)', 'liability'],
        '2100' => ['Loans', 'liability'],
        '2200' => ['Other liabilities', 'liability'],
        '3000' => ['Capital', 'equity'],
        '3100' => ['Retained earnings', 'equity'],
        '3200' => ['Profit of the period', 'equity'],
        '3900' => ['Differences', 'equity'],
        '4000' => ['Sales', 'income'],
        '4100' => ['Sales returns', 'income'],
        '5000' => ['Cost of goods sold', 'expense'],
        '5100' => ['Stock losses and corrections', 'expense'],
        '6900' => ['Cash differences at closing', 'expense'],
    ];

    /** The accounts whose opening amount the owner types. */
    public const TYPED = ['1100', '1500', '1600', '2100', '2200', '3000'];

    private \PDO $pdo;
    private float $rate;

    public function __construct()
    {
        $this->pdo = Database::pdo();
        $this->rate = max(1, (int) (new ExchangeRate())->current());
    }

    // ---------------------------------------------------------------- the four statements

    /** @return array<string, mixed> the income statement over [from, to] */
    public function incomeStatement(string $from, string $to): array
    {
        $sales = $this->sum("SELECT COALESCE(SUM(total_usd), 0) FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", $from, $to);
        $returns = $this->sum('SELECT COALESCE(SUM(total_usd), 0) FROM returns WHERE created_at BETWEEN :f AND :t', $from, $to);
        $cogs = -$this->stockValue(['sale', 'return_restock', 'sale_void'], $from, $to);
        $losses = -$this->stockValue(['waste', 'adjustment', 'stocktake'], $from, $to);
        $expenses = $this->expensesByCategory($from, $to);
        $cashDiff = -$this->cashDifferences($from, $to);
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $gross = $sales - $returns - $cogs;
        $net = $gross - $losses - $totalExpenses - $cashDiff;

        return [
            'from' => $from, 'to' => $to,
            'sales' => Money::fmt($sales), 'returns' => Money::fmt($returns), 'net_sales' => Money::fmt($sales - $returns), 'cogs' => Money::fmt($cogs), 'gross' => Money::fmt($gross),
            'losses' => Money::fmt($losses), 'expenses' => $expenses, 'total_expenses' => Money::fmt($totalExpenses), 'cash_diff' => Money::fmt($cashDiff),
            'net' => Money::fmt($net), 'net_raw' => $net,
        ];
    }

    /** @return array<string, mixed> the balance sheet as of the end of $to; the period's profit runs from the fiscal year start */
    public function balanceSheet(string $to): array
    {
        $typed = (new OpeningBalance())->all();
        $yearStart = $this->fiscalYearStart($to);
        $assets = [
            '1000' => $this->cashInDrawers($to), '1100' => $this->bank($to, $typed), '1200' => $this->customers($to), '1300' => $this->stock($to),
            '1500' => (float) ($typed['1500'] ?? 0), '1600' => (float) ($typed['1600'] ?? 0),
        ];
        $liabilities = ['2000' => $this->suppliers($to), '2100' => (float) ($typed['2100'] ?? 0), '2200' => (float) ($typed['2200'] ?? 0)];
        $retained = $this->incomeStatement('1970-01-01', date('Y-m-d', strtotime($yearStart . ' -1 day')))['net_raw'];
        $period = $this->incomeStatement($yearStart, $to)['net_raw'];
        $equity = ['3000' => (float) ($typed['3000'] ?? 0) + $this->openingStock($to), '3100' => $retained, '3200' => $period];
        $difference = array_sum($assets) - array_sum($liabilities) - array_sum($equity);
        if (abs($difference) >= 0.005) {
            $equity['3900'] = $difference;   // what the figures typed do not explain: shown, never hidden
        }
        $fmt = static fn (array $side): array => array_map(static fn (string $code, float $v): array => ['code' => $code, 'name' => self::CHART[$code][0], 'amount' => Money::fmt($v)], array_keys($side), $side);

        return [
            'to' => $to, 'year_start' => $yearStart, 'assets' => $fmt($assets), 'liabilities' => $fmt($liabilities), 'equity' => $fmt($equity),
            'total_assets' => Money::fmt(array_sum($assets)), 'total_liabilities' => Money::fmt(array_sum($liabilities)), 'total_equity' => Money::fmt(array_sum($equity)),
            'total_right' => Money::fmt(array_sum($liabilities) + array_sum($equity)), 'difference' => Money::fmt($difference),
        ];
    }

    /** @return array<string, mixed> every account with its debit or credit balance as of $to; the period accounts since the fiscal year start */
    public function trialBalance(string $to): array
    {
        $bs = $this->balanceSheet($to);
        $is = $this->incomeStatement($bs['year_start'], $to);
        $rows = [];
        $add = static function (string $code, string $name, float $amount, string $type) use (&$rows): void {
            if (abs($amount) < 0.005) {
                return;
            }
            $debitSide = in_array($type, ['asset', 'expense'], true);   // a normal balance; a negative one flips the side
            $debit = ($debitSide && $amount > 0) || (!$debitSide && $amount < 0);
            $rows[] = ['code' => $code, 'name' => $name, 'debit' => $debit ? Money::fmt(abs($amount)) : '', 'credit' => $debit ? '' : Money::fmt(abs($amount))];
        };
        foreach ($bs['assets'] as $a) {
            $add($a['code'], $a['name'], (float) $a['amount'], 'asset');
        }
        foreach ($bs['liabilities'] as $l) {
            $add($l['code'], $l['name'], (float) $l['amount'], 'liability');
        }
        foreach ($bs['equity'] as $e) {
            if ($e['code'] !== '3200') {   // the period's profit is the income and expense accounts below, not a line of its own
                $add($e['code'], $e['name'], (float) $e['amount'], 'equity');
            }
        }
        $add('4000', self::CHART['4000'][0], (float) $is['sales'], 'income');
        $add('4100', self::CHART['4100'][0], -(float) $is['returns'], 'income');
        $add('5000', self::CHART['5000'][0], (float) $is['cogs'], 'expense');
        $add('5100', self::CHART['5100'][0], (float) $is['losses'], 'expense');
        foreach ($is['expenses'] as $x) {
            $add($x['code'], $x['name'], (float) $x['amount'], 'expense');
        }
        $add('6900', self::CHART['6900'][0], (float) $is['cash_diff'], 'expense');
        $debits = array_sum(array_map(static fn (array $r): float => (float) $r['debit'], $rows));
        $credits = array_sum(array_map(static fn (array $r): float => (float) $r['credit'], $rows));

        return ['to' => $to, 'year_start' => $bs['year_start'], 'rows' => $rows, 'total_debit' => Money::fmt($debits), 'total_credit' => Money::fmt($credits), 'balanced' => abs($debits - $credits) < 0.005];
    }

    /** @return array<string, mixed> where the money came from and went over [from, to], drawers and bank apart */
    public function cashFlow(string $from, string $to): array
    {
        $typed = (new OpeningBalance())->all();
        $before = date('Y-m-d', strtotime($from . ' -1 day'));
        $drawerIn = [
            'Cash sales' => $this->drawerMoves(['sale', 'change'], $from, $to), 'Debts collected in cash' => $this->drawerMoves(['debt_collection'], $from, $to),
            'Cash put in' => $this->drawerMoves(['cash_in', 'opening'], $from, $to), 'Cancelled sales' => $this->drawerMoves(['void_reversal'], $from, $to),
        ];
        $drawerOut = [
            'Refunds' => -$this->drawerMoves(['refund'], $from, $to), 'Expenses paid from the drawer' => -$this->drawerMoves(['expense', 'supplier_payment'], $from, $to),
            'Cash taken out' => -$this->drawerMoves(['cash_out'], $from, $to), 'Cash counted and taken at closing' => $this->countedCash($from, $to),
        ];
        $bankIn = [
            'Customer payments by card, Whish, transfer, cheque' => $this->bankReceipts($from, $to), 'Cash counted and taken from the drawers' => $this->countedCash($from, $to),
            'Cash taken out of the drawers' => -$this->drawerMoves(['cash_out'], $from, $to),
        ];
        $bankOut = [
            'Floats put into the drawers' => $this->drawerMoves(['opening', 'cash_in'], $from, $to), 'Expenses paid outside the drawer' => $this->expensesOutside($from, $to),
            'Supplier payments' => $this->supplierPayments($from, $to), 'Cash purchases without a supplier' => $this->cashPurchases($from, $to),
            'Production costs paid (labour, gas…)' => $this->productionCosts($from, $to),
        ];
        $line = static fn (array $items): array => array_values(array_filter(array_map(static fn (string $k, float $v): array => ['what' => $k, 'amount' => Money::fmt($v)], array_keys($items), $items), static fn (array $r): bool => abs((float) $r['amount']) >= 0.005));
        $open = ['drawers' => $this->cashInDrawers($before), 'bank' => $this->bank($before, $typed)];
        $close = ['drawers' => $this->cashInDrawers($to), 'bank' => $this->bank($to, $typed)];

        return [
            'from' => $from, 'to' => $to,
            'drawers' => ['opening' => Money::fmt($open['drawers']), 'in' => $line($drawerIn), 'out' => $line($drawerOut), 'total_in' => Money::fmt(array_sum($drawerIn)), 'total_out' => Money::fmt(array_sum($drawerOut)), 'closing' => Money::fmt($close['drawers'])],
            'bank' => ['opening' => Money::fmt($open['bank']), 'in' => $line($bankIn), 'out' => $line($bankOut), 'total_in' => Money::fmt(array_sum($bankIn)), 'total_out' => Money::fmt(array_sum($bankOut)), 'closing' => Money::fmt($close['bank'])],
            'opening_total' => Money::fmt($open['drawers'] + $open['bank']), 'closing_total' => Money::fmt($close['drawers'] + $close['bank']),
            'net_change' => Money::fmt($close['drawers'] + $close['bank'] - $open['drawers'] - $open['bank']),
        ];
    }

    /** The first day of the fiscal year that contains $date (Settings: fiscal_year_start_month, 1 = January). */
    public function fiscalYearStart(string $date): string
    {
        $month = max(1, min(12, (int) Settings::get('fiscal_year_start_month', '1')));
        $y = (int) substr($date, 0, 4);
        $start = sprintf('%04d-%02d-01', $y, $month);

        return $start <= $date ? $start : sprintf('%04d-%02d-01', $y - 1, $month);
    }

    // ---------------------------------------------------------------- the accounts, from the records

    /** What every open drawer holds at the end of $to: its movements up to then. A drawer closed by then holds nothing. */
    public function cashInDrawers(string $to): float
    {
        $st = $this->pdo->prepare("SELECT m.currency, SUM(m.amount) AS total FROM cash_movements m JOIN cash_sessions s ON s.id = m.session_id
                                   WHERE m.created_at <= :t AND s.opened_at <= :t2 AND (s.counted_at IS NULL OR s.counted_at > :t3) GROUP BY m.currency");
        $st->execute(['t' => $to . ' 23:59:59', 't2' => $to . ' 23:59:59', 't3' => $to . ' 23:59:59']);

        return $this->usdOf($st->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /** Every dollar outside the drawers: the opening typed, plus what came in and went out up to $to. */
    public function bank(string $to, array $typed): float
    {
        return (float) ($typed['1100'] ?? 0) + $this->countedCash('1970-01-01', $to) - $this->drawerMoves(['opening', 'cash_in'], '1970-01-01', $to) - $this->drawerMoves(['cash_out'], '1970-01-01', $to)
             + $this->bankReceipts('1970-01-01', $to) - $this->expensesOutside('1970-01-01', $to) - $this->supplierPayments('1970-01-01', $to) - $this->cashPurchases('1970-01-01', $to) - $this->productionCosts('1970-01-01', $to);
    }

    public function customers(string $to): float
    {
        return $this->sum('SELECT COALESCE(SUM(amount_usd), 0) FROM customer_ledger WHERE created_at BETWEEN :f AND :t', '1970-01-01', $to);
    }

    public function suppliers(string $to): float
    {
        return $this->sum('SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_ledger WHERE created_at BETWEEN :f AND :t', '1970-01-01', $to);
    }

    /** The stock at the cost each movement carried. */
    public function stock(string $to): float
    {
        return $this->stockValue(['opening', 'purchase', 'sale', 'sale_void', 'return_restock', 'waste', 'adjustment', 'stocktake', 'production_in', 'production_out'], '1970-01-01', $to);
    }

    /** Stock typed as "opening" on a product's page: the owner put it in, so it is capital. */
    private function openingStock(string $to): float
    {
        return $this->stockValue(['opening'], '1970-01-01', $to);
    }

    // ---------------------------------------------------------------- the flows

    /** @param list<string> $types */
    private function stockValue(array $types, string $from, string $to): float
    {
        $in = implode(',', array_map(fn (string $t): string => $this->pdo->quote($t), $types));
        return $this->sum("SELECT COALESCE(SUM(base_qty * cost_per_base), 0) FROM stock_movements WHERE type IN ({$in}) AND created_at BETWEEN :f AND :t", $from, $to);
    }

    /** @param list<string> $types */
    private function drawerMoves(array $types, string $from, string $to): float
    {
        $in = implode(',', array_map(fn (string $t): string => $this->pdo->quote($t), $types));
        $st = $this->pdo->prepare("SELECT currency, SUM(amount) AS total FROM cash_movements WHERE type IN ({$in}) AND created_at BETWEEN :f AND :t GROUP BY currency");
        $st->execute(['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']);

        return $this->usdOf($st->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /** The cash counted in the drawers closed in the period: it leaves the drawer for the bank / the owner. */
    private function countedCash(string $from, string $to): float
    {
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(counted_usd), 0) AS usd, COALESCE(SUM(counted_lbp), 0) AS lbp FROM cash_sessions WHERE status <> 'open' AND counted_at BETWEEN :f AND :t");
        $st->execute(['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: ['usd' => 0, 'lbp' => 0];

        return (float) $r['usd'] + (float) $r['lbp'] / $this->rate;
    }

    /** Counted against expected on the drawers closed in the period: negative is a shortage. */
    private function cashDifferences(string $from, string $to): float
    {
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(diff_usd), 0) AS usd, COALESCE(SUM(diff_lbp), 0) AS lbp FROM cash_sessions WHERE status <> 'open' AND counted_at BETWEEN :f AND :t");
        $st->execute(['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: ['usd' => 0, 'lbp' => 0];

        return (float) $r['usd'] + (float) $r['lbp'] / $this->rate;
    }

    /** Customer payments that did not go through a drawer: card, Whish, transfer, cheque (the ledger holds them negative). */
    private function bankReceipts(string $from, string $to): float
    {
        return -$this->sum("SELECT COALESCE(SUM(amount_usd), 0) FROM customer_ledger WHERE type = 'payment' AND session_id IS NULL AND created_at BETWEEN :f AND :t", $from, $to);
    }

    private function expensesOutside(string $from, string $to): float
    {
        return $this->sum("SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE supplier_id IS NULL AND paid_from = 'outside' AND expense_date BETWEEN :f AND :t", $from, $to);
    }

    private function supplierPayments(string $from, string $to): float
    {
        return -$this->sum("SELECT COALESCE(SUM(amount_usd), 0) FROM supplier_ledger WHERE type = 'payment' AND created_at BETWEEN :f AND :t", $from, $to);
    }

    private function cashPurchases(string $from, string $to): float
    {
        return $this->sum('SELECT COALESCE(SUM(total_usd), 0) FROM purchases WHERE supplier_id IS NULL AND purchase_date BETWEEN :f AND :t', $from, $to);
    }

    private function productionCosts(string $from, string $to): float
    {
        return $this->sum('SELECT COALESCE(SUM(c.amount_usd), 0) FROM production_costs c JOIN production_runs r ON r.id = c.run_id WHERE r.created_at BETWEEN :f AND :t', $from, $to);
    }

    /** @return list<array{code: string, name: string, amount: string}> one 61xx account per expense category, by name */
    private function expensesByCategory(string $from, string $to): array
    {
        $st = $this->pdo->prepare("SELECT category, SUM(amount_usd) AS total FROM expenses WHERE supplier_id IS NULL AND expense_date BETWEEN :f AND :t GROUP BY category ORDER BY category");
        $st->execute(['f' => $from, 't' => $to]);
        $out = [];
        $n = 0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $n++;
            $out[] = ['code' => (string) (6100 + $n), 'name' => $r['category'], 'amount' => Money::fmt((float) $r['total'])];
        }

        return $out;
    }

    private function sum(string $sql, string $from, string $to): float
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(['f' => strlen($from) === 10 && str_contains($sql, 'created_at') ? $from . ' 00:00:00' : $from, 't' => strlen($to) === 10 && str_contains($sql, 'created_at') ? $to . ' 23:59:59' : $to]);

        return (float) $st->fetchColumn();
    }

    /** @param array<string, mixed> $byCurrency USD and LBP totals */
    private function usdOf(array $byCurrency): float
    {
        return (float) ($byCurrency['USD'] ?? 0) + (float) ($byCurrency['LBP'] ?? 0) / $this->rate;
    }
}
