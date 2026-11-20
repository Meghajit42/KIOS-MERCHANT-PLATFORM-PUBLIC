<?php
declare(strict_types=1);

use Kios\Infrastructure\Database;
use Kios\Infrastructure\Environment;
use Kios\Infrastructure\MenuAdminRepository;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (\Kios\Infrastructure\StaffAccountService::enabled()) {
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    $remote = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
    if (!\Kios\Infrastructure\StaffAccessPolicy::allows(Environment::get('APP_ENV','local') === 'demo', $remote, $secure)) {
        http_response_code(400);exit('Secure access is required for owner administration.');
    }
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (\Kios\Infrastructure\StaffAccountService::enabled()) {
        session_name('KIOSMENUADMINACCOUNTS');
    } else {
        session_name('KIOSMENUADMIN');
    }
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/admin', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

const KIOS_ADMIN_OUTLET = 'demo-outlet';

function adminH(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminRepository(): MenuAdminRepository
{
    return new MenuAdminRepository(Database::connect());
}

function adminRequireLogin(): void
{
    if (!adminOwnerValid()) {
        header('Location: /admin/login.php', true, 303);
        exit;
    }
}

function adminStaffAccounts(): \Kios\Infrastructure\StaffAccountService
{
    return new \Kios\Infrastructure\StaffAccountService(Database::connect(), KIOS_ADMIN_OUTLET, Environment::get('KIOS_AUTH_RATE_KEY', '') ?? '');
}

function adminOwnerValid(): bool
{
    if (\Kios\Infrastructure\StaffAccountService::enabled()) {
        if (adminStaffAccounts()->ownerValid($_SESSION)) return true;
        unset($_SESSION['menu_admin_authenticated'], $_SESSION['_owner_account_tag']);
        return false;
    }
    // Do not accept an account-mode session after an explicit rollback/cutover reversal.
    if (isset($_SESSION['_owner_account_tag'])) return false;
    return ($_SESSION['menu_admin_authenticated'] ?? false) === true;
}

function adminToken(): string
{
    if (!isset($_SESSION['_menu_admin_csrf']) || !is_string($_SESSION['_menu_admin_csrf'])) {
        $_SESSION['_menu_admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_menu_admin_csrf'];
}

function adminCsrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . adminH(adminToken()) . '">';
}

function adminRequireCsrf(): void
{
    $submitted = $_POST['_csrf'] ?? '';
    if (!is_string($submitted) || !isset($_SESSION['_menu_admin_csrf'])
        || !is_string($_SESSION['_menu_admin_csrf']) || !hash_equals($_SESSION['_menu_admin_csrf'], $submitted)) {
        http_response_code(400);
        throw new DomainException('Your security check expired. Reload and try again.');
    }
}

function adminPost(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function adminFlash(string $message): void
{
    $_SESSION['_menu_admin_flash'] = $message;
}

function adminTakeFlash(): string
{
    $message = $_SESSION['_menu_admin_flash'] ?? '';
    unset($_SESSION['_menu_admin_flash']);
    return is_string($message) ? $message : '';
}

function adminStart(string $title): void
{
    $css = dirname(__DIR__) . '/assets/admin.css';
    $js = dirname(__DIR__) . '/assets/admin.js';
    $version = is_file($css) ? (string) filemtime($css) : '1';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    $ownerCss = dirname(__DIR__) . '/assets/owner-dashboard.css';
    $ownerVersion = is_file($ownerCss) ? (string) filemtime($ownerCss) : '1';
    $ownerJs = dirname(__DIR__) . '/assets/owner-dashboard.js';
    $ownerJsVersion = is_file($ownerJs) ? (string) filemtime($ownerJs) : '1';
    echo '<meta name="theme-color" content="#780909"><link rel="stylesheet" href="/assets/admin.css?v=' . adminH($version) . '"><link rel="stylesheet" href="/assets/owner-dashboard.css?v=' . adminH($ownerVersion) . '">';
    echo '<title>' . adminH($title) . ' · Kios Menu Admin</title></head><body><header class="admin-topbar">';
    echo '<a class="admin-brand" href="/admin/menu.php"><img class="owner-logo" src="/assets/brand-product-placeholder.svg" alt="" width="54" height="48"><span><strong>Synthetic Restaurant Demo</strong><small>Owner dashboard</small></span></a>';
    echo '<nav aria-label="Owner menu"><a href="/admin/menu.php">Overview</a><a href="/admin/shop-operations.php">Shop &amp; daily reports</a><a href="/admin/products.php">Products</a><a href="/admin/catalogue-review.php">Catalogue review</a><a href="/admin/route-review.php">Preparation routes</a><a href="/admin/categories.php">Categories</a><a href="/admin/departments.php">Floor routing</a><a href="/admin/settings.php">Preparation time</a><a href="/admin/changes.php">Recent changes</a>';
    if (\Kios\Infrastructure\StaffAccountService::enabled()) echo '<a href="/admin/staff.php">Staff</a>';
    echo '</nav>';
    echo '<form method="post" action="/admin/logout.php">' . adminCsrfField() . '<button class="admin-logout" type="submit">Sign out</button></form></header>';
    echo '<main class="admin-shell"><div class="admin-page-heading"><div><p class="admin-eyebrow">OWNER DASHBOARD</p><h1>' . adminH($title) . '</h1></div></div>';
    if (\Kios\Infrastructure\PrintGateway::enabled()) {
        echo '<p><a href="/admin/print-queue.php">Owner print queue</a></p>';
    }
    $flash = adminTakeFlash();
    if ($flash !== '') {
        echo '<p class="admin-flash" role="status">' . adminH($flash) . '</p>';
    }
    echo '<script src="/assets/admin.js" defer></script>';
    echo '<script src="/assets/owner-dashboard.js?v=' . adminH($ownerJsVersion) . '" defer></script>';
}

function adminEnd(): void
{
    echo '</main><footer class="admin-footer">Menu changes apply to future customer orders. Past orders keep their saved details.</footer></body></html>';
}

function adminFail(Throwable $error): string
{
    if ($error instanceof DomainException || $error instanceof InvalidArgumentException) {
        return $error->getMessage();
    }
    \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'menu_admin_operation');
    return 'The change could not be saved. Reload and try again.';
}
