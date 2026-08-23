<?php

declare(strict_types=1);

if (!defined('FINDIT_REMEMBER_ME_COOKIE')) {
    define('FINDIT_REMEMBER_ME_COOKIE', 'findit_remember');
}

if (!defined('FINDIT_REMEMBER_ME_EMAIL_COOKIE')) {
    define('FINDIT_REMEMBER_ME_EMAIL_COOKIE', 'findit_remember_email');
}

function ensureRememberMeTokensTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_remember_tokens (
            token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector CHAR(18) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            created_ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            UNIQUE KEY uniq_selector (selector),
            INDEX idx_user_id (user_id),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

function rememberMeCookieName(): string
{
    return FINDIT_REMEMBER_ME_COOKIE;
}

function rememberMeEmailCookieName(): string
{
    return FINDIT_REMEMBER_ME_EMAIL_COOKIE;
}

function rememberMeDurationDays(): int
{
    $raw = function_exists('envValue') ? envValue('REMEMBER_ME_DAYS', '30') : '30';
    $days = (int)$raw;
    if ($days < 1) {
        $days = 30;
    }
    if ($days > 90) {
        $days = 90;
    }
    return $days;
}

function rememberMeCookieSecure(): bool
{
    return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function rememberMeCookiePath(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = trim(dirname($scriptName), '/.');
    if ($scriptDir === '') {
        return '/';
    }

    $parts = explode('/', $scriptDir);
    $rootSegment = trim((string)($parts[0] ?? ''), '/');
    return $rootSegment !== '' ? '/' . $rootSegment : '/';
}

function rememberMeCookieClearPaths(): array
{
    $paths = ['/', rememberMeCookiePath()];
    return array_values(array_unique($paths));
}

function clearRememberMeCookie(): void
{
    $cookieName = rememberMeCookieName();

    foreach (rememberMeCookieClearPaths() as $path) {
        setcookie($cookieName, '', [
            'expires' => time() - 3600,
            'path' => $path,
            'secure' => rememberMeCookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    unset($_COOKIE[$cookieName]);
}

function getRememberedLoginEmail(): string
{
    $email = trim((string)($_COOKIE[rememberMeEmailCookieName()] ?? ''));
    if ($email === '' || strlen($email) > 254) {
        return '';
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function storeRememberedLoginEmail(string $email): void
{
    $email = trim($email);
    if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        clearRememberedLoginEmail();
        return;
    }

    $expiresAtTs = time() + (rememberMeDurationDays() * 86400);
    setcookie(rememberMeEmailCookieName(), $email, [
        'expires' => $expiresAtTs,
        'path' => rememberMeCookiePath(),
        'secure' => rememberMeCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE[rememberMeEmailCookieName()] = $email;
}

function clearRememberedLoginEmail(): void
{
    $cookieName = rememberMeEmailCookieName();

    foreach (rememberMeCookieClearPaths() as $path) {
        setcookie($cookieName, '', [
            'expires' => time() - 3600,
            'path' => $path,
            'secure' => rememberMeCookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    unset($_COOKIE[$cookieName]);
}

function createRememberMeToken(PDO $pdo, int $userId): void
{
    ensureRememberMeTokensTable($pdo);

    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $validatorHash = hash('sha256', $validator);

    $durationDays = rememberMeDurationDays();
    $expiresAtTs = time() + ($durationDays * 86400);
    $expiresAtSql = date('Y-m-d H:i:s', $expiresAtTs);

    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')->execute([$userId]);
    $pdo->exec('DELETE FROM user_remember_tokens WHERE expires_at < NOW()');

    $insert = $pdo->prepare(
        'INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at, created_ip, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([$userId, $selector, $validatorHash, $expiresAtSql, $ip !== '' ? $ip : null, $userAgent !== '' ? $userAgent : null]);

    setcookie(rememberMeCookieName(), $selector . ':' . $validator, [
        'expires' => $expiresAtTs,
        'path' => rememberMeCookiePath(),
        'secure' => rememberMeCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function applyRememberedUserSession(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['full_name'] = (string)$user['full_name'];
    $_SESSION['email'] = (string)$user['email'];
    $_SESSION['role'] = (string)$user['role'];
    unset($_SESSION['mfa_pending_user'], $_SESSION['remember_after_mfa']);
}

function attemptRememberMeLogin(PDO $pdo): bool
{
    if (!empty($_SESSION['user_id'])) {
        return true;
    }

    $cookie = (string)($_COOKIE[rememberMeCookieName()] ?? '');
    if ($cookie === '' || !str_contains($cookie, ':')) {
        return false;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if (!preg_match('/^[a-f0-9]{18}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        clearRememberMeCookie();
        return false;
    }

    ensureRememberMeTokensTable($pdo);
    $pdo->exec('DELETE FROM user_remember_tokens WHERE expires_at < NOW()');

    $stmt = $pdo->prepare(
        'SELECT rt.token_id, rt.token_hash, rt.user_id, u.full_name, u.email, u.role
         FROM user_remember_tokens rt
         INNER JOIN users u ON u.user_id = rt.user_id
         WHERE rt.selector = ? AND rt.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        clearRememberMeCookie();
        return false;
    }

    $validatorHash = hash('sha256', $validator);
    if (!hash_equals((string)$row['token_hash'], $validatorHash)) {
        $delete = $pdo->prepare('DELETE FROM user_remember_tokens WHERE selector = ?');
        $delete->execute([$selector]);
        clearRememberMeCookie();
        return false;
    }

    $newValidator = bin2hex(random_bytes(32));
    $newValidatorHash = hash('sha256', $newValidator);
    $expiresAtTs = time() + (rememberMeDurationDays() * 86400);
    $expiresAtSql = date('Y-m-d H:i:s', $expiresAtTs);

    $update = $pdo->prepare(
        'UPDATE user_remember_tokens
         SET token_hash = ?, expires_at = ?, last_used_at = NOW(), user_agent = ?
         WHERE token_id = ?'
    );
    $update->execute([$newValidatorHash, $expiresAtSql, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), (int)$row['token_id']]);

    setcookie(rememberMeCookieName(), $selector . ':' . $newValidator, [
        'expires' => $expiresAtTs,
        'path' => rememberMeCookiePath(),
        'secure' => rememberMeCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    applyRememberedUserSession($row);
    return true;
}

function revokeRememberMeFromCurrentDevice(PDO $pdo): void
{
    $cookie = (string)($_COOKIE[rememberMeCookieName()] ?? '');
    if ($cookie !== '' && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        if (preg_match('/^[a-f0-9]{18}$/', $selector)) {
            ensureRememberMeTokensTable($pdo);
            $delete = $pdo->prepare('DELETE FROM user_remember_tokens WHERE selector = ?');
            $delete->execute([$selector]);
        }
    }

    clearRememberMeCookie();
}

function revokeAllRememberMeTokensForUser(PDO $pdo, int $userId): void
{
    ensureRememberMeTokensTable($pdo);
    $delete = $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?');
    $delete->execute([$userId]);
    clearRememberMeCookie();
}

function rememberMeRequestedFromPost(): bool
{
    $value = strtolower(trim((string)($_POST['remember'] ?? '')));
    return in_array($value, ['1', 'true', 'on', 'yes'], true);
}
