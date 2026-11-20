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
use Kios\Domain\CheckoutIntent;
use Kios\Domain\OrderType;

ini_set('display_errors', '0');

/** @return array{exitCode:int,stdoutBytes:int,stderrBytes:int} */
function k7RecoveryWorker(string $sessionId, string $mode): array
{
    $worker = __DIR__ . DIRECTORY_SEPARATOR . 'k7-postcommit-recovery-worker.php';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $worker, $sessionId, $mode],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('A local K7 recovery worker could not start.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exitCode' => proc_close($process), 'stdoutBytes' => strlen((string) $stdout), 'stderrBytes' => strlen((string) $stderr)];
}

function k7RecoveryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    if (getenv('APP_ENV') !== 'demo' || getenv('PRINT_DRIVER') !== 'virtual') {
        throw new RuntimeException('K7 recovery probe requires local demo mode and virtual printing.');
    }
    $configuration = DatabaseConfiguration::fromEnvironment('KIOS_DB');
    $root = getenv('KIOS_K7_ROOT');
    if ($configuration->host !== '127.0.0.1' || $configuration->port !== 33082
        || $configuration->database !== 'kios_k7_test' || !is_string($root)
        || basename($root) !== 'KiosK7Qualification' || !is_dir($root . DIRECTORY_SEPARATOR . 'mariadb-data')) {
        throw new RuntimeException('K7 recovery probe is restricted to the isolated local qualification database.');
    }

    $pdo = Database::connect();
    $select = $pdo->prepare(
        'SELECT p.sku, pv.code FROM products p JOIN product_variants pv ON pv.product_id = p.product_id '
        . 'JOIN preparation_stations ps ON ps.outlet_id = p.outlet_id AND ps.station_id = p.station_id '
        . 'JOIN outlets o ON o.outlet_id = p.outlet_id '
        . 'WHERE o.outlet_code = :outlet AND ps.code = :station AND p.active = 1 AND p.available = 1 AND pv.active = 1 '
        . 'ORDER BY p.product_id, pv.variant_id LIMIT 1'
    );
    $select->execute(['outlet' => 'demo-outlet', 'station' => 'SWEETS']);
    $selection = $select->fetch(PDO::FETCH_ASSOC);
    k7RecoveryAssert(is_array($selection), 'No available synthetic K7 Sweets fixture was found.');

    $cart = [(string) $selection['sku'] => [(string) $selection['code'] => 1]];
    $sessionDirectory = $root . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
        throw new RuntimeException('The isolated K7 session directory could not be created.');
    }
    $sessionId = bin2hex(random_bytes(16));
    $csrf = bin2hex(random_bytes(32));
    $checkoutKey = CheckoutIntent::generateKey();
    $cartFingerprint = CheckoutIntent::fingerprintCart($cart, 'demo-outlet', OrderType::Takeaway);
    session_save_path($sessionDirectory);
    session_id($sessionId);
    session_start();
    $_SESSION = [
        'order_type' => 'takeaway',
        'cart' => $cart,
        '_csrf' => $csrf,
        '_checkout_intent_key' => $checkoutKey,
        '_checkout_intent_fingerprint' => $cartFingerprint,
        '_checkout_intent_completed' => false,
    ];
    session_write_close();

    $beforeOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $firstRequest = k7RecoveryWorker($sessionId, 'crash-after-commit');
    $afterCrashOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    session_id($sessionId);
    session_start();
    $cartAfterCrash = $_SESSION['cart'] ?? null;
    session_write_close();
    $firstRows = $pdo->query('SELECT order_id, pickup_token FROM orders ORDER BY order_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $afterCrashItems = $afterCrashOrders - $beforeOrders > 0
        ? (int) $pdo->query('SELECT COUNT(*) FROM order_items WHERE order_id = ' . (int) $firstRows['order_id'])->fetchColumn()
        : 0;

    $retryRequest = k7RecoveryWorker($sessionId, 'retry');
    $afterRetryOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $retryRows = $pdo->query('SELECT order_id, pickup_token FROM orders ORDER BY order_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $retryItems = is_array($retryRows)
        ? (int) $pdo->query('SELECT COUNT(*) FROM order_items WHERE order_id = ' . (int) $retryRows['order_id'])->fetchColumn()
        : 0;
    $retryHistory = is_array($retryRows)
        ? (int) $pdo->query('SELECT COUNT(*) FROM order_status_history WHERE order_id = ' . (int) $retryRows['order_id'])->fetchColumn()
        : 0;
    session_id($sessionId);
    session_start();
    $cartAfterRetry = $_SESSION['cart'] ?? null;
    $completedAfterRetry = $_SESSION['_checkout_intent_completed'] ?? false;
    session_write_close();

    // A repeated browser submission after response delivery loss must resolve the same committed order too.
    $refreshRetryRequest = k7RecoveryWorker($sessionId, 'retry');
    $afterRefreshRetryOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $refreshRows = $pdo->query('SELECT order_id, pickup_token FROM orders ORDER BY order_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $anomalies = (new K7ConsistencyChecker($pdo))->inspect();

    $recovered = $firstRequest['exitCode'] === 86 && $firstRequest['stdoutBytes'] === 0 && $firstRequest['stderrBytes'] === 0
        && $retryRequest['exitCode'] === 0 && $retryRequest['stderrBytes'] === 0
        && $refreshRetryRequest['exitCode'] === 0 && $refreshRetryRequest['stderrBytes'] === 0
        && $afterCrashOrders === $beforeOrders + 1 && $afterRetryOrders === $beforeOrders + 1
        && $afterRefreshRetryOrders === $beforeOrders + 1
        && is_array($cartAfterCrash) && $cartAfterCrash === $cart && $afterCrashItems === 1
        && is_array($firstRows) && is_array($retryRows) && is_array($refreshRows)
        && (int) $firstRows['order_id'] === (int) $retryRows['order_id']
        && (int) $firstRows['order_id'] === (int) $refreshRows['order_id']
        && (string) $firstRows['pickup_token'] === (string) $retryRows['pickup_token']
        && (string) $firstRows['pickup_token'] === (string) $refreshRows['pickup_token']
        && $retryItems === 1 && $retryHistory === 2 && $cartAfterRetry === [] && $completedAfterRetry === true
        && array_sum($anomalies) === 0;

    $result = [
        'result' => $recovered ? 'K7_CHECKOUT_POST_COMMIT_RECOVERY=PASS' : 'K7_CHECKOUT_POST_COMMIT_RECOVERY=FAIL',
        'firstRequestExitCode' => $firstRequest['exitCode'],
        'ordersAfterCrash' => $afterCrashOrders - $beforeOrders,
        'publicTokenAfterCrashAndRetry' => is_array($firstRows) ? $firstRows['pickup_token'] : null,
        'orderItemsAfterCrash' => $afterCrashItems,
        'ordersAfterRetry' => $afterRetryOrders - $beforeOrders,
        'sessionCartPreservedAfterCrash' => is_array($cartAfterCrash) && $cartAfterCrash === $cart,
        'orderItemsAfterRetry' => $retryItems,
        'historyRowsAfterRetry' => $retryHistory,
        'sessionCartClearedAfterRetry' => $cartAfterRetry === [],
        'repeatRetryOrders' => $afterRefreshRetryOrders - $beforeOrders,
        'consistencyAnomalies' => $anomalies,
    ];
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($recovered ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'K7 post-commit probe failed (' . get_class($error) . ').' . PHP_EOL);
    exit(1);
}
