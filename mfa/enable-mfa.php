<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/helpers/mfa_helper.php';

startSecureSession();
loadEnvFile(__DIR__ . '/../.env');
requireAuthenticatedUserWithPaths('../login.php', 'verify-mfa.php');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: setup-mfa.php');
    exit;
}
ensureMfaUsersCompletionColumn($pdo);

$otp = preg_replace('/\D/', '', $_POST['otp'] ?? '');
$csrfToken = $_POST['csrf_token'] ?? null;
$secret = $_SESSION['mfa_setup_secret'] ?? null;
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!isValidCsrfToken('enable_mfa', is_string($csrfToken) ? $csrfToken : null)) {
    $_SESSION['mfa_error'] = 'Invalid request token. Please try again.';
    header('Location: setup-mfa.php');
    exit;
}

if (hasTooManyOtpAttempts('enable_mfa')) {
    $_SESSION['mfa_error'] = 'Too many attempts. Please wait a few minutes and try again.';
    header('Location: setup-mfa.php');
    exit;
}

if ($userId <= 0 || !is_string($secret) || $secret === '') {
    $_SESSION['mfa_error'] = 'MFA setup session expired. Please try again.';
    header('Location: setup-mfa.php');
    exit;
}

$secret = normalizeMfaSecret($secret);

if (!preg_match('/^\d{6}$/', $otp)) {
    recordOtpAttempt('enable_mfa');
    $_SESSION['mfa_error'] = 'Enter a valid 6-digit code.';
    header('Location: setup-mfa.php');
    exit;
}

$tfa = getTfaInstance();
$serverTime = time();
$serverCode = $tfa->getCode($secret, $serverTime);
mfaDebugLog('Enable MFA: user_id=' . $userId . ' otp=' . $otp . ' server_code=' . $serverCode . ' server_time=' . $serverTime);

// Allow a slightly larger time-window to reduce rejects if server clock drifts.
$isValid = $tfa->verifyCode($secret, $otp, 2, $serverTime);

if (!$isValid) {
    recordOtpAttempt('enable_mfa');
    $_SESSION['mfa_error'] = 'Invalid code. Try the newest 6-digit code and make sure your device time is set to automatic. In Microsoft Authenticator, add this as an "Other account" (TOTP), not a work/school account.';
    header('Location: setup-mfa.php');
    exit;
}

$update = $pdo->prepare('UPDATE users SET mfa_secret = ?, mfa_enabled = 1, mfa_backup_codes_downloaded_at = NULL WHERE user_id = ?');
$update->execute([$secret, $userId]);

$backupCodes = generateBackupCodes(8);
saveBackupCodes($pdo, $userId, $backupCodes);
saveBackupCodeSnapshot($pdo, $userId, $backupCodes, $secret);

unset($_SESSION['mfa_setup_secret'], $_SESSION['force_mfa_setup']);
clearOtpAttempts('enable_mfa');
$_SESSION['mfa_new_backup_codes'] = $backupCodes;
$_SESSION['mfa_success'] = 'MFA enabled successfully. Save your backup codes now.';

header('Location: setup-mfa.php?enabled=1');
exit;

