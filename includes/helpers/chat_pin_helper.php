<?php

declare(strict_types=1);

/**
 * Chat PIN security policy values.
 */
function getChatPinPolicy(): array
{
    $lockMinutes = 3;

    return [
        'max_attempts' => 5,
        'lock_minutes' => $lockMinutes,
    ];
}

/**
 * Creates the chat PIN table if it does not exist.
 */
function ensureChatPinTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_chat_pins (
            pin_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            pin_hash VARCHAR(255) NOT NULL,
            failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_chat_pin (user_id),
            INDEX idx_locked_until (locked_until),
            CONSTRAINT fk_user_chat_pin_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

/**
 * Normalizes a PIN to digits only.
 */
function normalizeChatPin(string $value): string
{
    return preg_replace('/\D+/', '', trim($value)) ?? '';
}

/**
 * Validates chat PIN format (exactly 6 digits).
 */
function isValidChatPinFormat(string $pin): bool
{
    return (bool)preg_match('/^\d{6}$/', $pin);
}

/**
 * Returns a normalized status payload for chat PIN state.
 */
function getChatPinStatus(PDO $pdo, int $userId): array
{
    ensureChatPinTable($pdo);

    $policy = getChatPinPolicy();
    $maxAttempts = (int)$policy['max_attempts'];
    $lockMinutes = min(3, max(1, (int)($policy['lock_minutes'] ?? 3)));

    $stmt = $pdo->prepare('SELECT pin_hash, failed_attempts, locked_until FROM user_chat_pins WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row === null) {
        return [
            'has_pin' => false,
            'locked' => false,
            'failed_attempts' => 0,
            'remaining_attempts' => $maxAttempts,
            'locked_until' => null,
            'max_attempts' => $maxAttempts,
            'lock_minutes' => $lockMinutes,
        ];
    }

    $failedAttempts = (int)($row['failed_attempts'] ?? 0);
    $lockedUntilRaw = $row['locked_until'] ?? null;
    $locked = false;

    if (is_string($lockedUntilRaw) && $lockedUntilRaw !== '') {
        $lockedUntilTime = strtotime($lockedUntilRaw);
        if ($lockedUntilTime !== false && $lockedUntilTime > time()) {
            $locked = true;
        } else {
            $clear = $pdo->prepare('UPDATE user_chat_pins SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?');
            $clear->execute([$userId]);
            $failedAttempts = 0;
            $lockedUntilRaw = null;
        }
    }

    $remainingAttempts = max(0, $maxAttempts - $failedAttempts);

    return [
        'has_pin' => true,
        'locked' => $locked,
        'failed_attempts' => $failedAttempts,
        'remaining_attempts' => $locked ? 0 : $remainingAttempts,
        'locked_until' => $locked ? $lockedUntilRaw : null,
        'max_attempts' => $maxAttempts,
        'lock_minutes' => $lockMinutes,
    ];
}

/**
 * Creates or updates a 6-digit chat PIN hash.
 */
function setChatPin(PDO $pdo, int $userId, string $pin): void
{
    ensureChatPinTable($pdo);

    if (!isValidChatPinFormat($pin)) {
        throw new InvalidArgumentException('Chat PIN must be exactly 6 digits.');
    }

    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO user_chat_pins (user_id, pin_hash, failed_attempts, locked_until)
         VALUES (?, ?, 0, NULL)
         ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash), failed_attempts = 0, locked_until = NULL'
    );
    $stmt->execute([$userId, $hash]);
}

/**
 * Deletes the saved chat PIN for account recovery/reset.
 */
function resetChatPin(PDO $pdo, int $userId): void
{
    ensureChatPinTable($pdo);

    $stmt = $pdo->prepare('DELETE FROM user_chat_pins WHERE user_id = ?');
    $stmt->execute([$userId]);

    clearChatPinVerifiedInSession($userId);
}

/**
 * Verifies a user-entered chat PIN, enforcing lockout on repeated failures.
 */
function verifyChatPin(PDO $pdo, int $userId, string $pin): array
{
    ensureChatPinTable($pdo);

    $status = getChatPinStatus($pdo, $userId);
    $policy = getChatPinPolicy();
    $maxAttempts = (int)$policy['max_attempts'];
    $lockMinutes = min(3, max(1, (int)($policy['lock_minutes'] ?? 3)));

    if (!$status['has_pin']) {
        return [
            'success' => false,
            'needs_setup' => true,
            'locked' => false,
            'remaining_attempts' => $maxAttempts,
            'locked_until' => null,
            'message' => 'Create a 6-digit chat PIN first.',
        ];
    }

    if ($status['locked']) {
        return [
            'success' => false,
            'needs_setup' => false,
            'locked' => true,
            'remaining_attempts' => 0,
            'locked_until' => $status['locked_until'],
            'message' => 'Chat PIN is temporarily locked.',
        ];
    }

    if (!isValidChatPinFormat($pin)) {
        return [
            'success' => false,
            'needs_setup' => false,
            'locked' => false,
            'remaining_attempts' => (int)$status['remaining_attempts'],
            'locked_until' => null,
            'message' => 'Enter a valid 6-digit PIN.',
        ];
    }

    $stmt = $pdo->prepare('SELECT pin_hash, failed_attempts FROM user_chat_pins WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row === null) {
        return [
            'success' => false,
            'needs_setup' => true,
            'locked' => false,
            'remaining_attempts' => $maxAttempts,
            'locked_until' => null,
            'message' => 'Create a 6-digit chat PIN first.',
        ];
    }

    $hash = (string)($row['pin_hash'] ?? '');
    $failedAttempts = (int)($row['failed_attempts'] ?? 0);

    if ($hash !== '' && password_verify($pin, $hash)) {
        $resetStmt = $pdo->prepare('UPDATE user_chat_pins SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?');
        $resetStmt->execute([$userId]);

        return [
            'success' => true,
            'needs_setup' => false,
            'locked' => false,
            'remaining_attempts' => $maxAttempts,
            'locked_until' => null,
            'message' => 'PIN verified.',
        ];
    }

    $failedAttempts++;
    if ($failedAttempts >= $maxAttempts) {
        $lockedUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
        $lockStmt = $pdo->prepare('UPDATE user_chat_pins SET failed_attempts = ?, locked_until = ? WHERE user_id = ?');
        $lockStmt->execute([$maxAttempts, $lockedUntil, $userId]);

        return [
            'success' => false,
            'needs_setup' => false,
            'locked' => true,
            'remaining_attempts' => 0,
            'locked_until' => $lockedUntil,
            'message' => 'Too many attempts. Chat PIN is locked temporarily.',
        ];
    }

    $updateStmt = $pdo->prepare('UPDATE user_chat_pins SET failed_attempts = ?, locked_until = NULL WHERE user_id = ?');
    $updateStmt->execute([$failedAttempts, $userId]);

    return [
        'success' => false,
        'needs_setup' => false,
        'locked' => false,
        'remaining_attempts' => max(0, $maxAttempts - $failedAttempts),
        'locked_until' => null,
        'message' => 'Incorrect PIN.',
    ];
}

/**
 * Marks chat PIN as verified for this user in the current session.
 */
function markChatPinVerifiedInSession(int $userId): void
{
    if (!isset($_SESSION['chat_pin_verified']) || !is_array($_SESSION['chat_pin_verified'])) {
        $_SESSION['chat_pin_verified'] = [];
    }

    $_SESSION['chat_pin_verified'][$userId] = time();
}

/**
 * Clears chat PIN verification marker for this user in current session.
 */
function clearChatPinVerifiedInSession(int $userId): void
{
    if (isset($_SESSION['chat_pin_verified'][$userId])) {
        unset($_SESSION['chat_pin_verified'][$userId]);
    }
}

/**
 * Returns whether this session already verified chat PIN for this user.
 */
function isChatPinVerifiedInSession(int $userId): bool
{
    return isset($_SESSION['chat_pin_verified'][$userId]);
}
