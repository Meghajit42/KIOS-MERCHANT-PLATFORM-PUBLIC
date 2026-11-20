<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Infrastructure\Database;
use Kios\Infrastructure\MigrationRunner;

try {
    $environment = \Kios\Infrastructure\Environment::get('APP_ENV', 'local');
    if (is_file(dirname(__DIR__) . '/database/migrations/008_product_verification.sql')
        && $environment !== 'local') {
        if ($environment !== 'demo') {
            fwrite(STDERR, "C2_MIGRATION_008_DEMO_ONLY\n");
            exit(1);
        }
        $approved = getenv('KIOS_C2_MIGRATION_APPROVED') === 'C2_MIGRATION_008_APPROVED';
        $application = \Kios\Infrastructure\DatabaseConfiguration::fromEnvironment('KIOS_DB');
        $migration = \Kios\Infrastructure\DatabaseConfiguration::fromEnvironment('KIOS_DB_MIGRATION');
        if (!$approved || $application->database !== 'hosting_account_kiosdemo'
            || $migration->database !== $application->database
            || $migration->host !== $application->host || $migration->port !== $application->port) {
            fwrite(STDERR, "C2_MIGRATION_008_APPROVAL_OR_TARGET_REQUIRED\n");
            exit(1);
        }
    }
    if (is_file(dirname(__DIR__) . '/database/migrations/011_staff_accounts.sql') && $environment !== 'local') {
        if ($environment !== 'demo' || getenv('KIOS_AUTH_P0_RELEASE_APPROVED') !== 'K10_AUTH_P0_RELEASE_APPROVED'
            || \Kios\Infrastructure\DatabaseConfiguration::fromEnvironment('KIOS_DB')->database !== 'hosting_account_kiosdemo') {
            fwrite(STDERR, "K10_AUTH_MIGRATION_APPROVAL_REQUIRED\n");exit(1);
        }
    }
    $applied = (new MigrationRunner(Database::connectForMigrations()))
        ->apply(dirname(__DIR__) . '/database/migrations');
    echo $applied === [] ? "Migrations already current.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
} catch (Throwable) {
    fwrite(STDERR, "Migration failed. Check the Kios database configuration and schema.\n");
    exit(1);
}
