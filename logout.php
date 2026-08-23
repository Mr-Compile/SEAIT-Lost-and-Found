<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers/mfa_helper.php';
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/includes/helpers/auth/remember_me_helper.php';

startSecureSession();
loadEnvFile(__DIR__ . '/.env');

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if (isset($pdo) && $pdo instanceof PDO) {
    if ($currentUserId > 0) {
        revokeAllRememberMeTokensForUser($pdo, $currentUserId);
    } else {
        revokeRememberMeFromCurrentDevice($pdo);
    }
} else {
    clearRememberMeCookie();
}

$_SESSION = [];
session_destroy();

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => (bool)$params['secure'],
        'httponly' => (bool)$params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

header('Location: login.php?reason=logged_out');
exit;
