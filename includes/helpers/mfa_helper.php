<?php

declare(strict_types=1);

/**
 * Starts a hardened session once per request.
 */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Ensure a consistent timezone for OTP calculations (time() is epoch-based,
    // but misconfigured environments can still cause confusion when debugging).
    if (ini_get('date.timezone') === '' || ini_get('date.timezone') === false) {
        // Default to Philippines timezone since this project appears PH-based.
        // Override by setting date.timezone in php.ini if needed.
        @date_default_timezone_set('Asia/Manila');
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Creates a TwoFactorAuth instance for FindIt.
 */
function getTfaInstance()
{
    return new FindItTotp('FindIt');
}

/**
 * Enables MFA debug logging when APP_DEBUG_MFA=1 is set (via config/env.php).
 */
function isMfaDebugEnabled(): bool
{
    if (defined('APP_DEBUG_MFA')) {
        return (bool)APP_DEBUG_MFA;
    }

    if (function_exists('envValue')) {
        $flag = envValue('APP_DEBUG_MFA', '0');
        return (string)$flag === '1' || strtolower((string)$flag) === 'true';
    }

    $raw = $_ENV['APP_DEBUG_MFA'] ?? $_SERVER['APP_DEBUG_MFA'] ?? getenv('APP_DEBUG_MFA');
    if ($raw === false || $raw === null) {
        return false;
    }
    return (string)$raw === '1' || strtolower((string)$raw) === 'true';
}

/**
 * Writes to PHP error log when MFA debug is enabled.
 */
function mfaDebugLog(string $message): void
{
    if (!isMfaDebugEnabled()) {
        return;
    }

    error_log('[MFA] ' . $message);
}

/**
 * Normalizes a base32 MFA secret.
 *
 * Authenticator apps and users sometimes copy secrets with spaces/dashes.
 * Normalizing prevents accidental mismatches.
 */
function normalizeMfaSecret(string $secret): string
{
    $secret = strtoupper(trim($secret));
    // Remove separators/whitespace.
    $secret = preg_replace('/[^A-Z2-7]/', '', $secret) ?? '';
    return $secret;
}

/**
 * Lightweight TOTP implementation compatible with PHP 8.0.
 */
class FindItTotp
{
    private $issuer;
    private $digits;
    private $period;

    public function __construct(string $issuer = 'FindIt', int $digits = 6, int $period = 30)
    {
        $this->issuer = $issuer;
        $this->digits = $digits > 0 ? $digits : 6;
        $this->period = $period > 0 ? $period : 30;
    }

    public function createSecret(int $bits = 80): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $length = (int)ceil($bits / 5);
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }

    public function getCode(string $secret, ?int $time = null): string
    {
        $timestamp = $time ?? time();
        $counter = (int)floor($timestamp / $this->period);
        $secretKey = $this->base32Decode($secret);

        $binaryCounter = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $binary = substr($hash, $offset, 4);
        $value = unpack('N', $binary);
        $code = ((int)$value[1]) & 0x7FFFFFFF;

        return str_pad((string)($code % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    public function verifyCode(string $secret, string $code, int $discrepancy = 1, ?int $time = null): bool
    {
        if (!preg_match('/^\d{' . $this->digits . '}$/', $code)) {
            return false;
        }

        $timestamp = $time ?? time();
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $candidate = $this->getCode($secret, $timestamp + ($i * $this->period));
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    public function getQRCodeImageAsDataUri(string $label, string $secret, int $size = 200): string
    {
        $size = max(120, $size);
        $qrText = $this->getQRText($label, $secret);
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . rawurlencode($qrText);
        $rawImage = @file_get_contents($qrUrl);

        if ($rawImage !== false && $rawImage !== '') {
            return 'data:image/png;base64,' . base64_encode($rawImage);
        }

        // Fallback to direct URL if remote fetch is disabled.
        return $qrUrl;
    }

    private function getQRText(string $label, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($label)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($this->issuer)
            . '&period=' . $this->period
            . '&algorithm=SHA1'
            . '&digits=' . $this->digits;
    }

    private function base32Decode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }

        $bits = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '=') {
                continue;
            }

            $index = strpos($alphabet, $char);
            if ($index === false) {
                throw new RuntimeException('Invalid base32 secret.');
            }

            $bits .= str_pad(decbin((int)$index), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        $bytes = floor(strlen($bits) / 8);
        for ($i = 0; $i < $bytes; $i++) {
            $output .= chr(bindec(substr($bits, $i * 8, 8)));
        }

        return $output;
    }
}

/**
 * Ensures the current user is logged in.
 */
function requireAuthenticatedUser(): void
{
    if (!empty($_SESSION['mfa_pending_user']) && is_array($_SESSION['mfa_pending_user'])) {
        header('Location: verify-mfa.php?reason=pending');
        exit;
    }

    if (empty($_SESSION['user_id'])) {
        header('Location: login.php?reason=auth_required');
        exit;
    }
}

/**
 * Ensures the current user is logged in.
 */
function requireAuthenticatedUserWithPaths(string $loginPath, string $verifyPath): void
{
    if (!empty($_SESSION['mfa_pending_user']) && is_array($_SESSION['mfa_pending_user'])) {
        header('Location: ' . $verifyPath . '?reason=pending');
        exit;
    }

    if (empty($_SESSION['user_id'])) {
        header('Location: ' . $loginPath . '?reason=auth_required');
        exit;
    }
}

/**
 * Ensures there is an active MFA login challenge.
 */
function requirePendingMfaChallenge(): void
{
    if (empty($_SESSION['mfa_pending_user']) || !is_array($_SESSION['mfa_pending_user'])) {
        if (!empty($_SESSION['user_id'])) {
            $role = (string)($_SESSION['role'] ?? 'claimant');
            $dashboard = ($role === 'admin') ? 'admin/admin_dashboard.php' : 'claimant/claimant_dashboard.php';
            header('Location: ' . $dashboard);
            exit;
        }

        header('Location: login.php?reason=mfa_required');
        exit;
    }

    $pending = $_SESSION['mfa_pending_user'];
    $expiresAt = (int)($pending['expires_at'] ?? 0);
    if ($expiresAt < time()) {
        unset($_SESSION['mfa_pending_user']);
        header('Location: login.php?reason=mfa_expired');
        exit;
    }
}

/**
 * Ensures there is an active MFA login challenge.
 */
function requirePendingMfaChallengeWithPaths(string $loginPath, string $adminDashboardPath, string $claimantDashboardPath): void
{
    if (empty($_SESSION['mfa_pending_user']) || !is_array($_SESSION['mfa_pending_user'])) {
        if (!empty($_SESSION['user_id'])) {
            $role = (string)($_SESSION['role'] ?? 'claimant');
            $dashboard = ($role === 'admin') ? $adminDashboardPath : $claimantDashboardPath;
            header('Location: ' . $dashboard);
            exit;
        }

        header('Location: ' . $loginPath . '?reason=mfa_required');
        exit;
    }

    $pending = $_SESSION['mfa_pending_user'];
    $expiresAt = (int)($pending['expires_at'] ?? 0);
    if ($expiresAt < time()) {
        unset($_SESSION['mfa_pending_user']);
        header('Location: ' . $loginPath . '?reason=mfa_expired');
        exit;
    }
}

/**
 * Creates MFA backup code storage table if not present.
 */
function ensureMfaBackupCodesTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_mfa_backup_codes (
            backup_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_used_at (used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

/**
 * Stores encrypted (display-only) backup code snapshots for consistent UX across sessions.
 */
function ensureMfaBackupCodeSnapshotsTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_mfa_backup_code_snapshots (
            snapshot_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            display_order TINYINT UNSIGNED NOT NULL,
            encrypted_code TEXT NOT NULL,
            code_fingerprint CHAR(64) NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_order (user_id, display_order),
            INDEX idx_snapshot_user_id (user_id),
            INDEX idx_snapshot_used_at (used_at),
            INDEX idx_snapshot_fingerprint (user_id, code_fingerprint)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    $columnsStmt = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_mfa_backup_code_snapshots'"
    );
    $columns = [];
    foreach (($columnsStmt ? $columnsStmt->fetchAll() : []) as $row) {
        $name = (string)($row['COLUMN_NAME'] ?? '');
        if ($name !== '') {
            $columns[$name] = true;
        }
    }

    if (!isset($columns['code_fingerprint'])) {
        $pdo->exec('ALTER TABLE user_mfa_backup_code_snapshots ADD COLUMN code_fingerprint CHAR(64) NULL AFTER encrypted_code');
    }

    if (!isset($columns['used_at'])) {
        $pdo->exec('ALTER TABLE user_mfa_backup_code_snapshots ADD COLUMN used_at DATETIME NULL AFTER code_fingerprint');
    }

    try {
        $pdo->exec('CREATE INDEX idx_snapshot_used_at ON user_mfa_backup_code_snapshots (used_at)');
    } catch (Throwable $exception) {
    }

    try {
        $pdo->exec('CREATE INDEX idx_snapshot_fingerprint ON user_mfa_backup_code_snapshots (user_id, code_fingerprint)');
    } catch (Throwable $exception) {
    }
}

/**
 * Ensures strict MFA completion column exists on users table.
 */
function ensureMfaUsersCompletionColumn(PDO $pdo): void
{
    $check = $pdo->query(
        "SELECT COUNT(*) AS column_exists
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'mfa_backup_codes_downloaded_at'"
    );
    $row = $check->fetch();
    $exists = (int)($row['column_exists'] ?? 0) > 0;

    if (!$exists) {
        $pdo->exec('ALTER TABLE users ADD COLUMN mfa_backup_codes_downloaded_at DATETIME NULL AFTER mfa_enabled');
    }
}

/**
 * Generates user-facing backup recovery codes.
 *
 * @return array<int, string>
 */
function generateBackupCodes(int $count = 8): array
{
    $codes = [];

    for ($index = 0; $index < $count; $index++) {
        $left = strtoupper(bin2hex(random_bytes(2)));
        $right = strtoupper(bin2hex(random_bytes(2)));
        $codes[] = $left . '-' . $right;
    }

    return $codes;
}

/**
 * Persists backup codes as password hashes.
 *
 * @param array<int, string> $codes
 */
function saveBackupCodes(PDO $pdo, int $userId, array $codes): void
{
    ensureMfaBackupCodesTable($pdo);

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM user_mfa_backup_codes WHERE user_id = ?');
        $delete->execute([$userId]);

        $insert = $pdo->prepare('INSERT INTO user_mfa_backup_codes (user_id, code_hash) VALUES (?, ?)');
        foreach ($codes as $code) {
            $insert->execute([$userId, password_hash(normalizeBackupCode($code), PASSWORD_DEFAULT)]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Persists a recoverable encrypted copy of generated backup codes for display/download.
 *
 * @param array<int, string> $codes
 */
function saveBackupCodeSnapshot(PDO $pdo, int $userId, array $codes, string $mfaSecret): void
{
    ensureMfaBackupCodeSnapshotsTable($pdo);
    $key = deriveMfaBackupCodeSnapshotKey($userId, $mfaSecret);

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM user_mfa_backup_code_snapshots WHERE user_id = ?');
        $delete->execute([$userId]);

        $insert = $pdo->prepare(
            'INSERT INTO user_mfa_backup_code_snapshots (user_id, display_order, encrypted_code, code_fingerprint, used_at)
             VALUES (?, ?, ?, ?, NULL)'
        );

        foreach (array_values($codes) as $index => $code) {
            $displayCode = strtoupper(trim((string)$code));
            if ($displayCode === '') {
                continue;
            }

            $normalizedCode = normalizeBackupCode($displayCode);
            if ($normalizedCode === '') {
                continue;
            }

            $insert->execute([
                $userId,
                $index + 1,
                encryptMfaBackupCodeSnapshot($displayCode, $key),
                hash('sha256', $normalizedCode)
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Returns decrypted backup code snapshots for display/download.
 *
 * @return array<int, string>
 */
function getBackupCodeSnapshot(PDO $pdo, int $userId, string $mfaSecret): array
{
    ensureMfaBackupCodeSnapshotsTable($pdo);
    $key = deriveMfaBackupCodeSnapshotKey($userId, $mfaSecret);

    $stmt = $pdo->prepare(
        'SELECT encrypted_code
         FROM user_mfa_backup_code_snapshots
         WHERE user_id = ?
         ORDER BY display_order ASC'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $codes = [];
    foreach ($rows as $row) {
        $encrypted = (string)($row['encrypted_code'] ?? '');
        if ($encrypted === '') {
            continue;
        }

        $decrypted = decryptMfaBackupCodeSnapshot($encrypted, $key);
        if ($decrypted !== '') {
            $codes[] = $decrypted;
        }
    }

    return $codes;
}

/**
 * Returns decrypted backup code snapshots with one-time usage status.
 *
 * @return array<int, array{code:string, used:bool}>
 */
function getBackupCodeSnapshotWithStatus(PDO $pdo, int $userId, string $mfaSecret): array
{
    ensureMfaBackupCodeSnapshotsTable($pdo);
    $key = deriveMfaBackupCodeSnapshotKey($userId, $mfaSecret);

    $stmt = $pdo->prepare(
        'SELECT encrypted_code, used_at
         FROM user_mfa_backup_code_snapshots
         WHERE user_id = ?
         ORDER BY display_order ASC'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $codes = [];
    foreach ($rows as $row) {
        $encrypted = (string)($row['encrypted_code'] ?? '');
        if ($encrypted === '') {
            continue;
        }

        $decrypted = decryptMfaBackupCodeSnapshot($encrypted, $key);
        if ($decrypted !== '') {
            $codes[] = [
                'code' => $decrypted,
                'used' => !empty($row['used_at']),
            ];
        }
    }

    return $codes;
}

/**
 * Removes any persisted backup code snapshot for the given user.
 */
function clearBackupCodeSnapshot(PDO $pdo, int $userId): void
{
    ensureMfaBackupCodeSnapshotsTable($pdo);
    $delete = $pdo->prepare('DELETE FROM user_mfa_backup_code_snapshots WHERE user_id = ?');
    $delete->execute([$userId]);
}

/**
 * Masks a backup code for UI display while still allowing code identification.
 */
function maskBackupCode(string $code): string
{
    $normalized = normalizeBackupCode($code);
    if ($normalized === '') {
        return '****-****';
    }

    $length = strlen($normalized);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    $masked = substr($normalized, 0, 2)
        . str_repeat('*', max(1, $length - 4))
        . substr($normalized, -2);

    if ($length === 8) {
        return substr($masked, 0, 4) . '-' . substr($masked, 4);
    }

    return $masked;
}

/**
 * Derives a stable symmetric key for MFA backup code snapshot encryption.
 */
function deriveMfaBackupCodeSnapshotKey(int $userId, string $mfaSecret): string
{
    $normalizedSecret = normalizeMfaSecret($mfaSecret);
    if ($normalizedSecret === '') {
        throw new RuntimeException('Cannot derive backup code snapshot key without MFA secret.');
    }

    return hash('sha256', 'findit-mfa-backup-codes|' . $userId . '|' . $normalizedSecret, true);
}

/**
 * Encrypts one backup code using OpenSSL AES-256-GCM.
 */
function encryptMfaBackupCodeSnapshot(string $plaintext, string $key): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required for MFA backup code snapshot encryption.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException('Failed to encrypt MFA backup code snapshot.');
    }

    return 'v1:' . base64_encode($iv) . ':' . base64_encode($ciphertext) . ':' . base64_encode($tag);
}

/**
 * Decrypts one backup code encrypted by encryptMfaBackupCodeSnapshot().
 */
function decryptMfaBackupCodeSnapshot(string $storedValue, string $key): string
{
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required for MFA backup code snapshot decryption.');
    }

    if (!str_starts_with($storedValue, 'v1:')) {
        return '';
    }

    $parts = explode(':', $storedValue, 4);
    if (count($parts) !== 4) {
        return '';
    }

    [, $ivB64, $cipherB64, $tagB64] = $parts;
    $iv = base64_decode($ivB64, true);
    $ciphertext = base64_decode($cipherB64, true);
    $tag = base64_decode($tagB64, true);
    if ($iv === false || $ciphertext === false || $tag === false) {
        return '';
    }

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        ''
    );

    return $plaintext === false ? '' : strtoupper(trim($plaintext));
}

/**
 * Attempts one-time backup code usage.
 */
function consumeBackupCode(PDO $pdo, int $userId, string $backupCode): bool
{
    ensureMfaBackupCodesTable($pdo);

    $normalized = normalizeBackupCode($backupCode);
    if ($normalized === '' || strlen($normalized) < 6) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT backup_id, code_hash FROM user_mfa_backup_codes WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $hash = (string)($row['code_hash'] ?? '');
        if ($hash !== '' && password_verify($normalized, $hash)) {
            $update = $pdo->prepare('UPDATE user_mfa_backup_codes SET used_at = NOW() WHERE backup_id = ? AND used_at IS NULL');
            $update->execute([(int)$row['backup_id']]);
            $didConsume = $update->rowCount() > 0;
            if ($didConsume) {
                markBackupCodeSnapshotAsUsed($pdo, $userId, $normalized);
            }

            return $didConsume;
        }
    }

    return false;
}

/**
 * Checks whether a backup code is valid format but already consumed.
 */
function isBackupCodeAlreadyUsed(PDO $pdo, int $userId, string $backupCode): bool
{
    ensureMfaBackupCodesTable($pdo);

    $normalized = normalizeBackupCode($backupCode);
    if ($normalized === '' || strlen($normalized) < 6) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT code_hash FROM user_mfa_backup_codes WHERE user_id = ? AND used_at IS NOT NULL');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $hash = (string)($row['code_hash'] ?? '');
        if ($hash !== '' && password_verify($normalized, $hash)) {
            return true;
        }
    }

    return false;
}

/**
 * Marks encrypted snapshot code as used, based on deterministic fingerprint.
 */
function markBackupCodeSnapshotAsUsed(PDO $pdo, int $userId, string $normalizedBackupCode): void
{
    ensureMfaBackupCodeSnapshotsTable($pdo);

    $fingerprint = hash('sha256', $normalizedBackupCode);
    $stmt = $pdo->prepare(
        'UPDATE user_mfa_backup_code_snapshots
         SET used_at = NOW()
         WHERE user_id = ?
           AND code_fingerprint = ?
           AND used_at IS NULL'
    );
    $stmt->execute([$userId, $fingerprint]);
}

/**
 * Returns remaining unused backup code count.
 */
function getRemainingBackupCodeCount(PDO $pdo, int $userId): int
{
    ensureMfaBackupCodesTable($pdo);

    $stmt = $pdo->prepare('SELECT COUNT(*) AS remaining FROM user_mfa_backup_codes WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return (int)($row['remaining'] ?? 0);
}

/**
 * Normalizes user-entered backup code for verification.
 */
function normalizeBackupCode(string $backupCode): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $backupCode) ?? '');
}

/**
 * Returns a CSRF token for a given form scope.
 */
function getCsrfToken(string $scope): string
{
    if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }

    if (empty($_SESSION['csrf_tokens'][$scope]) || !is_string($_SESSION['csrf_tokens'][$scope])) {
        $_SESSION['csrf_tokens'][$scope] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_tokens'][$scope];
}

/**
 * Validates a CSRF token for a form scope.
 */
function isValidCsrfToken(string $scope, ?string $token): bool
{
    $stored = $_SESSION['csrf_tokens'][$scope] ?? '';
    if (!is_string($stored) || $stored === '' || !is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($stored, $token);
}

/**
 * Simple session-backed OTP attempt throttle.
 */
function hasTooManyOtpAttempts(string $scope, int $maxAttempts = 5, int $windowSeconds = 180): bool
{
    if (!isset($_SESSION['otp_attempts']) || !is_array($_SESSION['otp_attempts'])) {
        $_SESSION['otp_attempts'] = [];
    }

    $record = $_SESSION['otp_attempts'][$scope] ?? ['count' => 0, 'first_attempt_at' => time()];
    $firstAttemptAt = (int)($record['first_attempt_at'] ?? time());

    // Reset the counter after the sliding window expires.
    if ((time() - $firstAttemptAt) > $windowSeconds) {
        $_SESSION['otp_attempts'][$scope] = ['count' => 0, 'first_attempt_at' => time()];
        return false;
    }

    return (int)($record['count'] ?? 0) >= $maxAttempts;
}

/**
 * Increments OTP attempts for throttling.
 */
function recordOtpAttempt(string $scope): void
{
    if (!isset($_SESSION['otp_attempts']) || !is_array($_SESSION['otp_attempts'])) {
        $_SESSION['otp_attempts'] = [];
    }

    if (!isset($_SESSION['otp_attempts'][$scope]) || !is_array($_SESSION['otp_attempts'][$scope])) {
        $_SESSION['otp_attempts'][$scope] = ['count' => 0, 'first_attempt_at' => time()];
    }

    $_SESSION['otp_attempts'][$scope]['count'] = (int)($_SESSION['otp_attempts'][$scope]['count'] ?? 0) + 1;
}

/**
 * Clears OTP attempt counters for a scope.
 */
function clearOtpAttempts(string $scope): void
{
    if (isset($_SESSION['otp_attempts'][$scope])) {
        unset($_SESSION['otp_attempts'][$scope]);
    }
}
