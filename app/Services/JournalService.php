<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Account;
use App\Models\JournalEntry;

/**
 * Journal entries (2026-11-03): what no screen records, written by the owner as an accountant would. A van bought on a
 * loan, depreciation, a loan repaid, money moved from the bank to Whish, bank interest. Rules: two lines at least, each a
 * debit or a credit, debits = credits to the cent, only on accounts a journal may touch (his own, and the typed built-ins:
 * bank, equipment, other assets, loans, other liabilities, capital) that are open. Append-only: a mistake is undone by a
 * reversing entry, once.
 */
final class JournalService
{
    /** @param list<array{account_id: int|string, debit: string, credit: string}> $lines @return int the entry's id */
    public function post(string $date, string $description, array $lines, int $userId): int
    {
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 255) {
            throw new \DomainException('Say what the entry is, in up to 255 characters.');
        }
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new \DomainException('Choose the date of the entry.');
        }
        $accounts = [];
        foreach ((new Account())->all() as $a) {
            $accounts[(int) $a['id']] = $a;
        }
        $clean = [];
        $debits = 0;
        $credits = 0;
        foreach (array_values($lines) as $i => $l) {
            $n = $i + 1;
            $accountId = (int) ($l['account_id'] ?? 0);
            $debit = trim((string) ($l['debit'] ?? ''));
            $credit = trim((string) ($l['credit'] ?? ''));
            if ($accountId === 0 && $debit === '' && $credit === '') {
                continue;   // an empty line of the form
            }
            $a = $accounts[$accountId] ?? null;
            if ($a === null) {
                throw new \DomainException("Line {$n}: choose the account.");
            }
            $label = $a['number'] . ' ' . $a['name'];
            if (!AccountingService::canPost($a)) {
                throw new \DomainException("{$label} is fed by the records (sales, stock, ledgers, expenses); a journal entry cannot touch it.");
            }
            if ((int) $a['is_active'] !== 1) {
                throw new \DomainException("{$label} is closed; open it again on the chart of accounts first.");
            }
            $dr = $debit === '' ? '0.00' : Pricing::parse($debit);
            $cr = $credit === '' ? '0.00' : Pricing::parse($credit);
            if (((float) $dr > 0) === ((float) $cr > 0)) {
                throw new \DomainException("Line {$n} ({$label}): type a debit or a credit, one of the two.");
            }
            $debits += (int) round((float) $dr * 100);
            $credits += (int) round((float) $cr * 100);
            $clean[] = ['account_id' => $accountId, 'debit' => $dr, 'credit' => $cr];
        }
        if (count($clean) < 2) {
            throw new \DomainException('An entry needs two lines at least: what is debited and what is credited.');
        }
        if ($debits !== $credits) {
            throw new \DomainException(sprintf('The debits ($%s) and the credits ($%s) must be equal.', number_format($debits / 100, 2), number_format($credits / 100, 2)));
        }

        $id = $this->write($date, $description, $clean, null, $userId);
        Audit::log('journal.post', 'journal_entries', $id, ['description' => $description], $debits / 100, 'USD');

        return $id;
    }

    /** Undoes an entry with its mirror image, dated today. Once only; a reversal is not reversed (post a new entry). */
    public function reverse(int $id, int $userId): int
    {
        $model = new JournalEntry();
        $entry = $model->find($id);
        if ($entry === null) {
            throw new \DomainException('This entry does not exist.');
        }
        if ($entry['reverses_id'] !== null) {
            throw new \DomainException('This entry is itself a reversal; write a new entry instead.');
        }
        if ($model->isReversed($id)) {
            throw new \DomainException('This entry is already reversed.');
        }
        $lines = array_map(static fn (array $l): array => ['account_id' => (int) $l['account_id'], 'debit' => (string) $l['credit_usd'], 'credit' => (string) $l['debit_usd']], $model->lines($id));
        $rev = $this->write(date('Y-m-d'), mb_substr('Reversal of entry #' . $id . ': ' . $entry['description'], 0, 255), $lines, $id, $userId);
        Audit::log('journal.reverse', 'journal_entries', $rev, ['reverses' => $id]);

        return $rev;
    }

    /** @param list<array{account_id: int, debit: string, credit: string}> $lines */
    private function write(string $date, string $description, array $lines, ?int $reverses, int $userId): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = (new JournalEntry())->insert($date, $description, $lines, $reverses, $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $id;
    }
}
