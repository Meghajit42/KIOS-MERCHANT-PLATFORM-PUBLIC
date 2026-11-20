<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
adminRequireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
adminRequireCsrf();
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 42000, 'path' => '/admin', 'secure' => $params['secure'],
    'httponly' => true, 'samesite' => 'Strict',
]);
session_destroy();
header('Location: /admin/login.php', true, 303);
