<?php
declare(strict_types=1);

use Kios\Domain\PrintErrorCategory;
use Kios\Domain\PrintJob;
use Kios\Domain\PrintJobStatus;
use Kios\Domain\PrintJobType;
use Kios\Domain\PrinterAdapterType;
use Kios\Infrastructure\DatabaseConfiguration;
use Kios\Infrastructure\Environment;
use Kios\Infrastructure\PrintAdapter;
use Kios\Infrastructure\PrintAdapterFailure;
use Kios\Infrastructure\PrintJobService;
use Kios\Infrastructure\StaffAccessPolicy;

require_once dirname(__DIR__) . '/app/bootstrap.php';

$withEnvironment = static function (array $values, callable $test): void {
    $previous = [];
    foreach ($values as $name => $value) {
        $current = getenv($name);
        $previous[$name] = $current === false ? null : $current;
        putenv($name . '=' . $value);
    }
    try {
        $test();
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === null) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }
};

$dbValues = [
    'APP_ENV' => 'demo',
    'KIOS_DB_HOST' => 'db.example.test',
    'KIOS_DB_PORT' => '3306',
    'KIOS_DB_NAME' => 'acct_kios_demo',
    'KIOS_DB_USER' => 'acct_kios_app',
    'KIOS_DB_PASSWORD' => 'test-only-password',
];

return [
    'hosted demo database config accepts a provider endpoint without connecting' => static function () use ($withEnvironment, $dbValues): void {
        $withEnvironment($dbValues, static function (): void {
            $configuration = DatabaseConfiguration::fromEnvironment('KIOS_DB');
            if ($configuration->dsn() !== 'mysql:host=db.example.test;port=3306;dbname=acct_kios_demo;charset=utf8mb4') {
                throw new RuntimeException('hosted demo DSN was not built from the configured endpoint');
            }
        });
    },
    'local mode still rejects non-dedicated database endpoints' => static function () use ($withEnvironment, $dbValues): void {
        $values = $dbValues;
        $values['APP_ENV'] = 'local';
        $withEnvironment($values, static function (): void {
            try {
                DatabaseConfiguration::fromEnvironment('KIOS_DB');
            } catch (RuntimeException $error) {
                if (!str_contains($error->getMessage(), 'dedicated loopback')) {
                    throw new RuntimeException('local database rejection was not specific and safe');
                }
                return;
            }
            throw new RuntimeException('local mode accepted a hosted database endpoint');
        });
    },
    'hosted database config rejects DSN injection and unsupported environments' => static function () use ($withEnvironment, $dbValues): void {
        $values = $dbValues;
        $values['KIOS_DB_HOST'] = 'db.example.test;port=3307';
        $withEnvironment($values, static function (): void {
            try {
                DatabaseConfiguration::fromEnvironment('KIOS_DB');
            } catch (RuntimeException) {
                return;
            }
            throw new RuntimeException('database endpoint accepted DSN separator injection');
        });

        $values = $dbValues;
        $values['APP_ENV'] = 'production';
        $withEnvironment($values, static function (): void {
            try {
                DatabaseConfiguration::fromEnvironment('KIOS_DB');
            } catch (RuntimeException) {
                return;
            }
            throw new RuntimeException('unsupported production environment was accepted');
        });
    },
    'hosted migration credentials cannot target another database' => static function () use ($withEnvironment, $dbValues): void {
        $values = $dbValues + [
            'KIOS_DB_MIGRATION_HOST' => 'db.example.test',
            'KIOS_DB_MIGRATION_PORT' => '3306',
            'KIOS_DB_MIGRATION_NAME' => 'acct_unrelated',
            'KIOS_DB_MIGRATION_USER' => 'acct_kios_migrate',
            'KIOS_DB_MIGRATION_PASSWORD' => 'migration-test-only-password',
        ];
        $withEnvironment($values, static function (): void {
            try {
                \Kios\Infrastructure\Database::connectForMigrations();
            } catch (RuntimeException $error) {
                if (!str_contains($error->getMessage(), 'must target the configured Kios demo database')) {
                    throw new RuntimeException('migration target rejection was not specific and safe');
                }
                return;
            }
            throw new RuntimeException('migration account reached an unrelated database target');
        });
    },
    'hosted demo refuses physical printer adapters' => static function () use ($withEnvironment): void {
        $withEnvironment(['APP_ENV' => 'demo', 'PRINT_DRIVER' => 'virtual'], static function (): void {
            $virtual = new class implements PrintAdapter {
                public function print(PrintJob $job, string $bytes): string
                {
                    throw new RuntimeException('adapter should not print in selector test');
                }
            };
            $windows = new class implements PrintAdapter {
                public function print(PrintJob $job, string $bytes): string
                {
                    throw new RuntimeException('physical adapter must not run');
                }
            };
            $service = new PrintJobService(new PDO('sqlite::memory:'), $virtual, $windows);
            $select = new ReflectionMethod($service, 'adapterFor');
            $job = new PrintJob(
                1, 1, 1, null, null, 1, PrintJobType::CashierReceipt, PrintJobStatus::Queued,
                'G-TEST', null, null, 'Physical printer', PrinterAdapterType::WindowsSpooler,
                'configured-device', 80, true, 'TEST RECEIPT', 0,
            );
            try {
                $select->invoke($service, $job);
            } catch (ReflectionException $error) {
                throw new RuntimeException('virtual-only adapter selection could not be inspected');
            } catch (PrintAdapterFailure $error) {
                if ($error->category !== PrintErrorCategory::NoPrinterConfigured) {
                    throw new RuntimeException('physical adapter refusal used the wrong safe category');
                }
                return;
            }
            throw new RuntimeException('hosted demo selected a physical printer adapter');
        });
    },
    'Gopal menu importer is private CLI-only and package includes it outside public' => static function (string $root): void {
        $importer = file_get_contents($root . '/bin/import-gopal-menu-v1.php');
        $voiceImporter = file_get_contents($root . '/bin/import-voice-catalogue.php');
        $backup = file_get_contents($root . '/app/Infrastructure/MenuBackupExporter.php');
        $packageBuilder = file_get_contents($root . '/deploy/hostinger/build-package.ps1');
        if (!is_string($importer) || !is_string($voiceImporter) || !is_string($backup) || !is_string($packageBuilder)
            || !str_contains($importer, "if (PHP_SAPI !== 'cli')")
            || !str_contains($importer, "Environment::get('APP_ENV', 'local') !== 'demo'")
            || !str_contains($importer, 'MenuBackupExporter')
            || !str_contains($voiceImporter, "if (PHP_SAPI !== 'cli')")
            || !str_contains($voiceImporter, 'KIOS_C2_CATALOGUE_IMPORT_APPROVED')
            || !str_contains($voiceImporter, 'hosting_account_kiosdemo')
            || !str_contains($voiceImporter, "Environment::get('PRINT_DRIVER') !== 'virtual'")
            || !str_contains($backup, 'storage/runtime/menu-backups/')
            || !str_contains($packageBuilder, "'bin/import-gopal-menu-v1.php'")
            || !str_contains($packageBuilder, "'bin/import-voice-catalogue.php'")
            || !str_contains($packageBuilder, "'data/gopal_voice_catalogue_77.json'")
            || file_exists($root . '/public/import-gopal-menu-v1.php')) {
            throw new RuntimeException('Gopal import is not restricted to the private demo CLI environment');
        }
    },
    'Hostinger package includes durable checkout migration 007 and documents pre-migration backup' => static function (string $root): void {
        $builder = file_get_contents($root . '/deploy/hostinger/build-package.ps1');
        $guide = file_get_contents($root . '/deploy/hostinger/HOSTINGER_DEPLOYMENT.md');
        $migration = file_get_contents($root . '/database/migrations/007_durable_checkout_idempotency.sql');
        $verificationMigration = file_get_contents($root . '/database/migrations/008_product_verification.sql');
        $migrator = file_get_contents($root . '/bin/migrate.php');
        if (!is_string($builder) || !is_string($guide) || !is_string($migration)
            || !is_string($verificationMigration) || !is_string($migrator)
            || substr_count($builder, '007_durable_checkout_idempotency.sql') < 2
            || !str_contains($migration, 'ALTER TABLE orders')
            || !str_contains($migration, 'checkout_idempotency_key_hash')
            || !str_contains($migration, 'uq_orders_checkout_idempotency_hash')
            || substr_count($builder, '008_product_verification.sql') < 2
            || !str_contains($builder, 'responsive_webp_assets = 308')
            || !str_contains($guide, 'Before applying migration 008')
            || !str_contains($guide, 'All eight SQL migrations')
            || !str_contains($migrator, 'KIOS_C2_MIGRATION_APPROVED')
            || !str_contains($migrator, 'hosting_account_kiosdemo')
            || !str_contains($migrator, 'C2_MIGRATION_008_DEMO_ONLY')
            || !str_contains($builder, 'merge-base --is-ancestor')) {
            throw new RuntimeException('Hostinger C2 package, explicit migration gate, or backup guidance is incomplete');
        }
    },
    'staff access is local-only by default and hosted demo requires HTTPS' => static function (): void {
        if (!StaffAccessPolicy::allows(false, '127.0.0.1', false)
            || !StaffAccessPolicy::allows(false, '::1', false)
            || StaffAccessPolicy::allows(false, '203.0.113.8', true)) {
            throw new RuntimeException('local staff access is not restricted to loopback');
        }
        if (!StaffAccessPolicy::allows(true, '203.0.113.8', true)
            || StaffAccessPolicy::allows(true, '203.0.113.8', false)) {
            throw new RuntimeException('hosted demo staff access does not enforce HTTPS');
        }
    },
];
