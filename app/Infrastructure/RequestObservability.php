<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

use Throwable;

/** Request correlation and sanitized private error records for Kios web requests. */
final class RequestObservability
{
    private const LOG_NAME = 'errors.jsonl';
    private const MAX_LOG_BYTES = 262144;
    private const MAX_MESSAGE_BYTES = 600;
    private static ?string $requestId = null;
    private static ?string $lastRecordFingerprint = null;
    private static bool $uncaughtExceptionHandled = false;

    public static function register(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        self::$requestId = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            header('X-Kios-Request-Id: ' . self::$requestId);
        }

        set_error_handler([self::class, 'handlePhpError'], E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR | E_USER_ERROR);
        set_exception_handler([self::class, 'handleUncaughtException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handlePhpError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return true;
        }

        self::writeRecord(
            'php_error',
            'PHP ' . self::severityName($severity),
            $message,
            $file,
            $line,
            [],
        );

        // The sanitized private record replaces PHP's potentially sensitive raw message.
        return true;
    }

    public static function handleUncaughtException(Throwable $error): void
    {
        self::$uncaughtExceptionHandled = true;
        if ($error instanceof DatabaseConnectionException) {
            self::respondWithGenericError(503);
            return;
        }
        self::writeThrowable('uncaught_exception', $error);
        self::respondWithGenericError();
    }

    public static function handleShutdown(): void
    {
        if (self::$uncaughtExceptionHandled) {
            return;
        }
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        self::writeRecord(
            'php_fatal',
            'PHP ' . self::severityName((int) $error['type']),
            (string) ($error['message'] ?? ''),
            (string) ($error['file'] ?? ''),
            (int) ($error['line'] ?? 0),
            [],
        );

        if (http_response_code() === false || http_response_code() < 400) {
            self::respondWithGenericError();
        }
    }

    public static function recordCaughtException(Throwable $error, string $context): void
    {
        if (self::$requestId === null) {
            return;
        }
        self::writeThrowable('caught_exception:' . self::safeContext($context), $error);
    }

    public static function recordDatabaseConnectionEvent(
        DatabaseConnectionException $failure,
        string $outcome,
        ?int $successfulAttempt = null,
    ): void {
        if (self::$requestId === null) {
            return;
        }
        $outcome = in_array($outcome, ['retrying', 'recovered', 'failed'], true) ? $outcome : 'failed';
        self::writeRecord(
            'database_connection',
            DatabaseConnectionException::class,
            'Database connection ' . $outcome . '.',
            $failure->getFile(),
            $failure->getLine(),
            [],
            [
                'connection_stage' => $failure->connectionStage,
                'attempt' => $failure->attempt,
                'successful_attempt' => $successfulAttempt,
                'transient' => $failure->transient,
                'outcome' => $outcome,
                'sqlstate' => $failure->sqlState,
                'driver_code' => $failure->driverCode,
            ],
        );
    }

    private static function writeThrowable(string $kind, Throwable $error): void
    {
        self::writeRecord(
            $kind,
            get_class($error),
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            $error->getTrace(),
        );
    }

    /** @param list<array<string, mixed>> $trace */
    private static function writeRecord(
        string $kind,
        string $errorClass,
        string $message,
        string $file,
        int $line,
        array $trace,
        array $metadata = [],
    ): void {
        if (self::$requestId === null) {
            return;
        }

        $fingerprint = hash('sha256', $kind . "\0" . $errorClass . "\0" . $file . "\0" . $line . "\0" . $message
            . "\0" . json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        if (self::$lastRecordFingerprint === $fingerprint) {
            return;
        }
        self::$lastRecordFingerprint = $fingerprint;

        $token = $_GET['token'] ?? null;
        $publicToken = is_string($token) && preg_match('/^G-[0-9]{3,16}$/D', $token) === 1 ? $token : null;
        $route = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
        $route = is_string($route) ? $route : '/';
        $route = preg_replace('/[^A-Za-z0-9._\/~\-]/', '_', $route) ?? '/';
        $route = substr($route, 0, 160);

        $method = strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'UNKNOWN');
        if (preg_match('/^[A-Z]{1,12}$/D', $method) !== 1) {
            $method = 'UNKNOWN';
        }

        $record = [
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'request_id' => self::$requestId,
            'method' => $method,
            'route' => $route,
            'public_pickup_token' => $publicToken,
            'error_type' => $kind,
            'exception_class' => substr($errorClass, 0, 160),
            'file' => substr($file, 0, 512),
            'line' => max(0, $line),
            'message' => self::safeMessage($message, $errorClass),
            'stack' => self::compactStack($trace),
            'php_version' => PHP_VERSION,
        ];
        foreach ($metadata as $key => $value) {
            if (in_array($key, ['connection_stage', 'attempt', 'successful_attempt', 'transient', 'outcome', 'sqlstate', 'driver_code'], true)) {
                $record[$key] = $value;
            }
        }

        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encoded)) {
            self::fallbackLog();
            return;
        }
        self::appendPrivateRecord($encoded . "\n");
    }

    private static function safeMessage(string $message, string $errorClass): string
    {
        if (is_a($errorClass, \PDOException::class, true)) {
            return 'Database exception; provider details redacted.';
        }

        $message = preg_replace('/-----BEGIN [A-Z0-9 ]*KEY-----.*?-----END [A-Z0-9 ]*KEY-----/is', '[REDACTED_PRIVATE_KEY]', $message) ?? '';
        $message = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message) ?? '';
        $message = preg_replace('/\b([a-z0-9_-]*(?:authorization|password|passwd|pwd|secret|csrf|session(?:_id)?|cookie|token|private[_ -]?key|api[_ -]?key))\b\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1=[REDACTED]', $message) ?? '';
        $message = preg_replace('/\b(?:mysql|pgsql|sqlite):[^\s,;]+/i', '[REDACTED_DSN]', $message) ?? '';
        $message = preg_replace('/\bhttps?:\/\/[^\s,;]+/i', '[REDACTED_URL]', $message) ?? '';
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if ($message === '') {
            return 'Error details omitted.';
        }

        return substr($message, 0, self::MAX_MESSAGE_BYTES);
    }

    /** @param list<array<string, mixed>> $trace
     *  @return list<array{file?: string, line?: int, class?: string, function?: string}> */
    private static function compactStack(array $trace): array
    {
        $frames = [];
        foreach (array_slice($trace, 0, 8) as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $safe = [];
            if (is_string($frame['file'] ?? null)) {
                $safe['file'] = substr($frame['file'], 0, 512);
            }
            if (is_int($frame['line'] ?? null)) {
                $safe['line'] = max(0, $frame['line']);
            }
            foreach (['class', 'function'] as $key) {
                if (is_string($frame[$key] ?? null)) {
                    $safe[$key] = substr($frame[$key], 0, 160);
                }
            }
            $frames[] = $safe;
        }
        return $frames;
    }

    private static function appendPrivateRecord(string $line): void
    {
        $directory = self::logDirectory();
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true))) {
            self::fallbackLog();
            return;
        }
        @chmod($directory, 0700);
        if (PHP_OS_FAMILY !== 'Windows' && ((@fileperms($directory) & 0777) !== 0700)) {
            self::fallbackLog();
            return;
        }

        $lockPath = $directory . DIRECTORY_SEPARATOR . '.errors.lock';
        $logPath = $directory . DIRECTORY_SEPARATOR . self::LOG_NAME;
        $rotatedPath = $logPath . '.1';
        if (is_link($lockPath) || is_link($logPath) || is_link($rotatedPath)) {
            self::fallbackLog();
            return;
        }

        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock)) {
            self::fallbackLog();
            return;
        }
        @chmod($lockPath, 0600);
        if (PHP_OS_FAMILY !== 'Windows' && ((@fileperms($lockPath) & 0777) !== 0600)) {
            @flock($lock, LOCK_UN);
            fclose($lock);
            self::fallbackLog();
            return;
        }
        if (!@flock($lock, LOCK_EX)) {
            fclose($lock);
            self::fallbackLog();
            return;
        }

        try {
            clearstatcache(true, $logPath);
            $currentSize = is_file($logPath) ? (int) @filesize($logPath) : 0;
            if ($currentSize + strlen($line) > self::MAX_LOG_BYTES) {
                if (is_file($logPath)) {
                    @chmod($logPath, 0600);
                }
                if (is_file($rotatedPath) && !@unlink($rotatedPath)) {
                    self::fallbackLog();
                    return;
                }
                if (is_file($logPath) && !@rename($logPath, $rotatedPath)) {
                    self::fallbackLog();
                    return;
                }
                if (is_file($rotatedPath)) {
                    @chmod($rotatedPath, 0600);
                    if (PHP_OS_FAMILY !== 'Windows' && ((@fileperms($rotatedPath) & 0777) !== 0600)) {
                        self::fallbackLog();
                        return;
                    }
                }
            }

            if (is_file($logPath)) {
                @chmod($logPath, 0600);
                if (PHP_OS_FAMILY !== 'Windows' && ((@fileperms($logPath) & 0777) !== 0600)) {
                    self::fallbackLog();
                    return;
                }
            }
            $handle = @fopen($logPath, 'ab');
            if (!is_resource($handle)) {
                self::fallbackLog();
                return;
            }
            @chmod($logPath, 0600);
            if (PHP_OS_FAMILY !== 'Windows' && ((@fileperms($logPath) & 0777) !== 0600)) {
                fclose($handle);
                self::fallbackLog();
                return;
            }
            $written = @fwrite($handle, $line);
            @fflush($handle);
            fclose($handle);
            if ($written !== strlen($line)) {
                self::fallbackLog();
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function logDirectory(): string
    {
        $k7eServerTest = PHP_SAPI === 'cli-server' && defined('KIOS_K7E_TEST_MODE') && KIOS_K7E_TEST_MODE === true;
        $k7gCliTest = PHP_SAPI === 'cli' && defined('KIOS_K7G_TEST_MODE') && KIOS_K7G_TEST_MODE === true;
        if ($k7eServerTest || $k7gCliTest) {
            $candidate = getenv('KIOS_K7E_TEST_LOG_DIR');
            $temp = realpath(sys_get_temp_dir());
            if (is_string($candidate) && $candidate !== '' && is_string($temp)
                && realpath(dirname($candidate)) === $temp
                && preg_match('/^kios-k7[eg]-observability-[a-f0-9]{16,32}$/iD', basename($candidate)) === 1
                && !is_link($candidate)) {
                return $candidate;
            }
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    }

    private static function respondWithGenericError(int $status = 500): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code($status);
        if (!headers_sent()) {
            header_remove('Content-Length');
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $page = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '500.html';
        if (is_file($page)) {
            $content = @file_get_contents($page);
            if (is_string($content)) {
                echo $content;
                return;
            }
        }
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Temporarily unavailable</title><p>Please try again later.</p></html>';
    }

    private static function severityName(int $severity): string
    {
        return match ($severity) {
            E_WARNING => 'warning',
            E_USER_WARNING => 'user_warning',
            E_RECOVERABLE_ERROR => 'recoverable_error',
            E_USER_ERROR => 'user_error',
            E_ERROR => 'fatal_error',
            E_PARSE => 'parse_error',
            E_CORE_ERROR => 'core_error',
            E_COMPILE_ERROR => 'compile_error',
            default => 'runtime_error',
        };
    }

    private static function safeContext(string $context): string
    {
        $context = preg_replace('/[^a-z0-9_.-]/i', '_', $context) ?? '';
        return substr($context, 0, 80);
    }

    private static function fallbackLog(): void
    {
        error_log('[Kios observability] private log write failed; request_id=' . (self::$requestId ?? 'unavailable'));
    }
}
