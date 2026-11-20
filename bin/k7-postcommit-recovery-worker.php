<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\DatabaseConfiguration;

ini_set('display_errors', '0');

try {
    if (getenv('APP_ENV') !== 'demo' || getenv('PRINT_DRIVER') !== 'virtual') {
        throw new RuntimeException('K7 recovery worker requires local demo mode and virtual printing.');
    }
    $configuration = DatabaseConfiguration::fromEnvironment('KIOS_DB');
    $root = getenv('KIOS_K7_ROOT');
    if ($configuration->host !== '127.0.0.1' || $configuration->port !== 33082
        || $configuration->database !== 'kios_k7_test' || !is_string($root)
        || basename($root) !== 'KiosK7Qualification' || !is_dir($root . DIRECTORY_SEPARATOR . 'mariadb-data')) {
        throw new RuntimeException('K7 recovery worker is restricted to the isolated local qualification database.');
    }

    $sessionId = $argv[1] ?? '';
    $mode = $argv[2] ?? '';
    if (preg_match('/^[A-Fa-f0-9]{32,64}$/D', $sessionId) !== 1 || !in_array($mode, ['crash-after-commit', 'retry'], true)) {
        throw new InvalidArgumentException('K7 recovery worker arguments are invalid.');
    }

    session_save_path($root . DIRECTORY_SEPARATOR . 'sessions');
    session_id($sessionId);
    if (!session_start()) {
        throw new RuntimeException('The isolated K7 session could not be opened.');
    }
    $csrf = $_SESSION['_csrf'] ?? null;
    $checkoutKey = $_SESSION['_checkout_intent_key'] ?? null;
    if (!is_string($csrf) || $csrf === '' || !is_string($checkoutKey)
        || preg_match('/^[a-f0-9]{64}$/D', $checkoutKey) !== 1) {
        throw new RuntimeException('The isolated K7 session is missing its checkout fixtures.');
    }
    session_write_close();

    if ($mode === 'crash-after-commit') {
        define('KIOS_K7_FAULT_AFTER_CHECKOUT_COMMIT', true);
    }

    $_COOKIE[session_name()] = $sessionId;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/';
    $_POST = ['_csrf' => $csrf, 'action' => 'checkout', 'checkout_intent_key' => $checkoutKey];
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
} catch (Throwable $error) {
    fwrite(STDERR, 'K7 recovery worker failed (' . get_class($error) . ').' . PHP_EOL);
    exit(1);
}
