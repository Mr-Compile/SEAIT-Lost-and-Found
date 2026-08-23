<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers/mfa_helper.php';

startSecureSession();
requireAuthenticatedUserWithPaths('../login.php', 'verify-mfa.php');
ensureMfaUsersCompletionColumn($pdo);

$isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

/**
 * Handles download endpoint failures for both form and AJAX requests.
 */
function failDownloadRequest(string $message, bool $isAjaxRequest): void
{
    if ($isAjaxRequest) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    $_SESSION['mfa_error'] = $message;
    header('Location: setup-mfa.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failDownloadRequest('Invalid request method.', $isAjaxRequest);
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!isValidCsrfToken('download_backup_codes', is_string($csrfToken) ? $csrfToken : null)) {
    failDownloadRequest('Invalid request token while downloading backup codes.', $isAjaxRequest);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare('SELECT email, mfa_enabled, mfa_secret FROM users WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

$isMfaEnabled = $user && (int)($user['mfa_enabled'] ?? 0) === 1 && !empty($user['mfa_secret']);
if (!$isMfaEnabled) {
    failDownloadRequest('Enable MFA first before downloading backup codes.', $isAjaxRequest);
}

$mfaSecret = normalizeMfaSecret((string)($user['mfa_secret'] ?? ''));
if ($mfaSecret === '') {
    failDownloadRequest('MFA secret is missing. Reconfigure MFA first.', $isAjaxRequest);
}

$codeRows = getBackupCodeSnapshotWithStatus($pdo, $userId, $mfaSecret);
$codes = array_map(static fn(array $row): string => (string)($row['code'] ?? ''), $codeRows);
if (count($codes) === 0) {
    failDownloadRequest('No backup codes found. Regenerate backup codes first.', $isAjaxRequest);
}

$siteName = 'FindIt';
$email = (string)($user['email'] ?? 'user');
$filenameSafeEmail = preg_replace('/[^a-zA-Z0-9._-]/', '_', $email) ?? 'user';
$filename = 'findit-mfa-backup-codes-' . $filenameSafeEmail . '.txt';

$content = [];
$content[] = $siteName . ' MFA Backup Codes';
$content[] = 'Generated: ' . date('Y-m-d H:i:s');
$content[] = 'Account: ' . $email;
$content[] = '';
$content[] = 'Important:';
$content[] = '- Each code can be used once.';
$content[] = '- Store this file securely and offline.';
$content[] = '- Do not share these codes.';
$content[] = '- [USED] means already consumed and cannot be used again.';
$content[] = '';
$content[] = 'Backup Codes:';
foreach ($codeRows as $index => $row) {
    $code = (string)($row['code'] ?? '');
    if ($code === '') {
        continue;
    }
    $status = !empty($row['used']) ? ' [USED]' : ' [UNUSED]';
    $content[] = sprintf('%d. %s%s', $index + 1, $code, $status);
}
$content[] = '';

$update = $pdo->prepare('UPDATE users SET mfa_backup_codes_downloaded_at = NOW() WHERE user_id = ?');
$update->execute([$userId]);

$_SESSION['mfa_new_backup_codes'] = $codes;

header('Content-Description: File Transfer');
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Pragma: public');

echo implode(PHP_EOL, $content);
exit;

