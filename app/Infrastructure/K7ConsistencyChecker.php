<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use PDO;

/** Read-only integrity checks used by the local K7 qualification harness. */
final class K7ConsistencyChecker
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Counts independently detectable integrity anomalies. Duplicate counts are duplicate groups.
     * No statement issued by this class writes to the database.
     *
     * @return array<string, int>
     */
    public function inspect(): array
    {
        $counts = [
            'duplicate_public_tokens' => $this->count(
                'SELECT COUNT(*) FROM (SELECT outlet_id, pickup_business_date, pickup_token FROM orders '
                . 'WHERE pickup_token IS NOT NULL GROUP BY outlet_id, pickup_business_date, pickup_token HAVING COUNT(*) > 1) duplicates'
            ),
            'malformed_public_tokens' => $this->malformedPublicTokens(),
            'orphan_order_items' => $this->count(
                'SELECT COUNT(*) FROM order_items oi LEFT JOIN orders o ON o.outlet_id = oi.outlet_id AND o.order_id = oi.order_id '
                . 'WHERE o.order_id IS NULL'
            ),
            'duplicate_payment_records' => $this->count(
                'SELECT COUNT(*) FROM (SELECT order_id FROM payment_confirmations GROUP BY order_id HAVING COUNT(*) > 1) duplicates'
            ),
            'duplicate_station_tasks' => $this->count(
                'SELECT COUNT(*) FROM (SELECT outlet_id, order_id, station_id FROM preparation_tasks '
                . 'GROUP BY outlet_id, order_id, station_id HAVING COUNT(*) > 1) duplicates'
            ),
            'task_routing_mismatches' => $this->count(
                'SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.outlet_id = oi.outlet_id AND o.order_id = oi.order_id '
                . 'WHERE o.status IN (\'paid\', \'preparing\', \'ready\', \'collected\') AND ('
                . 'SELECT COUNT(*) FROM preparation_task_items pti '
                . 'JOIN preparation_tasks pt ON pt.outlet_id = pti.outlet_id AND pt.order_id = pti.order_id '
                . 'AND pt.preparation_task_id = pti.preparation_task_id '
                . 'WHERE pti.outlet_id = oi.outlet_id AND pti.order_id = oi.order_id AND pti.order_item_id = oi.order_item_id '
                . 'AND pt.station_id = oi.station_id_snapshot) <> 1'
            ),
            'paid_orders_missing_tasks' => $this->count(
                "SELECT COUNT(*) FROM orders o WHERE o.status = 'paid' AND NOT EXISTS "
                . '(SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = o.outlet_id AND pt.order_id = o.order_id)'
            ),
            'ready_orders_with_unfinished_tasks' => $this->count(
                "SELECT COUNT(*) FROM orders o WHERE o.status = 'ready' AND (NOT EXISTS "
                . '(SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = o.outlet_id AND pt.order_id = o.order_id) '
                . "OR EXISTS (SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = o.outlet_id AND pt.order_id = o.order_id AND pt.status <> 'ready'))"
            ),
            'duplicate_initial_print_jobs' => $this->count(
                "SELECT COUNT(*) FROM (SELECT idempotency_key FROM print_jobs WHERE idempotency_key LIKE 'auto:%' "
                . 'GROUP BY idempotency_key HAVING COUNT(*) > 1) duplicates'
            ),
            'invalid_monetary_totals' => $this->count(
                'SELECT COUNT(*) FROM orders o WHERE NOT EXISTS '
                . '(SELECT 1 FROM order_items oi WHERE oi.outlet_id = o.outlet_id AND oi.order_id = o.order_id) '
                . 'OR o.total_paise <> COALESCE((SELECT SUM(oi.line_total_paise) FROM order_items oi '
                . 'WHERE oi.outlet_id = o.outlet_id AND oi.order_id = o.order_id), 0) '
                . 'OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.outlet_id = o.outlet_id AND oi.order_id = o.order_id '
                . 'AND oi.line_total_paise <> oi.unit_price_paise * oi.quantity)'
            ),
            'invalid_state_combinations' => $this->count(
                "SELECT COUNT(*) FROM orders o WHERE "
                . "(o.status IN ('paid', 'preparing', 'ready', 'collected') AND NOT EXISTS "
                . '(SELECT 1 FROM payment_confirmations pc WHERE pc.order_id = o.order_id)) '
                . "OR (o.status IN ('draft', 'awaiting_payment', 'expired') AND EXISTS "
                . '(SELECT 1 FROM payment_confirmations pc WHERE pc.order_id = o.order_id)) '
                . "OR (o.status = 'preparing' AND NOT EXISTS "
                . '(SELECT 1 FROM preparation_tasks pt WHERE pt.outlet_id = o.outlet_id AND pt.order_id = o.order_id)) '
                . 'OR (SELECT COUNT(*) FROM order_status_history h WHERE h.order_id = o.order_id) = 0 '
                . 'OR (SELECT h.status FROM order_status_history h WHERE h.order_id = o.order_id '
                . 'ORDER BY h.history_id DESC LIMIT 1) <> o.status'
            ),
        ];

        return $counts;
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function malformedPublicTokens(): int
    {
        $query = $this->pdo->query('SELECT pickup_token FROM orders WHERE pickup_token IS NOT NULL');
        $malformed = 0;
        while (($token = $query->fetchColumn()) !== false) {
            if (!is_string($token) || preg_match('/^G-[0-9]{3,}$/D', $token) !== 1) {
                $malformed++;
            }
        }
        return $malformed;
    }
}
