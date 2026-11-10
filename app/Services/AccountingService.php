<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\OpeningBalance;

/**
 * The accounting side (owner, 2026-10-30): a chart of accounts, and the four statements built from the records
 * the system already keeps. Nothing is posted twice: every figure is read from sales, returns, purchases, expenses, the
 * customer and supplier ledgers, the stock movements and the cash sessions, at report time. What the system cannot
 * know (bank at the start, equipment, loans, capital) the owner types once on the Opening balances page.
 *
 * Money is in USD; a lira amount is converted at the rate of now (the drawers) or the rate stored on the row.
 * Cash: a drawer holds its movements while it is open; when it is counted and closed, the counted cash goes to
 * "Bank and owner's money" (1100), which is every dollar outside the drawers. Stock is valued at the cost each movement
 * carried, so the stock account and the cost of goods sold agree to the cent.
 *
 * The chart grows with the business (2026-11-03, table accounts): the built-in accounts keep what feeds them, the owner
 * numbers and names them, and adds his own (a second bank, vehicles, a loan, depreciation). His own accounts, and the
 * typed built-ins (bank, equipment, loans, capital), move by journal entries (JournalService); every entry balances,
 * so the balance sheet and the trial balance still balance to the cent.
 */
final class AccountingService
{
    /** code => [name, type]; types: asset, liability, equity, income, expense. Their rows are in the accounts table (migration 029), with the owner's numbers and names; expense categories take 6101 on. */
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

    /** The first number an expense category takes when the owner has not given it one. */
    public const FIRST_EXPENSE_NUMBER = 6101;

    private \PDO $pdo;
    private float $rate;
    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;
    /** @var array<string, array<int, array{d: float, c: float}>> */
    private array $journalCache = [];

    public function __construct()
    {
        $this->pdo = Database::pdo();
        $this->rate = max(1, (int) (new ExchangeRate())->current());
    }

    // ---------------------------------------------------------------- the chart, with the owner's numbers and names

    /** @return array<string, array{id: int, number: string, name: string, type: string}> the built-in accounts by key, with the owner's numbers and names */
    public function chart(): array
    {
        $rows = $this->bySystemKey();
        $out = [];
        foreach (self::CHART as $key => [$name, $type]) {
            $key = (string) $key;
            $r = $rows[$key] ?? null;
            $out[$key] = ['id' => (int) ($r['id'] ?? 0), 'number' => (string) ($r['number'] ?? $key), 'name' => ($r['name'] ?? '') !== '' ? (string) $r['name'] : $name, 'type' => $type];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the owner's own accounts, by number */
    public function ownAccounts(): array
    {
        return array_values(array_filter($this->rows(), static fn (array $r): bool => $r['system_key'] === null));
    }

    /** A journal entry may touch the owner's own accounts and the built-in ones that are typed, never what the records feed. */
    public static function canPost(array $account): bool
    {
        return $account['system_key'] === null || in_array((string) $account['system_key'], self::TYPED, true);
    }

    /**
     * Every expense category ever used, in the order of first use, with its number: the owner's, or else the first free
     * one from 6101. Numbering by first use means a new category never renumbers the old ones.
     *
     * @return array<string, array{key: string, category: string, number: string}> by lower-cased category
     */
    public function expenseAccounts(): array
    {
        $own = $this->bySystemKey();
        $taken = array_map(static fn (array $r): string => mb_strtolower((string) $r['number']), $this->rows());
        $next = self::FIRST_EXPENSE_NUMBER;
        $out = [];
        foreach ($this->pdo->query('SELECT category, MIN(id) AS first FROM expenses WHERE supplier_id IS NULL GROUP BY category ORDER BY first')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $lower = mb_strtolower((string) $r['category']);
            $key = 'exp:' . $lower;
            if (isset($own[$key])) {
                $number = (string) $own[$key]['number'];
            } else {
                while (in_array((string) $next, $taken, true)) {
                    $next++;
                }
                $number = (string) $next;
                $taken[] = $number;
            }
            $out[$lower] = ['key' => $key, 'category' => (string) $r['category'], 'number' => $number];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> every account row, by number */
    private function rows(): array
    {
        return $this->rows ??= (new Account())->all();
    }

    /** @return array<string, array<string, mixed>> the accounts the records feed, by key ('1100', 'exp:rent') */
    private function bySystemKey(): array
    {
        $out = [];
        foreach ($this->rows() as $r) {
            if ($r['system_key'] !== null) {
                $out[(string) $r['system_key']] = $r;
            }
        }

        return $out;
    }

    /** @return array<int, array{d: float, c: float}> the journal's debits and credits per account over [from, to] */
    private function journal(string $from, string $to): array
    {
        $key = $from . '|' . $to;
        if (!isset($this->journalCache[$key])) {
            $st = $this->pdo->prepare('SELECT l.account_id, SUM(l.debit_usd) AS d, SUM(l.credit_usd) AS c FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                                       WHERE e.entry_date BETWEEN :f AND :t GROUP BY l.account_id');
            $st->execute(['f' => $from, 't' => $to]);
            $out = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['account_id']] = ['d' => (float) $r['d'], 'c' => (float) $r['c']];
            }
            $this->journalCache[$key] = $out;
        }

        return $this->journalCache[$key];
    }

    /** The journal's effect on one account over [from, to], on its normal side: debit − credit for assets and expenses, the reverse for the rest. */
    private function journalOn(int $accountId, string $type, string $from, string $to): float
    {
        $j = $this->journal($from, $to)[$accountId] ?? ['d' => 0.0, 'c' => 0.0];

        return in_array($type, ['asset', 'expense'], true) ? $j['d'] - $j['c'] : $j['c'] - $j['d'];
    }

    /** A typed opening, moved by the journal since. */
    private function typedAccount(string $key, array $typed, string $to): float
    {
        $acc = $this->chart()[$key];

        return (float) ($typed[$key] ?? 0) + $this->journalOn($acc['id'], $acc['type'], '1970-01-01', $to);
    }

    /** @return list<array{key: string, code: string, name: string, amount: float}> the owner's own accounts of these types, with their balance up to $to (or over [from, to]) */
    private function ownBalances(array $types, string $from, string $to, bool $keepZero): array
    {
        $out = [];
        foreach ($this->ownAccounts() as $r) {
            if (!in_array($r['type'], $types, true)) {
                continue;
            }
            $amount = $this->journalOn((int) $r['id'], (string) $r['type'], $from, $to);
            if (abs($amount) >= 0.005 || ($keepZero && (int) $r['is_active'] === 1)) {
                $out[] = ['key' => 'id:' . $r['id'], 'code' => (string) $r['number'], 'name' => (string) $r['name'], 'amount' => $amount];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- the four statements

    /** @return array<string, mixed> the income statement over [from, to] */
    public function incomeStatement(string $from, string $to): array
    {
        $sales = $this->sum("SELECT COALESCE(SUM(total_usd), 0) FROM sales WHERE status = 'completed' AND created_at BETWEEN :f AND :t", $from, $to);
        $returns = $this->sum('SELECT COALESCE(SUM(total_usd), 0) FROM returns WHERE created_at BETWEEN :f AND :t', $from, $to);
        $cogs = -$this->stockValue(['sale', 'return_restock', 'sale_void'], $from, $to);
        $losses = -$this->stockValue(['waste', 'adjustment', 'stocktake'], $from, $to);
        $fmt = static fn (array $rows): array => array_map(static fn (array $r): array => ['key' => $r['key'], 'code' => $r['code'], 'name' => $r['name'], 'amount' => Money::fmt($r['amount'])], $rows);
        // the expense categories, and the owner's own expense accounts (depreciation, interest paid…) from the journal
        $expenses = array_merge($this->expensesByCategory($from, $to), $fmt($this->ownBalances(['expense'], $from, $to, false)));
        usort($expenses, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
        $otherIncome = $fmt($this->ownBalances(['income'], $from, $to, false));
        $cashDiff = -$this->cashDifferences($from, $to);
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $totalOther = array_sum(array_column($otherIncome, 'amount'));
        $gross = $sales - $returns - $cogs;
        $net = $gross + $totalOther - $losses - $totalExpenses - $cashDiff;

        return [
            'from' => $from, 'to' => $to,
            'sales' => Money::fmt($sales), 'returns' => Money::fmt($returns), 'net_sales' => Money::fmt($sales - $returns), 'cogs' => Money::fmt($cogs), 'gross' => Money::fmt($gross),
            'other_income' => $otherIncome, 'total_other_income' => Money::fmt($totalOther),
            'losses' => Money::fmt($losses), 'expenses' => $expenses, 'total_expenses' => Money::fmt($totalExpenses), 'cash_diff' => Money::fmt($cashDiff),
            'net' => Money::fmt($net), 'net_raw' => $net,
        ];
    }

    /** @return array<string, mixed> the balance sheet as of the end of $to; the period's profit runs from the fiscal year start */
    public function balanceSheet(string $to): array
    {
        $typed = (new OpeningBalance())->all();
        $yearStart = $this->fiscalYearStart($to);
        $chart = $this->chart();
        $line = static fn (string $key, float $v): array => ['key' => $key, 'code' => $chart[$key]['number'], 'name' => $chart[$key]['name'], 'amount' => $v];
        $byNumber = static function (array $rows): array {
            usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
            return $rows;
        };
        // the built-in accounts, then the owner's own (fed by the journal), each side by number
        $assets = $byNumber(array_merge([
            $line('1000', $this->cashInDrawers($to)), $line('1100', $this->bank($to, $typed)), $line('1200', $this->customers($to)), $line('1300', $this->stock($to)),
            $line('1500', $this->typedAccount('1500', $typed, $to)), $line('1600', $this->typedAccount('1600', $typed, $to)),
        ], $this->ownBalances(['asset'], '1970-01-01', $to, true)));
        $liabilities = $byNumber(array_merge([
            $line('2000', $this->suppliers($to)), $line('2100', $this->typedAccount('2100', $typed, $to)), $line('2200', $this->typedAccount('2200', $typed, $to)),
        ], $this->ownBalances(['liability'], '1970-01-01', $to, true)));
        $retained = $this->incomeStatement('1970-01-01', date('Y-m-d', strtotime($yearStart . ' -1 day')))['net_raw'];
        $period = $this->incomeStatement($yearStart, $to)['net_raw'];
        $equity = array_merge(
            $byNumber(array_merge([$line('3000', $this->typedAccount('3000', $typed, $to) + $this->openingStock($to))], $this->ownBalances(['equity'], '1970-01-01', $to, true))),
            [$line('3100', $retained), $line('3200', $period)]
        );
        $sum = static fn (array $rows): float => array_sum(array_column($rows, 'amount'));
        $difference = $sum($assets) - $sum($liabilities) - $sum($equity);
        if (abs($difference) >= 0.005) {
            $equity[] = $line('3900', $difference);   // what the figures typed do not explain: shown, never hidden
        }
        $fmt = static fn (array $rows): array => array_map(static fn (array $r): array => ['key' => $r['key'], 'code' => $r['code'], 'name' => $r['name'], 'amount' => Money::fmt($r['amount'])], $rows);

        return [
            'to' => $to, 'year_start' => $yearStart, 'assets' => $fmt($assets), 'liabilities' => $fmt($liabilities), 'equity' => $fmt($equity),
            'total_assets' => Money::fmt($sum($assets)), 'total_liabilities' => Money::fmt($sum($liabilities)), 'total_equity' => Money::fmt($sum($equity)),
            'total_right' => Money::fmt($sum($liabilities) + $sum($equity)), 'difference' => Money::fmt($difference),
        ];
    }

    /** @return array<string, mixed> every account with its debit or credit balance as of $to; the period accounts since the fiscal year start */
    public function trialBalance(string $to): array
    {
        $bs = $this->balanceSheet($to);
        $is = $this->incomeStatement($bs['year_start'], $to);
        $chart = $this->chart();
        $rows = [];
        $add = static function (string $key, string $code, string $name, float $amount, string $type) use (&$rows): void {
            if (abs($amount) < 0.005) {
                return;
            }
            $debitSide = in_array($type, ['asset', 'expense'], true);   // a normal balance; a negative one flips the side
            $debit = ($debitSide && $amount > 0) || (!$debitSide && $amount < 0);
            $rows[] = ['key' => $key, 'code' => $code, 'name' => $name, 'debit' => $debit ? Money::fmt(abs($amount)) : '', 'credit' => $debit ? '' : Money::fmt(abs($amount))];
        };
        $fixed = static function (string $key, float $amount) use ($add, $chart): void {
            $add($key, $chart[$key]['number'], $chart[$key]['name'], $amount, $chart[$key]['type']);
        };
        foreach ($bs['assets'] as $a) {
            $add($a['key'], $a['code'], $a['name'], (float) $a['amount'], 'asset');
        }
        foreach ($bs['liabilities'] as $l) {
            $add($l['key'], $l['code'], $l['name'], (float) $l['amount'], 'liability');
        }
        foreach ($bs['equity'] as $e) {
            if ($e['key'] !== '3200') {   // the period's profit is the income and expense accounts below, not a line of its own
                $add($e['key'], $e['code'], $e['name'], (float) $e['amount'], 'equity');
            }
        }
        $fixed('4000', (float) $is['sales']);
        $fixed('4100', -(float) $is['returns']);
        foreach ($is['other_income'] as $x) {
            $add($x['key'], $x['code'], $x['name'], (float) $x['amount'], 'income');
        }
        $fixed('5000', (float) $is['cogs']);
        $fixed('5100', (float) $is['losses']);
        foreach ($is['expenses'] as $x) {
            $add($x['key'], $x['code'], $x['name'], (float) $x['amount'], 'expense');
        }
        $fixed('6900', (float) $is['cash_diff']);
        usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));   // the owner's numbers, digit by digit: by class, as a ledger sorts
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
        $chart = $this->chart();
        $period = $this->journal($from, $to);
        $bankJournal = $period[$chart['1100']['id']] ?? ['d' => 0.0, 'c' => 0.0];
        $bankIn['Journal entries'] = $bankJournal['d'];
        $bankOut['Journal entries'] = $bankJournal['c'];
        $box = static fn (string $title, float $open, array $in, array $out, float $close): array => [
            'title' => $title, 'opening' => Money::fmt($open), 'in' => $line($in), 'out' => $line($out),
            'total_in' => Money::fmt(array_sum($in)), 'total_out' => Money::fmt(array_sum($out)), 'closing' => Money::fmt($close),
        ];
        $boxes = [
            $box($chart['1000']['number'] . ' ' . $chart['1000']['name'], $this->cashInDrawers($before), $drawerIn, $drawerOut, $this->cashInDrawers($to)),
            $box($chart['1100']['number'] . ' ' . $chart['1100']['name'], $this->bank($before, $typed), $bankIn, $bankOut, $this->bank($to, $typed)),
        ];
        // the owner's own money accounts (a second bank, Whish, a safe): moved by the journal only
        foreach ($this->ownAccounts() as $r) {
            if ((int) $r['is_money'] !== 1) {
                continue;
            }
            $j = $period[(int) $r['id']] ?? ['d' => 0.0, 'c' => 0.0];
            $open = $this->journalOn((int) $r['id'], 'asset', '1970-01-01', $before);
            if ((int) $r['is_active'] === 1 || abs($open) >= 0.005 || $j['d'] > 0 || $j['c'] > 0) {
                $boxes[] = $box($r['number'] . ' ' . $r['name'], $open, ['Journal entries' => $j['d']], ['Journal entries' => $j['c']], $open + $j['d'] - $j['c']);
            }
        }
        $openTotal = array_sum(array_map(static fn (array $b): float => (float) $b['opening'], $boxes));
        $closeTotal = array_sum(array_map(static fn (array $b): float => (float) $b['closing'], $boxes));

        return [
            'from' => $from, 'to' => $to, 'boxes' => $boxes, 'drawers' => $boxes[0], 'bank' => $boxes[1],
            'opening_total' => Money::fmt($openTotal), 'closing_total' => Money::fmt($closeTotal), 'net_change' => Money::fmt($closeTotal - $openTotal),
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
             + $this->bankReceipts('1970-01-01', $to) - $this->expensesOutside('1970-01-01', $to) - $this->supplierPayments('1970-01-01', $to) - $this->cashPurchases('1970-01-01', $to) - $this->productionCosts('1970-01-01', $to)
             + $this->journalOn($this->chart()['1100']['id'], 'asset', '1970-01-01', $to);
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
    /** Payments that reached the bank: card, Whish, transfer and cheque recorded on the Invoices page, and card payments taken at the till (review of 2026-11-10). */
    private function bankReceipts(string $from, string $to): float
    {
        return -$this->sum("SELECT COALESCE(SUM(amount_usd), 0) FROM customer_ledger WHERE type = 'payment' AND session_id IS NULL AND created_at BETWEEN :f AND :t", $from, $to)
            + $this->sum("SELECT COALESCE(SUM(p.amount_usd), 0) FROM sale_payments p JOIN sales s ON s.id = p.sale_id WHERE p.method = 'card' AND s.status = 'completed' AND s.created_at BETWEEN :f AND :t", $from, $to);
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

    /** @return list<array{key: string, code: string, name: string, amount: string}> one account per expense category, by number */
    private function expensesByCategory(string $from, string $to): array
    {
        $accounts = $this->expenseAccounts();
        $st = $this->pdo->prepare("SELECT category, SUM(amount_usd) AS total FROM expenses WHERE supplier_id IS NULL AND expense_date BETWEEN :f AND :t GROUP BY category");
        $st->execute(['f' => $from, 't' => $to]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $acc = $accounts[mb_strtolower((string) $r['category'])] ?? $this->sameCategory($accounts, (string) $r['category']);
            $out[] = ['key' => $acc['key'], 'code' => $acc['number'], 'name' => (string) $r['category'], 'amount' => Money::fmt((float) $r['total'])];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $out;
    }

    /**
     * The database groups spellings it takes as equal ("Café" and "Cafe"); PHP lower-casing does not. Ask the database.
     *
     * @param array<string, array{key: string, category: string, number: string}> $accounts
     * @return array{key: string, category: string, number: string}
     */
    private function sameCategory(array $accounts, string $category): array
    {
        $st = $this->pdo->prepare('SELECT CAST(:a AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci = CAST(:b AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci');
        foreach ($accounts as $acc) {
            $st->execute(['a' => $acc['category'], 'b' => $category]);
            if ((int) $st->fetchColumn() === 1) {
                return $acc;
            }
        }

        return ['key' => 'exp:' . mb_strtolower($category), 'category' => $category, 'number' => ''];
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
