<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\K7ConsistencyChecker;

/** @return PDO Small schema shared with the read-only checker tests. */
function kiosK7ConsistencyFixture(): PDO
{
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $schema = [
        'CREATE TABLE orders (order_id INTEGER, outlet_id INTEGER, pickup_business_date TEXT, pickup_token TEXT, total_paise INTEGER, status TEXT)',
        'CREATE TABLE order_items (order_item_id INTEGER, outlet_id INTEGER, order_id INTEGER, station_id_snapshot INTEGER, line_total_paise INTEGER, unit_price_paise INTEGER, quantity INTEGER)',
        'CREATE TABLE payment_confirmations (order_id INTEGER)',
        'CREATE TABLE preparation_tasks (preparation_task_id INTEGER, outlet_id INTEGER, order_id INTEGER, station_id INTEGER, status TEXT)',
        'CREATE TABLE preparation_task_items (outlet_id INTEGER, order_id INTEGER, preparation_task_id INTEGER, order_item_id INTEGER)',
        'CREATE TABLE print_jobs (idempotency_key TEXT)',
        'CREATE TABLE order_status_history (history_id INTEGER, order_id INTEGER, status TEXT)',
    ];
    foreach ($schema as $statement) {
        $pdo->exec($statement);
    }
    $pdo->exec("INSERT INTO orders VALUES (1, 1, '2026-11-02', 'G-701', 100, 'ready'), (2, 1, '2026-11-02', 'G-702', 200, 'awaiting_payment')");
    $pdo->exec('INSERT INTO order_items VALUES (11, 1, 1, 7, 100, 100, 1), (12, 1, 2, 8, 200, 100, 2)');
    $pdo->exec('INSERT INTO payment_confirmations VALUES (1)');
    $pdo->exec("INSERT INTO preparation_tasks VALUES (21, 1, 1, 7, 'ready')");
    $pdo->exec('INSERT INTO preparation_task_items VALUES (1, 1, 21, 11)');
    $pdo->exec("INSERT INTO order_status_history VALUES (1, 1, 'draft'), (2, 1, 'awaiting_payment'), (3, 1, 'paid'), (4, 1, 'preparing'), (5, 1, 'ready'), (6, 2, 'draft'), (7, 2, 'awaiting_payment')");
    return $pdo;
}

return [
    'K7 consistency checker reports a valid mixed lifecycle without anomalies' => static function (): void {
        $pdo = kiosK7ConsistencyFixture();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $report = (new K7ConsistencyChecker($pdo))->inspect();
        if ($report === [] || array_sum($report) !== 0 || (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() !== $before) {
            throw new RuntimeException('a valid lifecycle was flagged or the read-only checker changed data');
        }
    },
    'K7 consistency checker detects token, money, payment, task, print and history anomalies' => static function (): void {
        $pdo = kiosK7ConsistencyFixture();
        $pdo->exec("INSERT INTO orders VALUES (3, 1, '2026-11-02', 'G-701', 250, 'ready'), (4, 1, '2026-11-02', 'BAD', 100, 'paid')");
        $pdo->exec('INSERT INTO order_items VALUES (31, 1, 3, 9, 200, 100, 1), (32, 1, 4, 7, 100, 100, 1), (99, 1, 999, 7, 100, 100, 1)');
        $pdo->exec('INSERT INTO payment_confirmations VALUES (1), (1)');
        $pdo->exec("INSERT INTO preparation_tasks VALUES (22, 1, 1, 7, 'queued'), (23, 1, 3, 9, 'queued')");
        $pdo->exec('INSERT INTO preparation_task_items VALUES (1, 3, 23, 31)');
        $pdo->exec("INSERT INTO print_jobs VALUES ('auto:receipt:1:1'), ('auto:receipt:1:1')");
        $pdo->exec("INSERT INTO order_status_history VALUES (8, 3, 'ready')");
        $report = (new K7ConsistencyChecker($pdo))->inspect();
        $expected = [
            'duplicate_public_tokens' => 1,
            'malformed_public_tokens' => 1,
            'orphan_order_items' => 1,
            'duplicate_payment_records' => 1,
            'duplicate_station_tasks' => 1,
            'task_routing_mismatches' => 1,
            'paid_orders_missing_tasks' => 1,
            'ready_orders_with_unfinished_tasks' => 2,
            'duplicate_initial_print_jobs' => 1,
            'invalid_monetary_totals' => 1,
            'invalid_state_combinations' => 2,
        ];
        foreach ($expected as $name => $count) {
            if (($report[$name] ?? null) !== $count) {
                throw new RuntimeException("checker anomaly count for {$name} was incorrect");
            }
        }
    },
];
