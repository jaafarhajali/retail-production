<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Account;

/**
 * The chart of accounts the owner keeps (2026-11-03). He numbers and names every account, adds his own as the business
 * grows, closes one he no longer uses, and deletes one only while no entry touches it. The built-in accounts keep what
 * feeds them and cannot be deleted. Two accounts never share a number (expense categories included).
 */
final class ChartService
{
    public const TYPES = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Capital', 'income' => 'Income', 'expense' => 'Expense'];
    private const NUMBER = '/^[0-9A-Za-z.\-]{1,10}$/';

    public function add(string $number, string $name, string $type, bool $isMoney, int $userId): int
    {
        $number = trim($number);
        $name = trim($name);
        if (!isset(self::TYPES[$type])) {
            throw new \DomainException('Choose what kind of account it is: asset, liability, capital, income or expense.');
        }
        $this->checkName($name, 'The new account');
        $this->checkNumber($number, $name);
        $taken = $this->numbersInUse();
        if (isset($taken[mb_strtolower($number)])) {
            throw new \DomainException("The number {$number} is already {$taken[mb_strtolower($number)]}. Each account needs its own number.");
        }
        $id = (new Account())->insert($number, $name, $type, null, $isMoney && $type === 'asset', $userId);
        Audit::log('accounts.add', 'accounts', $id, ['number' => $number, 'name' => $name, 'type' => $type]);

        return $id;
    }

    /**
     * The whole chart at once: numbers and names by account id, the owner's accounts marked closed, and the numbers of
     * the expense categories (by category). Two accounts can swap numbers: every number is moved aside first.
     *
     * @param array<int|string, string> $numbers @param array<int|string, string> $names @param array<int|string, mixed> $closed
     * @param list<string> $categories @param list<string> $categoryNumbers
     */
    public function save(array $numbers, array $names, array $closed, array $categories, array $categoryNumbers, int $userId): void
    {
        $model = new Account();
        $rows = $model->all();
        $used = [];
        $claim = static function (string $number, string $what) use (&$used): void {
            $key = mb_strtolower($number);
            if (isset($used[$key])) {
                throw new \DomainException("The number {$number} is given to both {$used[$key]} and {$what}. Each account needs its own number.");
            }
            $used[$key] = $what;
        };
        $updates = [];
        $bySystemKey = [];
        foreach ($rows as $r) {
            $key = $r['system_key'] === null ? null : (string) $r['system_key'];
            if ($key !== null && str_starts_with($key, 'exp:')) {
                $bySystemKey[$key] = $r;
                continue;   // an expense category: its number comes below, its name from the Expenses page
            }
            $id = (int) $r['id'];
            $name = trim((string) ($names[$id] ?? $r['name']));
            $number = trim((string) ($numbers[$id] ?? $r['number']));
            $this->checkName($name, 'Account ' . $r['number']);
            $this->checkNumber($number, $name);
            $claim($number, $name);
            $updates[$id] = ['number' => $number, 'name' => $name, 'active' => $key !== null || !isset($closed[$id])];
        }
        $categoryRows = [];
        foreach (array_values($categories) as $i => $category) {
            $category = trim((string) $category);
            if ($category === '') {
                continue;
            }
            $number = trim((string) ($categoryNumbers[$i] ?? ''));
            $this->checkNumber($number, $category);
            $claim($number, $category);
            $categoryRows['exp:' . mb_strtolower($category)] = $number;
        }
        foreach ($bySystemKey as $key => $r) {
            if (!isset($categoryRows[$key])) {
                $claim((string) $r['number'], (string) $key);   // a numbered category not on the form keeps its number
            }
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            foreach (array_keys($updates) as $id) {
                $model->park($id);
            }
            foreach ($categoryRows as $key => $number) {
                if (isset($bySystemKey[$key])) {
                    $model->park((int) $bySystemKey[$key]['id']);
                }
            }
            foreach ($updates as $id => $u) {
                $model->update($id, $u['number'], $u['name'], $u['active'], $userId);
            }
            foreach ($categoryRows as $key => $number) {
                if (isset($bySystemKey[$key])) {
                    $model->update((int) $bySystemKey[$key]['id'], $number, '', true, $userId);
                } else {
                    $model->insert($number, '', 'expense', $key, false, $userId);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Audit::log('accounts.chart', 'accounts', null, ['accounts' => count($updates) + count($categoryRows)]);
    }

    public function delete(int $id): void
    {
        $model = new Account();
        $a = $model->find($id);
        if ($a === null) {
            throw new \DomainException('This account does not exist.');
        }
        if ($a['system_key'] !== null) {
            throw new \DomainException("{$a['number']} {$a['name']} is a built-in account: the records feed it, so it cannot be deleted.");
        }
        if ($model->hasLines($id)) {
            throw new \DomainException("{$a['number']} {$a['name']} has journal entries, so it stays. Close it instead: it will take no new entry.");
        }
        $model->delete($id);
        Audit::log('accounts.delete', 'accounts', $id, ['number' => $a['number'], 'name' => $a['name']]);
    }

    /** @return array<string, string> lower-cased number => what holds it: every account, and the numbers the expense categories took by themselves */
    private function numbersInUse(): array
    {
        $out = [];
        foreach ((new Account())->all() as $a) {
            $out[mb_strtolower((string) $a['number'])] = $a['name'] !== '' ? (string) $a['name'] : 'an expense category';
        }
        foreach ((new AccountingService())->expenseAccounts() as $x) {
            $out[mb_strtolower($x['number'])] = 'the expense category ' . $x['category'];
        }

        return $out;
    }

    private function checkNumber(string $number, string $what): void
    {
        if (!preg_match(self::NUMBER, $number)) {
            throw new \DomainException("{$what}: the number is 1 to 10 digits or letters (a dot or a dash may separate them).");
        }
    }

    private function checkName(string $name, string $what): void
    {
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \DomainException("{$what}: type a name, up to 100 characters.");
        }
    }
}
