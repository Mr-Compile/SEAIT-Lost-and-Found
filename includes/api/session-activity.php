<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/helpers/session_timeout_helper.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'reason' => 'auth_required',
    ]);
    exit;
}

if (isSessionTimedOutByInactivity()) {
    destroyAuthenticatedSession();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'reason' => 'session_timeout',
    ]);
    exit;
}

touchSessionActivity();

echo json_encode([
    'success' => true,
    'timeout_seconds' => getSessionTimeoutSeconds(),
    'warning_seconds' => getSessionWarningSeconds(),
    'server_time' => time(),
]);
