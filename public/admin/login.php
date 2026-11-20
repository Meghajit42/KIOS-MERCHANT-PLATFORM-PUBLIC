<?php
declare(strict_types=1);

use Kios\Infrastructure\Environment;

require_once __DIR__ . '/_bootstrap.php';

if (adminOwnerValid()) {
    header('Location: /admin/menu.php', true, 303);
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adminRequireCsrf();
        if (adminPost('action') !== 'login') {
            throw new DomainException('Sign-in could not be verified.');
        }
        $now = time();
        if ((int) ($_SESSION['_menu_admin_locked_until'] ?? 0) > $now) {
            throw new DomainException('Too many attempts. Wait a little before trying again.');
        }
        $candidate = $_POST['password'] ?? '';
        $hash = Environment::get('KIOS_MENU_ADMIN_PASSWORD_HASH');
        $ownerAccountTag = \Kios\Infrastructure\StaffAccountService::enabled()
            ? adminStaffAccounts()->authenticateOwner($candidate, is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '') : null;
        $valid = \Kios\Infrastructure\StaffAccountService::enabled() ? is_string($ownerAccountTag) : is_string($candidate) && strlen($candidate) <= 200 && is_string($hash) && strlen($hash) >= 60
            && password_verify($candidate, $hash);
        unset($candidate);
        if (!$valid) {
            $attempts = (int) ($_SESSION['_menu_admin_attempts'] ?? 0) + 1;
            $_SESSION['_menu_admin_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['_menu_admin_locked_until'] = $now + 900;
                $_SESSION['_menu_admin_attempts'] = 0;
            }
            throw new DomainException('Owner sign-in could not be verified.');
        }
        session_regenerate_id(true);
        $_SESSION['menu_admin_authenticated'] = true;
        $_SESSION['menu_admin_actor'] = 'menu-admin';
        if (\Kios\Infrastructure\StaffAccountService::enabled()) $_SESSION['_owner_account_tag'] = $ownerAccountTag;
        $_SESSION['_menu_admin_attempts'] = 0;
        unset($_SESSION['_menu_admin_locked_until']);
        $_SESSION['_menu_admin_csrf'] = bin2hex(random_bytes(32));
        header('Location: /admin/menu.php', true, 303);
        exit;
    } catch (Throwable $exception) {
        if (http_response_code() < 400) {
            http_response_code(401);
        }
        $error = adminFail($exception);
    }
}
$style = is_file(dirname(__DIR__) . '/assets/admin.css') ? (string) filemtime(dirname(__DIR__) . '/assets/admin.css') : '1';
$ownerStyle = is_file(dirname(__DIR__) . '/assets/owner-dashboard.css') ? (string) filemtime(dirname(__DIR__) . '/assets/owner-dashboard.css') : '1';
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<meta name="theme-color" content="#780909"><link rel="stylesheet" href="/assets/admin.css?v=' . adminH($style) . '"><link rel="stylesheet" href="/assets/owner-dashboard.css?v=' . adminH($ownerStyle) . '"><title>Owner sign in · Kios Menu Admin</title></head><body class="admin-login-body">';
echo '<main class="admin-login-card"><a class="admin-brand" href="/"><img class="owner-logo" src="/assets/brand-product-placeholder.svg" alt="" width="54" height="48"><span><strong>Synthetic Restaurant Demo</strong><small>Owner dashboard</small></span></a>';
echo '<p class="admin-eyebrow">OWNER ACCESS</p><h1>Sign in to your dashboard</h1><p>Manage shop operations, products and staff from one place.</p>';
if ($error !== '') {
    echo '<p class="admin-flash" role="alert">' . adminH($error) . '</p>';
}
echo '<form method="post" class="admin-form">' . adminCsrfField() . '<input type="hidden" name="action" value="login">';
echo '<label for="password">Owner password</label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="200" required autofocus>';
echo '<button class="admin-button admin-button-primary admin-button-wide" type="submit">Sign in</button></form></main></body></html>';
