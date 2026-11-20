<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private const MAX_CONNECTION_ATTEMPTS = 3;
    private const TRANSIENT_DRIVER_CODES = [1040, 1203, 2002, 2003, 2006, 2013];

    /** @var null|callable(DatabaseConfiguration): PDO */
    private static $testConnector = null;

    /** @var null|callable(int): void */
    private static $testDelay = null;

    public static function connect(): PDO
    {
        try {
            $configuration = DatabaseConfiguration::fromEnvironment('KIOS_DB');
        } catch (Throwable $error) {
            $failure = new DatabaseConnectionException('connect', 1, false, null, null, $error);
            RequestObservability::recordDatabaseConnectionEvent($failure, 'failed');
            throw $failure;
        }
        return self::connectWithConfiguration($configuration);
    }

    public static function connectForMigrations(): PDO
    {
        $migration = DatabaseConfiguration::fromEnvironment('KIOS_DB_MIGRATION');
        if (Environment::get('APP_ENV', 'local') === 'demo') {
            $application = DatabaseConfiguration::fromEnvironment('KIOS_DB');
            if ($migration->host !== $application->host || $migration->port !== $application->port
                || $migration->database !== $application->database) {
                throw new RuntimeException('Hosted migration credentials must target the configured Kios demo database.');
            }
        }
        return self::connectWithConfiguration($migration);
    }

    private static function connectWithConfiguration(DatabaseConfiguration $configuration): PDO
    {
        $lastFailure = null;
        for ($attempt = 1; $attempt <= self::MAX_CONNECTION_ATTEMPTS; $attempt++) {
            try {
                $connector = self::$testConnector;
                $pdo = is_callable($connector)
                    ? $connector($configuration)
                    : self::createPdo($configuration);
                if (!$pdo instanceof PDO) {
                    throw new RuntimeException('The database connector returned an invalid connection.');
                }
                if ($attempt > 1 && $lastFailure instanceof DatabaseConnectionException) {
                    RequestObservability::recordDatabaseConnectionEvent($lastFailure, 'recovered', $attempt);
                }
                return $pdo;
            } catch (PDOException $error) {
                [$sqlState, $driverCode] = self::exceptionMetadata($error);
                $transient = self::isTransient($sqlState, $driverCode);
                $lastFailure = new DatabaseConnectionException(
                    'connect', $attempt, $transient, $sqlState, $driverCode, $error,
                );
                if (!$transient || $attempt === self::MAX_CONNECTION_ATTEMPTS) {
                    RequestObservability::recordDatabaseConnectionEvent($lastFailure, 'failed');
                    throw $lastFailure;
                }
                RequestObservability::recordDatabaseConnectionEvent($lastFailure, 'retrying');
                self::delayBeforeAttempt($attempt + 1);
            } catch (Throwable $error) {
                $failure = new DatabaseConnectionException('connect', $attempt, false, null, null, $error);
                RequestObservability::recordDatabaseConnectionEvent($failure, 'failed');
                throw $failure;
            }
        }
        throw new RuntimeException('Database connection retry invariant failed.');
    }

    private static function createPdo(DatabaseConfiguration $configuration): PDO
    {
        if (PHP_SAPI === 'cli-server' && defined('KIOS_K7G_TEST_FAILURE_MODE')) {
            $mode = constant('KIOS_K7G_TEST_FAILURE_MODE');
            if (is_string($mode) && in_array($mode, ['transient_all', 'permanent'], true)) {
                $driverCode = $mode === 'transient_all' ? 1040 : 1045;
                $error = new PDOException('Synthetic K7G database connection failure.', $driverCode);
                $error->errorInfo = [$mode === 'transient_all' ? 'HY000' : '28000', $driverCode, 'Synthetic K7G failure'];
                throw $error;
            }
        }
        return new PDO(
            $configuration->dsn(),
            $configuration->username,
            $configuration->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_PERSISTENT => false],
        );
    }

    /** @return array{?string, ?int} */
    private static function exceptionMetadata(PDOException $error): array
    {
        $errorInfo = is_array($error->errorInfo ?? null) ? $error->errorInfo : [];
        $sqlState = isset($errorInfo[0]) && is_string($errorInfo[0]) && preg_match('/^[A-Z0-9]{5}$/D', $errorInfo[0]) === 1
            ? $errorInfo[0]
            : (is_string($error->getCode()) && preg_match('/^[A-Z0-9]{5}$/D', $error->getCode()) === 1 ? $error->getCode() : null);
        $driverCode = isset($errorInfo[1]) && is_numeric($errorInfo[1]) ? (int) $errorInfo[1] : null;
        return [$sqlState, $driverCode];
    }

    private static function isTransient(?string $sqlState, ?int $driverCode): bool
    {
        return ($sqlState !== null && str_starts_with($sqlState, '08'))
            || ($driverCode !== null && in_array($driverCode, self::TRANSIENT_DRIVER_CODES, true));
    }

    private static function delayBeforeAttempt(int $attempt): void
    {
        $milliseconds = $attempt === 2 ? random_int(75, 150) : random_int(200, 400);
        $delay = self::$testDelay;
        if (is_callable($delay)) {
            $delay($milliseconds);
            return;
        }
        usleep($milliseconds * 1000);
    }

    /** Test-only deterministic connection injection; unavailable to web SAPIs. */
    public static function configureTestHarness(?callable $connector, ?callable $delay = null): void
    {
        if (PHP_SAPI !== 'cli' || !defined('KIOS_K7G_TEST_MODE') || KIOS_K7G_TEST_MODE !== true) {
            throw new RuntimeException('Database connection test injection is restricted to the CLI K7G harness.');
        }
        self::$testConnector = $connector;
        self::$testDelay = $delay;
    }
}
