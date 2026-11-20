<?php
declare(strict_types=1);

ini_set('session.save_path', sys_get_temp_dir());
require dirname(__DIR__) . '/public/admin/_bootstrap.php';

$scenario = $argv[1] ?? '';
if ($scenario === 'staff') {
    $_SESSION['cashier_authenticated'] = true;
    $_SESSION['staff_authenticated'] = true;
    adminRequireLogin();
    echo 'allowed';
    return;
}

if ($scenario === 'admin') {
    $_SESSION['menu_admin_authenticated'] = true;
    adminRequireLogin();
    echo 'allowed';
    return;
}

if (in_array($scenario, ['csrf-missing', 'csrf-invalid', 'csrf-valid'], true)) {
    $_SESSION['menu_admin_authenticated'] = true;
    $_SESSION['_menu_admin_csrf'] = 'test-csrf-token';
    $_POST = $scenario === 'csrf-missing' ? [] : ['_csrf' => ($scenario === 'csrf-valid' ? 'test-csrf-token' : 'wrong-token')];
    try {
        adminRequireCsrf();
        echo 'allowed';
    } catch (DomainException) {
        echo 'denied';
    }
    return;
}

adminRequireLogin();
echo 'allowed';
