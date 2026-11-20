<?php
declare(strict_types=1);

define('KIOS_K7G_TEST_MODE', true);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\Database;
use Kios\Infrastructure\DatabaseConfiguration;

foreach ([
    'APP_ENV' => 'demo',
    'KIOS_DB_HOST' => 'db.example.test',
    'KIOS_DB_PORT' => '3306',
    'KIOS_DB_NAME' => 'kios_test',
    'KIOS_DB_USER' => 'kios_test',
    'KIOS_DB_PASSWORD' => 'synthetic-test-password',
] as $name => $value) {
    putenv($name . '=' . $value);
}

$attempts = 0;
Database::configureTestHarness(
    static function (DatabaseConfiguration $configuration) use (&$attempts): PDO {
        $attempts++;
        if ($attempts === 1) {
            $error = new PDOException('Synthetic transient connection failure.', 1040);
            $error->errorInfo = ['HY000', 1040, 'Synthetic transient connection failure.'];
            throw $error;
        }
        return new PDO('sqlite::memory:');
    },
    static function (int $milliseconds): void {
        if ($milliseconds < 75 || $milliseconds > 150) {
            throw new RuntimeException('First retry delay escaped its bounded jitter range.');
        }
    },
);

$pdo = Database::connect();
echo json_encode([
    'ok' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite',
    'attempts' => $attempts,
], JSON_THROW_ON_ERROR) . PHP_EOL;
