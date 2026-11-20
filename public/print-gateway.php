<?php
declare(strict_types=1);

use Kios\Infrastructure\Database;
use Kios\Infrastructure\PrintGateway;
use Kios\Infrastructure\RequestObservability;

require_once dirname(__DIR__) . '/app/bootstrap.php';

function gatewayResponse(int $status, array $body = []): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/json');
    if ($status !== 204) {
        echo json_encode($body, JSON_THROW_ON_ERROR);
    }
    exit;
}

if (!PrintGateway::enabled()) {
    gatewayResponse(404);
}
// Trust only the web server's HTTPS flag. The P1D loopback preflight sets it for its TLS proxy.
if (!in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true)) {
    gatewayResponse(403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    gatewayResponse(405);
}
if (!preg_match('/^Bearer ([a-z0-9][a-z0-9-]{0,63})\.([a-f0-9]{64})$/D', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $auth)) {
    gatewayResponse(401);
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0])) !== 'application/json') {
    gatewayResponse(415);
}
try {
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (!is_string($raw) || strlen($raw) > 4096) {
        gatewayResponse(413);
    }
    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    gatewayResponse(400);
}
if (!is_array($body)) {
    gatewayResponse(400);
}
try {
    if (($body['action'] ?? null) === 'claim' && count($body) === 1) {
        $job = (new PrintGateway(Database::connect()))->claim($auth[1], $auth[2]);
        if ($job === false) {
            gatewayResponse(401);
        }
        if ($job === null) {
            gatewayResponse(204);
        }
        gatewayResponse(200, $job);
    }
    if (($body['action'] ?? null) === 'complete' && count($body) === 4
        && is_int($body['id'] ?? null) && $body['id'] > 0
        && is_string($body['claim'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $body['claim'])
        && in_array($body['result'] ?? null, ['submitted', 'failed', 'needs_review'], true)
    ) {
        $done = (new PrintGateway(Database::connect()))->complete($auth[1], $auth[2], $body['id'], $body['claim'], $body['result']);
        gatewayResponse(match ($done) { true => 204, false => 401, null => 409 });
    }
    gatewayResponse(400);
} catch (Throwable $error) {
    RequestObservability::recordCaughtException($error, 'print_gateway');
    gatewayResponse(503);
}
