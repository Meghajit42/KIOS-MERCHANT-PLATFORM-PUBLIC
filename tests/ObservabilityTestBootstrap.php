<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    define('KIOS_K7E_TEST_MODE', true);
    define(
        'KIOS_K7E_TEST_MESSAGE',
        'Synthetic status-route fault; db_password=KIOS_TEST_DB_PASSWORD_SENTINEL; session_id=KIOS_TEST_SESSION_ID_SENTINEL; csrf=KIOS_TEST_CSRF_SENTINEL; Authorization: Bearer KIOS_TEST_AUTH_SENTINEL; cookie=KIOS_TEST_COOKIE_SENTINEL; private_key=-----BEGIN PRIVATE KEY-----KIOS_TEST_PRIVATE_KEY_SENTINEL-----END PRIVATE KEY-----; test_password=KIOS_TEST_PASSWORD_SENTINEL',
    );
}
