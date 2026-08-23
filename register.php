<?php
// backend_register.php - Handles user registration
require_once 'config/db.php';
require_once 'includes/helpers/settings_helper.php';
require_once 'includes/helpers/captcha_helper.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['register_csrf_token'])) {
    $_SESSION['register_csrf_token'] = bin2hex(random_bytes(32));
}

// Get system settings
$settings = getSystemSettings();

function sendRegisterResponse(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

function getRegisterClientIp(): string
{
    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $remoteAddr !== '' ? $remoteAddr : 'unknown';
}

function checkRegisterRateLimit(string $ip, int $limit = 8, int $windowSeconds = 900): array
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'findit_rate_limits';
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }

    $file = $directory . DIRECTORY_SEPARATOR . 'register_' . sha1($ip) . '.json';
    $now = time();
    $state = [
        'window_start' => $now,
        'count' => 0
    ];

    if (is_file($file)) {
        $raw = file_get_contents($file);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state['window_start'] = (int) ($decoded['window_start'] ?? $now);
                $state['count'] = (int) ($decoded['count'] ?? 0);
            }
        }
    }

    if (($now - $state['window_start']) >= $windowSeconds) {
        $state['window_start'] = $now;
        $state['count'] = 0;
    }

    $state['count']++;
    @file_put_contents($file, json_encode($state), LOCK_EX);

    $retryAfter = max(0, $windowSeconds - ($now - $state['window_start']));
    return [($state['count'] > $limit), $retryAfter];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    [$isLimited, $retryAfter] = checkRegisterRateLimit(getRegisterClientIp());
    if ($isLimited) {
        $minutes = max(1, (int) ceil($retryAfter / 60));
        sendRegisterResponse(false, 'Too many registration attempts. Please try again in ' . $minutes . ' minute(s).');
    }

    $honeypot = trim($_POST['website'] ?? '');
    if ($honeypot !== '') {
        sendRegisterResponse(false, 'Registration blocked due to suspicious activity.', ['refreshCaptcha' => true]);
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['register_csrf_token'], (string) $csrfToken)) {
        sendRegisterResponse(false, 'Invalid session token. Please refresh the page and try again.', ['refreshCaptcha' => true]);
    }

    $captchaInput = $_POST['captcha'] ?? '';
    $captchaResult = captchaVerifyInput((string) $captchaInput);
    if (!($captchaResult['ok'] ?? false)) {
        sendRegisterResponse(false, (string) ($captchaResult['message'] ?? 'Captcha validation failed.'), ['refreshCaptcha' => true]);
    }

    $fullName = trim($_POST['fullName'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    // Basic validation
    if (!$fullName || !$email || !$phone || !$password || !$confirmPassword) {
        sendRegisterResponse(false, 'Please fill in all fields.', ['refreshCaptcha' => true]);
    }
    if (!preg_match("/^[A-Za-z][A-Za-z\\s.'-]{1,99}$/", $fullName)) {
        sendRegisterResponse(false, 'Full name must be 2-100 characters and contain only letters, spaces, apostrophes, periods, or hyphens.', ['refreshCaptcha' => true]);
    }
    if (strlen($email) > 254) {
        sendRegisterResponse(false, 'Email address is too long.', ['refreshCaptcha' => true]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendRegisterResponse(false, 'Invalid email address.', ['refreshCaptcha' => true]);
    }
    if (!preg_match('/^9[0-9]{9}$/', $phone)) {
        sendRegisterResponse(false, 'Phone number must be 10 digits starting with 9.', ['refreshCaptcha' => true]);
    }
    if ($password !== $confirmPassword) {
        sendRegisterResponse(false, 'Passwords do not match.', ['refreshCaptcha' => true]);
    }
    if (
        strlen($password) < 8
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        sendRegisterResponse(false, 'Password must be at least 8 characters and include uppercase, lowercase, number, and symbol.', ['refreshCaptcha' => true]);
    }

    // Check if email already exists
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendRegisterResponse(false, 'Email already registered.', ['refreshCaptcha' => true]);
    }

    // Check if phone already exists
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        sendRegisterResponse(false, 'Phone number already registered.', ['refreshCaptcha' => true]);
    }

    // Insert user
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    try {
        $stmt->execute([$fullName, $email, $phone, $passwordHash, 'claimant']);
        $_SESSION['register_csrf_token'] = bin2hex(random_bytes(32));
        sendRegisterResponse(true, 'Registration successful!');
    } catch (PDOException $e) {
        error_log('Registration failed: ' . $e->getMessage());
        sendRegisterResponse(false, 'Registration failed. Please try again later.', ['refreshCaptcha' => true]);
    }
}
 
$pageTitle = "Create Account";
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
        background: linear-gradient(135deg, rgba(6, 6, 7, 0.74), rgba(255, 122, 26, 0.18));
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
        font-size: clamp(2rem, 3.2vw, 3rem);
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

    .feature-list {
        margin-top: 1.4rem;
        display: grid;
        gap: 0.78rem;
    }

    .feature-item {
        display: flex;
        align-items: flex-start;
        gap: 0.65rem;
        color: #f9fafb;
        font-size: 0.93rem;
    }

    .feature-item i {
        color: #ffb37a;
        margin-top: 0.18rem;
    }

    .hero-svg-wrap {
        margin-top: 1.5rem;
        width: 220px;
        height: 58px;
    }

    .register-card {
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.03) 20%, var(--card-grad-a) 42%, var(--card-grad-b) 100%);
        color: var(--text-strong);
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 1.2rem;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.38);
        padding: 2rem;
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(6px);
    }

    .register-card::before {
        content: "";
        position: absolute;
        top: -38%;
        right: -8%;
        width: 240px;
        height: 240px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.17), transparent 68%);
        pointer-events: none;
    }

    .section-kicker {
        font-size: 0.72rem;
        letter-spacing: 0.11em;
        text-transform: uppercase;
        color: #ffbe8f;
        font-weight: 800;
    }

    .hero-icon-wrap {
        width: 68px;
        height: 68px;
        border-radius: 9999px;
        background: radial-gradient(circle at 35% 35%, #ffb37a, #a63f00);
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .security-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .security-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: rgba(255, 255, 255, 0.08);
        color: #ffd5b3;
        border: 1px solid rgba(255, 179, 122, 0.45);
        border-radius: 9999px;
        padding: 0.35rem 0.6rem;
        font-size: 0.72rem;
        font-weight: 700;
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .field-label {
        display: inline-block;
        font-size: 0.78rem;
        font-weight: 700;
        color: #e5e7eb;
        margin-bottom: 0.35rem;
    }

    .field-wrap {
        position: relative;
    }

    .field-wrap .input-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #bec6d1;
        font-size: 0.92rem;
        transition: color 0.2s ease;
    }

    .modern-input {
        width: 100%;
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 0.75rem;
        padding: 0.75rem 0.9rem 0.75rem 2.5rem;
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.08), rgba(17, 17, 18, 0.68));
        color: #f9fafb;
        transition: all 0.22s ease;
    }

    .modern-input::placeholder {
        color: rgba(233, 236, 242, 0.58);
    }

    .field-wrap:focus-within .input-icon {
        color: var(--auth-orange);
    }

    .modern-input:focus {
        border-color: #ff9d52;
        box-shadow: 0 0 0 3px rgba(255, 122, 26, 0.26);
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.11), rgba(24, 24, 27, 0.76));
        outline: none;
    }

    .field-helper {
        font-size: 0.73rem;
        color: var(--text-soft);
        margin-top: 0.35rem;
    }

    .wide-field {
        grid-column: span 1;
    }

    .captcha-image {
        width: 190px;
        height: 60px;
        border: 1px solid rgba(255, 255, 255, 0.25);
        border-radius: 0.6rem;
        background: #ffffff;
    }

    .captcha-refresh {
        min-width: 42px;
        height: 42px;
        border: 1px solid rgba(255, 255, 255, 0.24);
        border-radius: 0.65rem;
        color: #ffd2ad;
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.08), rgba(17, 17, 18, 0.68));
        transition: all 0.2s ease;
    }

    .captcha-refresh:hover {
        color: #ffe7d2;
        border-color: rgba(255, 179, 122, 0.56);
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.12), rgba(24, 24, 27, 0.76));
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

    .auth-link {
        color: #ffc89b;
        font-weight: 700;
        transition: color 0.2s ease;
    }

    .auth-link:hover {
        color: #ffe7d2;
    }

    .register-card .text-gray-800 {
        color: var(--text-strong) !important;
    }

    .register-card .text-gray-600 {
        color: var(--text-soft) !important;
    }

    @media (min-width: 768px) {
        .form-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem 1rem;
        }

        .wide-field {
            grid-column: span 2;
        }
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
            min-height: 44vh;
            padding: 2.2rem 1.2rem;
        }

        .right-panel {
            padding: 1.2rem;
        }

        .register-card {
            padding: 1.3rem;
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
$navShowLogin = true;
$navShowRegister = false;
include __DIR__ . '/includes/components/navigation.php';
?>

    <div class="split-container auth-shell flex min-h-screen">
        <!-- Left Panel - 50% width -->
        <div class="left-panel flex items-center justify-center p-8 relative overflow-hidden" style="width: 50%; <?php 
            if (!empty($settings['register_image']) && file_exists($settings['register_image'])) {
                echo 'background: linear-gradient(135deg, rgba(8, 8, 9, 0.85), rgba(255, 122, 26, 0.45)), url(\'' . htmlspecialchars($settings['register_image']) . '\'); background-size: cover; background-position: center;';
            } else {
                echo 'background: linear-gradient(135deg, #0f0f10, #1f1f24 60%, #3b1d06);';
            }
        ?>">
            <!-- Background decoration -->
            <div class="absolute top-10 left-10 w-32 h-32 bg-white bg-opacity-10 rounded-full"></div>
            <div class="absolute bottom-20 right-20 w-24 h-24 bg-white bg-opacity-10 rounded-full"></div>
            <div class="absolute top-1/2 right-10 w-16 h-16 bg-white bg-opacity-10 rounded-full"></div>
            
            <div class="hero-content text-white z-10">
                <span class="hero-kicker"><i class="fas fa-bolt"></i> Join The Network</span>
                <h1 class="hero-title">Welcome to <?php echo htmlspecialchars(explode(' -', $settings['site_name'] ?? 'FindIt')[0]); ?></h1>
                <p class="hero-copy"><?php echo htmlspecialchars($settings['site_tagline'] ?? 'Join the community and never lose what matters'); ?></p>

                <div class="feature-list">
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <p>Report lost items and discover smart found-item matches quickly.</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <p>Connect with finders in a secure conversation channel.</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <p>Receive instant updates as soon as potential matches appear.</p>
                    </div>
                </div>

                <div class="hero-svg-wrap" aria-hidden="true">
                    <svg width="100%" height="100%" viewBox="0 0 220 58" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 44C36 44 36 12 70 12C104 12 104 44 138 44C172 44 172 12 206 12" stroke="#FFB37A" stroke-width="3" stroke-linecap="round"/>
                        <circle cx="36" cy="44" r="5" fill="#FF7A1A"/>
                        <circle cx="104" cy="12" r="5" fill="#FFFFFF"/>
                        <circle cx="172" cy="44" r="5" fill="#FF7A1A"/>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Right Panel - 50% width -->
        <div class="right-panel flex items-center justify-center p-8" style="width: 50%;">
            <div class="w-full max-w-3xl">
                <div class="register-card">
                    <div class="mb-8">
                        <div class="flex items-center justify-between gap-4 flex-wrap">
                            <div>
                                <p class="section-kicker mb-2">Secure Registration</p>
                                <h2 class="text-3xl font-bold text-gray-800 leading-tight">Create your account</h2>
                                <p class="text-gray-600 mt-2">Complete the fields below. We enforce strong credentials and captcha verification for account safety.</p>
                            </div>
                            <div class="hero-icon-wrap" aria-hidden="true">
                                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 2L19 5V11C19 15.42 16.12 19.45 12 21C7.88 19.45 5 15.42 5 11V5L12 2Z" stroke="#311104" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M9 12L11 14L15 10" stroke="#311104" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                        </div>
                        <div class="security-strip mt-4">
                            <span class="security-pill"><i class="fas fa-shield-alt"></i> CSRF protected</span>
                            <span class="security-pill"><i class="fas fa-robot"></i> Bot filtering</span>
                            <span class="security-pill"><i class="fas fa-key"></i> Strong password policy</span>
                        </div>
                    </div>

                    <form id="registrationForm" class="space-y-6" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['register_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="hidden" aria-hidden="true">
                            <label for="website">Website</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="form-grid">
                            <div>
                                <label for="fullName" class="field-label">Full Name</label>
                                <div class="field-wrap">
                                    <i class="fas fa-user input-icon"></i>
                                    <input
                                        type="text"
                                        id="fullName"
                                        name="fullName"
                                        required
                                        minlength="2"
                                        maxlength="100"
                                        pattern="^[A-Za-z][A-Za-z\s.'-]{1,99}$"
                                        autocomplete="name"
                                        class="modern-input"
                                        placeholder="Juan Dela Cruz"
                                        title="Use 2-100 letters, spaces, apostrophes, periods, or hyphens."
                                    >
                                </div>
                            </div>

                            <div>
                                <label for="email" class="field-label">Email Address</label>
                                <div class="field-wrap">
                                    <i class="fas fa-envelope input-icon"></i>
                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        required
                                        maxlength="254"
                                        autocomplete="email"
                                        class="modern-input"
                                        placeholder="you@example.com"
                                    >
                                </div>
                            </div>

                            <div>
                                <label for="phone" class="field-label">Mobile Number</label>
                                <div class="field-wrap">
                                    <i class="fas fa-phone input-icon"></i>
                                    <input
                                        type="tel"
                                        id="phone"
                                        name="phone"
                                        required
                                        inputmode="numeric"
                                        minlength="10"
                                        maxlength="10"
                                        pattern="9[0-9]{9}"
                                        autocomplete="tel"
                                        class="modern-input"
                                        placeholder="9XXXXXXXXX"
                                        title="Phone must be exactly 10 digits and start with 9."
                                    >
                                </div>
                                <p class="field-helper">Use 10 digits starting with 9.</p>
                            </div>

                            <div>
                                <label for="password" class="field-label">Password</label>
                                <div class="field-wrap">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input
                                        type="password"
                                        id="password"
                                        name="password"
                                        required
                                        minlength="8"
                                        maxlength="128"
                                        autocomplete="new-password"
                                        class="modern-input"
                                        placeholder="Create a strong password"
                                        title="At least 8 characters with uppercase, lowercase, number, and symbol."
                                    >
                                </div>
                                <p class="field-helper">At least 8 chars with uppercase, lowercase, number, and symbol.</p>
                            </div>

                            <div class="wide-field">
                                <label for="confirmPassword" class="field-label">Confirm Password</label>
                                <div class="field-wrap">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input
                                        type="password"
                                        id="confirmPassword"
                                        name="confirmPassword"
                                        required
                                        minlength="8"
                                        maxlength="128"
                                        autocomplete="new-password"
                                        class="modern-input"
                                        placeholder="Re-enter your password"
                                    >
                                </div>
                            </div>

                            <div class="wide-field">
                                <label for="captcha" class="field-label">Security Captcha</label>
                                <div class="flex items-center gap-2 mb-2 flex-wrap">
                                    <img
                                        id="captchaImage"
                                        src="includes/helpers/captcha_helper.php?init=1"
                                        alt="Captcha"
                                        class="captcha-image"
                                    >
                                    <button
                                        type="button"
                                        id="refreshCaptchaBtn"
                                        class="captcha-refresh inline-flex items-center justify-center"
                                        aria-label="Refresh captcha"
                                        title="Refresh captcha"
                                    >
                                        <i class="fas fa-rotate-right"></i>
                                    </button>
                                </div>
                                <div class="field-wrap">
                                    <i class="fas fa-fingerprint input-icon"></i>
                                    <input
                                        type="text"
                                        id="captcha"
                                        name="captcha"
                                        required
                                        minlength="5"
                                        maxlength="7"
                                        pattern="[A-Za-z0-9]{5,7}"
                                        inputmode="text"
                                        autocomplete="off"
                                        class="modern-input uppercase tracking-widest"
                                        placeholder="Enter 5 to 7 characters"
                                        title="Enter the captcha shown above (5 to 7 letters and numbers)."
                                    >
                                </div>
                                <p class="field-helper">Captcha expires in 2 minutes with 3 attempts max.</p>
                            </div>
                        </div>

                        <button
                            type="submit"
                            id="registerBtn"
                            class="auth-button flex items-center justify-center"
                        >
                            <i class="fas fa-user-plus mr-2"></i> Create Account
                        </button>
                    </form>

                    <div class="mt-6 text-center">
                        <p class="text-gray-600">
                            Already have an account?
                            <a href="login.php" class="auth-link transition-colors duration-200">
                                <i class="fas fa-sign-in-alt text-xs mr-1"></i> Login
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        const captchaImage = document.getElementById('captchaImage');
        const captchaInput = document.getElementById('captcha');
        const refreshCaptchaBtn = document.getElementById('refreshCaptchaBtn');
        const registrationForm = document.getElementById('registrationForm');
        const phoneInput = document.getElementById('phone');
        const fullNameInput = document.getElementById('fullName');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');

        function refreshCaptcha(clearInput = true) {
            captchaImage.src = 'includes/helpers/captcha_helper.php?refresh=1&t=' + Date.now();
            if (clearInput) {
                captchaInput.value = '';
            }
        }

        refreshCaptchaBtn.addEventListener('click', function() {
            refreshCaptcha();
            captchaInput.focus();
        });

        captchaInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 7);
        });

        phoneInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 10);
        });

        fullNameInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^A-Za-z\s.'-]/g, '').replace(/\s{2,}/g, ' ').slice(0, 100);
        });

        registrationForm.addEventListener('submit', function(e) {
            e.preventDefault();

            if (!this.reportValidity()) {
                return;
            }

            if (passwordInput.value !== confirmPasswordInput.value) {
                showToast('Passwords do not match.', false);
                confirmPasswordInput.focus();
                return;
            }

            const registerBtn = document.getElementById('registerBtn');
            const originalBtnContent = registerBtn.innerHTML;
            
            registerBtn.disabled = true;
            registerBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i> Registering...';
            showLoading('Creating your account...');
            
            const formData = new FormData(this);
            
            fetch('register.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                hideLoading();
                showToast(data.message, data.success);

                if (!data.success && data.refreshCaptcha) {
                    refreshCaptcha();
                }
                
                if (data.success) {
                    this.reset();
                    refreshCaptcha();
                    navigateWithLoading('login.php', 'Opening sign in...', 3000);
                }
            })
            .catch(() => {
                hideLoading();
                showToast('Registration failed. Please try again.', false);
                refreshCaptcha();
            })
            .finally(() => {
                registerBtn.disabled = false;
                registerBtn.innerHTML = originalBtnContent;
            });
        });
        
        // Password strength indicator
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const strengthIndicator = document.createElement('div');
            let strength = 0;
            
            // Remove any existing indicator
            const existingIndicator = document.querySelector('.password-strength');
            if (existingIndicator) {
                existingIndicator.remove();
            }
            
            if (password.length < 1) {
                return;
            }
            
            // Calculate password strength
            if (password.length >= 8) strength += 1;
            if (password.match(/[a-z]+/)) strength += 1;
            if (password.match(/[A-Z]+/)) strength += 1;
            if (password.match(/[0-9]+/)) strength += 1;
            if (password.match(/[^a-zA-Z0-9]+/)) strength += 1;
            
            // Define strength classes and text
            let strengthClass, strengthText;
            switch(strength) {
                case 0:
                case 1:
                    strengthClass = 'bg-red-500';
                    strengthText = 'Weak';
                    break;
                case 2:
                case 3:
                    strengthClass = 'bg-yellow-500';
                    strengthText = 'Medium';
                    break;
                case 4:
                case 5:
                    strengthClass = 'bg-green-500';
                    strengthText = 'Strong';
                    break;
            }
            
            // Create and append the strength indicator
            strengthIndicator.className = 'password-strength mt-2 text-xs flex items-center';
            strengthIndicator.innerHTML = `
                <div class="w-full bg-gray-200 rounded-full h-1.5 mr-2">
                    <div class="${strengthClass} h-1.5 rounded-full" style="width: ${(strength/5)*100}%"></div>
                </div>
                <span class="text-gray-600">${strengthText}</span>
            `;
            
            // Append after password input
            this.parentElement.insertAdjacentElement('afterend', strengthIndicator);
        });
        
        // Add real-time password matching validation
        confirmPasswordInput.addEventListener('input', function() {
            const password = passwordInput.value;
            const confirmPassword = this.value;
            
            // Remove any existing indicator
            const existingIndicator = document.querySelector('.password-match');
            if (existingIndicator) {
                existingIndicator.remove();
            }
            
            if (!confirmPassword) {
                this.style.borderColor = '#d1d5db';
                return;
            }
            
            const matchIndicator = document.createElement('div');
            matchIndicator.className = 'password-match mt-2 text-xs flex items-center';
            
            if (password === confirmPassword) {
                this.style.borderColor = '#10b981';
                matchIndicator.innerHTML = `
                    <i class="fas fa-check-circle text-green-500 mr-1"></i>
                    <span class="text-green-500">Passwords match</span>
                `;
            } else {
                this.style.borderColor = '#ef4444';
                matchIndicator.innerHTML = `
                    <i class="fas fa-times-circle text-red-500 mr-1"></i>
                    <span class="text-red-500">Passwords do not match</span>
                `;
            }
            
            // Append after confirm password input
            this.parentElement.insertAdjacentElement('afterend', matchIndicator);
        });
    </script>
<?php include 'includes/layout/footer.php'; ?>
</body>
</html>

