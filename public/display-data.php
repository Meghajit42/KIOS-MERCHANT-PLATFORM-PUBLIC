<?php
declare(strict_types=1);

use Kios\Application\PublicTokenDisplayController;
use Kios\Infrastructure\Database;

require_once dirname(__DIR__) . '/app/bootstrap.php';

header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
$controller = new PublicTokenDisplayController(static fn(): \PDO => Database::connect());
$response = $controller->respond(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '');
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
