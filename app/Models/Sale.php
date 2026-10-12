<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Sales, their lines and payments (spec §3.7). Append-only; a sale is undone with a return (old sales may still be 'voided'). */
final class Sale extends Model
{
    private const SELECT = 'SELECT s.*, u.username, r.name AS register_name, c.name AS customer_name, cs.session_no,
                            (SELECT COALESCE(SUM(rt.total_usd), 0) FROM returns rt WHERE rt.sale_id = s.id) AS returned_usd FROM sales s
                            JOIN users u ON u.id = s.user_id JOIN registers r ON r.id = s.register_id
                            LEFT JOIN customers c ON c.id = s.customer_id JOIN cash_sessions cs ON cs.id = s.session_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE s.id = :id', ['id' => $id]);
    }

    public function findByInvoice(string $no): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE s.invoice_no = :n', ['n' => $no]);
    }

    /**
     * Completed sales a return can be made from, found by invoice number ("12" = INV-000012), customer name or item
     * (spec §9: "search by number, customer, date, or product"). Newest first. With nothing typed: the latest
     * few sales, because most returns are for something sold today (2026-10-07).
     */
    public function forReturn(string $q, int $limit = 20, int $recent = 10): array
    {
        $q = trim($q);
        $select = "SELECT s.id, s.invoice_no, s.created_at, s.total_usd, c.name AS customer_name,
                    (SELECT GROUP_CONCAT(si.product_name ORDER BY si.id SEPARATOR ', ') FROM sale_items si WHERE si.sale_id = s.id) AS products
             FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.status = 'completed'";
        if ($q === '') {
            return $this->fetchAll($select . ' ORDER BY s.id DESC LIMIT ' . $recent);
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $number = ctype_digit($q) ? 'INV-' . str_pad($q, 6, '0', STR_PAD_LEFT) : strtoupper($q);

        return $this->fetchAll(
            $select . "
               AND (s.invoice_no = :n OR s.invoice_no LIKE :q1 OR c.name LIKE :q2 OR c.phone LIKE :q3
                    OR EXISTS (SELECT 1 FROM sale_items x WHERE x.sale_id = s.id AND x.product_name LIKE :q4))
             ORDER BY s.id DESC LIMIT " . $limit,
            ['n' => $number, 'q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]
        );
    }

    public function items(int $saleId): array
    {
        return $this->fetchAll(
            'SELECT i.*, COALESCE((SELECT SUM(ri.base_qty) FROM return_items ri WHERE ri.sale_item_id = i.id), 0) AS returned_base_qty,
                    pu.factor, pu.allows_fraction FROM sale_items i JOIN product_units pu ON pu.id = i.product_unit_id WHERE i.sale_id = :s ORDER BY i.id',
            ['s' => $saleId]
        );
    }

    public function payments(int $saleId): array
    {
        return $this->fetchAll('SELECT * FROM sale_payments WHERE sale_id = :s ORDER BY id', ['s' => $saleId]);
    }

    /** @return array{cash: string, card: string, credit: string} USD value paid by each method */
    public function paidByMethod(int $saleId): array
    {
        $sums = ['cash' => 0.0, 'card' => 0.0, 'credit' => 0.0];
        foreach ($this->payments($saleId) as $p) {
            $sums[$p['method']] = ($sums[$p['method']] ?? 0.0) + (float) $p['amount_usd'];
        }

        return array_map(static fn (float $v): string => number_format($v, 2, '.', ''), $sums);
    }

    public function search(array $filters, int $page): array
    {
        $where = ['1=1'];
        $params = [];
        if ($filters['q'] !== '') {
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $where[] = '(s.invoice_no LIKE :q1 OR c.name LIKE :q2)';
            $params += ['q1' => $like, 'q2' => $like];
        }
        if ($filters['session_id'] > 0) {
            $where[] = 's.session_id = :ses';
            $params['ses'] = $filters['session_id'];
        }
        if ($filters['user_id'] > 0) {
            $where[] = 's.user_id = :u';
            $params['u'] = $filters['user_id'];
        }
        if ($filters['customer_id'] > 0) {
            $where[] = 's.customer_id = :c';
            $params['c'] = $filters['customer_id'];
        }
        if ($filters['from'] !== '') {
            $where[] = 's.created_at >= :f';
            $params['f'] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $where[] = 's.created_at <= :t';
            $params['t'] = $filters['to'] . ' 23:59:59';
        }
        $w = implode(' AND ', $where);

        return $this->paginate(
            self::SELECT . " WHERE {$w} ORDER BY s.id DESC",
            "SELECT COUNT(*) FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE {$w}",
            $params, $page, 25
        );
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO sales (invoice_no, register_id, session_id, user_id, customer_id, price_level, subtotal_usd, discount_usd, total_usd, rounding_usd,
                                cost_total_usd, exchange_rate, change_usd, change_lbp, notes, status, held_at)
             VALUES (:no, :r, :s, :u, :c, :pl, :sub, :d, :t, :ro, :ct, :x, :cu, :cl, :n, :st, :ha)',
            [
                'no' => $f['invoice_no'], 'r' => $f['register_id'], 's' => $f['session_id'], 'u' => $f['user_id'], 'c' => $f['customer_id'],
                'pl' => $f['price_level'], 'sub' => $f['subtotal_usd'], 'd' => $f['discount_usd'], 't' => $f['total_usd'], 'ro' => $f['rounding_usd'],
                'ct' => $f['cost_total_usd'], 'x' => $f['exchange_rate'], 'cu' => $f['change_usd'], 'cl' => $f['change_lbp'], 'n' => $f['notes'],
                'st' => $f['status'] ?? 'completed', 'ha' => $f['held_at'] ?? null,
            ]
        );

        return $this->lastId();
    }

    public function addItem(int $saleId, array $l): int
    {
        $this->execute(
            'INSERT INTO sale_items (sale_id, product_id, product_unit_id, product_name, unit_name, qty, base_qty, unit_price_usd, line_discount_usd,
                                     line_total_usd, cost_per_base, line_cost_usd, entry_mode, price_overridden)
             VALUES (:s, :p, :u, :pn, :un, :q, :b, :pr, :ld, :lt, :cb, :lc, :em, :po)',
            [
                's' => $saleId, 'p' => $l['product_id'], 'u' => $l['product_unit_id'], 'pn' => $l['product_name'], 'un' => $l['unit_name'],
                'q' => $l['qty'], 'b' => $l['base_qty'], 'pr' => $l['unit_price_usd'], 'ld' => $l['line_discount_usd'], 'lt' => $l['line_total_usd'],
                'cb' => $l['cost_per_base'], 'lc' => $l['line_cost_usd'], 'em' => $l['entry_mode'], 'po' => (int) $l['price_overridden'],
            ]
        );

        return $this->lastId();
    }

    /** F3: the invoices out with the seller, newest first. */
    public function onHold(): array
    {
        return $this->fetchAll(self::SELECT . " WHERE s.status = 'on_hold' ORDER BY s.id DESC");
    }

    /** F3: a line after settlement: the net quantity and its share of the money. */
    public function settleItem(int $itemId, array $l): void
    {
        $this->execute('UPDATE sale_items SET qty = :q, base_qty = :b, line_discount_usd = :ld, line_total_usd = :lt, line_cost_usd = :lc WHERE id = :id',
            ['q' => $l['qty'], 'b' => $l['base_qty'], 'ld' => $l['line_discount_usd'], 'lt' => $l['line_total_usd'], 'lc' => $l['line_cost_usd'], 'id' => $itemId]);
    }

    /** F3: the held invoice becomes a completed sale in the settler's session. */
    public function settle(int $id, array $f): void
    {
        $this->execute(
            "UPDATE sales SET status = 'completed', session_id = :ses, register_id = :reg, subtotal_usd = :sub, discount_usd = :d, total_usd = :t, rounding_usd = :ro, cost_total_usd = :ct,
                              exchange_rate = :x, change_usd = :cu, change_lbp = :cl, settled_at = NOW(), settled_by = :by WHERE id = :id AND status = 'on_hold'",
            ['ses' => $f['session_id'], 'reg' => $f['register_id'], 'sub' => $f['subtotal_usd'], 'd' => $f['discount_usd'], 't' => $f['total_usd'], 'ro' => $f['rounding_usd'], 'ct' => $f['cost_total_usd'],
             'x' => $f['exchange_rate'], 'cu' => $f['change_usd'], 'cl' => $f['change_lbp'], 'by' => $f['settled_by'], 'id' => $id]
        );
    }

    public function addSettlement(int $saleId, array $f): int
    {
        $this->execute('INSERT INTO sale_settlements (sale_id, original_usd, returned_usd, net_usd, paid_usd, balance_usd, settled_by, notes) VALUES (:s, :o, :r, :n, :p, :b, :by, :t)',
            ['s' => $saleId, 'o' => $f['original_usd'], 'r' => $f['returned_usd'], 'n' => $f['net_usd'], 'p' => $f['paid_usd'], 'b' => $f['balance_usd'], 'by' => $f['settled_by'], 't' => $f['notes']]);

        return $this->lastId();
    }

    public function addSettlementReturn(int $settlementId, array $b): void
    {
        $this->execute('INSERT INTO settlement_returns (settlement_id, sale_item_id, qty, base_qty, value_usd) VALUES (:s, :i, :q, :b, :v)',
            ['s' => $settlementId, 'i' => $b['sale_item_id'], 'q' => $b['qty'], 'b' => $b['base_qty'], 'v' => $b['value_usd']]);
    }

    public function settlement(int $saleId): ?array
    {
        $st = $this->fetch('SELECT t.*, u.username AS settled_by_name FROM sale_settlements t LEFT JOIN users u ON u.id = t.settled_by WHERE t.sale_id = :s', ['s' => $saleId]);
        if ($st !== null) {
            $st['returns'] = $this->fetchAll('SELECT r.*, i.product_name, i.unit_name FROM settlement_returns r JOIN sale_items i ON i.id = r.sale_item_id WHERE r.settlement_id = :t ORDER BY r.id', ['t' => (int) $st['id']]);
        }

        return $st;
    }

    public function addPayment(int $saleId, string $method, string $currency, string $amount, string $amountUsd): void
    {
        $this->execute('INSERT INTO sale_payments (sale_id, method, currency, amount, amount_usd) VALUES (:s, :m, :c, :a, :u)',
            ['s' => $saleId, 'm' => $method, 'c' => $currency, 'a' => $amount, 'u' => $amountUsd]);
    }

    /** Session totals for X/Z: sales, retail/wholesale, payments by method+currency, counts. */
    public function sessionSummary(int $sessionId): array
    {
        $head = $this->fetch(
            "SELECT COUNT(*) AS invoices, COALESCE(SUM(total_usd), 0) AS total, COALESCE(SUM(rounding_usd), 0) AS rounding,
                    COALESCE(SUM(CASE WHEN price_level = 'retail' THEN total_usd ELSE 0 END), 0) AS retail,
                    COALESCE(SUM(CASE WHEN price_level = 'wholesale' THEN total_usd ELSE 0 END), 0) AS wholesale,
                    COALESCE(SUM(change_usd), 0) AS change_usd, COALESCE(SUM(change_lbp), 0) AS change_lbp
             FROM sales WHERE session_id = :s1 AND status = 'completed'",
            ['s1' => $sessionId]
        ) ?? [];
        $head['voided'] = (int) $this->fetchValue("SELECT COUNT(*) FROM sales WHERE session_id = :s AND status = 'voided'", ['s' => $sessionId]);
        $head['payments'] = $this->fetchAll(
            "SELECT p.method, p.currency, SUM(p.amount) AS amount, SUM(p.amount_usd) AS amount_usd FROM sale_payments p
             JOIN sales s ON s.id = p.sale_id WHERE s.session_id = :s AND s.status = 'completed' GROUP BY p.method, p.currency ORDER BY p.method, p.currency",
            ['s' => $sessionId]
        );

        return $head;
    }
}
