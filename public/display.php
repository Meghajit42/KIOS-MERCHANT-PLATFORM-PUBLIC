<?php
declare(strict_types=1);

use Kios\Application\PublicTokenDisplayView;
use Kios\Infrastructure\Database;
use Kios\Infrastructure\PublicTokenDisplayService;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$snapshot = ['outlet' => 'Gopal demo outlet', 'tokens' => []];
$connectionFailed = false;
try {
    $snapshot = (new PublicTokenDisplayService(Database::connect()))->snapshot();
} catch (\Throwable $error) {
    \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'token_display');
    $connectionFailed = true;
}

echo PublicTokenDisplayView::render($snapshot, $connectionFailed);
