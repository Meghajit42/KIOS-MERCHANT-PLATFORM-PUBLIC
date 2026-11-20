<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
$root = getenv('KIOS_K7_ROOT');
$sessionId = $argv[1] ?? '';
$barrier = $argv[2] ?? '';
if (!is_string($root) || !is_dir($root) || preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sessionId) !== 1
    || !is_file($root . DIRECTORY_SEPARATOR . 'session-csrf-' . $sessionId)
    || !is_file($root . DIRECTORY_SEPARATOR . 'session-checkout-key-' . $sessionId)) {
    exit(2);
}

$barrierDirectory = realpath(dirname($barrier)) ?: '';
$rootPath = realpath($root) ?: '';
if ($barrier !== '' && ($rootPath === '' || $barrierDirectory !== $rootPath)) {
    exit(2);
}

if ($barrier !== '') {
    $readyPath = $barrier . '.' . getmypid() . '.ready';
    if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
        exit(2);
    }
    $deadline = hrtime(true) + 15_000_000_000;
    while (!is_file($barrier)) {
        if (hrtime(true) >= $deadline) {
            exit(3);
        }
        usleep(1000);
    }
}

$csrf = trim((string) file_get_contents($root . DIRECTORY_SEPARATOR . 'session-csrf-' . $sessionId));
$checkoutKey = trim((string) file_get_contents($root . DIRECTORY_SEPARATOR . 'session-checkout-key-' . $sessionId));
if ($csrf === '' || preg_match('/^[a-f0-9]{64}$/D', $checkoutKey) !== 1) {
    exit(2);
}
session_save_path($root . DIRECTORY_SEPARATOR . 'sessions');
$_COOKIE[session_name()] = $sessionId;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_POST = ['_csrf' => $csrf, 'action' => 'checkout', 'checkout_intent_key' => $checkoutKey];
include dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
