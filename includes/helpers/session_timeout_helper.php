<?php

declare(strict_types=1);

function getSessionTimeoutSeconds(): int
{
    $defaultSeconds = 180;

    $raw = $_ENV['SESSION_IDLE_TIMEOUT_SECONDS']
        ?? $_SERVER['SESSION_IDLE_TIMEOUT_SECONDS']
        ?? getenv('SESSION_IDLE_TIMEOUT_SECONDS');

    if ($raw === false || $raw === null || $raw === '') {
        return $defaultSeconds;
    }

    $seconds = (int)$raw;
    if ($seconds < 60) {
        return 60;
    }

    if ($seconds > 3600) {
        return 3600;
    }

    return $seconds;
}

function getSessionWarningSeconds(): int
{
    $defaultSeconds = 120;

    $raw = $_ENV['SESSION_WARNING_SECONDS']
        ?? $_SERVER['SESSION_WARNING_SECONDS']
        ?? getenv('SESSION_WARNING_SECONDS');

    if ($raw === false || $raw === null || $raw === '') {
        $warningSeconds = $defaultSeconds;
    } else {
        $warningSeconds = (int)$raw;
        if ($warningSeconds < 15) {
            $warningSeconds = 15;
        }
    }

    $timeoutSeconds = getSessionTimeoutSeconds();
    if ($warningSeconds >= $timeoutSeconds) {
        $warningSeconds = max(15, $timeoutSeconds - 10);
    }

    return $warningSeconds;
}

function getSessionActivityKey(): string
{
    return 'last_activity_at';
}

function ensureSessionActivityTimestamp(): void
{
    $key = getSessionActivityKey();
    if (!isset($_SESSION[$key]) || !is_int($_SESSION[$key])) {
        $_SESSION[$key] = time();
    }
}

function touchSessionActivity(): void
{
    $_SESSION[getSessionActivityKey()] = time();
}

function isSessionTimedOutByInactivity(): bool
{
    ensureSessionActivityTimestamp();

    $lastActivity = (int)($_SESSION[getSessionActivityKey()] ?? 0);
    if ($lastActivity <= 0) {
        return false;
    }

    $timeoutSeconds = getSessionTimeoutSeconds();
    return (time() - $lastActivity) > $timeoutSeconds;
}

function destroyAuthenticatedSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}
