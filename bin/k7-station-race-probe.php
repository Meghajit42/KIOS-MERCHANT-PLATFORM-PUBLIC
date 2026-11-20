<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Domain\OrderType;
use Kios\Domain\PaymentMethod;
use Kios\Infrastructure\Database;
use Kios\Infrastructure\DatabaseConfiguration;
use Kios\Infrastructure\OrderRepository;

try {
    if (getenv('APP_ENV') !== 'demo' || getenv('PRINT_DRIVER') !== 'virtual') {
        throw new RuntimeException('K7 local demo and virtual-print environment are required.');
    }
    $config = DatabaseConfiguration::fromEnvironment('KIOS_DB');
    if ($config->host !== '127.0.0.1' || $config->port !== 33082 || $config->database !== 'kios_k7_test') {
        throw new RuntimeException('Station race probe is restricted to the isolated local K7 database.');
    }
    $root = getenv('KIOS_K7_ROOT');
    if (!is_string($root) || !is_dir($root)) {
        throw new RuntimeException('The isolated K7 runtime directory is unavailable.');
    }

    $pdo = Database::connect();
    $selection = $pdo->prepare(
        'SELECT p.sku, pv.code FROM products p '
        . 'JOIN product_variants pv ON pv.product_id = p.product_id '
        . 'JOIN preparation_stations ps ON ps.station_id = p.station_id AND ps.outlet_id = p.outlet_id '
        . 'JOIN outlets o ON o.outlet_id = p.outlet_id '
        . 'WHERE o.outlet_code = :outlet AND ps.code = :station AND p.active = 1 AND p.available = 1 AND pv.active = 1 LIMIT 1'
    );
    $selection->execute(['outlet' => 'demo-outlet', 'station' => 'SWEETS']);
    $item = $selection->fetch(PDO::FETCH_ASSOC);
    if (!is_array($item)) {
        throw new RuntimeException('The synthetic Sweets fixture is unavailable.');
    }
    $outlet = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :code AND active = 1');
    $outlet->execute(['code' => 'demo-outlet']);
    $outletId = (int) $outlet->fetchColumn();
    $repository = new OrderRepository($pdo);
    $order = $repository->createFromCart([(string) $item['sku'] => [(string) $item['code'] => 1]], OrderType::Takeaway);
    $repository->confirmPaymentByPickupToken($order->publicToken(), PaymentMethod::Cash, 'k7-synthetic', $outletId);

    $taskQuery = $pdo->prepare(
        'SELECT pt.preparation_task_id, ps.code FROM preparation_tasks pt '
        . 'JOIN preparation_stations ps ON ps.station_id = pt.station_id AND ps.outlet_id = pt.outlet_id '
        . 'JOIN orders o ON o.order_id = pt.order_id WHERE o.token = :token'
    );
    $taskQuery->execute(['token' => $order->token]);
    $task = $taskQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($task)) {
        throw new RuntimeException('The synthetic preparation task was not created.');
    }

    $barrier = $root . DIRECTORY_SEPARATOR . 'station-race-start-' . bin2hex(random_bytes(6));
    $worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'k7-worker.php';
    $workers = [];
    for ($index = 0; $index < 2; $index++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $worker, 'advance', (int) $task['preparation_task_id'] . '|' . (string) $task['code'] . '|queued', $barrier],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('A local station-race worker could not start.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $readyDeadline = hrtime(true) + 10_000_000_000;
    do {
        $ready = glob($barrier . '.*.ready') ?: [];
        if (count($ready) === count($workers)) {
            break;
        }
        if (hrtime(true) >= $readyDeadline) {
            throw new RuntimeException('Station-race workers did not reach the local barrier.');
        }
        usleep(5000);
    } while (true);
    file_put_contents($barrier, 'go', LOCK_EX);

    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $decoded = is_string($stdout) ? json_decode(trim($stdout), true) : null;
        $results[] = ['exitCode' => $exitCode, 'result' => is_array($decoded) ? $decoded : null, 'stderrBytes' => strlen((string) $stderr)];
    }

    $taskState = $pdo->prepare('SELECT status FROM preparation_tasks WHERE preparation_task_id = :id');
    $taskState->execute(['id' => (int) $task['preparation_task_id']]);
    $orderState = $pdo->prepare('SELECT o.status, (SELECT COUNT(*) FROM order_status_history h WHERE h.order_id = o.order_id AND h.status = \'ready\') AS ready_events FROM orders o WHERE o.token = :token');
    $orderState->execute(['token' => $order->token]);
    $final = $orderState->fetch(PDO::FETCH_ASSOC);
    $output = [
        'syntheticToken' => $order->publicToken(),
        'concurrentRequests' => $results,
        'finalTaskStatus' => $taskState->fetchColumn(),
        'finalOrderStatus' => is_array($final) ? $final['status'] : null,
        'readyHistoryEvents' => is_array($final) ? (int) $final['ready_events'] : null,
    ];
    echo json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    $category = get_class($error);
    if ($error instanceof PDOException) {
        $info = $error->errorInfo;
        $category .= ' SQLSTATE=' . (string) ($info[0] ?? $error->getCode())
            . ' driver=' . (string) ($info[1] ?? 'unknown');
    }
    fwrite(STDERR, 'K7 station race probe failed: ' . $category . PHP_EOL);
    exit(1);
}
