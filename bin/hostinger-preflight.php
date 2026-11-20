<?php
declare(strict_types=1);

use Kios\Infrastructure\Database;
use Kios\Infrastructure\DatabaseConfiguration;
use Kios\Infrastructure\Environment;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$root = dirname(__DIR__);
$checks = [];
$check = static function (string $name, bool $passed, string $safeDetail = '') use (&$checks): void {
    $checks[$name] = $passed;
    echo $name . ': ' . ($passed ? 'PASS' : 'FAIL');
    if ($safeDetail !== '') {
        echo ' (' . $safeDetail . ')';
    }
    echo PHP_EOL;
};

$check('PHP >= 8.2', PHP_VERSION_ID >= 80200);
$check('PDO MySQL', class_exists(PDO::class) && extension_loaded('pdo_mysql') && in_array('mysql', PDO::getAvailableDrivers(), true));
$check('Sessions', extension_loaded('session') && function_exists('session_start'));
$check('Menu image processing', extension_loaded('fileinfo') && extension_loaded('gd') && function_exists('imagewebp'));

$configurationReady = false;
try {
    $appDatabase = DatabaseConfiguration::fromEnvironment('KIOS_DB');
    $migrationDatabase = DatabaseConfiguration::fromEnvironment('KIOS_DB_MIGRATION');
    $appUrl = Environment::get('APP_URL');
    $cashierPassword = Environment::get('KIOS_CASHIER_PASSWORD');
    $groundFloorPassword = Environment::get('KIOS_GROUND_FLOOR_PASSWORD');
    $firstFloorPassword = Environment::get('KIOS_FIRST_FLOOR_KITCHEN_PASSWORD');
    $menuAdminHash = Environment::get('KIOS_MENU_ADMIN_PASSWORD_HASH');
    $menuAdminHashValid = is_string($menuAdminHash) && password_get_info($menuAdminHash)['algoName'] !== 'unknown';
    $httpsUrl = is_string($appUrl) && filter_var($appUrl, FILTER_VALIDATE_URL) !== false
        && strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) === 'https'
        && parse_url($appUrl, PHP_URL_HOST) !== null;
    $accountMode = \Kios\Infrastructure\StaffAccountService::enabled();
    $staffConfigurationReady = $accountMode ? strlen(Environment::get('KIOS_AUTH_RATE_KEY', '') ?? '') >= 32
        : strlen((string)$cashierPassword)>=20 && strlen((string)$groundFloorPassword)>=20 && strlen((string)$firstFloorPassword)>=20 && $menuAdminHashValid;
    $configurationReady = Environment::get('APP_ENV') === 'demo'
        && Environment::get('PRINT_DRIVER') === 'virtual'
        && $httpsUrl
        && $staffConfigurationReady
        && $appDatabase->host !== ''
        && $migrationDatabase->host !== '';
} catch (Throwable) {
    $configurationReady = false;
}
$check('Demo configuration', $configurationReady);

foreach (['storage/runtime', 'storage/logs'] as $relativeDirectory) {
    $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    $check($relativeDirectory . ' writable', is_dir($directory) && is_writable($directory));
}

$errorSafety = is_file($root . '/public/.user.ini')
    && is_file($root . '/public/500.html')
    && is_file($root . '/public/.htaccess');
$check('Public error protection', $errorSafety);

$pdo = null;
try {
    if ($configurationReady && extension_loaded('pdo_mysql')) {
        $pdo = Database::connect();
        $pdo->query('SELECT 1');
    }
} catch (Throwable) {
    $pdo = null;
}
$check('Application database connection', $pdo instanceof PDO);

$migrationPdo = null;
try {
    if ($configurationReady && extension_loaded('pdo_mysql')) {
        $migrationPdo = Database::connectForMigrations();
        $migrationPdo->query('SELECT 1');
    }
} catch (Throwable) {
    $migrationPdo = null;
}
$check('Migration database connection', $migrationPdo instanceof PDO);

$expectedMigrations = array_map('basename', glob($root . '/database/migrations/[0-9][0-9][0-9]_*.sql') ?: []);
$migrationStatus = false;
$migrationCount = 0;
if ($pdo instanceof PDO && count($expectedMigrations) >= 10) {
    try {
        $applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_map('strval', $applied);
        $migrationCount = count(array_intersect($expectedMigrations, $applied));
        $migrationStatus = $migrationCount === count($expectedMigrations) && count($applied) === count($expectedMigrations);
    } catch (Throwable) {
        $migrationStatus = false;
    }
}
$check('Migrations', $migrationStatus, $migrationCount . '/' . count($expectedMigrations) . ' current');

if (\Kios\Infrastructure\StaffAccountService::enabled()) {
    $accountsReady = false;
    try {
        if ($pdo instanceof PDO) {
            $service = new \Kios\Infrastructure\StaffAccountService($pdo);
            $accountsReady = password_get_info($service->ownerHash())['algoName'] !== 'unknown';
            $q = $pdo->query("SELECT COUNT(DISTINCT a.role) FROM staff_accounts a JOIN outlets o ON o.outlet_id=a.outlet_id WHERE o.outlet_code='demo-outlet' AND a.realm='staff' AND a.enabled=1");
            $accountsReady = $accountsReady && (int)$q->fetchColumn() === 3;
        }
    } catch (Throwable) { $accountsReady = false; }
    $check('Account authentication provisioned', $accountsReady);
    $check('Encrypted recovery delivery', function_exists('sodium_crypto_box_seal'));
}
exit(in_array(false, $checks, true) ? 1 : 0);
