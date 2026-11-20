<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\Database;
use Kios\Infrastructure\DemoSeeder;

try {
    $counts = (new DemoSeeder(Database::connect()))->seed();
    foreach ($counts as $table => $count) {
        echo $table . ': ' . $count . " synthetic rows\n";
    }
} catch (Throwable) {
    fwrite(STDERR, "Demo seeding failed. Check that required migrations are applied.\n");
    exit(1);
}
