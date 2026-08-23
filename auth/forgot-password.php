<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers/settings_helper.php';
require_once __DIR__ . '/../includes/helpers/mfa_helper.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/helpers/auth/mail_helper.php';
require_once __DIR__ . '/../includes/helpers/auth/password_reset_helper.php';

startSecureSession();
loadEnvFile(__DIR__ . '/../.env');

$settings = getSystemSettings();
ensurePasswordResetRequestsTable($pdo);

function resetFlowKey(): string
{
    return 'password_reset_flow';
}

function getResetFlow(): ?array
{
    $flow = $_SESSION[resetFlowKey()] ?? null;
    return is_array($flow) ? $flow : null;
}

function saveResetFlow(array $flow): void
{
    $_SESSION[resetFlowKey()] = $flow;
}

function clearResetFlow(): void
{
    unset($_SESSION[resetFlowKey()]);
}

function resetVerifiedKey(): string
{
    return 'password_reset_verified';
}

function setVerifiedResetSession(int $resetId, int $userId, int $expiresAtEpoch): void
{
    $_SESSION[resetVerifiedKey()] = [
        'reset_id' => $resetId,
        'user_id' => $userId,
        'verified_at' => time(),
        'expires_at' => $expiresAtEpoch,
    ];
}

function getVerifiedResetSession(): ?array
{
    $value = $_SESSION[resetVerifiedKey()] ?? null;
    return is_array($value) ? $value : null;
}

function clearVerifiedResetSession(): void
{
    unset($_SESSION[resetVerifiedKey()]);
}

function flowFromResetRequest(array $request, bool $verified = false, int $otpSentAt = 0): array
{
    $flowToken = (string)($request['plain_token'] ?? '');

    return [
        'reset_id' => (int)$request['reset_id'],
        'user_id' => (int)$request['user_id'],
        'masked_email' => maskEmailForDisplay((string)$request['email']),
        'selector' => (string)$request['selector'],
        'token' => $flowToken,
        'otp_sent_at' => $otpSentAt > 0 ? $otpSentAt : time(),
        'expires_at' => strtotime((string)$request['expires_at']) ?: (time() + passwordResetTokenTtlSeconds()),
        'verified' => $verified,
    ];
}

function getPasswordResetRequestById(PDO $pdo, int $resetId): ?array
{
    if ($resetId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT r.reset_id, r.user_id, r.selector, r.otp_hash, r.otp_expires_at,
                r.otp_verified_at, r.expires_at, r.used_at,
                u.email, u.full_name
         FROM user_password_resets r
         INNER JOIN users u ON u.user_id = r.user_id
         WHERE r.reset_id = ? AND r.used_at IS NULL AND r.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$resetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function getPasswordResetRequestByIdAnyExpiry(PDO $pdo, int $resetId): ?array
{
    if ($resetId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT r.reset_id, r.user_id, r.selector, r.otp_hash, r.otp_expires_at,
                r.otp_verified_at, r.expires_at, r.used_at,
                u.email, u.full_name
         FROM user_password_resets r
         INNER JOIN users u ON u.user_id = r.user_id
         WHERE r.reset_id = ? AND r.used_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$resetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function getLatestActiveResetByUser(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT r.reset_id, r.user_id, r.selector, r.otp_hash, r.otp_expires_at,
                r.otp_verified_at, r.expires_at, r.used_at,
                u.email, u.full_name
         FROM user_password_resets r
         INNER JOIN users u ON u.user_id = r.user_id
            WHERE r.user_id = ? AND r.used_at IS NULL
         ORDER BY r.reset_id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function getLatestVerifiedResetByUser(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT r.reset_id, r.user_id, r.selector, r.otp_hash, r.otp_expires_at,
                r.otp_verified_at, r.expires_at, r.used_at,
                u.email, u.full_name
         FROM user_password_resets r
         INNER JOIN users u ON u.user_id = r.user_id
         WHERE r.user_id = ? AND r.used_at IS NULL AND r.otp_verified_at IS NOT NULL
         ORDER BY r.reset_id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function completePasswordResetFromVerifiedSession(PDO $pdo, int $userId, string $newPasswordHash): bool
{
    if ($userId <= 0 || $newPasswordHash === '') {
        return false;
    }

    ensureRememberMeTokensTable($pdo);

    $pdo->beginTransaction();
    try {
        $verifyUser = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1 FOR UPDATE');
        $verifyUser->execute([$userId]);
        $userRow = $verifyUser->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            $pdo->rollBack();
            return false;
        }

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

function isResetOtpRecentlyVerified(PDO $pdo, int $resetId, int $maxAgeSeconds = 900): bool
{
    if ($resetId <= 0 || $maxAgeSeconds <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT reset_id
         FROM user_password_resets
         WHERE reset_id = ?
           AND used_at IS NULL
           AND otp_verified_at IS NOT NULL
           AND TIMESTAMPDIFF(SECOND, otp_verified_at, NOW()) <= ?
         LIMIT 1'
    );
    $stmt->execute([$resetId, $maxAgeSeconds]);

    return (bool)$stmt->fetchColumn();
}

function resetOtpScope(int $resetId): string
{
    return 'password_reset_verify_' . $resetId;
}

function resendOtpScope(int $userId): string
{
    return 'password_reset_resend_' . $userId;
}

function getOtpAttemptCount(string $scope): int
{
    $record = $_SESSION['otp_attempts'][$scope] ?? null;
    if (!is_array($record)) {
        return 0;
    }
    return (int)($record['count'] ?? 0);
}

function isGmailAddress(string $email): bool
{
    return preg_match('/^[a-z0-9._%+\-]+@gmail\.com$/i', $email) === 1;
}

function sendResetOtpEmail(array $user, string $otpCode, array $settings): array
{
    $siteName = htmlspecialchars(explode(' -', $settings['site_name'] ?? 'FindIt')[0], ENT_QUOTES, 'UTF-8');
    $otpMinutes = (int)floor(passwordResetOtpTtlSeconds() / 60);

    $subject = 'Your ' . $siteName . ' password reset code';
    $htmlBody = '
        <div style="font-family:Manrope,Segoe UI,Arial,sans-serif;background:#0d1117;color:#f3f4f6;padding:24px;border-radius:14px;border:1px solid #2a3140;max-width:560px;">
            <h2 style="margin:0 0 10px;font-size:22px;">Password Reset OTP</h2>
            <p style="margin:0 0 12px;font-size:14px;color:#c7d0dd;">Hello ' . htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') . ',</p>
            <p style="margin:0 0 10px;font-size:14px;color:#dbe4f0;">Use this code to reset your password:</p>
            <p style="margin:0 0 14px;">
                <span style="display:inline-block;font-size:30px;letter-spacing:0.16em;font-weight:800;color:#fff;background:#111827;border:1px solid #374151;border-radius:10px;padding:10px 14px;">' . htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8') . '</span>
            </p>
            <p style="margin:0 0 10px;font-size:13px;color:#cdd6e3;">This code expires in ' . $otpMinutes . ' minute(s).</p>
            <p style="margin:12px 0 0;font-size:12px;color:#98a4b8;">If you did not request this, ignore this email.</p>
        </div>';

    $plainBody = "Password Reset OTP\n\n"
        . "Hello " . (string)$user['full_name'] . ",\n"
        . "Use this code to reset your password: " . $otpCode . "\n"
        . "This code expires in " . $otpMinutes . " minute(s).\n"
        . "If you did not request this, ignore this email.\n";

    return sendAppEmail((string)$user['email'], (string)$user['full_name'], $subject, $htmlBody, $plainBody);
}

$csrfToken = getCsrfToken('forgot_password_request');
$flashError = '';
$flow = getResetFlow();

if (is_array($flow) && (int)($flow['expires_at'] ?? 0) < time()) {
    clearResetFlow();
    $flow = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    $action = (string)($_POST['action'] ?? '');
    $requestCsrf = $_POST['csrf_token'] ?? null;

    if (!isValidCsrfToken('forgot_password_request', is_string($requestCsrf) ? $requestCsrf : null)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request token. Refresh and try again.']);
        exit;
    }

    if ($action === 'send_otp') {
        if (hasTooManyOtpAttempts('forgot_password_request_submit', 8, 900)) {
            echo json_encode(['success' => false, 'message' => 'Too many reset requests. Please wait a few minutes.']);
            exit;
        }

        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        if (!isGmailAddress($email)) {
            echo json_encode(['success' => false, 'message' => 'Only Gmail accounts (@gmail.com) are allowed for password reset.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT user_id, full_name, email FROM users WHERE LOWER(email) = ? AND LOWER(email) LIKE ? LIMIT 1');
        $stmt->execute([$email, '%@gmail.com']);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'No account found for this email address.']);
            exit;
        }

        $resetData = createPasswordResetRequest($pdo, (int)$user['user_id'], true);
        if (empty($resetData['otp']) || empty($resetData['reset_id'])) {
            echo json_encode(['success' => false, 'message' => 'Unable to start reset flow. Please try again.']);
            exit;
        }

        $mailResult = sendResetOtpEmail($user, (string)$resetData['otp'], $settings);
        if (empty($mailResult['success'])) {
            error_log('OTP email send failure for user ' . (int)$user['user_id'] . ': ' . (string)($mailResult['error'] ?? 'unknown'));
            echo json_encode(['success' => false, 'message' => 'Unable to send OTP email right now. Please try again.']);
            exit;
        }

        $newFlow = [
            'reset_id' => (int)$resetData['reset_id'],
            'user_id' => (int)$user['user_id'],
            'masked_email' => maskEmailForDisplay((string)$user['email']),
            'selector' => (string)$resetData['selector'],
            'token' => (string)$resetData['token'],
            'otp_sent_at' => time(),
            'expires_at' => strtotime((string)$resetData['expires_at']) ?: (time() + passwordResetTokenTtlSeconds()),
            'verified' => false,
        ];
        saveResetFlow($newFlow);
        clearVerifiedResetSession();

        clearOtpAttempts('forgot_password_request_submit');
        clearOtpAttempts(resetOtpScope((int)$resetData['reset_id']));

        echo json_encode([
            'success' => true,
            'message' => 'OTP sent to your Gmail. Check your inbox and enter it below.',
            'masked_email' => $newFlow['masked_email'],
            'cooldown' => 60,
            'show_modal' => true,
            'reset_id' => (int)$newFlow['reset_id'],
            'selector' => $newFlow['selector'],
            'token' => $newFlow['token'],
        ]);
        exit;
    }

    if ($action === 'close_modal') {
        $flow = getResetFlow();
        if (is_array($flow)) {
            $flowResetId = (int)($flow['reset_id'] ?? 0);
            if ($flowResetId > 0) {
                clearOtpAttempts(resetOtpScope($flowResetId));
            }
        }

        clearResetFlow();
        clearVerifiedResetSession();

        echo json_encode(['success' => true, 'message' => 'Reset flow cleared.']);
        exit;
    }

    if ($action === 'verify_otp') {
        $postedResetId = (int)($_POST['reset_id'] ?? 0);
        $postedSelector = strtolower(trim((string)($_POST['selector'] ?? '')));
        $postedToken = strtolower(trim((string)($_POST['token'] ?? '')));

        $request = null;
        if ($postedResetId > 0) {
            $request = getPasswordResetRequestByIdAnyExpiry($pdo, $postedResetId);
        }

        if ($postedSelector !== '' && $postedToken !== '') {
            $request = $request ?: getPasswordResetRequest($pdo, $postedSelector, $postedToken);
            if (is_array($request)) {
                $request['plain_token'] = $postedToken;
            }
        }

        $flow = getResetFlow();
        if (is_array($flow) && is_array($request)) {
            $flowUserId = (int)($flow['user_id'] ?? 0);
            if ($flowUserId > 0 && $flowUserId !== (int)$request['user_id']) {
                $request = null;
            }
        }

        if (!is_array($request)) {
            if (is_array($flow)) {
                $flowResetId = (int)($flow['reset_id'] ?? 0);
                $flowUserId = (int)($flow['user_id'] ?? 0);
                $flowSelector = strtolower(trim((string)($flow['selector'] ?? '')));
                $flowToken = strtolower(trim((string)($flow['token'] ?? '')));

                if ($flowResetId > 0) {
                    $request = getPasswordResetRequestByIdAnyExpiry($pdo, $flowResetId);
                }

                if (!is_array($request) && $flowUserId > 0) {
                    $request = getLatestActiveResetByUser($pdo, $flowUserId);
                }

                if ($flowSelector !== '' && $flowToken !== '') {
                    $request = $request ?: getPasswordResetRequest($pdo, $flowSelector, $flowToken);
                    if (is_array($request)) {
                        $request['plain_token'] = $flowToken;
                    }
                }
            }
        }

        if (!is_array($request)) {
            echo json_encode(['success' => false, 'message' => 'Reset session expired. Request a new OTP.']);
            exit;
        }

        $resetId = (int)($request['reset_id'] ?? 0);
        $scope = resetOtpScope($resetId);
        $maxAttempts = 5;

        if (hasTooManyOtpAttempts($scope, $maxAttempts, passwordResetOtpTtlSeconds())) {
            echo json_encode([
                'success' => false,
                'locked' => true,
                'message' => 'Too many invalid OTP attempts. Request a new OTP.',
            ]);
            exit;
        }

        $otp = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
        if (!preg_match('/^\d{6}$/', $otp)) {
            echo json_encode(['success' => false, 'message' => 'OTP must be 6 digits.']);
            exit;
        }

        if (!verifyPasswordResetOtp($pdo, $resetId, $otp)) {
            recordOtpAttempt($scope);
            $attempts = getOtpAttemptCount($scope);
            $remaining = max(0, $maxAttempts - $attempts);

            echo json_encode([
                'success' => false,
                'message' => $remaining > 0 ? ('Incorrect OTP. Remaining attempts: ' . $remaining . '.') : 'Too many invalid attempts. Request a new OTP.',
                'remaining_attempts' => $remaining,
                'locked' => $remaining <= 0,
            ]);
            exit;
        }

        $existingFlow = getResetFlow();
        $otpSentAt = is_array($existingFlow) ? (int)($existingFlow['otp_sent_at'] ?? time()) : time();
        if (!isset($request['plain_token']) && is_array($existingFlow)) {
            $request['plain_token'] = (string)($existingFlow['token'] ?? '');
        }
        $verifiedFlow = flowFromResetRequest($request, true, $otpSentAt);
        saveResetFlow($verifiedFlow);
        $verifiedExpiresAt = strtotime((string)($request['expires_at'] ?? '')) ?: (time() + 900);
        setVerifiedResetSession((int)$verifiedFlow['reset_id'], (int)$verifiedFlow['user_id'], $verifiedExpiresAt);
        clearOtpAttempts($scope);

        echo json_encode([
            'success' => true,
            'message' => 'OTP verified. Set your new password.',
            'verified' => true,
            'reset_id' => (int)$verifiedFlow['reset_id'],
            'selector' => (string)$verifiedFlow['selector'],
            'token' => (string)$verifiedFlow['token'],
        ]);
        exit;
    }

    if ($action === 'resend_otp') {
        $postedResetId = (int)($_POST['reset_id'] ?? 0);
        $postedSelector = strtolower(trim((string)($_POST['selector'] ?? '')));
        $postedToken = strtolower(trim((string)($_POST['token'] ?? '')));

        $request = null;
        $lastSent = 0;

        if ($postedResetId > 0) {
            $request = getPasswordResetRequestByIdAnyExpiry($pdo, $postedResetId);
        }

        if ($postedSelector !== '' && $postedToken !== '') {
            $request = $request ?: getPasswordResetRequest($pdo, $postedSelector, $postedToken);
            if (is_array($request)) {
                $request['plain_token'] = $postedToken;
            }
        }

        $flow = getResetFlow();
        if (is_array($flow) && is_array($request)) {
            $flowUserId = (int)($flow['user_id'] ?? 0);
            if ($flowUserId > 0 && $flowUserId !== (int)$request['user_id']) {
                $request = null;
            }
        }

        if (!is_array($request)) {
            if (is_array($flow)) {
                $flowResetId = (int)($flow['reset_id'] ?? 0);
                $flowUserId = (int)($flow['user_id'] ?? 0);
                $flowSelector = strtolower(trim((string)($flow['selector'] ?? '')));
                $flowToken = strtolower(trim((string)($flow['token'] ?? '')));
                $lastSent = (int)($flow['otp_sent_at'] ?? 0);

                if ($flowResetId > 0) {
                    $request = getPasswordResetRequestByIdAnyExpiry($pdo, $flowResetId);
                }

                if (!is_array($request) && $flowUserId > 0) {
                    $request = getLatestActiveResetByUser($pdo, $flowUserId);
                }

                if ($flowSelector !== '' && $flowToken !== '') {
                    $request = $request ?: getPasswordResetRequest($pdo, $flowSelector, $flowToken);
                    if (is_array($request)) {
                        $request['plain_token'] = $flowToken;
                    }
                }
            }
        }

        if (!is_array($request)) {
            echo json_encode(['success' => false, 'message' => 'Reset session expired. Start again.']);
            exit;
        }

        $now = time();
        if ($lastSent <= 0) {
            $lastSent = $now - 60;
        }

        $elapsed = $now - $lastSent;
        $wait = 60 - $elapsed;
        if ($wait > 0) {
            echo json_encode(['success' => false, 'message' => 'Please wait before resending OTP.', 'retry_after' => $wait]);
            exit;
        }

        $userId = (int)($request['user_id'] ?? 0);
        if ($userId <= 0) {
            clearResetFlow();
            echo json_encode(['success' => false, 'message' => 'Reset session expired. Start again.']);
            exit;
        }

        $resendScope = resendOtpScope($userId);
        if (hasTooManyOtpAttempts($resendScope, 5, 1800)) {
            echo json_encode(['success' => false, 'locked' => true, 'message' => 'Too many OTP resend requests. Please wait 30 minutes.']);
            exit;
        }

        $userStmt = $pdo->prepare('SELECT user_id, full_name, email FROM users WHERE user_id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            clearResetFlow();
            echo json_encode(['success' => false, 'message' => 'Account unavailable for reset.']);
            exit;
        }

        $oldResetId = (int)($request['reset_id'] ?? 0);
        $resetData = createPasswordResetRequest($pdo, $userId, true);
        if (empty($resetData['otp']) || empty($resetData['reset_id'])) {
            echo json_encode(['success' => false, 'message' => 'Unable to resend OTP. Please try again.']);
            exit;
        }

        $mailResult = sendResetOtpEmail($user, (string)$resetData['otp'], $settings);
        if (empty($mailResult['success'])) {
            error_log('OTP resend failure for user ' . $userId . ': ' . (string)($mailResult['error'] ?? 'unknown'));
            echo json_encode(['success' => false, 'message' => 'Unable to resend OTP right now.']);
            exit;
        }

        $newFlow = [
            'reset_id' => (int)$resetData['reset_id'],
            'user_id' => $userId,
            'masked_email' => maskEmailForDisplay((string)$user['email']),
            'selector' => (string)$resetData['selector'],
            'token' => (string)$resetData['token'],
            'otp_sent_at' => $now,
            'expires_at' => strtotime((string)$resetData['expires_at']) ?: ($now + passwordResetTokenTtlSeconds()),
            'verified' => false,
        ];
        saveResetFlow($newFlow);
        clearVerifiedResetSession();

        if ($oldResetId > 0) {
            clearOtpAttempts(resetOtpScope($oldResetId));
        }
        clearOtpAttempts(resetOtpScope((int)$resetData['reset_id']));
        recordOtpAttempt($resendScope);

        echo json_encode([
            'success' => true,
            'message' => 'A new OTP has been sent.',
            'cooldown' => 60,
            'masked_email' => $newFlow['masked_email'],
            'reset_id' => (int)$newFlow['reset_id'],
            'selector' => $newFlow['selector'],
            'token' => $newFlow['token'],
        ]);
        exit;
    }

    if ($action === 'reset_password') {
        $verifiedSession = getVerifiedResetSession();
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($newPassword === '' || $confirmPassword === '') {
            echo json_encode(['success' => false, 'message' => 'Please fill in both password fields.']);
            exit;
        }

        if (!hash_equals($newPassword, $confirmPassword)) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit;
        }

        if (is_array($verifiedSession)) {
            $verifiedResetId = (int)($verifiedSession['reset_id'] ?? 0);
            $verifiedUserId = (int)($verifiedSession['user_id'] ?? 0);
            $verifiedAt = (int)($verifiedSession['verified_at'] ?? 0);

            if ($verifiedUserId > 0 && $verifiedAt > 0 && (time() - $verifiedAt) <= 900) {
                $done = completePasswordResetFromVerifiedSession(
                    $pdo,
                    $verifiedUserId,
                    password_hash($newPassword, PASSWORD_DEFAULT)
                );

                if ($done) {
                    clearRememberMeCookie();
                    if ($verifiedResetId > 0) {
                        clearOtpAttempts(resetOtpScope($verifiedResetId));
                    }
                    clearResetFlow();
                    clearVerifiedResetSession();

                    echo json_encode([
                        'success' => true,
                        'message' => 'Password updated successfully. You can now sign in.',
                        'redirect' => '../login.php?reason=password_reset_success',
                    ]);
                    exit;
                }
            }
        }

        $postedResetId = (int)($_POST['reset_id'] ?? 0);
        $postedSelector = strtolower(trim((string)($_POST['selector'] ?? '')));
        $postedToken = strtolower(trim((string)($_POST['token'] ?? '')));

        $request = null;
        $trustedVerifiedSession = false;
        if (is_array($verifiedSession)) {
            $verifiedResetId = (int)($verifiedSession['reset_id'] ?? 0);
            $verifiedUserId = (int)($verifiedSession['user_id'] ?? 0);
            $verifiedAt = (int)($verifiedSession['verified_at'] ?? 0);

            if ($verifiedResetId > 0 && $verifiedUserId > 0 && $verifiedAt > 0 && (time() - $verifiedAt) <= 900) {
                $candidate = getPasswordResetRequestByIdAnyExpiry($pdo, $verifiedResetId);
                if (is_array($candidate) && (int)$candidate['user_id'] === $verifiedUserId && !empty($candidate['otp_verified_at'])) {
                    $request = $candidate;
                    $trustedVerifiedSession = true;
                }
            }
        }

        if ($postedResetId > 0) {
            $request = $request ?: getPasswordResetRequestByIdAnyExpiry($pdo, $postedResetId);
        }

        if ($postedSelector !== '' && $postedToken !== '') {
            $request = $request ?: getPasswordResetRequest($pdo, $postedSelector, $postedToken);
            if (is_array($request)) {
                $request['plain_token'] = $postedToken;
            }
        }

        $flow = getResetFlow();
        if (is_array($flow) && is_array($request)) {
            $flowUserId = (int)($flow['user_id'] ?? 0);
            if ($flowUserId > 0 && $flowUserId !== (int)$request['user_id']) {
                $request = null;
            }
        }

        if (!is_array($request)) {
            if (is_array($flow)) {
                $flowResetId = (int)($flow['reset_id'] ?? 0);
                $flowUserId = (int)($flow['user_id'] ?? 0);
                $flowSelector = strtolower(trim((string)($flow['selector'] ?? '')));
                $flowToken = strtolower(trim((string)($flow['token'] ?? '')));

                if ($flowResetId > 0) {
                    $request = getPasswordResetRequestByIdAnyExpiry($pdo, $flowResetId);
                }

                if (!is_array($request) && $flowUserId > 0) {
                    $request = getLatestVerifiedResetByUser($pdo, $flowUserId);
                }

                if ($flowSelector !== '' && $flowToken !== '') {
                    $request = $request ?: getPasswordResetRequest($pdo, $flowSelector, $flowToken);
                    if (is_array($request)) {
                        $request['plain_token'] = $flowToken;
                    }
                }
            }
        }

        if (!is_array($request)) {
            echo json_encode(['success' => false, 'message' => 'Reset session expired. Start again.']);
            exit;
        }

        if (empty($request['otp_verified_at'])) {
            echo json_encode(['success' => false, 'message' => 'Verify OTP first before setting password.']);
            exit;
        }

        $resetId = (int)($request['reset_id'] ?? 0);
        $userId = (int)($request['user_id'] ?? 0);

        if (!$trustedVerifiedSession && !isResetOtpRecentlyVerified($pdo, $resetId, 900)) {
            clearVerifiedResetSession();
            echo json_encode(['success' => false, 'message' => 'OTP verification timed out. Verify OTP again.']);
            exit;
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        if ($trustedVerifiedSession) {
            $done = completePasswordResetFromVerifiedSession($pdo, $userId, $passwordHash);
        } else {
            $done = completePasswordReset($pdo, $resetId, $passwordHash);
        }

        if (!$done) {
            clearResetFlow();
            clearVerifiedResetSession();
            echo json_encode(['success' => false, 'message' => 'Reset request is no longer valid. Request a new OTP.']);
            exit;
        }

        clearRememberMeCookie();
        clearOtpAttempts(resetOtpScope($resetId));
        clearResetFlow();
        clearVerifiedResetSession();

        echo json_encode([
            'success' => true,
            'message' => 'Password updated successfully. You can now sign in.',
            'redirect' => '../login.php?reason=password_reset_success',
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown request action.']);
    exit;
}

$modalShouldOpen = is_array($flow);
$modalVerified = $modalShouldOpen && !empty($flow['verified']);
$modalMaskedEmail = $modalShouldOpen ? (string)($flow['masked_email'] ?? 'your email') : 'your email';
$modalResetId = $modalShouldOpen ? (int)($flow['reset_id'] ?? 0) : 0;
$modalSelector = $modalShouldOpen ? (string)($flow['selector'] ?? '') : '';
$modalToken = $modalShouldOpen ? (string)($flow['token'] ?? '') : '';
$modalRetryAfter = 0;
if ($modalShouldOpen && !$modalVerified) {
    $elapsed = time() - (int)($flow['otp_sent_at'] ?? 0);
    $modalRetryAfter = max(0, 60 - $elapsed);
}

$pageTitle = 'Forgot Password';
$additionalStyles = '
<style>
    @import url("https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap");

    :root {
        --auth-bg-a: #0a0d12;
        --auth-bg-b: #121823;
        --auth-card: #141c27;
        --auth-line: #2c3645;
        --auth-text: #edf3ff;
        --auth-muted: #a5b3c6;
        --auth-orange: #ff7b2c;
        --auth-orange-2: #ff5b1f;
    }

    body {
        font-family: "Manrope", "Segoe UI", sans-serif;
        background:
            radial-gradient(circle at 10% 12%, rgba(255, 123, 44, 0.18), transparent 36%),
            radial-gradient(circle at 88% 82%, rgba(255, 123, 44, 0.12), transparent 34%),
            linear-gradient(140deg, var(--auth-bg-a), var(--auth-bg-b));
        color: var(--auth-text);
    }

    .reset-shell {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 6rem 1rem 2rem;
    }

    .reset-card {
        width: 100%;
        max-width: 520px;
        background: linear-gradient(158deg, rgba(255,255,255,0.04), rgba(255,255,255,0.01) 22%, var(--auth-card) 100%);
        border: 1px solid var(--auth-line);
        border-radius: 20px;
        box-shadow: 0 24px 48px rgba(0, 0, 0, 0.42);
        overflow: hidden;
    }

    .reset-head {
        padding: 1.3rem 1.4rem;
        background: linear-gradient(132deg, var(--auth-orange), var(--auth-orange-2));
    }

    .reset-title {
        font-family: "Sora", "Segoe UI", sans-serif;
        font-size: 1.3rem;
        font-weight: 700;
        margin: 0;
        color: #fff;
    }

    .reset-sub {
        margin-top: 0.35rem;
        color: #ffe4d0;
        font-size: 0.84rem;
    }

    .reset-body {
        padding: 1.35rem;
    }

    .field {
        width: 100%;
        border: 1px solid #374457;
        border-radius: 12px;
        padding: 0.78rem 0.95rem;
        background: #0f1620;
        color: #f3f7ff;
        outline: none;
    }

    .field:focus {
        border-color: #ff8f4a;
        box-shadow: 0 0 0 3px rgba(255, 123, 44, 0.22);
    }

    .submit-btn {
        width: 100%;
        border: 0;
        border-radius: 12px;
        padding: 0.8rem 1rem;
        font-weight: 800;
        color: #fff;
        background: linear-gradient(132deg, var(--auth-orange), var(--auth-orange-2));
    }

    .submit-btn:hover {
        filter: brightness(1.06);
    }

    .modal-backdrop {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(2, 6, 23, 0.74);
        z-index: 1300;
        padding: 1rem;
    }

    .modal-backdrop.open {
        display: flex;
    }

    .modal-panel {
        width: 100%;
        max-width: 560px;
        border-radius: 18px;
        border: 1px solid #2e394b;
        background: #131b26;
        box-shadow: 0 28px 54px rgba(0, 0, 0, 0.48);
        overflow: hidden;
    }

    .modal-head {
        padding: 1rem 1.2rem;
        background: linear-gradient(132deg, #ff7b2c, #ff5b1f);
        color: #fff;
    }

    .modal-body {
        padding: 1.2rem;
    }

    .modal-step.hidden {
        display: none;
    }

    .resend-btn[disabled] {
        opacity: 0.62;
        cursor: not-allowed;
    }

    .otp-input {
        letter-spacing: 0.28em;
        text-align: center;
        font-size: 1.1rem;
        font-weight: 700;
    }

    .strength-track {
        width: 100%;
        height: 8px;
        border-radius: 999px;
        background: #2c3645;
        overflow: hidden;
    }

    .strength-fill {
        height: 100%;
        width: 0;
        border-radius: 999px;
        background: #64748b;
        transition: width 0.2s ease, background 0.2s ease;
    }

    .match-hint {
        font-size: 0.75rem;
        margin-top: 0.35rem;
        color: #94a3b8;
    }
</style>';

include __DIR__ . '/../includes/layout/header.php';
?>

<div class="reset-shell">
    <div class="reset-card">
        <div class="reset-head">
            <h1 class="reset-title"><i class="fas fa-key mr-2"></i>Forgot password</h1>
            <p class="reset-sub">Email OTP verification with secure reset</p>
        </div>
        <div class="reset-body">
            <?php if ($flashError !== ''): ?>
                <div class="mb-4 rounded-lg border border-red-400/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    <i class="fas fa-triangle-exclamation mr-1"></i><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <p class="text-sm text-slate-300 mb-4">
                Enter your account email. If found, we will send a 6-digit OTP to your Gmail and open secure verification.
            </p>

            <form id="sendOtpForm" class="space-y-4" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="send_otp">

                <div>
                    <label for="email" class="block text-sm font-semibold text-slate-200 mb-2"><i class="fas fa-envelope-open-text mr-1 text-orange-300"></i>Email address</label>
                    <input id="email" name="email" type="email" class="field" placeholder="you@gmail.com" pattern="[a-zA-Z0-9._%+\-]+@gmail\.com" title="Use a valid Gmail address (example@gmail.com)" required autocomplete="email">
                    <p class="text-xs text-slate-500 mt-1">Only Gmail addresses are accepted for reset requests.</p>
                </div>

                <button type="submit" class="submit-btn" id="sendOtpBtn">
                    <i class="fas fa-paper-plane mr-2"></i>Send OTP to Gmail
                </button>
            </form>

            <p class="text-sm text-slate-300 mt-4">
                Remembered your password?
                <a href="../login.php" class="text-orange-300 font-semibold hover:text-orange-200"><i class="fas fa-right-to-bracket mr-1"></i>Back to login</a>
            </p>
        </div>
    </div>
</div>

<div id="otpResetModal" class="modal-backdrop<?php echo $modalShouldOpen ? ' open' : ''; ?>" aria-hidden="<?php echo $modalShouldOpen ? 'false' : 'true'; ?>">
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="otpResetTitle">
        <div class="modal-head flex items-center justify-between gap-2">
            <div>
                <h2 id="otpResetTitle" class="text-lg font-bold"><i class="fas fa-shield-halved mr-2"></i>Password Recovery</h2>
                <p class="text-xs text-orange-100">Account: <span id="maskedEmailText"><?php echo htmlspecialchars($modalMaskedEmail, ENT_QUOTES, 'UTF-8'); ?></span></p>
            </div>
            <button type="button" id="closeModalBtn" class="text-white/90 hover:text-white" aria-label="Close">
                <i class="fas fa-xmark text-lg"></i>
            </button>
        </div>

        <div class="modal-body space-y-4">
            <div id="otpStep" class="modal-step<?php echo $modalVerified ? ' hidden' : ''; ?>">
                <p class="text-sm text-slate-300 mb-3">Enter the 6-digit OTP sent to your Gmail to continue.</p>
                <form id="verifyOtpForm" class="space-y-3" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="verify_otp">
                    <input type="hidden" name="reset_id" id="verifyResetId" value="<?php echo (int)$modalResetId; ?>">
                    <input type="hidden" name="selector" id="verifySelector" value="<?php echo htmlspecialchars($modalSelector, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="token" id="verifyToken" value="<?php echo htmlspecialchars($modalToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input id="otpCode" name="otp" type="text" inputmode="numeric" maxlength="6" class="field otp-input" placeholder="123456" required>
                    <button type="submit" class="submit-btn" id="verifyOtpBtn"><i class="fas fa-check-double mr-2"></i>Verify OTP</button>
                </form>

                <div class="flex items-center justify-between gap-3 mt-2">
                    <button type="button" id="resendOtpBtn" class="resend-btn text-sm font-semibold text-orange-300 hover:text-orange-200" disabled>
                        <i class="fas fa-rotate-right mr-1"></i>Resend OTP
                    </button>
                    <span id="resendTimerText" class="text-xs text-slate-400">Resend available in <?php echo (int)$modalRetryAfter; ?>s</span>
                </div>
            </div>

            <div id="passwordStep" class="modal-step<?php echo $modalVerified ? '' : ' hidden'; ?>">
                <p class="text-sm text-slate-300 mb-3">OTP verified. Set your new password.</p>
                <form id="resetPasswordForm" class="space-y-3" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="reset_id" id="resetRequestId" value="<?php echo (int)$modalResetId; ?>">
                    <input type="hidden" name="selector" id="resetSelector" value="<?php echo htmlspecialchars($modalSelector, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="token" id="resetToken" value="<?php echo htmlspecialchars($modalToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <div>
                        <label for="newPassword" class="block text-sm font-semibold text-slate-200 mb-2"><i class="fas fa-lock mr-1 text-orange-300"></i>New password</label>
                        <input id="newPassword" name="new_password" type="password" class="field" required autocomplete="new-password" placeholder="8+ chars, upper, lower, number, symbol">
                        <div class="mt-2">
                            <div class="strength-track">
                                <div id="passwordStrengthFill" class="strength-fill"></div>
                            </div>
                            <p id="passwordStrengthText" class="text-xs text-slate-400 mt-1">Strength: not set</p>
                            <p class="text-xs text-slate-500">Suggestion: try mixing uppercase, lowercase, numbers, and symbols for better security.</p>
                        </div>
                    </div>

                    <div>
                        <label for="confirmPassword" class="block text-sm font-semibold text-slate-200 mb-2"><i class="fas fa-check mr-1 text-orange-300"></i>Confirm password</label>
                        <input id="confirmPassword" name="confirm_password" type="password" class="field" required autocomplete="new-password" placeholder="Repeat new password">
                        <p id="passwordMatchText" class="match-hint">Match status: waiting</p>
                    </div>

                    <button type="submit" class="submit-btn" id="resetPasswordBtn"><i class="fas fa-key mr-2"></i>Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const endpoint = 'forgot-password.php';
    const sendOtpForm = document.getElementById('sendOtpForm');
    const verifyOtpForm = document.getElementById('verifyOtpForm');
    const resetPasswordForm = document.getElementById('resetPasswordForm');
    const modal = document.getElementById('otpResetModal');
    const closeModalBtn = document.getElementById('closeModalBtn');

    const maskedEmailText = document.getElementById('maskedEmailText');
    const otpStep = document.getElementById('otpStep');
    const passwordStep = document.getElementById('passwordStep');
    const resendOtpBtn = document.getElementById('resendOtpBtn');
    const resendTimerText = document.getElementById('resendTimerText');
    const otpCode = document.getElementById('otpCode');
    const verifyResetIdInput = document.getElementById('verifyResetId');
    const verifySelectorInput = document.getElementById('verifySelector');
    const verifyTokenInput = document.getElementById('verifyToken');
    const resetRequestIdInput = document.getElementById('resetRequestId');
    const resetSelectorInput = document.getElementById('resetSelector');
    const resetTokenInput = document.getElementById('resetToken');
    const newPasswordInput = document.getElementById('newPassword');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    const strengthFill = document.getElementById('passwordStrengthFill');
    const strengthText = document.getElementById('passwordStrengthText');
    const passwordMatchText = document.getElementById('passwordMatchText');

    let resendCountdown = <?php echo (int)$modalRetryAfter; ?>;
    let resendTimerId = null;

    function setResetContext(selector, token, resetId) {
        const s = String(selector || '');
        const t = String(token || '');
        const r = Number(resetId || 0);

        if (verifyResetIdInput) verifyResetIdInput.value = r > 0 ? String(r) : '';
        if (resetRequestIdInput) resetRequestIdInput.value = r > 0 ? String(r) : '';

        if (verifySelectorInput) verifySelectorInput.value = s;
        if (verifyTokenInput) verifyTokenInput.value = t;
        if (resetSelectorInput) resetSelectorInput.value = s;
        if (resetTokenInput) resetTokenInput.value = t;
    }

    (function showReasonToast() {
        const params = new URLSearchParams(window.location.search);
        const reason = params.get('reason');
        if (reason === 'otp_flow') {
            showToast('Password reset now uses OTP verification. Enter your email to continue.', true);
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    })();

    function openModal() {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

        setResetContext('', '', 0);
        setStep(false);
        otpCode.value = '';
        if (resendTimerId) {
            clearInterval(resendTimerId);
            resendTimerId = null;
        }
        resendCountdown = 0;
        resendOtpBtn.disabled = true;
        resendTimerText.textContent = 'Resend available in 0s';

        const formData = new FormData();
        formData.append('action', 'close_modal');
        formData.append('csrf_token', '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>');
        postAction(formData).catch(() => {});
    }

    function setStep(isVerified) {
        if (isVerified) {
            otpStep.classList.add('hidden');
            passwordStep.classList.remove('hidden');
        } else {
            otpStep.classList.remove('hidden');
            passwordStep.classList.add('hidden');
        }
    }

    function startResendCountdown(seconds) {
        resendCountdown = Math.max(0, Number(seconds || 0));

        if (resendTimerId) {
            clearInterval(resendTimerId);
            resendTimerId = null;
        }

        function render() {
            if (resendCountdown > 0) {
                resendOtpBtn.disabled = true;
                resendTimerText.textContent = 'Resend available in ' + resendCountdown + 's';
            } else {
                resendOtpBtn.disabled = false;
                resendTimerText.textContent = 'You can resend OTP now';
            }
        }

        render();
        if (resendCountdown <= 0) {
            return;
        }

        resendTimerId = setInterval(() => {
            resendCountdown -= 1;
            if (resendCountdown <= 0) {
                resendCountdown = 0;
                clearInterval(resendTimerId);
                resendTimerId = null;
            }
            render();
        }, 1000);
    }

    function evaluatePasswordStrength(value) {
        const password = String(value || '');
        if (password.length === 0) {
            return { score: 0, label: 'not set', color: '#64748b', width: 0 };
        }

        let score = 0;
        if (password.length >= 8) score += 1;
        if (/[A-Z]/.test(password)) score += 1;
        if (/[a-z]/.test(password)) score += 1;
        if (/\d/.test(password)) score += 1;
        if (/[^A-Za-z0-9]/.test(password)) score += 1;

        if (score <= 2) {
            return { score, label: 'weak', color: '#ef4444', width: 30 };
        }
        if (score <= 4) {
            return { score, label: 'fair', color: '#f59e0b', width: 65 };
        }
        return { score, label: 'strong', color: '#22c55e', width: 100 };
    }

    function renderPasswordStrength() {
        if (!newPasswordInput || !strengthFill || !strengthText) {
            return;
        }

        const result = evaluatePasswordStrength(newPasswordInput.value);
        strengthFill.style.width = result.width + '%';
        strengthFill.style.background = result.color;
        strengthText.textContent = 'Strength: ' + result.label;
        strengthText.style.color = result.color;
    }

    function renderPasswordMatch() {
        if (!newPasswordInput || !confirmPasswordInput || !passwordMatchText) {
            return true;
        }

        const password = String(newPasswordInput.value || '');
        const confirm = String(confirmPasswordInput.value || '');

        if (confirm.length === 0) {
            passwordMatchText.textContent = 'Match status: waiting';
            passwordMatchText.style.color = '#94a3b8';
            return true;
        }

        const matches = password === confirm;
        passwordMatchText.textContent = matches ? 'Match status: passwords match' : 'Match status: passwords do not match';
        passwordMatchText.style.color = matches ? '#22c55e' : '#ef4444';
        return matches;
    }

    async function postAction(formData) {
        const res = await fetch(endpoint, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        let data = null;
        try {
            data = await res.json();
        } catch (e) {
            data = { success: false, message: 'Unexpected server response.' };
        }

        return data;
    }

    sendOtpForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        const emailInput = document.getElementById('email');
        const emailValue = String(emailInput ? emailInput.value : '').trim();
        const gmailPattern = /^[a-z0-9._%+\-]+@gmail\.com$/i;
        if (!gmailPattern.test(emailValue)) {
            showToast('Use a Gmail address ending with @gmail.com.', false);
            return;
        }

        const sendOtpBtn = document.getElementById('sendOtpBtn');
        const originalText = sendOtpBtn.innerHTML;
        sendOtpBtn.disabled = true;
        sendOtpBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i>Sending OTP...';

        const formData = new FormData(sendOtpForm);
        const data = await postAction(formData);

        sendOtpBtn.disabled = false;
        sendOtpBtn.innerHTML = originalText;

        if (!data.success) {
            showToast(data.message || 'Unable to send OTP.', false);
            return;
        }

        maskedEmailText.textContent = data.masked_email || 'your email';
        setResetContext(data.selector || '', data.token || '', Number(data.reset_id || 0));
        setStep(false);
        openModal();
        startResendCountdown(Number(data.cooldown || 60));
        showToast(data.message || 'OTP sent.', true);
    });

    verifyOtpForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        const verifyOtpBtn = document.getElementById('verifyOtpBtn');
        const originalText = verifyOtpBtn.innerHTML;
        verifyOtpBtn.disabled = true;
        verifyOtpBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i>Verifying...';

        const formData = new FormData(verifyOtpForm);
        const data = await postAction(formData);

        verifyOtpBtn.disabled = false;
        verifyOtpBtn.innerHTML = originalText;

        if (!data.success) {
            showToast(data.message || 'Invalid OTP.', false);
            if (data.locked) {
                resendOtpBtn.disabled = false;
                resendTimerText.textContent = 'OTP locked. Please resend a new OTP.';
            }
            return;
        }

        setResetContext(data.selector || (verifySelectorInput ? verifySelectorInput.value : ''), data.token || (verifyTokenInput ? verifyTokenInput.value : ''), Number(data.reset_id || (verifyResetIdInput ? verifyResetIdInput.value : 0)));
        setStep(true);
        showToast(data.message || 'OTP verified.', true);
    });

    resendOtpBtn.addEventListener('click', async function () {
        if (resendOtpBtn.disabled) {
            return;
        }

        resendOtpBtn.disabled = true;
        const formData = new FormData();
        formData.append('action', 'resend_otp');
        formData.append('csrf_token', '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>');
        formData.append('reset_id', verifyResetIdInput ? verifyResetIdInput.value : '');
        formData.append('selector', verifySelectorInput ? verifySelectorInput.value : '');
        formData.append('token', verifyTokenInput ? verifyTokenInput.value : '');

        const data = await postAction(formData);

        if (!data.success) {
            showToast(data.message || 'Unable to resend OTP.', false);
            if (typeof data.retry_after !== 'undefined') {
                startResendCountdown(Number(data.retry_after || 60));
            } else {
                resendOtpBtn.disabled = false;
            }
            return;
        }

        maskedEmailText.textContent = data.masked_email || maskedEmailText.textContent;
        setResetContext(data.selector || '', data.token || '', Number(data.reset_id || 0));
        otpCode.value = '';
        setStep(false);
        startResendCountdown(Number(data.cooldown || 60));
        showToast(data.message || 'OTP resent.', true);
    });

    resetPasswordForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!renderPasswordMatch()) {
            showToast('Passwords do not match.', false);
            return;
        }

        const resetPasswordBtn = document.getElementById('resetPasswordBtn');
        const originalText = resetPasswordBtn.innerHTML;
        resetPasswordBtn.disabled = true;
        resetPasswordBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i>Updating...';

        const formData = new FormData(resetPasswordForm);
        const data = await postAction(formData);

        resetPasswordBtn.disabled = false;
        resetPasswordBtn.innerHTML = originalText;

        if (!data.success) {
            showToast(data.message || 'Unable to reset password.', false);
            return;
        }

        showToast(data.message || 'Password reset complete.', true);
        navigateWithLoading(data.redirect || '../login.php?reason=password_reset_success', 'Opening sign in...', 3000);
    });

    otpCode.addEventListener('input', function () {
        otpCode.value = otpCode.value.replace(/\D/g, '').slice(0, 6);
    });

    if (newPasswordInput) {
        newPasswordInput.addEventListener('input', function () {
            renderPasswordStrength();
            renderPasswordMatch();
        });
        renderPasswordStrength();
        renderPasswordMatch();
    }

    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', renderPasswordMatch);
    }

    closeModalBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    if (modal.classList.contains('open')) {
        setResetContext('<?php echo htmlspecialchars($modalSelector, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($modalToken, ENT_QUOTES, 'UTF-8'); ?>', <?php echo (int)$modalResetId; ?>);
        openModal();
        setStep(<?php echo $modalVerified ? 'true' : 'false'; ?>);
        startResendCountdown(<?php echo (int)$modalRetryAfter; ?>);
    }
})();
</script>

<?php include __DIR__ . '/../includes/layout/footer.php'; ?>
