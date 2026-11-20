<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Domain\OrderType;
use Kios\Domain\CheckoutIntent;
use Kios\Domain\PaymentMethod;
use Kios\Infrastructure\Database;
use Kios\Infrastructure\DatabaseConfiguration;
use Kios\Infrastructure\DatabaseConnectionException;
use Kios\Infrastructure\OrderRepository;
use Kios\Infrastructure\PrintJobService;
use Kios\Infrastructure\PreparationWorkflowService;

ini_set('display_errors', '0');

function k7WorkerFail(Throwable $error): never
{
    $failure = ['ok' => false, 'errorType' => get_class($error)];
    if ($error instanceof PDOException) {
        $info = $error->errorInfo;
        $failure['sqlState'] = (string) ($info[0] ?? $error->getCode());
        $failure['driverCode'] = is_numeric($info[1] ?? null) ? (int) $info[1] : null;
    } elseif ($error instanceof DatabaseConnectionException) {
        $failure['connectionStage'] = $error->connectionStage;
        $failure['attempt'] = $error->attempt;
        $failure['transient'] = $error->transient;
        $failure['sqlState'] = $error->sqlState;
        $failure['driverCode'] = $error->driverCode;
    }
    fwrite(STDOUT, json_encode($failure, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}

try {
    if (getenv('APP_ENV') !== 'demo' || getenv('PRINT_DRIVER') !== 'virtual') {
        throw new RuntimeException('K7 worker requires demo mode and virtual printing.');
    }
    $configuration = DatabaseConfiguration::fromEnvironment('KIOS_DB');
    if ($configuration->host !== '127.0.0.1' || $configuration->port !== 33082
        || $configuration->database !== 'kios_k7_test') {
        throw new RuntimeException('K7 worker is restricted to its isolated local database.');
    }

    $connectionAttempts = 1;
    if (getenv('KIOS_K7G_INJECT_FIRST_CONNECT_FAILURE') === '1') {
        $testRoot = getenv('KIOS_K7_ROOT');
        if (!is_string($testRoot) || basename($testRoot) !== 'KiosK7Qualification'
            || !is_dir($testRoot . DIRECTORY_SEPARATOR . 'mariadb-data')) {
            throw new RuntimeException('K7G connection injection is restricted to the isolated qualification runtime.');
        }
        if (!defined('KIOS_K7G_TEST_MODE')) {
            define('KIOS_K7G_TEST_MODE', true);
        }
        $connectionAttempts = 0;
        Database::configureTestHarness(
            static function (DatabaseConfiguration $testConfiguration) use (&$connectionAttempts): PDO {
                $connectionAttempts++;
                if ($connectionAttempts === 1) {
                    $error = new PDOException('Synthetic transient connection failure.', 1040);
                    $error->errorInfo = ['HY000', 1040, 'Synthetic transient connection failure.'];
                    throw $error;
                }
                return new PDO(
                    $testConfiguration->dsn(),
                    $testConfiguration->username,
                    $testConfiguration->password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false,
                        PDO::ATTR_PERSISTENT => false],
                );
            },
        );
    }

    $action = $argv[1] ?? '';
    $argument = $argv[2] ?? '';
    $barrier = $argv[3] ?? '';
    $testRoot = getenv('KIOS_K7_ROOT');
    if ($barrier !== '') {
        if (!is_string($testRoot) || !str_starts_with(realpath(dirname($barrier)) ?: '', realpath($testRoot) ?: '')) {
            throw new RuntimeException('K7 worker barrier escaped the isolated test directory.');
        }
    }

    $pdo = Database::connect();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
    }
    if ($barrier !== '') {
        $readyPath = $barrier . '.' . getmypid() . '.ready';
        if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
            throw new RuntimeException('K7 worker could not join the local start barrier.');
        }
        $deadline = hrtime(true) + 15_000_000_000;
        while (!is_file($barrier)) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('K7 worker barrier timed out.');
            }
            usleep(1000);
        }
    }

    $started = hrtime(true);
    if ($action === 'checkout') {
        $decoded = base64_decode($argument, true);
        $cart = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($cart) || $cart === []) {
            throw new InvalidArgumentException('K7 checkout fixture is invalid.');
        }
        foreach ($cart as $sku => $variants) {
            if (!is_string($sku) || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $sku) !== 1 || !is_array($variants)) {
                throw new InvalidArgumentException('K7 checkout fixture is invalid.');
            }
            foreach ($variants as $variant => $quantity) {
                if (!is_string($variant) || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $variant) !== 1
                    || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
                    throw new InvalidArgumentException('K7 checkout fixture is invalid.');
                }
            }
        }
        $order = (new OrderRepository($pdo))->createFromCart($cart, OrderType::Takeaway);
        $result = [
            'ok' => true,
            'action' => 'checkout',
            'id' => $order->id,
            'internalToken' => $order->token,
            'publicToken' => $order->publicToken(),
            'totalPaise' => $order->total->paise(),
            'status' => $order->status->value,
            'itemCount' => count($order->items),
        ];
    } elseif ($action === 'intent-checkout') {
        $decoded = base64_decode($argument, true);
        $intentData = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($intentData) || !is_array($intentData['cart'] ?? null)
            || !is_string($intentData['key'] ?? null) || !CheckoutIntent::isValidKey($intentData['key'])
            || !is_string($intentData['sessionId'] ?? null) || preg_match('/^[A-Fa-f0-9]{32,64}$/D', $intentData['sessionId']) !== 1
            || !is_string($intentData['fingerprint'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $intentData['fingerprint']) !== 1
            || !is_string($intentData['outletCode'] ?? null) || !in_array($intentData['orderType'] ?? null, ['takeaway', 'dine_in'], true)) {
            throw new InvalidArgumentException('K7 checkout-intent fixture is invalid.');
        }
        $type = OrderType::from($intentData['orderType']);
        $order = (new OrderRepository($pdo))->createFromCheckoutIntent(
            $intentData['cart'],
            $type,
            $intentData['key'],
            $intentData['sessionId'],
            $intentData['fingerprint'],
            $intentData['outletCode'],
        );
        $result = [
            'ok' => true,
            'action' => 'intent-checkout',
            'id' => $order->id,
            'internalToken' => $order->token,
            'publicToken' => $order->publicToken(),
            'totalPaise' => $order->total->paise(),
            'status' => $order->status->value,
            'itemCount' => count($order->items),
        ];
    } elseif ($action === 'payment' || $action === 'cancel') {
        if (preg_match('/^G-[0-9]{3,}$/D', $argument) !== 1) {
            throw new InvalidArgumentException('K7 public token is invalid.');
        }
        $outlet = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :code AND active = 1');
        $outlet->execute(['code' => 'demo-outlet']);
        $outletId = (int) $outlet->fetchColumn();
        if ($outletId < 1) {
            throw new RuntimeException('K7 outlet fixture is missing.');
        }
        $repository = new OrderRepository($pdo);
        $order = $action === 'payment'
            ? $repository->confirmPaymentByPickupToken($argument, PaymentMethod::Cash, 'k7-synthetic', $outletId)
            : $repository->cancelPendingByPickupToken($argument, 'k7-synthetic', $outletId);
        $result = ['ok' => true, 'action' => $action, 'publicToken' => $order->publicToken(), 'status' => $order->status->value];
    } elseif ($action === 'enqueue') {
        if (preg_match('/^G-[0-9]{3,}$/D', $argument) !== 1) {
            throw new InvalidArgumentException('K7 public token is invalid.');
        }
        $orderQuery = $pdo->prepare('SELECT order_id, outlet_id FROM orders WHERE pickup_token = :token ORDER BY order_id DESC LIMIT 1');
        $orderQuery->execute(['token' => $argument]);
        $orderRow = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($orderRow)) {
            throw new InvalidArgumentException('K7 order fixture was not found.');
        }
        $pdo->beginTransaction();
        $ids = (new PrintJobService($pdo))->enqueueForOrder((int) $orderRow['order_id'], (int) $orderRow['outlet_id']);
        $pdo->commit();
        $result = ['ok' => true, 'action' => 'enqueue', 'newJobs' => count($ids)];
    } elseif ($action === 'advance') {
        $parameters = explode('|', $argument);
        $expectedStatus = count($parameters) === 3 ? Kios\Domain\PreparationTaskStatus::tryFrom($parameters[2]) : null;
        if (count($parameters) !== 3 || !ctype_digit($parameters[0]) || (int) $parameters[0] < 1
            || preg_match('/^[A-Z_]{2,40}$/D', $parameters[1]) !== 1 || $expectedStatus === null
            || $expectedStatus === Kios\Domain\PreparationTaskStatus::Ready) {
            throw new InvalidArgumentException('K7 station fixture is invalid.');
        }
        $outlet = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :code AND active = 1');
        $outlet->execute(['code' => 'demo-outlet']);
        $outletId = (int) $outlet->fetchColumn();
        $status = (new PreparationWorkflowService($pdo))->advanceTask((int) $parameters[0], $outletId, $parameters[1], $expectedStatus);
        $result = [
            'ok' => true,
            'action' => 'advance',
            'taskStatus' => $status['taskStatus']->value,
            'orderStatus' => $status['orderStatus']->value,
        ];
    } else {
        throw new InvalidArgumentException('K7 worker action is unsupported.');
    }
    $result['elapsedMs'] = (hrtime(true) - $started) / 1_000_000;
    $result['connectionAttempts'] = $connectionAttempts;
    $result['connectionRetries'] = max(0, $connectionAttempts - 1);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $error) {
    k7WorkerFail($error);
}
