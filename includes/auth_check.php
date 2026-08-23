<?php
// auth_check.php - Session authentication checker
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/helpers/mfa_helper.php';
require_once __DIR__ . '/helpers/auth/remember_me_helper.php';
require_once __DIR__ . '/helpers/session_timeout_helper.php';

startSecureSession();
loadEnvFile(dirname(__DIR__) . '/.env');
ensureRememberMeTokensTable($pdo);

function getAppBasePath(): string
{
    $documentRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
    $appRoot = str_replace('\\', '/', dirname(__DIR__));

    if ($documentRoot !== '' && strncmp($appRoot, $documentRoot, strlen($documentRoot)) === 0) {
        $relative = trim(substr($appRoot, strlen($documentRoot)), '/');
        return $relative !== '' ? '/' . $relative : '';
    }

    $scriptName = trim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($scriptName === '') {
        return '';
    }

    $parts = explode('/', $scriptName);
    return !empty($parts[0]) ? '/' . $parts[0] : '';
}

require_once __DIR__ . '/helpers/assets_helper.php';

function redirectToApp(string $path): void
{
    $basePath = getAppBasePath();
    header('Location: ' . $basePath . '/' . ltrim($path, '/'));
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    attemptRememberMeLogin($pdo);
}

if (!isset($_SESSION['user_id'])) {
    // Redirect to login if not authenticated
    redirectToApp('login.php');
}

if (isSessionTimedOutByInactivity()) {
    destroyAuthenticatedSession();
    redirectToApp('login.php?reason=session_timeout');
}

touchSessionActivity();

// Enforce MFA for all authenticated users before dashboard/module access.
ensureMfaUsersCompletionColumn($pdo);

try {
    $mfaStmt = $pdo->prepare('SELECT mfa_enabled, mfa_secret, mfa_backup_codes_downloaded_at FROM users WHERE user_id = ? LIMIT 1');
    $mfaStmt->execute([$_SESSION['user_id']]);
    $mfaRow = $mfaStmt->fetch(PDO::FETCH_ASSOC);

    $isMfaEnabled = $mfaRow && (int)($mfaRow['mfa_enabled'] ?? 0) === 1 && !empty($mfaRow['mfa_secret']);
    $isBackupDownloadCompleted = $mfaRow && !empty($mfaRow['mfa_backup_codes_downloaded_at']);
    if (!$isMfaEnabled || !$isBackupDownloadCompleted) {
        $_SESSION['force_mfa_setup'] = true;
        redirectToApp('mfa/setup-mfa.php?enroll=1');
    }
} catch (PDOException $e) {
    // If MFA check fails unexpectedly, fail safe by requiring re-login.
    redirectToApp('login.php?reason=auth_required');
}

// Check role-based access
function requireRole($requiredRole) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $requiredRole) {
        // Redirect based on actual role or back to login
        if (isset($_SESSION['role'])) {
            if ($_SESSION['role'] === 'admin') {
                redirectToApp('admin/admin_dashboard.php');
            } else {
                redirectToApp('claimant/claimant_dashboard.php');
            }
        } else {
            redirectToApp('login.php');
        }
    }
}

// Function to get user info for display
function getUserInfo() {
    global $pdo;

    // If database connection is available, fetch fresh data
    if (isset($pdo) && isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT user_id, full_name, email, role, image_path FROM users WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Update session with latest data
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                return $user;
            }
        } catch (PDOException $e) {
            // Fall back to session data if database query fails
        }
    }

    // Fallback to session data
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? 'Unknown User',
        'email' => $_SESSION['email'] ?? '',
        'role' => $_SESSION['role'] ?? 'claimant',
        'image_path' => $_SESSION['image_path'] ?? null
    ];
}
?>

