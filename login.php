<?php

require_once 'config/db.php';
require_once 'includes/helpers/settings_helper.php';
require_once __DIR__ . '/includes/helpers/mfa_helper.php';
require_once __DIR__ . '/includes/helpers/auth/remember_me_helper.php';
require_once __DIR__ . '/config/env.php';

startSecureSession();
loadEnvFile(__DIR__ . '/.env');
ensureRememberMeTokensTable($pdo);

$loginMaxAttempts = 5;
$loginLockSeconds = 30;

$existingLoginLockUntil = (int)($_SESSION['login_lock_until'] ?? 0);
if ($existingLoginLockUntil > 0 && $existingLoginLockUntil <= time()) {
    unset($_SESSION['login_lock_until'], $_SESSION['login_failed_attempts']);
}

$loginLockUntil = (int)($_SESSION['login_lock_until'] ?? 0);
$loginLockRemaining = max(0, $loginLockUntil - time());
$logoutReason = (string)($_GET['reason'] ?? '');
$skipRememberMeAutoLogin = $logoutReason === 'logged_out';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!$skipRememberMeAutoLogin && empty($_SESSION['user_id'])) {
        attemptRememberMeLogin($pdo);
    }

    if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
        $dash = $_SESSION['role'] === 'admin' ? 'admin/admin_dashboard.php' : 'claimant/claimant_dashboard.php';
        header('Location: ' . $dash);
        exit;
    }
}

$rememberedLoginEmail = getRememberedLoginEmail();
$rememberCheckedByDefault = $rememberedLoginEmail !== '';

// Get system settings
$settings = getSystemSettings();

$siteLogoSrc = '';
if (!empty($settings['site_logo']) && file_exists($settings['site_logo'])) {
    $siteLogoSrc = (string)$settings['site_logo'];
}

$signInLoaderHtml = '<span class="btn-loader-dot mr-2" aria-hidden="true"></span>';
if ($siteLogoSrc !== '') {
    $signInLoaderHtml = '<img src="' . htmlspecialchars($siteLogoSrc, ENT_QUOTES, 'UTF-8') . '" alt="Loading" class="btn-logo-spin mr-2">';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    $activeLockUntil = (int)($_SESSION['login_lock_until'] ?? 0);
    $activeLockRemaining = max(0, $activeLockUntil - time());
    if ($activeLockRemaining > 0) {
        echo json_encode([
            'success' => false,
            'locked' => true,
            'lock_seconds_remaining' => $activeLockRemaining,
            'message' => 'Too many failed attempts. Login is locked for 30 seconds.'
        ]);
        exit;
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberRequested = rememberMeRequestedFromPost();

    if (!$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT user_id, full_name, email, password_hash, role, mfa_secret, mfa_enabled FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        if ($rememberRequested) {
            storeRememberedLoginEmail($email);
        } else {
            clearRememberedLoginEmail();
        }

        unset($_SESSION['login_failed_attempts'], $_SESSION['login_lock_until']);
        $isMfaEnabled = (int)($user['mfa_enabled'] ?? 0) === 1 && !empty($user['mfa_secret']);

        if ($isMfaEnabled) {
            // Regenerate before creating a temporary MFA challenge session.
            session_regenerate_id(true);
            unset($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['email'], $_SESSION['role']);
            $_SESSION['remember_after_mfa'] = $rememberRequested;
            $_SESSION['mfa_pending_user'] = [
                'user_id' => (int)$user['user_id'],
                'role' => (string)$user['role'],
                'expires_at' => time() + 300,
            ];

            echo json_encode([
                'success' => true,
                'message' => 'Enter your authentication code to continue.',
                'mfa_required' => true,
                'redirect' => 'mfa/verify-mfa.php'
            ]);
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            unset($_SESSION['mfa_pending_user']);
            $_SESSION['force_mfa_setup'] = true;

            if ($rememberRequested) {
                createRememberMeToken($pdo, (int)$user['user_id']);
            } else {
                revokeRememberMeFromCurrentDevice($pdo);
            }

            // MFA is required for all users before dashboard access.
            echo json_encode([
                'success' => true,
                'message' => 'Set up Microsoft Authenticator to continue.',
                'role' => $user['role'],
                'mfa_setup_required' => true,
                'redirect' => 'mfa/setup-mfa.php?enroll=1'
            ]);
        }
    } else {
        $failedAttempts = (int)($_SESSION['login_failed_attempts'] ?? 0) + 1;
        $_SESSION['login_failed_attempts'] = $failedAttempts;
        unset($_SESSION['remember_after_mfa']);

        if ($failedAttempts >= $loginMaxAttempts) {
            $_SESSION['login_lock_until'] = time() + $loginLockSeconds;
            unset($_SESSION['login_failed_attempts']);

            echo json_encode([
                'success' => false,
                'locked' => true,
                'lock_seconds_remaining' => $loginLockSeconds,
                'message' => 'Too many failed attempts. Login is locked for 30 seconds.'
            ]);
            exit;
        }

        $attemptsRemaining = max(0, $loginMaxAttempts - $failedAttempts);
        echo json_encode([
            'success' => false,
            'locked' => false,
            'attempts_remaining' => $attemptsRemaining,
            'message' => 'Invalid email or password. Attempts left before lock: ' . $attemptsRemaining
        ]);
    }
    exit;
}
?>
<?php
$pageTitle = "Login";
$additionalStyles = '
<style>
    @import url("https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap");

    :root {
        --auth-black: #0f0f10;
        --auth-ink: #18181b;
        --auth-soft-ink: #26262b;
        --auth-orange: #ff7a1a;
        --auth-orange-deep: #dd6000;
        --auth-orange-soft: #ffb37a;
        --auth-white: #ffffff;
        --auth-gray-100: #f3f4f6;
        --auth-gray-300: #d1d5db;
        --auth-gray-500: #6b7280;
        --card-grad-a: #2a2a2e;
        --card-grad-b: #141417;
        --card-grad-c: #1d1d21;
        --text-strong: #f9fafb;
        --text-soft: #b6bfcb;
    }

    body {
        font-family: "Manrope", "Segoe UI", sans-serif;
        background:
            radial-gradient(circle at 8% 12%, rgba(255, 122, 26, 0.18), transparent 36%),
            radial-gradient(circle at 88% 76%, rgba(255, 122, 26, 0.14), transparent 38%),
            linear-gradient(140deg, #0b0b0d 0%, #141417 46%, #1a1a1f 100%);
        color: var(--auth-white);
    }

    .split-container {
        padding-top: 68px;
    }

    .auth-shell {
        min-height: 100vh;
    }

    .left-panel {
        overflow: hidden;
    }

    .left-panel::before {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(6, 6, 7, 0.75), rgba(255, 122, 26, 0.18));
        z-index: 0;
    }

    .hero-content {
        position: relative;
        z-index: 1;
        width: min(590px, 92%);
    }

    .hero-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.74rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        padding: 0.42rem 0.7rem;
        border-radius: 999px;
        background: rgba(255, 122, 26, 0.2);
        color: #ffd7b8;
        border: 1px solid rgba(255, 179, 122, 0.5);
        margin-bottom: 1.1rem;
        font-weight: 700;
    }

    .hero-title {
        font-family: "Sora", "Segoe UI", sans-serif;
        font-size: clamp(2rem, 3.5vw, 3.1rem);
        font-weight: 800;
        line-height: 1.1;
        letter-spacing: -0.02em;
        margin-bottom: 0.8rem;
    }

    .hero-copy {
        color: rgba(255, 255, 255, 0.82);
        font-size: 1.02rem;
        line-height: 1.65;
        max-width: 560px;
    }

    .feature-row {
        margin-top: 1.6rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.58rem;
    }

    .feature-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.42rem;
        border-radius: 999px;
        padding: 0.45rem 0.72rem;
        font-size: 0.76rem;
        font-weight: 700;
        color: #ffe7d2;
        border: 1px solid rgba(255, 179, 122, 0.45);
        background: rgba(16, 16, 17, 0.62);
    }

    .hero-svg-wrap {
        margin-top: 1.7rem;
        width: 210px;
        height: 58px;
    }

    .auth-card {
        width: 100%;
        max-width: 460px;
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.03) 20%, var(--card-grad-a) 42%, var(--card-grad-b) 100%);
        color: var(--text-strong);
        border-radius: 1.1rem;
        border: 1px solid rgba(255, 255, 255, 0.16);
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.38);
        padding: 2rem;
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(6px);
    }

    .auth-card::before {
        content: "";
        position: absolute;
        top: -40%;
        right: -10%;
        width: 220px;
        height: 220px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.17), transparent 68%);
        pointer-events: none;
    }

    .form-kicker {
        color: #ffbe8f;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        font-weight: 800;
        font-size: 0.68rem;
        margin-bottom: 0.45rem;
    }

    .auth-title {
        font-family: "Sora", "Segoe UI", sans-serif;
        font-size: 1.8rem;
        letter-spacing: -0.01em;
        font-weight: 700;
        color: var(--text-strong);
        margin-bottom: 0.4rem;
    }

    .muted-copy {
        font-size: 0.92rem;
        line-height: 1.55;
        color: var(--text-soft);
    }

    .input-icon-wrapper {
        position: relative;
    }

    .input-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #bec6d1;
        font-size: 0.92rem;
        transition: color 0.2s ease;
    }

    .input-with-icon {
        width: 100%;
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 0.8rem;
        padding: 0.78rem 0.9rem 0.78rem 2.5rem;
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.08), rgba(17, 17, 18, 0.68));
        transition: all 0.22s ease;
        font-size: 0.95rem;
        color: #f9fafb;
    }

    .input-with-icon::placeholder {
        color: rgba(233, 236, 242, 0.58);
    }

    .input-with-icon:focus {
        outline: none;
        border-color: #ff9d52;
        box-shadow: 0 0 0 3px rgba(255, 122, 26, 0.26);
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.11), rgba(24, 24, 27, 0.76));
    }

    .input-with-icon:focus + .input-icon,
    .input-icon-wrapper:focus-within .input-icon {
        color: var(--auth-orange);
    }

    .auth-checkbox {
        accent-color: var(--auth-orange);
    }

    .auth-link {
        color: #ffc89b;
        font-weight: 700;
        transition: color 0.2s ease;
    }

    .auth-link:hover {
        color: #ffe7d2;
    }

    .auth-button {
        width: 100%;
        border: none;
        border-radius: 0.82rem;
        padding: 0.85rem 1rem;
        background: linear-gradient(135deg, var(--auth-orange), #ff9f4a);
        color: #ffffff;
        font-weight: 800;
        font-size: 0.95rem;
        letter-spacing: 0.01em;
        box-shadow: 0 12px 24px rgba(255, 122, 26, 0.4);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .auth-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 28px rgba(255, 122, 26, 0.42);
    }

    .auth-button:disabled {
        opacity: 0.8;
        transform: none;
    }

    .btn-logo-spin {
        width: 28px;
        height: 28px;
        border-radius: 999px;
        object-fit: cover;
        background: #ffffff;
        border: 2px solid rgba(255, 255, 255, 0.96);
        padding: 2px;
        animation: btnLogoSpin 0.95s linear infinite;
    }

    .btn-loader-dot {
        width: 24px;
        height: 24px;
        border-radius: 999px;
        border: 3px solid rgba(255, 255, 255, 0.28);
        border-top-color: rgba(255, 255, 255, 0.92);
        display: inline-block;
        animation: btnLogoSpin 0.95s linear infinite;
    }

    @keyframes btnLogoSpin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .auth-card .text-gray-600 {
        color: var(--text-soft) !important;
    }

    .login-lock-overlay {
        position: absolute;
        inset: 0;
        z-index: 30;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.25rem;
        background: linear-gradient(160deg, rgba(8, 8, 10, 0.82), rgba(39, 17, 3, 0.88));
        backdrop-filter: blur(3px);
    }

    .lock-overlay-panel {
        width: min(280px, 100%);
        border-radius: 1rem;
        border: 1px solid rgba(255, 179, 122, 0.55);
        background: linear-gradient(150deg, rgba(255, 255, 255, 0.11), rgba(20, 10, 3, 0.72));
        box-shadow: 0 18px 36px rgba(0, 0, 0, 0.35);
        padding: 1rem 1rem 1.1rem;
        text-align: center;
    }

    .lock-overlay-title {
        font-family: "Sora", "Segoe UI", sans-serif;
        font-size: 0.9rem;
        color: #ffe8d6;
        font-weight: 700;
        letter-spacing: -0.01em;
        margin-bottom: 0.85rem;
    }

    .lock-circle-wrap {
        margin: 0 auto;
        width: 112px;
        height: 112px;
        position: relative;
    }

    .lock-circle-svg {
        width: 112px;
        height: 112px;
        transform: rotate(-90deg);
    }

    .lock-circle-track {
        fill: none;
        stroke: rgba(255, 255, 255, 0.18);
        stroke-width: 8;
    }

    .lock-circle-progress {
        fill: none;
        stroke: #ff9f4a;
        stroke-width: 8;
        stroke-linecap: round;
        transition: stroke-dashoffset 0.9s linear;
    }

    .lock-circle-value {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: "Sora", "Segoe UI", sans-serif;
        font-size: 1.2rem;
        font-weight: 800;
        color: #ffe5d1;
    }

    .lock-overlay-note {
        margin-top: 0.9rem;
        color: #ffd9be;
        font-size: 0.8rem;
        line-height: 1.45;
    }

    .login-form-disabled {
        opacity: 0.72;
    }

    .login-form-disabled .input-with-icon,
    .login-form-disabled .auth-button,
    .login-form-disabled .auth-checkbox {
        cursor: not-allowed;
    }

    @media (max-width: 960px) {
        .split-container {
            flex-direction: column;
        }

        .left-panel,
        .right-panel {
            width: 100% !important;
        }

        .left-panel {
            min-height: 46vh;
            padding: 2.2rem 1.2rem;
        }

        .right-panel {
            min-height: 54vh;
            padding: 1.2rem;
        }

        .auth-card {
            max-width: 620px;
            padding: 1.45rem;
        }

        .hero-svg-wrap {
            width: 165px;
            margin-top: 1rem;
        }
    }
</style>';
include 'includes/layout/header.php';
?>

<?php
$navShowHome = true;
$navShowLogin = false;
$navShowRegister = true;
include __DIR__ . '/includes/components/navigation.php';
?>

    <div class="split-container auth-shell flex min-h-screen">
        <!-- Left Panel - 60% width -->
        <div class="left-panel flex flex-col items-center justify-center text-white relative" style="width: 60%; <?php 
            if (!empty($settings['login_image']) && file_exists($settings['login_image'])) {
                echo 'background: linear-gradient(135deg, rgba(8, 8, 9, 0.85), rgba(255, 122, 26, 0.45)), url(\'' . htmlspecialchars($settings['login_image']) . '\'); background-size: cover; background-position: center;';
            } else {
                echo 'background: linear-gradient(135deg, #0f0f10, #1f1f24 60%, #3b1d06);';
            }
        ?>">
            <div class="hero-content">
                <span class="hero-kicker">Trusted Recovery Network</span>
                <h1 class="hero-title"><?php echo htmlspecialchars(explode(' -', $settings['site_name'] ?? 'FindIt')[0]); ?></h1>
                <p class="hero-copy"><?php echo htmlspecialchars($settings['site_description'] ?? 'Lost & Found Community Hub'); ?> Built for fast reporting, smart matching, and secure handovers.</p>

                <div class="feature-row">
                    <span class="feature-chip">Report fast</span>
                    <span class="feature-chip">Private chat</span>
                    <span class="feature-chip">Verified returns</span>
                </div>

                <div class="hero-svg-wrap" aria-hidden="true">
                    <svg width="100%" height="100%" viewBox="0 0 210 58" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 44C34 44 34 14 66 14C98 14 98 44 130 44C162 44 162 14 194 14" stroke="#FFB37A" stroke-width="3" stroke-linecap="round"/>
                        <circle cx="34" cy="44" r="5" fill="#FF7A1A"/>
                        <circle cx="98" cy="14" r="5" fill="#FFFFFF"/>
                        <circle cx="162" cy="44" r="5" fill="#FF7A1A"/>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Right Panel - 40% width -->
        <div class="right-panel flex items-center justify-center p-8" style="width: 40%;">
            <div class="w-full max-w-md">
                <div class="auth-card">
                    <!-- Form Header -->
                    <div class="text-center mb-8">
                        <p class="form-kicker">Member Access</p>
                        <h2 class="auth-title">Login to your account</h2>
                        <p class="muted-copy">Welcome back. Continue with your email and password to access your dashboard.</p>
                    </div>

                    <div id="loginLockOverlay" class="login-lock-overlay hidden" role="status" aria-live="polite">
                        <div class="lock-overlay-panel">
                            <p class="lock-overlay-title">Too many failed attempts</p>
                            <div class="lock-circle-wrap" aria-hidden="true">
                                <svg class="lock-circle-svg" viewBox="0 0 120 120">
                                    <circle class="lock-circle-track" cx="60" cy="60" r="44"></circle>
                                    <circle id="lockCircleProgress" class="lock-circle-progress" cx="60" cy="60" r="44"></circle>
                                </svg>
                                <div id="lockCircleValue" class="lock-circle-value">30s</div>
                            </div>
                            <p class="lock-overlay-note">Form is temporarily disabled. Please wait for the countdown to finish.</p>
                        </div>
                    </div>
                    
                    <!-- Login Form -->
                    <form class="space-y-6" id="loginForm">
                        <!-- Email Field -->
                        <div class="input-icon-wrapper">
                            <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M4 6H20V18H4V6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                <path d="M4 7L12 13L20 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                required
                                class="input-with-icon"
                                value="<?php echo htmlspecialchars($rememberedLoginEmail, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Enter your email"
                            >
                        </div>
                        
                        <!-- Password Field -->
                        <div class="input-icon-wrapper">
                            <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M7 11V8A5 5 0 0 1 17 8V11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                <rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/>
                            </svg>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                required
                                class="input-with-icon"
                                placeholder="Enter your password"
                            >
                        </div>
                        
                        <!-- Remember Me & Forgot Password -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <input 
                                    type="checkbox" 
                                    id="remember" 
                                    name="remember"
                                    <?php echo $rememberCheckedByDefault ? 'checked' : ''; ?>
                                    class="h-4 w-4 auth-checkbox border-gray-300 rounded"
                                >
                                <label for="remember" class="ml-2 block text-sm text-gray-600">
                                    Remember me
                                </label>
                            </div>
                            <a href="auth/forgot-password.php" class="text-sm auth-link">
                                Forgot password?
                            </a>
                        </div>
                        
                        <!-- Login Button -->
                        <button 
                            type="submit"
                            id="loginBtn"
                            class="auth-button flex items-center justify-center"
                        >
                            Sign In
                        </button>
                    </form>
                    
                    <!-- Sign Up Link -->
                    <div class="text-center mt-6">
                        <p class="text-gray-600 text-sm">
                            Don't have an account? 
                            <a href="register.php" class="auth-link">
                                Sign up
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        const initialLoginLockSeconds = <?php echo json_encode((int)$loginLockRemaining); ?>;
        let loginLockSeconds = 0;
        let loginLockTotalSeconds = 30;
        let loginLockInterval = null;
        const lockCircleRadius = 44;
        const lockCircleCircumference = 2 * Math.PI * lockCircleRadius;

        function formatLockCountdown(seconds) {
            const total = Math.max(0, Number(seconds || 0));
            const mins = Math.floor(total / 60).toString().padStart(2, '0');
            const secs = Math.floor(total % 60).toString().padStart(2, '0');
            return mins + ':' + secs;
        }

        function setLoginFormDisabled(isDisabled) {
            const form = document.getElementById('loginForm');
            const controls = form.querySelectorAll('input, button');
            controls.forEach(control => {
                control.disabled = isDisabled;
            });
            form.classList.toggle('login-form-disabled', isDisabled);
        }

        function stopLoginLockCountdown() {
            if (loginLockInterval !== null) {
                window.clearInterval(loginLockInterval);
                loginLockInterval = null;
            }
        }

        function unlockLoginForm() {
            loginLockSeconds = 0;
            loginLockTotalSeconds = 30;
            stopLoginLockCountdown();
            const lockOverlay = document.getElementById('loginLockOverlay');
            if (lockOverlay) {
                lockOverlay.classList.add('hidden');
            }
            setLoginFormDisabled(false);
        }

        function updateLockOverlayProgress() {
            const lockOverlay = document.getElementById('loginLockOverlay');
            const valueEl = document.getElementById('lockCircleValue');
            const progressEl = document.getElementById('lockCircleProgress');

            if (!lockOverlay || !valueEl || !progressEl) {
                return;
            }

            const safeTotal = Math.max(1, loginLockTotalSeconds);
            const ratio = Math.max(0, Math.min(1, loginLockSeconds / safeTotal));
            const dashOffset = lockCircleCircumference * (1 - ratio);

            progressEl.style.strokeDasharray = String(lockCircleCircumference);
            progressEl.style.strokeDashoffset = String(dashOffset);
            valueEl.textContent = Math.max(0, loginLockSeconds) + 's';
        }

        function startLoginLockCountdown(seconds) {
            loginLockSeconds = Math.max(0, parseInt(seconds, 10) || 0);
            loginLockTotalSeconds = loginLockSeconds > 0 ? loginLockSeconds : 30;
            const lockOverlay = document.getElementById('loginLockOverlay');

            if (loginLockSeconds <= 0 || !lockOverlay) {
                unlockLoginForm();
                return;
            }

            setLoginFormDisabled(true);
            lockOverlay.classList.remove('hidden');
            updateLockOverlayProgress();

            stopLoginLockCountdown();
            loginLockInterval = window.setInterval(function() {
                loginLockSeconds -= 1;
                if (loginLockSeconds <= 0) {
                    unlockLoginForm();
                    return;
                }

                updateLockOverlayProgress();
            }, 1000);
        }

        (function showRedirectReason() {
            const params = new URLSearchParams(window.location.search);
            const reason = params.get('reason');
            if (!reason) {
                return;
            }

            const reasonMessages = {
                auth_required: 'Please sign in to access that page.',
                mfa_required: 'Please sign in first to start MFA verification.',
                mfa_expired: 'Your MFA session expired. Please sign in again.',
                logged_out: 'You have been logged out successfully.',
                password_reset_success: 'Password reset complete. Please sign in with your new password.'
            };

            if (reasonMessages[reason]) {
                showToast(reasonMessages[reason], false);
            }

            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        })();

        document.getElementById('loginForm').addEventListener('submit', function(event) {
            event.preventDefault();

            if (loginLockSeconds > 0) {
                showToast('Login is temporarily locked. Try again in ' + formatLockCountdown(loginLockSeconds) + '.', false);
                return;
            }

            const loginBtn = document.getElementById('loginBtn');
            const originalBtnContent = loginBtn.innerHTML;
            const signingInIconHtml = <?php echo json_encode($signInLoaderHtml, JSON_UNESCAPED_SLASHES); ?>;
            
            loginBtn.disabled = true;
            loginBtn.innerHTML = signingInIconHtml + 'Signing in...';
            showLoading('Signing in...');
            
            const formData = new FormData(this);
            fetch('login.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, true);

                    // If MFA is required, continue to challenge screen first.
                    if (data.mfa_required) {
                        setTimeout(() => {
                            window.location.href = data.redirect || 'mfa/verify-mfa.php';
                        }, 600);
                        return;
                    }

                    if (data.mfa_setup_required) {
                        setTimeout(() => {
                            window.location.href = data.redirect || 'mfa/setup-mfa.php?enroll=1';
                        }, 600);
                        return;
                    }

                    // Role-based redirection
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                } else {
                    if (data.locked) {
                        startLoginLockCountdown(data.lock_seconds_remaining || 30);
                    }
                    showToast(data.message, false);
                    hideLoading();
                }
            })
            .catch(() => {
                showToast('Login failed. Please try again.', false);
                hideLoading();
            })
            .finally(() => {
                loginBtn.innerHTML = originalBtnContent;

                if (loginLockSeconds > 0) {
                    setLoginFormDisabled(true);
                } else {
                    loginBtn.disabled = false;
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            if (initialLoginLockSeconds > 0) {
                startLoginLockCountdown(initialLoginLockSeconds);
            }
        });
        
    </script>
<?php include 'includes/layout/footer.php'; ?></body>
</html>

