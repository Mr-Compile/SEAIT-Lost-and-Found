<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers/settings_helper.php';
require_once __DIR__ . '/../includes/helpers/mfa_helper.php';
require_once __DIR__ . '/../includes/helpers/assets_helper.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/anti_inspect.php';

startSecureSession();
loadEnvFile(__DIR__ . '/../.env');
requireAuthenticatedUserWithPaths('../login.php', 'verify-mfa.php');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$settings = getSystemSettings();
ensureMfaUsersCompletionColumn($pdo);
ensureMfaBackupCodeSnapshotsTable($pdo);

$stmt = $pdo->prepare('SELECT user_id, email, role, mfa_enabled, mfa_secret, mfa_backup_codes_downloaded_at FROM users WHERE user_id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: ../login.php?reason=auth_required');
    exit;
}

$isMfaEnabled = (int)($user['mfa_enabled'] ?? 0) === 1 && !empty($user['mfa_secret']);
$isBackupDownloadCompleted = !empty($user['mfa_backup_codes_downloaded_at']);
$isMfaSetupCompleted = $isMfaEnabled && $isBackupDownloadCompleted;
if ($isMfaSetupCompleted && isset($_SESSION['force_mfa_setup'])) {
    unset($_SESSION['force_mfa_setup']);
}

$tfa = getTfaInstance();
$secret = $_SESSION['mfa_setup_secret'] ?? '';
if (!is_string($secret) || $secret === '') {
    $secret = $tfa->createSecret();
    $_SESSION['mfa_setup_secret'] = $secret;
}

// Always normalize before presenting/using the secret.
$secret = normalizeMfaSecret($secret);
$_SESSION['mfa_setup_secret'] = $secret;

$label = 'FindIt:' . $user['email'];
$qrCodeDataUri = $tfa->getQRCodeImageAsDataUri($label, $secret, 240);
$csrfToken = getCsrfToken('enable_mfa');
$downloadBackupToken = getCsrfToken('download_backup_codes');
$backupRevealScope = 'view_backup_codes_full';
$backupRevealToken = getCsrfToken($backupRevealScope);
$backupRevealSessionKey = 'mfa_backup_codes_reveal_until';
$continueUrl = ((string)$user['role'] === 'admin') ? '../admin/admin_dashboard.php' : '../claimant/claimant_dashboard.php';

$errorMessage = $_SESSION['mfa_error'] ?? '';
$successMessage = $_SESSION['mfa_success'] ?? '';
$newBackupCodes = $_SESSION['mfa_new_backup_codes'] ?? [];
unset($_SESSION['mfa_error'], $_SESSION['mfa_success']);

$shouldShowSetupSteps = !$isMfaEnabled;
$shouldShowBackupDownloadStep = $isMfaEnabled && !$isBackupDownloadCompleted;
$shouldShowCompletedState = $isMfaSetupCompleted;

$mfaSecretForCodes = normalizeMfaSecret((string)($user['mfa_secret'] ?? ''));

if ($isMfaEnabled && $mfaSecretForCodes !== '') {
    $persistedBackupCodeRows = getBackupCodeSnapshotWithStatus($pdo, (int)$user['user_id'], $mfaSecretForCodes);
    if (count($persistedBackupCodeRows) > 0) {
        $newBackupCodes = array_map(static fn(array $row): string => (string)($row['code'] ?? ''), $persistedBackupCodeRows);
        $_SESSION['mfa_new_backup_codes'] = $newBackupCodes;
    }
}

if ($shouldShowBackupDownloadStep && (!is_array($newBackupCodes) || count($newBackupCodes) === 0)) {
    $newBackupCodes = generateBackupCodes(8);
    saveBackupCodes($pdo, (int)$user['user_id'], $newBackupCodes);
    if ($mfaSecretForCodes !== '') {
        saveBackupCodeSnapshot($pdo, (int)$user['user_id'], $newBackupCodes, $mfaSecretForCodes);
    }
    $_SESSION['mfa_new_backup_codes'] = $newBackupCodes;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'view_backup_codes_full') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    $passwordInput = (string)($_POST['backup_codes_password'] ?? '');

    if (!isValidCsrfToken($backupRevealScope, is_string($submittedToken) ? $submittedToken : null)) {
        $errorMessage = 'Invalid request token while revealing backup codes.';
    } elseif ($passwordInput === '') {
        $errorMessage = 'Enter your current account password to reveal full backup codes.';
    } elseif (!$isMfaEnabled || $mfaSecretForCodes === '') {
        $errorMessage = 'MFA must be enabled before viewing backup codes.';
    } else {
        $passwordStmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
        $passwordStmt->execute([(int)$user['user_id']]);
        $storedHash = (string)($passwordStmt->fetchColumn() ?? '');

        if ($storedHash === '' || !password_verify($passwordInput, $storedHash)) {
            $errorMessage = 'Current password is incorrect. Full backup codes remain hidden.';
        } else {
            $_SESSION[$backupRevealSessionKey] = time() + 180;
            $successMessage = 'Full backup codes are visible for 3 minutes in this session.';
        }
    }
}

$showFullBackupCodes = ((int)($_SESSION[$backupRevealSessionKey] ?? 0)) > time();
if (!$showFullBackupCodes && isset($_SESSION[$backupRevealSessionKey])) {
    unset($_SESSION[$backupRevealSessionKey]);
}

$backupCodeRows = [];
foreach ($newBackupCodes as $code) {
    $backupCodeRows[] = ['code' => (string)$code, 'used' => false];
}
if (isset($persistedBackupCodeRows) && is_array($persistedBackupCodeRows) && count($persistedBackupCodeRows) > 0) {
    $backupCodeRows = $persistedBackupCodeRows;
}

$usedBackupCodesCount = 0;
foreach ($backupCodeRows as $row) {
    if (!empty($row['used'])) {
        $usedBackupCodesCount++;
    }
}

$hasNewBackupCodes = is_array($newBackupCodes) && count($newBackupCodes) > 0;

$remainingBackupCodes = getRemainingBackupCodeCount($pdo, (int)$user['user_id']);
$enrollRequired = isset($_SESSION['force_mfa_setup']) || (isset($_GET['enroll']) && $_GET['enroll'] === '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MFA Setup - FindIt</title>
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
            --mfa-info: #22d3ee;
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

        .mfa-panel {
            background: var(--mfa-panel-2);
            border: 1px solid var(--mfa-line);
            border-radius: 14px;
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
            transition: all 0.2s ease;
        }

        .mfa-btn-primary {
            color: #ffffff;
            background: linear-gradient(132deg, var(--mfa-accent), var(--mfa-accent-2));
        }

        .mfa-btn-primary:hover {
            filter: brightness(1.08);
        }

        .mfa-btn-soft {
            color: #d6f7ff;
            background: rgba(34, 211, 238, 0.14);
            border: 1px solid rgba(34, 211, 238, 0.35);
        }

        .mfa-btn-soft:hover {
            background: rgba(34, 211, 238, 0.22);
        }

        .mfa-btn-success {
            color: #ffffff;
            background: linear-gradient(135deg, #059669, #10b981);
        }

        .mfa-note {
            color: var(--mfa-muted);
        }

        .mfa-alert {
            border-radius: 12px;
            border: 1px solid var(--mfa-line);
            background: #101722;
            color: var(--mfa-text);
        }

        .mfa-toast {
            border: 1px solid rgba(255, 255, 255, 0.14);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.32);
        }
    </style>
</head>
<body class="min-h-screen mfa-theme">
    <?php renderAntiInspectScript(); ?>
    <div id="toastContainer" class="fixed top-4 right-4 z-50 space-y-2"></div>

    <div class="min-h-screen flex items-center justify-center px-4 py-8">
        <div class="w-full max-w-4xl mfa-card overflow-hidden">
            <div class="mfa-hero px-8 py-6 text-white">
                <div class="flex items-center gap-3">
                    <i class="fas fa-shield-halved text-2xl"></i>
                    <div>
                        <h1 class="text-2xl font-bold">Secure Your Account</h1>
                        <p class="text-orange-50/95 text-sm">Microsoft Authenticator setup for <?php echo htmlspecialchars(explode(' -', $settings['site_name'] ?? 'FindIt')[0], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
            </div>

            <div class="p-8 md:p-10 space-y-5">
                <?php if ($enrollRequired && !$isMfaEnabled): ?>
                    <div class="mfa-alert px-4 py-3 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation mt-0.5 text-amber-300"></i>
                        <span>
                        MFA is required for all users. Complete setup to continue.
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($isMfaEnabled && !$isBackupDownloadCompleted): ?>
                    <div class="mfa-alert px-4 py-3 flex items-start gap-3">
                        <i class="fa-solid fa-download mt-0.5 text-amber-300"></i>
                        <span>
                        Final step required: download your backup codes to unlock dashboard access.
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="rounded-lg border border-red-400/50 bg-red-500/15 px-4 py-3 text-red-100">
                        <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage !== ''): ?>
                    <div class="rounded-lg border border-emerald-400/50 bg-emerald-500/15 px-4 py-3 text-emerald-100">
                        <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($shouldShowSetupSteps): ?>
                    <div class="grid gap-6 md:grid-cols-2">
                        <div class="mfa-panel p-4 flex items-center justify-center">
                            <img src="<?php echo htmlspecialchars($qrCodeDataUri, ENT_QUOTES, 'UTF-8'); ?>" alt="MFA QR Code" class="rounded-lg border border-gray-700 bg-white p-2" />
                        </div>
                        <div class="space-y-4">
                            <div>
                                <h2 class="font-semibold text-gray-100 inline-flex items-center gap-2"><i class="fa-solid fa-qrcode text-orange-300"></i>Step 1: Scan QR code</h2>
                                <p class="text-sm mfa-note">Open Microsoft Authenticator, tap <strong>Add account</strong>, then scan the QR.</p>
                            </div>
                            <div class="mfa-panel p-4">
                                <p class="text-xs mfa-note">Manual key</p>
                                <p class="mt-1 break-all font-mono text-sm font-semibold text-gray-100"><?php echo htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="rounded-lg border border-cyan-400/40 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-100 inline-flex items-center gap-2">
                                <i class="fa-solid fa-key"></i>
                                Remaining backup codes: <strong><?php echo (int)$remainingBackupCodes; ?></strong>
                            </div>
                        </div>
                    </div>

                    <form action="enable-mfa.php" method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <label for="otp" class="block text-sm font-medium text-gray-100 inline-flex items-center gap-2"><i class="fa-solid fa-mobile-screen-button text-orange-300"></i>Step 2: Enter 6-digit code</label>
                        <input
                            id="otp"
                            name="otp"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            required
                            placeholder="000000"
                            class="mfa-input text-center text-2xl tracking-[0.3em]"
                        >
                        <button type="submit" class="w-full mfa-btn mfa-btn-primary px-4 py-3 inline-flex items-center justify-center gap-2">
                            <i class="fa-solid fa-shield"></i>
                            Enable MFA and Generate Backup Codes
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($shouldShowBackupDownloadStep): ?>
                    <div class="rounded-xl border border-orange-400/40 bg-orange-500/10 p-5">
                        <div>
                            <h3 class="font-semibold text-orange-100 inline-flex items-center gap-2"><i class="fa-solid fa-file-arrow-down"></i>Final Step: Save Backup Codes</h3>
                            <p class="text-sm text-orange-100/85">MFA is enabled. Save your recovery codes now to unlock dashboard access.</p>
                        </div>
                        <?php if ($hasNewBackupCodes): ?>
                            <form id="backupCodesForm" action="download-backup-codes.php" method="POST" class="mt-4">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($downloadBackupToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <button id="downloadBackupCodesBtn" type="button" class="w-full mfa-btn mfa-btn-soft px-4 py-3 inline-flex items-center justify-center gap-2">
                                    <i class="fa-solid fa-download"></i>
                                    Save Backup Codes (.txt)
                                </button>
                            </form>

                            <div class="mt-4 mfa-panel px-4 py-4 text-sm text-gray-100 space-y-3">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="font-semibold inline-flex items-center gap-2"><i class="fa-solid fa-key"></i>Current Backup Codes</p>
                                    <span class="text-xs <?php echo $showFullBackupCodes ? 'text-emerald-300' : 'text-amber-200'; ?>">
                                        <?php echo $showFullBackupCodes ? 'Full view unlocked' : 'Masked by default'; ?>
                                    </span>
                                </div>

                                <p class="text-xs mfa-note">Used: <?php echo number_format((int)$usedBackupCodesCount); ?> | Unused: <?php echo number_format(max(0, count($backupCodeRows) - (int)$usedBackupCodesCount)); ?> (backup codes are one-time use)</p>

                                <ul class="grid grid-cols-2 gap-2 text-sm">
                                    <?php foreach ($backupCodeRows as $row): ?>
                                        <?php $codeValue = (string)($row['code'] ?? ''); ?>
                                        <?php $isCodeUsed = !empty($row['used']); ?>
                                        <li class="rounded border px-2 py-1 font-mono tracking-wide <?php echo $isCodeUsed ? 'border-red-400/40 bg-red-900/20 text-red-200' : 'border-slate-600 bg-slate-900/70'; ?>">
                                            <div class="flex items-center justify-between gap-2">
                                                <span><?php echo htmlspecialchars($showFullBackupCodes ? $codeValue : maskBackupCode($codeValue), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="text-[10px] font-semibold <?php echo $isCodeUsed ? 'text-red-200' : 'text-emerald-300'; ?>"><?php echo $isCodeUsed ? 'USED' : 'UNUSED'; ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>

                                <p class="text-xs mfa-note">The downloaded TXT file uses this same code set.</p>

                                <?php if (!$showFullBackupCodes): ?>
                                    <form method="POST" class="space-y-2">
                                        <input type="hidden" name="action" value="view_backup_codes_full">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($backupRevealToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <label for="backup_codes_password" class="block text-xs font-medium text-gray-100">Enter current password to reveal full codes</label>
                                        <div class="flex gap-2">
                                            <input id="backup_codes_password" name="backup_codes_password" type="password" class="mfa-input" autocomplete="current-password" placeholder="Current account password" required>
                                            <button type="submit" class="mfa-btn mfa-btn-primary px-4 py-2 whitespace-nowrap">Reveal</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 rounded-lg border border-red-400/50 bg-red-500/15 px-4 py-3 text-sm text-red-100">
                                Backup codes are unavailable right now. Regenerate backup codes to create a fresh set.
                            </div>
                        <?php endif; ?>

                        <div class="mt-4 mfa-panel px-4 py-3 text-sm text-gray-100">
                            <p class="font-semibold inline-flex items-center gap-2"><i class="fa-solid fa-bell"></i>Toast notification guide</p>
                            <ul class="mt-2 list-disc pl-5 space-y-1">
                                <li><strong>Green</strong>: Step completed successfully.</li>
                                <li><strong>Red</strong>: Action failed and needs retry.</li>
                                <li><strong>Blue</strong>: Informational reminder.</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($shouldShowCompletedState): ?>
                    <div class="rounded-xl border border-emerald-400/50 bg-emerald-500/15 p-5">
                        <h3 class="font-semibold text-emerald-100 inline-flex items-center gap-2"><i class="fa-solid fa-circle-check"></i>All MFA Steps Completed</h3>
                        <p class="mt-1 text-sm text-emerald-100/85">Your account is fully secured and backup codes were saved successfully.</p>
                    </div>
                <?php endif; ?>

                <div class="flex items-center justify-between text-sm">
                    <a href="../logout.php" class="text-gray-300 hover:text-white inline-flex items-center gap-2"><i class="fa-solid fa-arrow-right-from-bracket"></i>Sign out</a>
                    <?php if ($isMfaSetupCompleted): ?>
                        <a id="continueToDashboardLink" href="<?php echo htmlspecialchars($continueUrl, ENT_QUOTES, 'UTF-8'); ?>" class="font-semibold text-orange-300 hover:text-orange-200 inline-flex items-center gap-2"><i class="fa-solid fa-gauge-high"></i>Continue to dashboard</a>
                    <?php else: ?>
                        <span id="continueLockedText" class="font-medium text-amber-200">Dashboard is locked until all MFA steps are complete.</span>
                        <a id="continueToDashboardLink" href="<?php echo htmlspecialchars($continueUrl, ENT_QUOTES, 'UTF-8'); ?>" class="hidden font-semibold text-orange-300 hover:text-orange-200 inline-flex items-center gap-2"><i class="fa-solid fa-gauge-high"></i>Continue to dashboard</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            if (!container) {
                return;
            }

            const colorClassMap = {
                success: 'bg-emerald-600',
                error: 'bg-red-600',
                info: 'bg-blue-600'
            };
            const bgClass = colorClassMap[type] || colorClassMap.info;

            const toast = document.createElement('div');
            toast.className = `${bgClass} mfa-toast text-white px-4 py-3 rounded-lg text-sm min-w-[260px]`;
            toast.textContent = message;
            container.appendChild(toast);

            setTimeout(() => {
                toast.remove();
            }, 3200);
        }

        (function showInitialToastMessages() {
            const successMessage = <?php echo json_encode($successMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            const errorMessage = <?php echo json_encode($errorMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            if (successMessage) {
                showToast(successMessage, 'success');
            }
            if (errorMessage) {
                showToast(errorMessage, 'error');
            }
        })();

        (function initRealtimeBackupDownload() {
            const form = document.getElementById('backupCodesForm');
            const button = document.getElementById('downloadBackupCodesBtn');
            if (!form || !button) {
                return;
            }

            const continueLink = document.getElementById('continueToDashboardLink');
            const lockedText = document.getElementById('continueLockedText');

            button.addEventListener('click', async function handleDownload() {
                const originalText = button.textContent;
                button.disabled = true;
                button.textContent = 'Preparing download...';

                try {
                    const formData = new FormData(form);
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (!response.ok) {
                        let message = 'Failed to download backup codes. Please try again.';
                        try {
                            const errorData = await response.json();
                            if (errorData && errorData.message) {
                                message = errorData.message;
                            }
                        } catch (error) {
                        }
                        throw new Error(message);
                    }

                    const blob = await response.blob();
                    const downloadUrl = window.URL.createObjectURL(blob);
                    const downloader = document.createElement('a');
                    const disposition = response.headers.get('Content-Disposition') || '';
                    const match = disposition.match(/filename="([^"]+)"/i);
                    downloader.href = downloadUrl;
                    downloader.download = match ? match[1] : 'findit-mfa-backup-codes.txt';
                    document.body.appendChild(downloader);
                    downloader.click();
                    downloader.remove();
                    window.URL.revokeObjectURL(downloadUrl);

                    button.textContent = 'Backup codes downloaded';
                    button.classList.remove('mfa-btn-soft');
                    button.classList.add('mfa-btn-success');
                    showToast('Backup codes downloaded. Dashboard access unlocked.', 'success');

                    if (lockedText) {
                        lockedText.classList.add('hidden');
                    }
                    if (continueLink) {
                        continueLink.classList.remove('hidden');
                    }
                } catch (error) {
                    showToast(error.message || 'Download failed.', 'error');
                    button.disabled = false;
                    button.textContent = originalText;
                }
            });
        })();
    </script>
</body>
</html>

