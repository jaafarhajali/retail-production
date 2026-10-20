<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** The monthly fixed costs typed on the dashboard (2026-10-20): one amount per month, carried forward until changed. */
final class FixedCost extends Model
{
    /** @return array{month: string, amount_usd: string}|null the month's own amount, else the latest earlier month's; null when none was ever typed */
    public function forMonth(string $month): ?array
    {
        return $this->fetch('SELECT month, amount_usd FROM fixed_costs WHERE month <= :m ORDER BY month DESC LIMIT 1', ['m' => $month]);
    }

    public function set(string $month, string $amount, int $userId): void
    {
        $this->execute('INSERT INTO fixed_costs (month, amount_usd, user_id, updated_at) VALUES (:m, :a, :u, NOW())
                        ON DUPLICATE KEY UPDATE amount_usd = VALUES(amount_usd), user_id = VALUES(user_id), updated_at = NOW()', ['m' => $month, 'a' => $amount, 'u' => $userId]);
    }
}
