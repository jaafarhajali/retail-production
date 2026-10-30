<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** The opening balances the owner types for the accounts the system cannot derive (bank, equipment, loans, capital). */
final class OpeningBalance extends Model
{
    /** @return array<string, string> account code => USD amount */
    public function all(): array
    {
        $out = [];
        foreach ($this->fetchAll('SELECT account, amount_usd FROM opening_balances') as $r) {
            $out[$r['account']] = $r['amount_usd'];
        }

        return $out;
    }

    public function set(string|int $account, string $amount, int $userId): void
    {
        $this->execute('INSERT INTO opening_balances (account, amount_usd, user_id, updated_at) VALUES (:a, :m, :u, NOW())
                        ON DUPLICATE KEY UPDATE amount_usd = VALUES(amount_usd), user_id = VALUES(user_id), updated_at = NOW()', ['a' => (string) $account, 'm' => $amount, 'u' => $userId]);
    }
}
