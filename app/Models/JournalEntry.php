<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Journal entries and their lines (2026-11-03). Append-only: a correction is a reversing entry, never an edit. */
final class JournalEntry extends Model
{
    public function find(int $id): ?array
    {
        return $this->fetch('SELECT * FROM journal_entries WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $entryId): array
    {
        return $this->fetchAll('SELECT * FROM journal_lines WHERE entry_id = :id ORDER BY id', ['id' => $entryId]);
    }

    public function isReversed(int $id): bool
    {
        return (bool) $this->fetchValue('SELECT 1 FROM journal_entries WHERE reverses_id = :id LIMIT 1', ['id' => $id]);
    }

    /** @param list<array{account_id: int, debit: string, credit: string}> $lines */
    public function insert(string $date, string $description, array $lines, ?int $reverses, int $userId): int
    {
        $this->execute('INSERT INTO journal_entries (entry_date, description, reverses_id, user_id, created_at) VALUES (:d, :t, :r, :u, NOW())',
            ['d' => $date, 't' => $description, 'r' => $reverses, 'u' => $userId]);
        $id = (int) $this->db->lastInsertId();
        foreach ($lines as $l) {
            $this->execute('INSERT INTO journal_lines (entry_id, account_id, debit_usd, credit_usd) VALUES (:e, :a, :d, :c)',
                ['e' => $id, 'a' => $l['account_id'], 'd' => $l['debit'], 'c' => $l['credit']]);
        }

        return $id;
    }

    /**
     * The latest entries with their lines, the account numbers and names, who wrote them and what reversed them.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $from, string $to, int $limit = 200): array
    {
        $entries = $this->fetchAll('SELECT e.*, u.username, r.id AS reversed_by FROM journal_entries e LEFT JOIN users u ON u.id = e.user_id
                                    LEFT JOIN journal_entries r ON r.reverses_id = e.id
                                    WHERE e.entry_date BETWEEN :f AND :t ORDER BY e.entry_date DESC, e.id DESC LIMIT ' . max(1, $limit), ['f' => $from, 't' => $to]);
        if ($entries === []) {
            return [];
        }
        $ids = implode(',', array_map(static fn (array $e): int => (int) $e['id'], $entries));
        $byEntry = [];
        foreach ($this->fetchAll("SELECT l.*, a.number, a.name FROM journal_lines l JOIN accounts a ON a.id = l.account_id WHERE l.entry_id IN ({$ids}) ORDER BY l.id") as $l) {
            $byEntry[(int) $l['entry_id']][] = $l;
        }
        foreach ($entries as &$e) {
            $e['lines'] = $byEntry[(int) $e['id']] ?? [];
        }
        unset($e);

        return $entries;
    }
}
