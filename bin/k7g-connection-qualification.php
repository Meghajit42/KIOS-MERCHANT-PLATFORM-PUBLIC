<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\Database;
use Kios\Infrastructure\DatabaseConfiguration;
use Kios\Infrastructure\K7ConsistencyChecker;

function k7gAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<array{process:resource,pipes:array<int,resource>}> */
function k7gLaunch(int $count, string $argument, string $barrier): array
{
    $workers = [];
    for ($i = 0; $i < $count; $i++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'k7-worker.php', 'checkout', $argument, $barrier],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('A K7G checkout worker could not start.');
        }
        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }
    return $workers;
}

/** @param list<array{process:resource,pipes:array<int,resource>}> $workers */
function k7gFinish(array $workers, string $barrier): array
{
    $deadline = hrtime(true) + 20_000_000_000;
    while (count(glob($barrier . '.*.ready') ?: []) !== count($workers)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('K7G checkout workers did not reach the barrier.');
        }
        usleep(5000);
    }
    file_put_contents($barrier, 'go', LOCK_EX);
    $results = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        $decoded = is_string($stdout) && trim($stdout) !== '' ? json_decode(trim($stdout), true) : null;
        $results[] = ['exit' => $exit, 'stderrBytes' => strlen((string) $stderr), 'result' => $decoded];
    }
    foreach (glob($barrier . '.*.ready') ?: [] as $path) {
        @unlink($path);
    }
    @unlink($barrier);
    return $results;
}

$configuration = DatabaseConfiguration::fromEnvironment('KIOS_DB');
$runtimeRoot = getenv('KIOS_K7_ROOT');
if (getenv('APP_ENV') !== 'demo' || getenv('PRINT_DRIVER') !== 'virtual'
    || $configuration->host !== '127.0.0.1' || $configuration->port !== 33082
    || $configuration->database !== 'kios_k7_test' || !is_string($runtimeRoot)
    || basename($runtimeRoot) !== 'KiosK7Qualification'
    || !is_dir($runtimeRoot . DIRECTORY_SEPARATOR . 'mariadb-data')) {
    throw new RuntimeException('K7G qualification is restricted to its isolated local MariaDB runtime.');
}

putenv('KIOS_K7G_INJECT_FIRST_CONNECT_FAILURE=1');
$pdo = Database::connect();
$selection = $pdo->query(
    "SELECT p.sku, pv.code FROM products p JOIN product_variants pv ON pv.product_id=p.product_id "
    . "JOIN outlets o ON o.outlet_id=p.outlet_id WHERE o.outlet_code='demo-outlet' "
    . 'AND p.active=1 AND p.available=1 AND pv.active=1 ORDER BY p.product_id,pv.variant_id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
k7gAssert(is_array($selection), 'K7G synthetic menu fixture is missing.');
$cart = [(string) $selection['sku'] => [(string) $selection['code'] => 1]];
$argument = base64_encode(json_encode($cart, JSON_THROW_ON_ERROR));

$evidence = ['environment' => 'hardened Windows Sandbox; isolated MariaDB; synthetic checkout; virtual printing', 'tiers' => []];
foreach ([5, 10, 20] as $tier) {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $barrier = $runtimeRoot . DIRECTORY_SEPARATOR . 'k7g-barrier-' . bin2hex(random_bytes(8));
    $started = hrtime(true);
    $results = k7gFinish(k7gLaunch($tier, $argument, $barrier), $barrier);
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    $successes = array_values(array_filter($results, static fn(array $row): bool => $row['exit'] === 0
        && $row['stderrBytes'] === 0 && is_array($row['result']) && ($row['result']['ok'] ?? false) === true));
    $records = array_column($successes, 'result');
    $after = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $tokens = array_column($records, 'publicToken');
    $internalTokens = array_column($records, 'internalToken');
    $ids = array_column($records, 'id');
    $retryCounts = array_map('intval', array_column($records, 'connectionRetries'));
    $orderIds = [];
    if ($internalTokens !== []) {
        $marks = implode(',', array_fill(0, count($internalTokens), '?'));
        $query = $pdo->prepare("SELECT order_id FROM orders WHERE token IN ($marks)");
        $query->execute($internalTokens);
        $orderIds = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    }
    $items = 0;
    $partial = 0;
    $downstream = 0;
    foreach ($orderIds as $orderId) {
        $query = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=:id');
        $query->execute(['id' => $orderId]);
        $itemCount = (int) $query->fetchColumn();
        $items += $itemCount;
        if ($itemCount !== 1) {
            $partial++;
        }
        foreach (['payment_confirmations', 'preparation_tasks', 'print_jobs'] as $table) {
            $query = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE order_id=:id');
            $query->execute(['id' => $orderId]);
            $downstream += (int) $query->fetchColumn();
        }
    }
    $anomalies = (new K7ConsistencyChecker($pdo))->inspect();
    k7gAssert(count($successes) === $tier && $after === $before + $tier
        && count(array_unique($ids)) === $tier && count(array_unique($tokens)) === $tier
        && count(array_unique($internalTokens)) === $tier && $items === $tier
        && $partial === 0 && $downstream === 0 && array_sum($anomalies) === 0
        && array_filter($retryCounts, static fn(int $count): bool => $count !== 1) === [],
        'K7G connection recovery tier failed integrity or retry checks.');
    $evidence['tiers'][(string) $tier] = [
        'attempted' => $tier, 'accepted' => count($successes), 'failed' => $tier - count($successes),
        'ordersCreated' => $after - $before, 'uniqueOrderIds' => count(array_unique($ids)),
        'uniqueInternalTokens' => count(array_unique($internalTokens)), 'uniquePublicTokens' => count(array_unique($tokens)),
        'connectionRetries' => array_sum($retryCounts), 'retriesPerWorker' => array_values(array_unique($retryCounts)),
        'partialOrders' => $partial, 'unexpectedDownstreamRows' => $downstream,
        'consistencyAnomalies' => array_sum($anomalies), 'wallClockMs' => round($elapsed, 3),
    ];
}

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
