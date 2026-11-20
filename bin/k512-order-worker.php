<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Domain\OrderType;
use Kios\Infrastructure\Database;
use Kios\Infrastructure\DatabaseConfiguration;
use Kios\Infrastructure\OrderRepository;

try {
    $sku = $argv[1] ?? '';
    $variant = $argv[2] ?? '';
    if (!is_string($sku) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $sku)
        || !is_string($variant) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $variant)) {
        throw new RuntimeException('The synthetic menu selection is invalid.');
    }
    $configuration = DatabaseConfiguration::fromEnvironment('KIOS_DB');
    if ($configuration->host !== '127.0.0.1' || $configuration->port !== 33081
        || $configuration->database !== 'kios_development') {
        throw new RuntimeException('The order worker is restricted to the dedicated local Kios database.');
    }
    $order = (new OrderRepository(Database::connect()))->createFromCart([$sku => [$variant => 1]], OrderType::Takeaway);
    echo json_encode([
        'id' => $order->id,
        'internal' => $order->token,
        'pickup' => $order->publicToken(),
    ], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Order worker failed: ' . $error->getMessage() . "\n");
    exit(1);
}
