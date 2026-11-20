<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\Database;
use Kios\Infrastructure\DatabaseConfiguration;

$createdIds = [];
$workers = [];
$failure = false;
try {
    $configuration = DatabaseConfiguration::fromEnvironment('KIOS_DB');
    if ($configuration->host !== '127.0.0.1' || $configuration->port !== 33081
        || $configuration->database !== 'kios_development') {
        throw new RuntimeException('Concurrency acceptance is restricted to the dedicated local Kios database.');
    }
    $pdo = Database::connect();
    $outlet = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_code = :code AND active = 1');
    $outlet->execute(['code' => 'demo-outlet']);
    $outletId = $outlet->fetchColumn();
    if ($outletId === false) {
        throw new RuntimeException('The local Kios demo outlet is unavailable.');
    }

    $columnCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'pickup_token'")->fetch(PDO::FETCH_ASSOC);
    $counterCheck = $pdo->query("SHOW TABLES LIKE 'pickup_token_counters'")->fetchColumn();
    if (!is_array($columnCheck) || $counterCheck === false) {
        throw new RuntimeException('Migration 005 must be applied before the concurrency check.');
    }

    $product = $pdo->prepare(
        'SELECT p.sku, pv.code FROM products p '
        . 'JOIN outlets o ON o.outlet_id = p.outlet_id '
        . 'JOIN product_variants pv ON pv.product_id = p.product_id '
        . 'WHERE o.outlet_code = :outlet AND o.active = 1 AND p.active = 1 AND p.available = 1 AND pv.active = 1 '
        . 'ORDER BY p.product_id, pv.variant_id LIMIT 1'
    );
    $product->execute(['outlet' => 'demo-outlet']);
    $selection = $product->fetch(PDO::FETCH_ASSOC);
    if (!is_array($selection)) {
        throw new RuntimeException('A synthetic demo menu selection is unavailable for the concurrency test.');
    }

    $workerPath = __DIR__ . '/k512-order-worker.php';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $launchFailed = false;
    for ($index = 0; $index < 20; $index++) {
        $process = proc_open(
            [PHP_BINARY, $workerPath, (string) $selection['sku'], (string) $selection['code']],
            $descriptors,
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            $launchFailed = true;
            break;
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }

    $records = [];
    $workerFailed = false;
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $record = is_string($stdout) ? json_decode(trim($stdout), true) : null;
        if ($exitCode !== 0 || !is_array($record) || !isset($record['id'], $record['internal'], $record['pickup'])) {
            $workerFailed = true;
            continue;
        }
        $records[] = $record;
        $createdIds[] = (string) $record['id'];
    }
    if ($launchFailed || $workerFailed || count($workers) !== 20) {
        throw new RuntimeException('All 20 local order workers did not complete successfully.');
    }

    $publicIds = array_column($records, 'id');
    $internalTokens = array_column($records, 'internal');
    $pickupTokens = array_column($records, 'pickup');
    $numbers = array_map(static fn(string $token): int => (int) substr($token, 2), $pickupTokens);
    sort($numbers, SORT_NUMERIC);
    $sequenceIsContiguous = count($numbers) === 20;
    for ($index = 1; $sequenceIsContiguous && $index < count($numbers); $index++) {
        $sequenceIsContiguous = $numbers[$index] === $numbers[$index - 1] + 1;
    }
    if (count(array_unique($publicIds)) !== 20 || count(array_unique($internalTokens)) !== 20
        || count(array_unique($pickupTokens)) !== 20 || !$sequenceIsContiguous
        || array_filter($pickupTokens, static fn(string $token): bool => preg_match('/^G-[0-9]{3,}$/D', $token) !== 1) !== []) {
        throw new RuntimeException('Concurrent allocation produced a duplicate, malformed, missing, or skipped token.');
    }

    $idPlaceholders = implode(',', array_fill(0, count($createdIds), '?'));
    $verify = $pdo->prepare(
        'SELECT o.public_id, o.token, o.pickup_token, o.status, COUNT(oi.order_item_id) AS item_count '
        . 'FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.order_id '
        . 'WHERE o.outlet_id = ? AND o.public_id IN (' . $idPlaceholders . ') GROUP BY o.order_id'
    );
    $verify->execute(array_merge([(int) $outletId], $createdIds));
    $rows = $verify->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 20 || array_filter($rows, static fn(array $row): bool =>
        !in_array($row['public_id'], $publicIds, true)
        || !in_array($row['pickup_token'], $pickupTokens, true)
        || $row['status'] !== 'awaiting_payment' || (int) $row['item_count'] !== 1) !== []) {
        throw new RuntimeException('One or more concurrent orders are missing or only partially persisted.');
    }

    echo "MariaDB 20-process token allocation: 20 orders, 20 unique internal IDs, 20 unique consecutive public tokens; no missing or partial orders.\n";
} catch (Throwable $error) {
    if ($error instanceof PDOException) {
        $info = $error->errorInfo;
        $detail = 'PDOException SQLSTATE=' . (string) ($info[0] ?? $error->getCode())
            . ' driver-code=' . (string) ($info[1] ?? 'n/a');
    } else {
        $detail = $error->getMessage();
    }
    fwrite(STDERR, 'K5.12 MariaDB concurrency acceptance failed: ' . $detail . "\n");
    $failure = true;
} finally {
    if ($createdIds !== []) {
        try {
            $cleanup = Database::connect();
            $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
            $delete = $cleanup->prepare('DELETE FROM orders WHERE public_id IN (' . $placeholders . ')');
            $delete->execute($createdIds);
            if ($delete->rowCount() !== count($createdIds)) {
                fwrite(STDERR, "Cleanup warning: not all captured K5.12 concurrency orders were removed.\n");
                $failure = true;
            }
        } catch (Throwable) {
            fwrite(STDERR, "Cleanup warning: captured K5.12 concurrency orders could not be removed.\n");
            $failure = true;
        }
    }
}
if (isset($failure) && $failure) {
    exit(1);
}
