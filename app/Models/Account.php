<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * The chart of accounts (2026-11-03): every account is a row. system_key is the built-in code ('1100') for an account
 * the records feed, 'exp:' + the category for a numbered expense category, or NULL for the owner's own account.
 */
final class Account extends Model
{
    /** @return list<array<string, mixed>> every account, by number digit by digit */
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM accounts ORDER BY CAST(number AS BINARY)');
    }

    public function find(int $id): ?array
    {
        return $this->fetch('SELECT * FROM accounts WHERE id = :id', ['id' => $id]);
    }

    public function byNumber(string $number): ?array
    {
        return $this->fetch('SELECT * FROM accounts WHERE number = :n', ['n' => $number]);
    }

    public function insert(string $number, string $name, string $type, ?string $systemKey, bool $isMoney, int $userId): int
    {
        $this->execute('INSERT INTO accounts (number, name, type, system_key, is_money, user_id, created_at, updated_at) VALUES (:n, :m, :t, :k, :money, :u, NOW(), NOW())',
            ['n' => $number, 'm' => $name, 't' => $type, 'k' => $systemKey, 'money' => $isMoney ? 1 : 0, 'u' => $userId]);

        return (int) $this->db->lastInsertId();
    }

    /** Moves the number aside first, so two accounts can swap numbers inside one transaction. */
    public function park(int $id): void
    {
        $this->execute("UPDATE accounts SET number = CONCAT('~', id) WHERE id = :id", ['id' => $id]);
    }

    public function update(int $id, string $number, string $name, bool $active, int $userId): void
    {
        $this->execute('UPDATE accounts SET number = :n, name = :m, is_active = :a, user_id = :u, updated_at = NOW() WHERE id = :id',
            ['n' => $number, 'm' => $name, 'a' => $active ? 1 : 0, 'u' => $userId, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM accounts WHERE id = :id AND system_key IS NULL', ['id' => $id]);
    }

    public function hasLines(int $id): bool
    {
        return (bool) $this->fetchValue('SELECT 1 FROM journal_lines WHERE account_id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string, array<string, mixed>> the accounts the records feed, by key: built-ins ('1100') and numbered expense categories ('exp:rent') */
    public function bySystemKey(): array
    {
        $out = [];
        foreach ($this->fetchAll('SELECT * FROM accounts WHERE system_key IS NOT NULL') as $r) {
            $out[(string) $r['system_key']] = $r;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the owner's own accounts */
    public function own(): array
    {
        return $this->fetchAll('SELECT * FROM accounts WHERE system_key IS NULL ORDER BY CAST(number AS BINARY)');
    }
}
