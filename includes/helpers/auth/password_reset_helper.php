<?php

declare(strict_types=1);

require_once __DIR__ . '/remember_me_helper.php';
require_once __DIR__ . '/mail_helper.php';

function ensurePasswordResetRequestsTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_password_resets (
            reset_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector CHAR(16) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            otp_hash VARCHAR(255) NULL,
            otp_expires_at DATETIME NULL,
            otp_verified_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            request_ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_selector (selector),
            INDEX idx_user_id (user_id),
            INDEX idx_expires_at (expires_at),
            INDEX idx_used_at (used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

function passwordResetTokenTtlSeconds(): int
{
    $raw = function_exists('envValue') ? envValue('PASSWORD_RESET_TOKEN_TTL', '1800') : '1800';
    $ttl = (int)$raw;
    if ($ttl < 600) {
        $ttl = 1800;
    }
    if ($ttl > 7200) {
        $ttl = 7200;
    }
    return $ttl;
}

function passwordResetOtpTtlSeconds(): int
{
    $raw = function_exists('envValue') ? envValue('PASSWORD_RESET_OTP_TTL', '600') : '600';
    $ttl = (int)$raw;
    if ($ttl < 120) {
        $ttl = 600;
    }
    if ($ttl > 1800) {
        $ttl = 1800;
    }
    return $ttl;
}

function isPasswordResetOtpEnabled(): bool
{
    if (!function_exists('envValue')) {
        return false;
    }

    $explicit = envValue('PASSWORD_RESET_REQUIRE_OTP', 'auto');
    $normalized = strtolower(trim((string)$explicit));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return isGmailSmtpConfigured();
}

function cleanupPasswordResetRequests(PDO $pdo): void
{
    ensurePasswordResetRequestsTable($pdo);
    $pdo->exec(
        'DELETE FROM user_password_resets
         WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 3 DAY))
            OR expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
    );
}

function createPasswordResetRequest(PDO $pdo, int $userId, bool $requireOtp): array
{
    ensurePasswordResetRequestsTable($pdo);
    cleanupPasswordResetRequests($pdo);

    $selector = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $otpPlain = null;
    $otpHash = null;
    $otpExpiresAt = null;

    if ($requireOtp) {
        $otpPlain = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otpPlain, PASSWORD_DEFAULT);
        $otpExpiresAt = date('Y-m-d H:i:s', time() + passwordResetOtpTtlSeconds());
    }

    $expiresAt = date('Y-m-d H:i:s', time() + passwordResetTokenTtlSeconds());
    $requestIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $invalidate = $pdo->prepare('UPDATE user_password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    $invalidate->execute([$userId]);

    $insert = $pdo->prepare(
        'INSERT INTO user_password_resets
        (user_id, selector, token_hash, otp_hash, otp_expires_at, expires_at, request_ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $userId,
        $selector,
        $tokenHash,
        $otpHash,
        $otpExpiresAt,
        $expiresAt,
        $requestIp !== '' ? $requestIp : null,
        $userAgent !== '' ? $userAgent : null,
    ]);

    $resetId = (int)$pdo->lastInsertId();

    return [
        'reset_id' => $resetId,
        'selector' => $selector,
        'token' => $token,
        'otp' => $otpPlain,
        'expires_at' => $expiresAt,
        'otp_expires_at' => $otpExpiresAt,
    ];
}

function getPasswordResetRequest(PDO $pdo, string $selector, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    ensurePasswordResetRequestsTable($pdo);
    cleanupPasswordResetRequests($pdo);

    $stmt = $pdo->prepare(
        'SELECT r.reset_id, r.user_id, r.selector, r.token_hash, r.otp_hash, r.otp_expires_at,
                r.otp_verified_at, r.expires_at, r.used_at,
                u.email, u.full_name
         FROM user_password_resets r
         INNER JOIN users u ON u.user_id = r.user_id
         WHERE r.selector = ? AND r.used_at IS NULL AND r.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $incomingHash = hash('sha256', $token);
    if (!hash_equals((string)$row['token_hash'], $incomingHash)) {
        return null;
    }

    return $row;
}

function isPasswordResetOtpPending(array $request): bool
{
    $hasOtp = !empty($request['otp_hash']);
    if (!$hasOtp) {
        return false;
    }

    return empty($request['otp_verified_at']);
}

function verifyPasswordResetOtp(PDO $pdo, int $resetId, string $otp): bool
{
    if (!preg_match('/^\d{6}$/', $otp)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT otp_hash, otp_expires_at, otp_verified_at, used_at, expires_at
         FROM user_password_resets
         WHERE reset_id = ?
         LIMIT 1'
    );
    $stmt->execute([$resetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !empty($row['used_at'])) {
        return false;
    }

    if (empty($row['otp_hash']) || empty($row['otp_expires_at']) || strtotime((string)$row['otp_expires_at']) < time()) {
        return false;
    }

    if (!empty($row['otp_verified_at'])) {
        return true;
    }

    if (!password_verify($otp, (string)$row['otp_hash'])) {
        return false;
    }

    $update = $pdo->prepare('UPDATE user_password_resets SET otp_verified_at = NOW() WHERE reset_id = ? AND otp_verified_at IS NULL');
    $update->execute([$resetId]);

    return true;
}

function completePasswordReset(PDO $pdo, int $resetId, string $newPasswordHash): bool
{
    ensurePasswordResetRequestsTable($pdo);
    ensureRememberMeTokensTable($pdo);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT reset_id, user_id, otp_hash, otp_verified_at
             FROM user_password_resets
             WHERE reset_id = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$resetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            return false;
        }

        if (!empty($row['otp_hash']) && empty($row['otp_verified_at'])) {
            $pdo->rollBack();
            return false;
        }

        $userId = (int)$row['user_id'];

        $updatePassword = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
        $updatePassword->execute([$newPasswordHash, $userId]);

        $markUsed = $pdo->prepare('UPDATE user_password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $markUsed->execute([$userId]);

        $clearRemember = $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?');
        $clearRemember->execute([$userId]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function maskEmailForDisplay(string $email): string
{
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) {
        return 'm****@mail.com';
    }

    [$local, $domain] = explode('@', $email, 2);
    $first = substr($local, 0, 1);
    return ($first !== '' ? $first : 'm') . '****@' . $domain;
}
