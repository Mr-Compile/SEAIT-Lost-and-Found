<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers/settings_helper.php';
require_once __DIR__ . '/../includes/helpers/mfa_helper.php';
require_once __DIR__ . '/../includes/helpers/auth/remember_me_helper.php';
require_once __DIR__ . '/../includes/helpers/assets_helper.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/anti_inspect.php';

startSecureSession();
loadEnvFile(__DIR__ . '/../.env');
requirePendingMfaChallengeWithPaths('../login.php', '../admin/admin_dashboard.php', '../claimant/claimant_dashboard.php');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$settings = getSystemSettings();

$pending = $_SESSION['mfa_pending_user'];
$userId = (int)$pending['user_id'];
$role = (string)$pending['role'];
$redirectUrl = ($role === 'admin') ? '../admin/admin_dashboard.php' : '../claimant/claimant_dashboard.php';
$csrfToken = getCsrfToken('verify_mfa_login');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputCode = trim((string)($_POST['otp'] ?? ''));
    $otp = preg_replace('/\D/', '', $inputCode);
    $requestCsrfToken = $_POST['csrf_token'] ?? null;

    if (!isValidCsrfToken('verify_mfa_login', is_string($requestCsrfToken) ? $requestCsrfToken : null)) {
        $error = 'Invalid request token. Please refresh and try again.';
    } elseif (hasTooManyOtpAttempts('verify_mfa_login')) {
        $error = 'Too many attempts. Please wait a few minutes and sign in again.';
    } else {
        $stmt = $pdo->prepare('SELECT user_id, full_name, email, role, mfa_secret, mfa_enabled FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || (int)$user['mfa_enabled'] !== 1 || empty($user['mfa_secret'])) {
            unset($_SESSION['mfa_pending_user']);
            $error = 'MFA challenge is no longer valid. Please sign in again.';
        } else {
            $isValid = false;

            if (preg_match('/^\d{6}$/', $otp) === 1) {
                $tfa = getTfaInstance();
                $serverTime = time();
                $normalizedSecret = normalizeMfaSecret((string)$user['mfa_secret']);
                $serverCode = $tfa->getCode($normalizedSecret, $serverTime);
                mfaDebugLog('Verify MFA: user_id=' . (int)$user['user_id'] . ' otp=' . $otp . ' server_code=' . $serverCode . ' server_time=' . $serverTime);

                // Allow a slightly larger time-window to reduce rejects if server clock drifts.
                $isValid = $tfa->verifyCode($normalizedSecret, $otp, 2, $serverTime);
            } else {
                $isValid = consumeBackupCode($pdo, (int)$user['user_id'], $inputCode);
            }

            if ($isValid) {
                $rememberAfterMfa = !empty($_SESSION['remember_after_mfa']);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['full_name'] = (string)$user['full_name'];
                $_SESSION['email'] = (string)$user['email'];
                $_SESSION['role'] = (string)$user['role'];
                unset($_SESSION['mfa_pending_user'], $_SESSION['remember_after_mfa']);
                clearOtpAttempts('verify_mfa_login');

                if ($rememberAfterMfa) {
                    createRememberMeToken($pdo, (int)$user['user_id']);
                }

                header('Location: ' . $redirectUrl);
                exit;
            }

            recordOtpAttempt('verify_mfa_login');
            if (preg_match('/^\d{6}$/', $otp) !== 1 && isBackupCodeAlreadyUsed($pdo, (int)$user['user_id'], $inputCode)) {
                $error = 'This backup code was already used. Backup codes are one-time use only.';
            } else {
                $error = 'Invalid authenticator code or backup code.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify MFA - FindIt</title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --mfa-bg: #090d13;
            --mfa-panel: #131922;
            --mfa-panel-2: #1a212d;
            --mfa-line: #273040;
            --mfa-text: #ecf2ff;
            --mfa-muted: #a7b4c8;
            --mfa-accent: #ff5b1f;
            --mfa-accent-2: #ff7b2c;
        }

        body.mfa-theme {
            margin: 0;
            color: var(--mfa-text);
            background:
                radial-gradient(circle at 8% 8%, rgba(255, 122, 28, 0.16), transparent 38%),
                radial-gradient(circle at 92% 4%, rgba(34, 211, 238, 0.12), transparent 42%),
                linear-gradient(180deg, #080b0f 0%, #0a0f14 48%, #0d1117 100%);
            font-family: 'Manrope', 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
        }

        .mfa-card {
            background: var(--mfa-panel);
            border: 1px solid var(--mfa-line);
            box-shadow: 0 22px 42px rgba(0, 0, 0, 0.42);
            border-radius: 20px;
        }

        .mfa-hero {
            background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 38%, #ef4444 68%, #22d3ee 100%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .mfa-input {
            width: 100%;
            border: 1px solid var(--mfa-line);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            background: #0f151e;
            color: var(--mfa-text);
            outline: none;
            transition: all 0.2s ease;
        }

        .mfa-input:focus {
            border-color: var(--mfa-accent-2);
            box-shadow: 0 0 0 3px rgba(255, 123, 44, 0.2);
        }

        .mfa-btn {
            border: 0;
            border-radius: 12px;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(132deg, var(--mfa-accent), var(--mfa-accent-2));
            transition: all 0.2s ease;
        }

        .mfa-btn:hover {
            filter: brightness(1.08);
        }
    </style>
</head>
<body class="min-h-screen mfa-theme">
    <?php renderAntiInspectScript(); ?>
    <div class="min-h-screen flex items-center justify-center px-4 py-8">
        <div class="w-full max-w-md mfa-card overflow-hidden">
            <div class="mfa-hero px-6 py-5 text-white">
                <h1 class="text-xl font-bold"><i class="fas fa-mobile-screen-button mr-2"></i>Multi-Factor Verification</h1>
                <p class="mt-1 text-sm text-orange-100">Verify login for <?php echo htmlspecialchars(explode(' -', $settings['site_name'] ?? 'FindIt')[0], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="p-6">
                <?php if ($error !== ''): ?>
                    <div class="mb-4 rounded-lg border border-red-400/50 bg-red-500/15 px-4 py-3 text-sm text-red-100">
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <label for="otp" class="block text-sm font-medium text-gray-100">Authenticator code or backup code</label>
                    <input
                        id="otp"
                        name="otp"
                        type="text"
                        autocomplete="one-time-code"
                        maxlength="12"
                        required
                        placeholder="123456 or ABCD-EF12"
                        class="mfa-input text-center text-lg tracking-[0.15em]"
                    >

                    <button type="submit" class="w-full mfa-btn px-4 py-3">
                        Verify and Sign In
                    </button>
                </form>

                <div class="mt-4 text-center text-sm text-slate-300">
                    Lost your authenticator? Use one of your backup codes.
                </div>
                <a href="../login.php" class="mt-3 block text-center text-sm font-medium text-orange-300 hover:text-orange-200">Back to login</a>
            </div>
        </div>
    </div>
</body>
</html>

