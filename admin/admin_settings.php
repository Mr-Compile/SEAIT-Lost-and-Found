<?php
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/helpers/settings_helper.php';
requireRole('admin');

$current_page = basename($_SERVER['PHP_SELF']);
$user = getUserInfo();
$active_tab = $_GET['tab'] ?? 'general';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['db_tools_unlock_csrf'])) {
    try {
        $_SESSION['db_tools_unlock_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['db_tools_unlock_csrf'] = hash('sha256', uniqid((string)mt_rand(), true));
    }
}

$dbToolsUnlockCsrf = (string)($_SESSION['db_tools_unlock_csrf'] ?? '');

function maskEmailForDisplay(string $email): string
{
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return '***';
    }

    [$local, $domain] = explode('@', $email, 2);
    $localVisible = substr($local, 0, min(2, strlen($local)));
    $localMasked = $localVisible . str_repeat('*', max(2, strlen($local) - strlen($localVisible)));

    $domainParts = explode('.', $domain, 2);
    $domainRoot = $domainParts[0] ?? '';
    $domainSuffix = isset($domainParts[1]) ? '.' . $domainParts[1] : '';
    $domainVisible = substr($domainRoot, 0, min(1, strlen($domainRoot)));
    $domainMasked = $domainVisible . str_repeat('*', max(2, strlen($domainRoot) - strlen($domainVisible)));

    return $localMasked . '@' . $domainMasked . $domainSuffix;
}

function maskPhoneForDisplay(?string $phone): string
{
    $phone = trim((string)$phone);
    if ($phone === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return '***';
    }

    $visibleDigits = 3;
    $maskedLength = max(0, strlen($digits) - $visibleDigits);
    return str_repeat('*', $maskedLength) . substr($digits, -$visibleDigits);
}

function resolveUserProfileImageSrc(?string $imagePath): ?string
{
    $imagePath = trim((string)$imagePath);
    if ($imagePath === '' || strtolower($imagePath) === 'null') {
        return null;
    }

    $normalized = str_replace('\\', '/', $imagePath);

    if (preg_match('#^(https?:)?//#i', $normalized) === 1 || strpos($normalized, 'data:') === 0) {
        return $normalized;
    }

    if (strpos($normalized, '../') === 0) {
        return $normalized;
    }

    if (strpos($normalized, '/uploads/') === 0) {
        return '..' . $normalized;
    }

    if (strpos($normalized, 'uploads/') === 0) {
        return '../' . $normalized;
    }

    return '../uploads/' . ltrim($normalized, '/');
}

function removeBackgroundWithRemoveBg(string $sourcePath, string $targetPath): bool
{
    $apiKey = 'FSEogCb5uJfyJ5jPk9v2vXyf';

    if (!function_exists('curl_init')) {
        return false;
    }

    $realSource = realpath($sourcePath);
    if ($realSource === false) {
        return false;
    }

    $ch = curl_init('https://api.remove.bg/v1.0/removebg');
    $postFields = [
        'size' => 'auto',
        'format' => 'png',
        'image_file' => new CURLFile($realSource),
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $apiKey],
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $statusCode !== 200) {
        return false;
    }

    return file_put_contents($targetPath, $response) !== false;
}

function verifyCurrentAdminPassword(PDO $pdo, int $userId, string $password): bool
{
    if ($password === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $hash = (string)($stmt->fetchColumn() ?: '');

    return $hash !== '' && password_verify($password, $hash);
}

function parseSqlStatements(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $statements = [];
    $delimiter = ';';
    $buffer = '';

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            continue;
        }

        if (stripos($trimmed, 'DELIMITER ') === 0) {
            $delimiter = trim(substr($trimmed, 10));
            $buffer = '';
            continue;
        }

        if (strpos($trimmed, '-- ') === 0 || strpos($trimmed, '#') === 0) {
            continue;
        }

        $buffer .= $line . "\n";

        if ($delimiter !== '' && str_ends_with(rtrim($line), $delimiter)) {
            $statement = trim(substr(trim($buffer), 0, -strlen($delimiter)));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $remaining = trim($buffer);
    if ($remaining !== '') {
        $statements[] = $remaining;
    }

    return $statements;
}

function buildDatabaseDump(PDO $pdo, string $databaseName): string
{
    $dump = [];
    $dump[] = '-- FindIt SQL Backup';
    $dump[] = '-- Generated: ' . date('Y-m-d H:i:s');
    $dump[] = '-- Database: ' . $databaseName;
    $dump[] = '';
    $dump[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
    $dump[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $dump[] = '';

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($tables as $table) {
        $tableName = (string)$table;
        $createRow = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $tableName) . '`')->fetch();
        $createSql = (string)($createRow['Create Table'] ?? '');

        if ($createSql === '') {
            continue;
        }

        $dump[] = '-- --------------------------------------------------------';
        $dump[] = '-- Table structure for table `' . $tableName . '`';
        $dump[] = '-- --------------------------------------------------------';
        $dump[] = 'DROP TABLE IF EXISTS `' . $tableName . '`;';
        $dump[] = $createSql . ';';
        $dump[] = '';

        $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $tableName) . '`')->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $columns = array_keys($rows[0]);
            $columnSql = '`' . implode('`, `', array_map(static fn($c) => str_replace('`', '``', (string)$c), $columns)) . '`';

            $dump[] = '-- Dumping data for table `' . $tableName . '`';
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $col) {
                    $value = $row[$col];
                    $values[] = $value === null ? 'NULL' : $pdo->quote((string)$value);
                }
                $dump[] = 'INSERT INTO `' . $tableName . '` (' . $columnSql . ') VALUES (' . implode(', ', $values) . ');';
            }
            $dump[] = '';
        }
    }

    $dump[] = 'SET FOREIGN_KEY_CHECKS=1;';

    return implode("\n", $dump) . "\n";
}

function clearDatabaseRecords(PDO $pdo): int
{
    $preserveTables = ['users', 'system_settings'];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $cleared = 0;
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $tableName = (string)$table;
        if (in_array($tableName, $preserveTables, true)) {
            continue;
        }
        $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $tableName) . '`');
        $cleared++;
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    return $cleared;
}

function getSecureBackupDirectory(): string
{
    $basePath = dirname(__DIR__);
    return $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
}

function ensureSecureDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    $htaccessPath = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccessPath)) {
        file_put_contents($htaccessPath, "Require all denied\nDeny from all\n");
    }

    $indexPath = $directory . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($indexPath)) {
        file_put_contents($indexPath, '<!doctype html><title>Access Denied</title><h1>Access Denied</h1>');
    }
}

function createSqlBackupFile(PDO $pdo, string $databaseName, string $prefix = 'findit_backup_'): array
{
    $backupDir = getSecureBackupDirectory();
    ensureSecureDirectory($backupDir);

    $sqlDump = buildDatabaseDump($pdo, $databaseName);
    $backupFile = $prefix . date('Ymd_His') . '.sql';
    $backupPath = $backupDir . DIRECTORY_SEPARATOR . $backupFile;

    if (file_put_contents($backupPath, $sqlDump) === false) {
        throw new RuntimeException('Unable to write backup file.');
    }

    $checksum = hash_file('sha256', $backupPath) ?: '';
    if ($checksum !== '') {
        file_put_contents($backupPath . '.sha256', $checksum . '  ' . $backupFile . PHP_EOL);
    }

    $allBackups = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    usort($allBackups, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    $toDelete = array_slice($allBackups, 40);
    foreach ($toDelete as $oldBackup) {
        @unlink($oldBackup);
        @unlink($oldBackup . '.sha256');
    }

    return [
        'file' => $backupFile,
        'path' => $backupPath,
        'checksum' => $checksum,
        'size' => filesize($backupPath) ?: 0,
    ];
}

function listRecentBackups(int $limit = 6): array
{
    $backupDir = getSecureBackupDirectory();
    if (!is_dir($backupDir)) {
        return [];
    }

    $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    $files = array_slice($files, 0, $limit);

    $recent = [];
    foreach ($files as $fullPath) {
        $fileName = basename($fullPath);
        $hashPath = $fullPath . '.sha256';
        $hash = '';
        if (file_exists($hashPath)) {
            $line = trim((string)file_get_contents($hashPath));
            $parts = preg_split('/\s+/', $line);
            $hash = (string)($parts[0] ?? '');
        }

        $recent[] = [
            'name' => $fileName,
            'size' => filesize($fullPath) ?: 0,
            'modified_at' => (int)(filemtime($fullPath) ?: 0),
            'checksum' => $hash,
        ];
    }

    return $recent;
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $size = $bytes / 1024;
    $unitIndex = 0;
    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }
    return number_format($size, 2) . ' ' . $units[$unitIndex];
}

function containsRestrictedSql(string $sql): ?string
{
    $patterns = [
        '/\bCREATE\s+USER\b/i',
        '/\bALTER\s+USER\b/i',
        '/\bDROP\s+DATABASE\b/i',
        '/\bGRANT\b/i',
        '/\bREVOKE\b/i',
        '/\bSET\s+GLOBAL\b/i',
        '/\bINTO\s+OUTFILE\b/i',
        '/\bLOAD\s+DATA\s+INFILE\b/i',
        '/\bINSTALL\s+PLUGIN\b/i',
        '/\bUNINSTALL\s+PLUGIN\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $sql)) {
            return $pattern;
        }
    }

    return null;
}

function issueDbActionToken(): array
{
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $token = hash('sha256', uniqid((string)mt_rand(), true) . microtime(true));
    }

    $ttl = 180;
    $_SESSION['db_tools_action_token_hash'] = hash('sha256', $token);
    $_SESSION['db_tools_action_token_expires'] = time() + $ttl;

    return [
        'token' => $token,
        'ttl' => $ttl,
    ];
}

function isDbActionTokenValid(string $token): bool
{
    $token = trim($token);
    $storedHash = (string)($_SESSION['db_tools_action_token_hash'] ?? '');
    $expiresAt = (int)($_SESSION['db_tools_action_token_expires'] ?? 0);

    if ($token === '' || $storedHash === '' || $expiresAt < time()) {
        return false;
    }

    return hash_equals($storedHash, hash('sha256', $token));
}

function writeDbAuditLog(string $action, bool $isSuccess, string $details = ''): void
{
    $logsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logsDir)) {
        mkdir($logsDir, 0700, true);
    }
    ensureSecureDirectory($logsDir);

    $entry = [
        'timestamp' => date('c'),
        'action' => $action,
        'success' => $isSuccess,
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
        'details' => substr(str_replace(["\r", "\n"], ' ', $details), 0, 400),
    ];

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        file_put_contents($logsDir . DIRECTORY_SEPARATOR . 'database_tools_audit.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'unlock_db_tools') {
        header('Content-Type: application/json');

        $submittedToken = (string)($_POST['db_tools_unlock_csrf'] ?? '');
        $sessionToken = (string)($_SESSION['db_tools_unlock_csrf'] ?? '');
        if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            writeDbAuditLog('unlock_db_tools', false, 'CSRF validation failed');
            echo json_encode(['success' => false, 'message' => 'Invalid request token. Refresh the page and try again.']);
            exit;
        }

        $attemptWindow = 300;
        $maxAttempts = 5;
        $now = time();
        $unlockAttempts = array_values(array_filter(
            (array)($_SESSION['db_tools_unlock_attempts'] ?? []),
            static fn($ts) => is_int($ts) && $ts > ($now - $attemptWindow)
        ));

        if (count($unlockAttempts) >= $maxAttempts) {
            writeDbAuditLog('unlock_db_tools', false, 'Rate limited unlock attempts');
            echo json_encode(['success' => false, 'message' => 'Too many attempts. Please wait a few minutes before retrying.']);
            exit;
        }

        $password = trim($_POST['admin_password'] ?? '');
        $adminId = (int)($user['user_id'] ?? 0);
        if ($adminId <= 0 || !verifyCurrentAdminPassword($pdo, $adminId, $password)) {
            $unlockAttempts[] = $now;
            $_SESSION['db_tools_unlock_attempts'] = $unlockAttempts;
            writeDbAuditLog('unlock_db_tools', false, 'Incorrect admin password');
            echo json_encode(['success' => false, 'message' => 'Incorrect admin password.']);
            exit;
        }

        unset($_SESSION['db_tools_unlock_attempts']);
        $issued = issueDbActionToken();
        writeDbAuditLog('unlock_db_tools', true, 'DB tools session token issued');

        echo json_encode([
            'success' => true,
            'message' => 'Database tools unlocked for this tab session.',
            'db_action_token' => $issued['token'],
            'token_ttl' => $issued['ttl'],
        ]);
        exit;
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_system_settings':
                // Handle system settings update
                $site_name = trim($_POST['site_name'] ?? '');
                $site_description = trim($_POST['site_description'] ?? '');
                $contact_email = trim($_POST['contact_email'] ?? '');
                $site_tagline = trim($_POST['site_tagline'] ?? '');
                
                // Validate email
                if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email address format.";
                } else {
                    try {
                        // Handle image uploads
                        $upload_dir = '../uploads/settings/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $current_settings = getSystemSettings();
                        $hero_image = $current_settings['hero_image'] ?? '';
                        $login_image = $current_settings['login_image'] ?? '';
                        $register_image = $current_settings['register_image'] ?? '';
                        $site_logo = $current_settings['site_logo'] ?? '';
                        
                        // Handle hero image upload
                        if (isset($_FILES['hero_image']) && $_FILES['hero_image']['error'] === UPLOAD_ERR_OK) {
                            $file_ext = strtolower(pathinfo($_FILES['hero_image']['name'], PATHINFO_EXTENSION));
                            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                            
                            if (in_array($file_ext, $allowed) && $_FILES['hero_image']['size'] <= 5000000) {
                                $new_filename = 'hero_' . time() . '.' . $file_ext;
                                if (move_uploaded_file($_FILES['hero_image']['tmp_name'], $upload_dir . $new_filename)) {
                                    // Delete old image if exists
                                    if ($hero_image && file_exists('../' . $hero_image)) {
                                        unlink('../' . $hero_image);
                                    }
                                    $hero_image = 'uploads/settings/' . $new_filename;
                                }
                            }
                        }
                        
                        // Handle login image upload
                        if (isset($_FILES['login_image']) && $_FILES['login_image']['error'] === UPLOAD_ERR_OK) {
                            $file_ext = strtolower(pathinfo($_FILES['login_image']['name'], PATHINFO_EXTENSION));
                            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                            
                            if (in_array($file_ext, $allowed) && $_FILES['login_image']['size'] <= 5000000) {
                                $new_filename = 'login_' . time() . '.' . $file_ext;
                                if (move_uploaded_file($_FILES['login_image']['tmp_name'], $upload_dir . $new_filename)) {
                                    // Delete old image if exists
                                    if ($login_image && file_exists('../' . $login_image)) {
                                        unlink('../' . $login_image);
                                    }
                                    $login_image = 'uploads/settings/' . $new_filename;
                                }
                            }
                        }
                        
                        // Handle register image upload
                        if (isset($_FILES['register_image']) && $_FILES['register_image']['error'] === UPLOAD_ERR_OK) {
                            $file_ext = strtolower(pathinfo($_FILES['register_image']['name'], PATHINFO_EXTENSION));
                            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                            
                            if (in_array($file_ext, $allowed) && $_FILES['register_image']['size'] <= 5000000) {
                                $new_filename = 'register_' . time() . '.' . $file_ext;
                                if (move_uploaded_file($_FILES['register_image']['tmp_name'], $upload_dir . $new_filename)) {
                                    // Delete old image if exists
                                    if ($register_image && file_exists('../' . $register_image)) {
                                        unlink('../' . $register_image);
                                    }
                                    $register_image = 'uploads/settings/' . $new_filename;
                                }
                            }
                        }

                        // Handle site logo upload (remove.bg background removal with fallback)
                        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                            $file_ext = strtolower(pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION));
                            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                            if (in_array($file_ext, $allowed, true) && $_FILES['site_logo']['size'] <= 5000000) {
                                $timestamp = time();
                                $new_filename = 'logo_' . $timestamp . '.png';
                                $target_path = $upload_dir . $new_filename;

                                $removedBg = removeBackgroundWithRemoveBg($_FILES['site_logo']['tmp_name'], $target_path);
                                if (!$removedBg) {
                                    $new_filename = 'logo_' . $timestamp . '.' . $file_ext;
                                    $target_path = $upload_dir . $new_filename;

                                    if (!move_uploaded_file($_FILES['site_logo']['tmp_name'], $target_path)) {
                                        throw new RuntimeException('Failed to upload site logo.');
                                    }
                                }

                                if ($site_logo && file_exists('../' . $site_logo)) {
                                    unlink('../' . $site_logo);
                                }

                                $site_logo = 'uploads/settings/' . $new_filename;
                            }
                        }
                        
                        // Update settings in database
                        $settings = [
                            'site_name' => $site_name,
                            'site_description' => $site_description,
                            'contact_email' => $contact_email,
                            'site_tagline' => $site_tagline,
                            'hero_image' => $hero_image,
                            'login_image' => $login_image,
                            'register_image' => $register_image,
                            'site_logo' => $site_logo
                        ];
                        
                        if (updateSettings($settings)) {
                            $message = "System settings updated successfully!";
                            $active_tab = 'general';
                        } else {
                            $error = "Failed to update settings. Please try again.";
                        }
                    } catch (Exception $e) {
                        $error = "Error updating settings: " . $e->getMessage();
                    }
                }
                break;
                
            case 'update_user_role':
                $user_id = $_POST['user_id'];
                $new_role = $_POST['new_role'];
                $allowed_roles = ['admin', 'claimant'];

                if (!in_array($new_role, $allowed_roles, true)) {
                    $error = "Invalid role selected.";
                    $active_tab = 'users';
                    break;
                }
                
                try {
                    $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE user_id = ?");
                    $stmt->execute([$new_role, $user_id]);
                    $message = "User role updated successfully!";
                    $active_tab = 'users';
                } catch (PDOException $e) {
                    $error = "Error updating user role: " . $e->getMessage();
                    $active_tab = 'users';
                }
                break;

            case 'update_user':
                $user_id = (int)($_POST['user_id'] ?? 0);
                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $role = trim($_POST['role'] ?? 'claimant');

                $allowed_roles = ['admin', 'claimant'];

                if ($user_id <= 0 || $full_name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowed_roles, true)) {
                    $error = "Invalid user details. Please check input values.";
                    $active_tab = 'users';
                    break;
                }

                try {
                    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ? WHERE user_id = ?");
                    $stmt->execute([$full_name, $email, $phone !== '' ? $phone : null, $role, $user_id]);
                    $message = "User updated successfully!";
                } catch (PDOException $e) {
                    $error = "Error updating user: " . $e->getMessage();
                }

                $active_tab = 'users';
                break;

            case 'delete_user':
                $user_id = (int)($_POST['user_id'] ?? 0);

                if ($user_id <= 0) {
                    $error = "Invalid user selection.";
                    $active_tab = 'users';
                    break;
                }

                if ($user_id == (int)$_SESSION['user_id']) {
                    $error = "You cannot delete your own account!";
                    $active_tab = 'users';
                    break;
                }

                try {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $message = "User deleted successfully!";
                } catch (PDOException $e) {
                    $error = "Error deleting user: " . $e->getMessage();
                }

                $active_tab = 'users';
                break;

            case 'backup_database':
            case 'export_database':
            case 'import_database':
            case 'download_backup_file':
            case 'clear_database':
                $active_tab = 'database';

                $dbActionToken = trim((string)($_POST['db_action_token'] ?? ''));
                if (!isDbActionTokenValid($dbActionToken)) {
                    writeDbAuditLog((string)$_POST['action'], false, 'Invalid or expired DB action token');
                    $error = 'Database tools session expired or invalid. Re-open the Database tab and unlock again.';
                    break;
                }

                try {
                    $databaseName = (string)($GLOBALS['db'] ?? 'findit');
                    $backupDir = getSecureBackupDirectory();
                    ensureSecureDirectory($backupDir);

                    if ($_POST['action'] === 'export_database') {
                        $export = createSqlBackupFile($pdo, $databaseName, 'findit_export_');
                        $fileName = (string)$export['file'];
                        $sqlDump = (string)file_get_contents((string)$export['path']);
                        writeDbAuditLog('export_database', true, 'Export generated: ' . $fileName);

                        header('Content-Type: application/sql');
                        header('Content-Disposition: attachment; filename="' . $fileName . '"');
                        header('Pragma: no-cache');
                        header('Expires: 0');
                        echo $sqlDump;
                        exit;
                    }

                    if ($_POST['action'] === 'backup_database') {
                        $backup = createSqlBackupFile($pdo, $databaseName, 'findit_backup_');
                        writeDbAuditLog('backup_database', true, 'Backup created: ' . $backup['file']);
                        $message = 'Backup saved to storage/backups/' . $backup['file'] . ' | SHA-256: ' . ($backup['checksum'] !== '' ? $backup['checksum'] : 'n/a');
                        break;
                    }

                    if ($_POST['action'] === 'download_backup_file') {
                        $fileName = basename((string)($_POST['backup_file'] ?? ''));
                        if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $fileName)) {
                            $error = 'Invalid backup file requested.';
                            writeDbAuditLog('download_backup_file', false, 'Invalid file name requested');
                            break;
                        }

                        $path = $backupDir . DIRECTORY_SEPARATOR . $fileName;
                        if (!is_file($path)) {
                            $error = 'Requested backup file was not found.';
                            writeDbAuditLog('download_backup_file', false, 'File not found: ' . $fileName);
                            break;
                        }

                        writeDbAuditLog('download_backup_file', true, 'Downloaded: ' . $fileName);

                        header('Content-Type: application/sql');
                        header('Content-Disposition: attachment; filename="' . $fileName . '"');
                        header('Content-Length: ' . (string)(filesize($path) ?: 0));
                        header('Pragma: no-cache');
                        header('Expires: 0');
                        readfile($path);
                        exit;
                        break;
                    }

                    if ($_POST['action'] === 'import_database') {
                        if (!isset($_FILES['db_import_file']) || $_FILES['db_import_file']['error'] !== UPLOAD_ERR_OK) {
                            $error = 'Please select a valid SQL file to import.';
                            break;
                        }

                        $ext = strtolower(pathinfo((string)$_FILES['db_import_file']['name'], PATHINFO_EXTENSION));
                        if ($ext !== 'sql') {
                            $error = 'Only .sql files are allowed for import.';
                            break;
                        }

                        $maxImportBytes = 8 * 1024 * 1024;
                        if ((int)($_FILES['db_import_file']['size'] ?? 0) > $maxImportBytes) {
                            $error = 'SQL import file is too large. Maximum size is 8MB.';
                            writeDbAuditLog('import_database', false, 'File too large');
                            break;
                        }

                        $sqlContent = file_get_contents($_FILES['db_import_file']['tmp_name']);
                        if ($sqlContent === false || trim($sqlContent) === '') {
                            $error = 'The uploaded SQL file is empty or unreadable.';
                            break;
                        }

                        $blockedPattern = containsRestrictedSql($sqlContent);
                        if ($blockedPattern !== null) {
                            $error = 'Import blocked due to restricted SQL content.';
                            writeDbAuditLog('import_database', false, 'Restricted SQL detected: ' . $blockedPattern);
                            break;
                        }

                        $statements = parseSqlStatements($sqlContent);
                        if (empty($statements)) {
                            $error = 'No SQL statements found in the uploaded file.';
                            break;
                        }

                        if (count($statements) > 4000) {
                            $error = 'Import blocked: too many SQL statements in one file.';
                            writeDbAuditLog('import_database', false, 'Too many SQL statements');
                            break;
                        }

                        $preImportBackup = createSqlBackupFile($pdo, $databaseName, 'findit_preimport_');

                        $pdo->beginTransaction();
                        try {
                            foreach ($statements as $statement) {
                                $pdo->exec($statement);
                            }
                            $pdo->commit();
                        } catch (Throwable $txError) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $txError;
                        }

                        writeDbAuditLog('import_database', true, 'Import completed. Recovery backup: ' . $preImportBackup['file']);
                        $message = 'Import completed. Safety backup saved as storage/backups/' . $preImportBackup['file'];
                        break;
                    }

                    if ($_POST['action'] === 'clear_database') {
                        $confirmation = strtoupper(trim($_POST['clear_confirmation'] ?? ''));
                        if ($confirmation !== 'CLEAR DATABASE') {
                            $error = 'Type CLEAR DATABASE exactly to confirm clearing records.';
                            break;
                        }

                        $preClearBackup = createSqlBackupFile($pdo, $databaseName, 'findit_preclear_');
                        $clearedCount = clearDatabaseRecords($pdo);
                        writeDbAuditLog('clear_database', true, 'Cleared tables: ' . $clearedCount . ' | Recovery backup: ' . $preClearBackup['file']);
                        $message = 'Database records cleared successfully. Tables reset: ' . $clearedCount . '. Recovery backup: storage/backups/' . $preClearBackup['file'];
                        break;
                    }
                } catch (Throwable $e) {
                    writeDbAuditLog((string)$_POST['action'], false, 'Exception: ' . $e->getMessage());
                    $error = 'Database tools error: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get system statistics
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM users WHERE role = 'admin') as admin_count,
    (SELECT COUNT(*) FROM users WHERE role = 'claimant') as claimant_count,
    (SELECT COUNT(*) FROM items) as total_items,
    (SELECT COUNT(*) FROM itemmatches) as total_matches
";
$stats_stmt = $pdo->query($stats_sql);
$stats = $stats_stmt->fetch();

// Users tab: search, filter, pagination
$user_search = trim($_GET['search'] ?? '');
$user_role_filter = trim($_GET['role'] ?? 'all');
$users_page = max(1, (int)($_GET['users_page'] ?? 1));
$users_per_page = 10;
$users_offset = ($users_page - 1) * $users_per_page;

$user_where_conditions = [];
$user_query_params = [];

if ($user_search !== '') {
    $user_where_conditions[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = "%{$user_search}%";
    $user_query_params[] = $like;
    $user_query_params[] = $like;
    $user_query_params[] = $like;
}

if (in_array($user_role_filter, ['admin', 'claimant'], true)) {
    $user_where_conditions[] = "role = ?";
    $user_query_params[] = $user_role_filter;
}

$user_where_clause = !empty($user_where_conditions) ? ('WHERE ' . implode(' AND ', $user_where_conditions)) : '';

$users_count_sql = "SELECT COUNT(*) AS total FROM users {$user_where_clause}";
$users_count_stmt = $pdo->prepare($users_count_sql);
$users_count_stmt->execute($user_query_params);
$total_users = (int)($users_count_stmt->fetch()['total'] ?? 0);
$total_users_pages = max(1, (int)ceil($total_users / $users_per_page));

if ($users_page > $total_users_pages) {
    $users_page = $total_users_pages;
    $users_offset = ($users_page - 1) * $users_per_page;
}

$users_sql = "SELECT user_id, full_name, email, phone, role, image_path, created_at FROM users {$user_where_clause} ORDER BY created_at DESC LIMIT ? OFFSET ?";
$users_stmt = $pdo->prepare($users_sql);
$users_query_params = $user_query_params;
$users_query_params[] = $users_per_page;
$users_query_params[] = $users_offset;
$users_stmt->execute($users_query_params);
$users = $users_stmt->fetchAll();

// Get current system settings from database
$system_settings = getSystemSettings();
$recentBackups = listRecentBackups(6);
$backupStorageRelativePath = 'storage/backups';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - System Settings</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        body.admin-theme { font-family: 'Manrope', 'Segoe UI', sans-serif; }
        .main-content h2, .main-content h3, .main-content h4 { font-family: 'Sora', 'Manrope', 'Segoe UI', sans-serif; letter-spacing: -0.02em; }
        .gradient-bg { background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 42%, #f23c00 100%); }
        .gradient-button { background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 42%, #f23c00 100%); transition: all 0.3s ease; }
        .gradient-button:hover { background: linear-gradient(132deg, #ff8f4a 0%, #ff6a2b 42%, #ff4b1f 100%); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(255, 91, 31, 0.3); }
        .tab-button {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            position: relative;
            font-weight: 700;
            letter-spacing: 0.01em;
            border-top-left-radius: 0.75rem;
            border-top-right-radius: 0.75rem;
            transition: all 0.2s ease;
        }
        .tab-button svg {
            width: 1rem;
            height: 1rem;
            flex-shrink: 0;
            color: #fb923c;
            opacity: 0.9;
        }
        .tab-button:hover {
            background: rgba(251, 146, 60, 0.08);
        }
        .tab-button.active {
            background: linear-gradient(180deg, rgba(251, 146, 60, 0.18) 0%, rgba(251, 146, 60, 0.04) 100%);
        }
        .tab-button.active svg {
            color: #f97316;
            opacity: 1;
        }
        .message { padding: 1rem; margin: 1rem 0; border-radius: 8px; }
        .message.success { background: rgba(34, 211, 238, 0.15); color: #c8f7ff; border: 1px solid rgba(34, 211, 238, 0.35); }
        .message.error { background: rgba(239, 68, 68, 0.16); color: #ffd2d2; border: 1px solid rgba(239, 68, 68, 0.35); }
        .stats-card {
            background: #131922;
            border: 1px solid #273040;
            border-radius: 0.9rem;
            box-shadow: 0 16px 30px rgba(2, 8, 20, 0.35);
            padding: 1.25rem;
        }
        .stats-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 123, 44, 0.18);
            border: 1px solid rgba(255, 123, 44, 0.36);
            color: #ffd8c2;
        }
        .db-header-chip {
            border: 1px solid #2f425b;
            background: linear-gradient(135deg, #0f1d30 0%, #13253c 100%);
            color: #ffbe95;
            border-radius: 999px;
            padding: 0.35rem 0.8rem;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .db-card {
            border: 1px solid #2b3a4f;
            background: linear-gradient(160deg, #101925 0%, #0e1723 100%);
            border-radius: 1rem;
            box-shadow: 0 18px 30px rgba(2, 8, 20, 0.35);
            padding: 1.2rem;
            color: #dbe7f7;
        }
        .db-card-danger {
            border-color: #7f1d1d;
            background: linear-gradient(150deg, #2a0d12 0%, #3b0f18 100%);
        }
        .db-card-icon {
            width: 2.1rem;
            height: 2.1rem;
            border-radius: 0.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #111f33 0%, #122740 100%);
            border: 1px solid #2e435d;
            color: #fb923c;
            box-shadow: 0 8px 14px rgba(2, 8, 20, 0.32);
        }
        #content-database h3,
        #content-database h4,
        #content-database th {
            color: #f8fafc !important;
        }
        #content-database p,
        #content-database td,
        #content-database label {
            color: #c7d3e6 !important;
        }
        #content-database .text-orange-900,
        #content-database .text-red-800,
        #content-database .text-red-700,
        #content-database .text-gray-900,
        #content-database .text-gray-800,
        #content-database .text-gray-700,
        #content-database .text-gray-600 {
            color: #dbe7f7 !important;
        }
        #content-database .db-card-danger .text-red-800,
        #content-database .db-card-danger .text-red-700 {
            color: #fecaca !important;
        }
        #content-database input[type='text'],
        #content-database input[type='file'] {
            background: #0b1320;
            border: 1px solid #334155;
            color: #e2e8f0;
            border-radius: 0.7rem;
        }
        #content-database table thead tr,
        #content-database table tbody tr {
            border-color: #27364a !important;
        }
        .db-security-list li {
            position: relative;
            padding-left: 1.5rem;
            margin-top: 0.5rem;
            color: #dbe7f7;
            font-size: 0.9rem;
        }
        .db-security-list li::before {
            content: '';
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 999px;
            background: linear-gradient(135deg, #fb923c 0%, #ea580c 100%);
            position: absolute;
            left: 0.4rem;
            top: 0.45rem;
        }
        .user-avatar-image {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 999px;
            object-fit: cover;
            border: 1px solid rgba(255, 123, 44, 0.45);
            box-shadow: 0 8px 14px rgba(8, 20, 35, 0.28);
        }
        .user-avatar-fallback {
            width: 2.75rem;
            height: 2.75rem;
            background: linear-gradient(135deg, #f97316 0%, #ef4444 100%);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 800;
            font-size: 0.8rem;
            box-shadow: 0 8px 14px rgba(8, 20, 35, 0.25);
        }
        .settings-modal-panel {
            border: 1px solid #273040;
            background: linear-gradient(160deg, #111924 0%, #0e1520 100%);
            color: #e8efff;
        }
        .settings-modal-title {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
        }
        .settings-modal-icon {
            width: 2rem;
            height: 2rem;
            border-radius: 0.6rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, rgba(249, 115, 22, 0.2), rgba(239, 68, 68, 0.18));
            border: 1px solid rgba(251, 146, 60, 0.35);
            color: #ffbe95;
        }
        .settings-modal-panel .text-gray-900,
        .settings-modal-panel .text-gray-700,
        .settings-modal-panel .text-gray-600 {
            color: #dbe7f7 !important;
        }
        .settings-modal-panel input,
        .settings-modal-panel select {
            background: #0b1320;
            border: 1px solid #334155;
            color: #e2e8f0;
        }
    </style>
</head>
<body class="admin-theme">
    <?php include "../includes/components/shared_sidebar.php"; ?>

    <div class="main-content">
        <div class="p-8 admin-shell">
            <div class="max-w-7xl mx-auto">
                <!-- Header -->
                <div class="mb-8">
                    <h2 class="text-3xl font-bold text-gray-800 mb-2">System Settings</h2>
                    <p class="text-gray-600">Manage system configuration, user roles, and appearance</p>
                </div>

                <!-- Messages -->
                <?php if (isset($message)): ?>
                    <div class="message success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                    <div class="message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- System Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                    <div class="stats-card">
                        <div class="flex items-center justify-between">
                            <div class="stats-icon">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-1a4 4 0 00-5.7-3.63M17 20H7m10 0v-1c0-1.4-.48-2.68-1.29-3.7M7 20H2v-1a4 4 0 015.7-3.63M7 20v-1c0-1.4.48-2.68 1.29-3.7m0 0a5 5 0 017.42 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold text-gray-100"><?php echo number_format($stats['claimant_count']); ?></div>
                                <div class="text-gray-400 text-xs font-medium">Claimants</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stats-card">
                        <div class="flex items-center justify-between">
                            <div class="stats-icon">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8 4-8-4m16 0l-8-4-8 4m16 0v10l-8 4m-8-4V7m8 4v10"/></svg>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold text-gray-100"><?php echo number_format($stats['total_items']); ?></div>
                                <div class="text-gray-400 text-xs font-medium">Total Items</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stats-card">
                        <div class="flex items-center justify-between">
                            <div class="stats-icon">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.07A7 7 0 1018.93 13M19 5v6h-6"/></svg>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold text-gray-100"><?php echo number_format($stats['total_matches']); ?></div>
                                <div class="text-gray-400 text-xs font-medium">Matches</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stats-card">
                        <div class="flex items-center justify-between">
                            <div class="stats-icon">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3l7 4v5c0 5-3.5 8-7 9-3.5-1-7-4-7-9V7l7-4z"/></svg>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold text-gray-100"><?php echo number_format($stats['admin_count']); ?></div>
                                <div class="text-gray-400 text-xs font-medium">Admins</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabs Navigation -->
                <div class="bg-white rounded-xl shadow-lg mb-6">
                    <div class="border-b border-gray-200">
                        <nav class="flex -mb-px">
                            <button onclick="switchTab('general')" id="tab-general" class="tab-button active px-6 py-4 text-sm font-medium border-b-2 border-orange-500 text-orange-500 transition-colors">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
                                General Settings
                            </button>
                            <button onclick="switchTab('appearance')" id="tab-appearance" class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 transition-colors">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                Appearance
                            </button>

                            <button onclick="switchTab('users')" id="tab-users" class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 transition-colors">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-1a4 4 0 00-5.7-3.63M17 20H7m10 0v-1c0-1.4-.48-2.68-1.29-3.7M7 20H2v-1a4 4 0 015.7-3.63M7 20v-1c0-1.4.48-2.68 1.29-3.7m0 0a5 5 0 017.42 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                User Roles
                            </button>

                            <button onclick="switchTab('database')" id="tab-database" class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 transition-colors">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"></path></svg>
                                Database Tools
                            </button>
                        </nav>
                    </div>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_system_settings">
                        
                        <!-- General Settings Tab -->
                        <div id="content-general" class="tab-content p-8">
                            <h3 class="text-xl font-bold text-gray-800 mb-6 inline-flex items-center gap-2">
                                <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"></path></svg>
                                General Information
                            </h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Site Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="site_name" value="<?php echo htmlspecialchars($system_settings['site_name'] ?? ''); ?>" required 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none"
                                           placeholder="e.g., FindIt">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Contact Email <span class="text-red-500">*</span></label>
                                    <input type="email" name="contact_email" value="<?php echo htmlspecialchars($system_settings['contact_email'] ?? ''); ?>" required 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none"
                                           placeholder="contact@example.com">
                                </div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Site Tagline <span class="text-red-500">*</span></label>
                                <input type="text" name="site_tagline" value="<?php echo htmlspecialchars($system_settings['site_tagline'] ?? ''); ?>" required 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none"
                                       placeholder="Find What's Lost. Return What's Found.">
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Site Description <span class="text-red-500">*</span></label>
                                <textarea name="site_description" rows="4" required 
                                          class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none resize-none"
                                          placeholder="A brief description of your lost and found platform..."><?php echo htmlspecialchars($system_settings['site_description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="border-t border-gray-200 pt-6">
                                <h4 class="text-lg font-bold text-gray-800 mb-3">Homepage Counters</h4>
                                <p class="text-sm text-gray-600">Homepage counters are now fully dynamic and are calculated directly from SQL data (items, successful claims, and users).</p>
                            </div>
                        </div>

                        <!-- Appearance Tab -->
                        <div id="content-appearance" class="tab-content hidden p-8">
                            <h3 class="text-xl font-bold text-gray-800 mb-6 inline-flex items-center gap-2">
                                <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                Page Background Images
                            </h3>
                            <p class="text-sm text-gray-600 mb-6">Upload custom background images for each page | Max 5MB | JPG/PNG/GIF/WEBP</p>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
                                <!-- Hero Image -->
                                <div class="bg-gray-50 rounded-xl p-6 border-2 border-gray-200">
                                    <h4 class="text-sm font-bold text-gray-800 mb-3 flex items-center">
                                        Homepage Hero
                                    </h4>
                                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-orange-500 hover:bg-white transition-all cursor-pointer">
                                        <?php if (!empty($system_settings['hero_image']) && file_exists('../' . $system_settings['hero_image'])): ?>
                                            <img src="../<?php echo htmlspecialchars($system_settings['hero_image']); ?>" 
                                                 alt="Hero Image" 
                                                 class="w-full h-40 object-cover rounded-lg mb-3 shadow-sm"
                                                 id="hero_preview">
                                        <?php else: ?>
                                            <div class="w-full h-40 bg-gradient-to-br from-gray-100 to-gray-200 rounded-lg mb-3 flex flex-col items-center justify-center" id="hero_preview">
                                                <div class="text-2xl font-bold tracking-wider mb-2">IMG</div>
                                                <div class="text-xs text-gray-500">No image uploaded</div>
                                            </div>
                                        <?php endif; ?>
                                        <input type="file" name="hero_image" id="hero_image" accept="image/*" 
                                               class="hidden" onchange="previewImage(this, 'hero_preview')">
                                        <label for="hero_image" class="cursor-pointer inline-block px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium rounded-lg transition-colors">
                                            Choose File
                                        </label>
                                    </div>
                                </div>
                                
                                <!-- Login Image -->
                                <div class="bg-gray-50 rounded-xl p-6 border-2 border-gray-200">
                                    <h4 class="text-sm font-bold text-gray-800 mb-3 flex items-center">
                                        Login Page
                                    </h4>
                                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-orange-500 hover:bg-white transition-all cursor-pointer">
                                        <?php if (!empty($system_settings['login_image']) && file_exists('../' . $system_settings['login_image'])): ?>
                                            <img src="../<?php echo htmlspecialchars($system_settings['login_image']); ?>" 
                                                 alt="Login Image" 
                                                 class="w-full h-40 object-cover rounded-lg mb-3 shadow-sm"
                                                 id="login_preview">
                                        <?php else: ?>
                                            <div class="w-full h-40 bg-gradient-to-br from-gray-100 to-gray-200 rounded-lg mb-3 flex flex-col items-center justify-center" id="login_preview">
                                                <div class="text-2xl font-bold tracking-wider mb-2">IMG</div>
                                                <div class="text-xs text-gray-500">No image uploaded</div>
                                            </div>
                                        <?php endif; ?>
                                        <input type="file" name="login_image" id="login_image" accept="image/*" 
                                               class="hidden" onchange="previewImage(this, 'login_preview')">
                                        <label for="login_image" class="cursor-pointer inline-block px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium rounded-lg transition-colors">
                                            Choose File
                                        </label>
                                    </div>
                                </div>
                                
                                <!-- Register Image -->
                                <div class="bg-gray-50 rounded-xl p-6 border-2 border-gray-200">
                                    <h4 class="text-sm font-bold text-gray-800 mb-3 flex items-center">
                                        Register Page
                                    </h4>
                                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-orange-500 hover:bg-white transition-all cursor-pointer">
                                        <?php if (!empty($system_settings['register_image']) && file_exists('../' . $system_settings['register_image'])): ?>
                                            <img src="../<?php echo htmlspecialchars($system_settings['register_image']); ?>" 
                                                 alt="Register Image" 
                                                 class="w-full h-40 object-cover rounded-lg mb-3 shadow-sm"
                                                 id="register_preview">
                                        <?php else: ?>
                                            <div class="w-full h-40 bg-gradient-to-br from-gray-100 to-gray-200 rounded-lg mb-3 flex flex-col items-center justify-center" id="register_preview">
                                                <div class="text-2xl font-bold tracking-wider mb-2">IMG</div>
                                                <div class="text-xs text-gray-500">No image uploaded</div>
                                            </div>
                                        <?php endif; ?>
                                        <input type="file" name="register_image" id="register_image" accept="image/*" 
                                               class="hidden" onchange="previewImage(this, 'register_preview')">
                                        <label for="register_image" class="cursor-pointer inline-block px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium rounded-lg transition-colors">
                                            Choose File
                                        </label>
                                    </div>
                                </div>

                                <!-- Site Logo -->
                                <div class="bg-gray-50 rounded-xl p-6 border-2 border-gray-200">
                                    <h4 class="text-sm font-bold text-gray-800 mb-3 flex items-center">
                                        Site Logo (remove.bg)
                                    </h4>
                                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-orange-500 hover:bg-white transition-all cursor-pointer">
                                        <?php if (!empty($system_settings['site_logo']) && file_exists('../' . $system_settings['site_logo'])): ?>
                                            <img src="../<?php echo htmlspecialchars($system_settings['site_logo']); ?>"
                                                 alt="Site Logo"
                                                 class="w-full h-40 object-contain rounded-lg mb-3 shadow-sm bg-gray-900/30 p-3"
                                                 id="site_logo_preview">
                                        <?php else: ?>
                                            <div class="w-full h-40 bg-gradient-to-br from-gray-100 to-gray-200 rounded-lg mb-3 flex flex-col items-center justify-center" id="site_logo_preview">
                                                <div class="text-2xl font-bold tracking-wider mb-2">LOGO</div>
                                                <div class="text-xs text-gray-500">No logo uploaded</div>
                                            </div>
                                        <?php endif; ?>
                                        <input type="file" name="site_logo" id="site_logo" accept="image/*"
                                               class="hidden" onchange="previewImage(this, 'site_logo_preview')">
                                        <label for="site_logo" class="cursor-pointer inline-block px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium rounded-lg transition-colors">
                                            Choose File
                                        </label>
                                    </div>
                                    <p class="mt-3 text-xs text-gray-500">Background will be auto-removed using remove.bg when available.</p>
                                </div>
                            </div>
                        </div>


                        <!-- Save Button (visible on all tabs except users) -->
                        <div id="settingsSaveSection" class="px-8 pb-8 pt-4 border-t border-gray-200">
                            <div class="flex justify-end gap-3">
                                <button type="button" onclick="location.reload()" class="theme-btn-soft px-6 py-3 rounded-lg font-medium transition-colors inline-flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    Reset
                                </button>
                                <button type="submit" class="gradient-button text-white px-8 py-3 rounded-lg font-semibold shadow-lg hover:shadow-xl inline-flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Save All Settings
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- User Management Tab -->
                    <div id="content-users" class="tab-content hidden p-8">
                        <div class="mb-6">
                            <h3 class="text-xl font-bold text-gray-800 inline-flex items-center gap-2">
                                <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-1a4 4 0 00-5.7-3.63M17 20H7m10 0v-1c0-1.4-.48-2.68-1.29-3.7M7 20H2v-1a4 4 0 015.7-3.63M7 20v-1c0-1.4.48-2.68 1.29-3.7m0 0a5 5 0 017.42 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                User Management
                            </h3>
                            <p class="text-sm text-gray-600 mt-1">Manage user accounts, profile details, and roles</p>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 mb-6">
                            <form method="GET" class="flex flex-col md:flex-row gap-3 md:items-center">
                                <input type="hidden" name="tab" value="users">
                                <input type="text" name="search" value="<?php echo htmlspecialchars($user_search); ?>"
                                       placeholder="Search by name, email, or phone..."
                                       class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none">
                                <select name="role" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none">
                                    <option value="all" <?php echo $user_role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                                    <option value="admin" <?php echo $user_role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="claimant" <?php echo $user_role_filter === 'claimant' ? 'selected' : ''; ?>>Claimant</option>
                                </select>
                                <button type="submit" class="px-5 py-2 gradient-button text-white rounded-lg font-medium">Search</button>
                            </form>
                        </div>

                        <div class="text-sm text-gray-600 mb-4">
                            <span class="font-semibold text-gray-800"><?php echo number_format($total_users); ?></span> user<?php echo $total_users === 1 ? '' : 's'; ?> found
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse">
                                <thead>
                                    <tr class="bg-gradient-to-r from-gray-50 to-gray-100 border-b-2 border-orange-500">
                                        <th class="text-left py-4 px-4 font-bold text-gray-800 text-sm">ID</th>
                                        <th class="text-left py-4 px-4 font-bold text-gray-800 text-sm">User</th>
                                        <th class="text-left py-4 px-4 font-bold text-gray-800 text-sm">Email</th>
                                        <th class="text-left py-4 px-4 font-bold text-gray-800 text-sm">Phone</th>
                                        <th class="text-left py-4 px-4 font-bold text-gray-800 text-sm">Role</th>
                                        <th class="text-left py-4 px-4 font-bold text-gray-800 text-sm">Member Since</th>
                                        <th class="text-center py-4 px-4 font-bold text-gray-800 text-sm">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user_item): ?>
                                    <?php $userProfileSrc = resolveUserProfileImageSrc($user_item['image_path'] ?? null); ?>
                                    <tr class="border-b border-gray-100 hover:bg-gradient-to-r hover:from-orange-50 hover:to-amber-50 transition-all">
                                        <td class="py-4 px-4 text-gray-700 font-medium"><?php echo (int)$user_item['user_id']; ?></td>
                                        <td class="py-4 px-4">
                                            <div class="flex items-center">
                                                <div class="mr-3 relative">
                                                    <?php if (!empty($userProfileSrc)): ?>
                                                        <img src="<?php echo htmlspecialchars((string)$userProfileSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($user_item['full_name']); ?>" class="user-avatar-image" onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');">
                                                        <span class="user-avatar-fallback hidden"><?php echo htmlspecialchars(strtoupper(substr($user_item['full_name'], 0, 2))); ?></span>
                                                    <?php else: ?>
                                                        <span class="user-avatar-fallback"><?php echo htmlspecialchars(strtoupper(substr($user_item['full_name'], 0, 2))); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($user_item['full_name']); ?></div>
                                                    <?php if ($user_item['user_id'] == $_SESSION['user_id']): ?>
                                                    <div class="text-xs text-orange-300 font-semibold">You</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="text-gray-600 text-sm"><?php echo htmlspecialchars(maskEmailForDisplay((string)$user_item['email'])); ?></div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <?php $masked_phone = maskPhoneForDisplay($user_item['phone'] ?? null); ?>
                                            <div class="text-gray-600 text-sm"><?php echo htmlspecialchars($masked_phone !== '' ? $masked_phone : 'N/A'); ?></div>
                                        </td>
                                        <td class="py-4 px-4">
                                            <span class="inline-flex items-center px-3 py-1.5 text-xs font-bold rounded-full
                                                <?php
                                                $roleValue = (string)$user_item['role'];
                                                if ($roleValue === 'admin') {
                                                    echo 'bg-gradient-to-r from-red-100 to-red-200 text-red-800 border border-red-300';
                                                } elseif ($roleValue === 'claimant') {
                                                    echo 'bg-gradient-to-r from-orange-100 to-amber-200 text-orange-800 border border-orange-300';
                                                } else {
                                                    echo 'bg-gradient-to-r from-gray-100 to-gray-200 text-gray-700 border border-gray-300';
                                                }
                                                ?>">
                                                <?php
                                                if ($roleValue === 'admin') {
                                                    echo 'ADM Admin';
                                                } elseif ($roleValue === 'claimant') {
                                                    echo 'USR Claimant';
                                                } else {
                                                    echo 'UNK ' . htmlspecialchars(ucfirst($roleValue));
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-4">
                                            <div class="text-gray-600 text-sm"><?php echo date('M d, Y', strtotime($user_item['created_at'])); ?></div>
                                        </td>
                                        <td class="py-4 px-4 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <button
                                                    onclick="editUser(<?php echo (int)$user_item['user_id']; ?>, '<?php echo htmlspecialchars($user_item['full_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user_item['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user_item['phone'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user_item['role'], ENT_QUOTES); ?>')"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 text-white text-xs font-semibold rounded-lg transition-all shadow-sm hover:shadow-md"
                                                >
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5h2m-7 4h12M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zm1-6l2 2 4-4"></path></svg>
                                                    Edit
                                                </button>
                                                <?php if ((int)$user_item['user_id'] !== (int)$_SESSION['user_id']): ?>
                                                    <button
                                                        onclick="deleteUser(<?php echo (int)$user_item['user_id']; ?>, '<?php echo htmlspecialchars($user_item['full_name'], ENT_QUOTES); ?>')"
                                                        class="inline-flex items-center gap-1.5 px-3 py-2 bg-gradient-to-r from-red-500 to-orange-600 hover:from-red-600 hover:to-orange-700 text-white text-xs font-semibold rounded-lg transition-all shadow-sm hover:shadow-md"
                                                    >
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-8 0l1 12h8l1-12"></path></svg>
                                                        Delete
                                                    </button>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-2 bg-gray-100 text-gray-500 text-xs font-semibold rounded-lg">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                        You
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="7" class="py-8 px-4 text-center text-gray-500">No users found for the selected filters.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_users_pages > 1): ?>
                        <div class="mt-6 flex flex-col md:flex-row items-center justify-between gap-4">
                            <div class="text-sm text-gray-600">
                                Showing <?php echo ($users_offset + 1); ?> to <?php echo min($users_offset + $users_per_page, $total_users); ?> of <?php echo $total_users; ?> users
                            </div>
                            <div class="flex items-center gap-2">
                                <?php if ($users_page > 1): ?>
                                    <a href="?tab=users&users_page=<?php echo ($users_page - 1); ?>&search=<?php echo urlencode($user_search); ?>&role=<?php echo urlencode($user_role_filter); ?>"
                                       class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Previous</a>
                                <?php endif; ?>

                                <?php for ($i = max(1, $users_page - 2); $i <= min($total_users_pages, $users_page + 2); $i++): ?>
                                    <a href="?tab=users&users_page=<?php echo $i; ?>&search=<?php echo urlencode($user_search); ?>&role=<?php echo urlencode($user_role_filter); ?>"
                                       class="px-4 py-2 rounded-lg <?php echo $i === $users_page ? 'gradient-button text-white' : 'border border-gray-300 hover:bg-gray-50'; ?> transition-colors">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($users_page < $total_users_pages): ?>
                                    <a href="?tab=users&users_page=<?php echo ($users_page + 1); ?>&search=<?php echo urlencode($user_search); ?>&role=<?php echo urlencode($user_role_filter); ?>"
                                       class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Database Tools Tab -->
                    <div id="content-database" class="tab-content hidden p-8">
                        <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div>
                                <h3 class="text-xl font-bold text-gray-800">Database Security Center</h3>
                                <p class="text-sm text-gray-600 mt-1">Critical operations are protected by per-tab password unlock, signed action tokens, and backup/audit controls.</p>
                            </div>
                            <span id="dbSessionBadge" class="db-header-chip inline-flex items-center gap-2">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l2.5 2.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span id="dbSessionBadgeText">Session Locked</span>
                                <span id="dbSessionCountdown" class="hidden">03:00</span>
                            </span>
                        </div>

                        <div id="dbLockedBox" class="db-card mb-6">
                            <div class="flex items-start gap-3">
                                <span class="db-card-icon">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2h-1V9a5 5 0 00-10 0v2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                                </span>
                                <div>
                                    <h4 class="text-base font-semibold text-orange-900">Database tools are locked</h4>
                                    <p class="text-sm text-orange-800 mt-1">Enter admin password each time you open this tab. Unlock token expires automatically after a short time.</p>
                                    <button type="button" onclick="openDbUnlockModal()" class="mt-4 px-4 py-2 rounded-lg bg-gradient-to-r from-orange-500 to-red-500 text-white text-sm font-semibold hover:from-orange-400 hover:to-red-400">
                                        Unlock Database Tools
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div id="dbToolsBox" class="hidden space-y-6">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                                <div class="db-card">
                                    <div class="flex items-center gap-3 mb-2">
                                        <span class="db-card-icon"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M8 12l4 4m0 0l4-4m-4 4V4"/></svg></span>
                                        <h4 class="font-semibold text-gray-900">Backup Database</h4>
                                    </div>
                                    <p class="text-sm text-gray-700 mb-4">Create encrypted-checksum SQL backup and save it to <?php echo htmlspecialchars($backupStorageRelativePath); ?>.</p>
                                    <form method="POST" class="space-y-2">
                                        <input type="hidden" name="action" value="backup_database">
                                        <input type="hidden" name="db_action_token" value="" class="db-action-token-input">
                                        <button type="submit" class="theme-btn px-4 py-2 rounded-lg text-sm font-semibold">Create Backup</button>
                                    </form>
                                </div>

                                <div class="db-card">
                                    <div class="flex items-center gap-3 mb-2">
                                        <span class="db-card-icon"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M3 12h18M3 17h18"/></svg></span>
                                        <h4 class="font-semibold text-gray-900">Export Database</h4>
                                    </div>
                                    <p class="text-sm text-gray-700 mb-4">Download full SQL export and keep a secure copy in backup storage for incident recovery.</p>
                                    <form method="POST" class="space-y-2">
                                        <input type="hidden" name="action" value="export_database">
                                        <input type="hidden" name="db_action_token" value="" class="db-action-token-input">
                                        <button type="submit" class="theme-btn px-4 py-2 rounded-lg text-sm font-semibold">Download SQL Export</button>
                                    </form>
                                </div>
                            </div>

                            <div class="db-card">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="db-card-icon"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 0115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg></span>
                                    <h4 class="font-semibold text-gray-900">Import SQL File</h4>
                                </div>
                                <p class="text-sm text-gray-700 mb-4">Imports are screened for risky SQL, capped by file size, and protected with automatic pre-import backup.</p>
                                <form method="POST" enctype="multipart/form-data" class="flex flex-col md:flex-row md:items-center gap-3">
                                    <input type="hidden" name="action" value="import_database">
                                    <input type="hidden" name="db_action_token" value="" class="db-action-token-input">
                                    <input type="file" name="db_import_file" accept=".sql" required class="block w-full md:w-auto text-sm text-gray-700">
                                    <button type="submit" class="theme-btn px-4 py-2 rounded-lg text-sm font-semibold">Import SQL</button>
                                </form>
                            </div>

                            <div class="db-card db-card-danger">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="db-card-icon"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></span>
                                    <h4 class="font-semibold text-red-800">Clear Database Records</h4>
                                </div>
                                <p class="text-sm text-red-700 mb-4">High-risk operation: requires phrase match, 5-second modal countdown, and automatic recovery backup before wipe.</p>
                                <form id="clearDatabaseForm" method="POST" class="space-y-3" onsubmit="return handleClearDatabaseFormSubmit(event);">
                                    <input type="hidden" name="action" value="clear_database">
                                    <input type="hidden" name="db_action_token" value="" class="db-action-token-input">
                                    <label for="clear_confirmation" class="block text-sm text-red-800">Type <strong>CLEAR DATABASE</strong> to continue:</label>
                                    <input type="text" name="clear_confirmation" id="clear_confirmation" required class="w-full md:w-80 px-3 py-2 border border-red-300 rounded-lg" placeholder="CLEAR DATABASE">
                                    <button type="submit" class="theme-btn-danger px-4 py-2 rounded-lg text-sm font-semibold">Clear Records</button>
                                </form>
                            </div>

                            <div class="db-card">
                                <h4 class="font-semibold text-gray-900 mb-3">Security Hardening Enabled</h4>
                                <ul class="db-security-list">
                                    <li>Admin password verification with unlock rate limiting and CSRF validation.</li>
                                    <li>Short-lived signed action token required by every database operation form.</li>
                                    <li>Sensitive operations written to audit log at storage/logs/database_tools_audit.log.</li>
                                    <li>Automatic pre-import and pre-clear backups with SHA-256 checksum files.</li>
                                    <li>Import restrictions block risky SQL keywords used in privilege escalation attacks.</li>
                                </ul>
                                <p class="text-xs text-gray-600 mt-4">Backup storage location: <strong><?php echo htmlspecialchars($backupStorageRelativePath); ?></strong></p>
                            </div>

                            <div class="db-card">
                                <h4 class="font-semibold text-gray-900 mb-3">Recent Backups</h4>
                                <?php if (empty($recentBackups)): ?>
                                    <p class="text-sm text-gray-600">No backup files found yet. Create one to start your recovery history.</p>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="border-b border-orange-100 text-left text-orange-900">
                                                    <th class="py-2 pr-3">File</th>
                                                    <th class="py-2 pr-3">Size</th>
                                                    <th class="py-2 pr-3">Created</th>
                                                    <th class="py-2 pr-3">SHA-256</th>
                                                    <th class="py-2">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentBackups as $backupItem): ?>
                                                    <tr class="border-b border-orange-50">
                                                        <td class="py-2 pr-3 font-semibold text-gray-800"><?php echo htmlspecialchars((string)$backupItem['name']); ?></td>
                                                        <td class="py-2 pr-3 text-gray-700"><?php echo htmlspecialchars(formatBytes((int)$backupItem['size'])); ?></td>
                                                        <td class="py-2 pr-3 text-gray-700"><?php echo htmlspecialchars(date('Y-m-d H:i', (int)$backupItem['modified_at'])); ?></td>
                                                        <td class="py-2 pr-3 text-xs text-gray-600"><?php echo htmlspecialchars((string)($backupItem['checksum'] !== '' ? substr((string)$backupItem['checksum'], 0, 18) . '...' : 'n/a')); ?></td>
                                                        <td class="py-2">
                                                            <form method="POST" class="inline-flex">
                                                                <input type="hidden" name="action" value="download_backup_file">
                                                                <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars((string)$backupItem['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                                <input type="hidden" name="db_action_token" value="" class="db-action-token-input">
                                                                <button type="submit" class="theme-btn-soft px-3 py-1.5 rounded-md text-xs font-semibold">Download</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="fixed inset-0 bg-black bg-opacity-50 theme-modal-backdrop hidden items-center justify-center z-50 p-4">
        <div class="theme-modal-panel settings-modal-panel rounded-xl max-w-md w-full p-6 shadow-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900 settings-modal-title">
                    <span class="settings-modal-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5h2m-7 4h12M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zm1-6l2 2 4-4"></path></svg>
                    </span>
                    Edit User
                </h3>
                <button onclick="closeEditUserModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId">

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Full Name</label>
                    <input type="text" name="full_name" id="editUserName" required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Email</label>
                    <input type="email" name="email" id="editUserEmail" required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Phone</label>
                    <input type="text" name="phone" id="editUserPhone"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none"
                           placeholder="Optional">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Role</label>
                    <select name="role" id="editUserRole" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none">
                        <option value="claimant">Claimant</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 gradient-button text-white py-3 px-4 rounded-lg font-semibold inline-flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        Save Changes
                    </button>
                    <button type="button" onclick="closeEditUserModal()" class="flex-1 theme-btn-soft py-3 px-4 rounded-lg font-semibold inline-flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div id="deleteUserModal" class="fixed inset-0 bg-black bg-opacity-50 theme-modal-backdrop hidden items-center justify-center z-50 p-4">
        <div class="theme-modal-panel settings-modal-panel rounded-xl max-w-md w-full p-6 shadow-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900 settings-modal-title">
                    <span class="settings-modal-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-8 0l1 12h8l1-12"></path></svg>
                    </span>
                    Delete User
                </h3>
                <button onclick="closeDeleteUserModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <p class="text-gray-600 mb-5">Are you sure you want to delete <span id="deleteUserName" class="font-semibold text-gray-900"></span>? This action cannot be undone.</p>

            <form method="POST" class="flex gap-3">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="deleteUserId">

                <button type="button" onclick="closeDeleteUserModal()" class="flex-1 theme-btn-soft py-3 px-4 rounded-lg font-semibold inline-flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    Cancel
                </button>
                <button type="submit" class="flex-1 theme-btn-danger py-3 px-4 rounded-lg font-semibold inline-flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-8 0l1 12h8l1-12"></path></svg>
                    Delete
                </button>
            </form>
        </div>
    </div>

    <!-- Clear Database Confirmation Modal -->
    <div id="clearDatabaseModal" class="fixed inset-0 bg-black bg-opacity-50 theme-modal-backdrop hidden items-center justify-center z-50 p-4">
        <div class="theme-modal-panel settings-modal-panel rounded-xl max-w-md w-full p-6 shadow-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-red-700 settings-modal-title">
                    <span class="settings-modal-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"></path></svg>
                    </span>
                    Final Confirmation
                </h3>
                <button type="button" onclick="closeClearDatabaseModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <p class="text-sm text-gray-700 mb-3">This operation will permanently clear records from all tables except users and system_settings.</p>
            <p id="clearDatabaseCountdownText" class="text-sm text-red-700 font-semibold mb-5">Proceed button will be enabled in 5 seconds.</p>

            <div class="flex gap-3">
                <button type="button" onclick="closeClearDatabaseModal()" class="flex-1 theme-btn-soft py-3 px-4 rounded-lg font-semibold inline-flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    Cancel
                </button>
                <button type="button" id="clearDatabaseProceedBtn" onclick="proceedClearDatabase()" disabled class="flex-1 theme-btn-danger py-3 px-4 rounded-lg font-semibold disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"></path></svg>
                    Proceed
                </button>
            </div>
        </div>
    </div>

    <!-- Database Unlock Modal -->
    <div id="dbUnlockModal" class="fixed inset-0 bg-black bg-opacity-50 theme-modal-backdrop hidden items-center justify-center z-50 p-4">
        <div class="theme-modal-panel settings-modal-panel rounded-xl max-w-md w-full p-6 shadow-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900 settings-modal-title">
                    <span class="settings-modal-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2h-1V9a5 5 0 00-10 0v2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>
                    </span>
                    Unlock Database Tools
                </h3>
                <button type="button" onclick="closeDbUnlockModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <p class="text-sm text-gray-600 mb-4">Enter your current admin password to unlock database tools for this tab session (maximum 3 minutes).</p>

            <form id="dbUnlockForm" class="space-y-4">
                <input type="hidden" id="dbUnlockCsrf" value="<?php echo htmlspecialchars($dbToolsUnlockCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2" for="dbUnlockPassword">Admin Password</label>
                    <input
                        type="password"
                        id="dbUnlockPassword"
                        autocomplete="current-password"
                        required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none"
                        placeholder="Enter your password">
                </div>

                <p id="dbUnlockError" class="hidden text-sm text-red-600"></p>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeDbUnlockModal()" class="flex-1 theme-btn-soft py-3 px-4 rounded-lg font-semibold inline-flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        Cancel
                    </button>
                    <button type="submit" id="dbUnlockSubmit" class="flex-1 gradient-button text-white py-3 px-4 rounded-lg font-semibold inline-flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        Unlock
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let dbToolsSessionToken = '';
        let dbToolsExpiryTimer = null;
        let dbToolsCountdownTimer = null;
        let dbToolsRemainingSeconds = 0;

        function formatCountdown(totalSeconds) {
            const seconds = Math.max(0, Number(totalSeconds || 0));
            const minutesPart = Math.floor(seconds / 60).toString().padStart(2, '0');
            const secondsPart = Math.floor(seconds % 60).toString().padStart(2, '0');
            return minutesPart + ':' + secondsPart;
        }

        function updateDbSessionCountdownUi() {
            const badgeText = document.getElementById('dbSessionBadgeText');
            const badgeCountdown = document.getElementById('dbSessionCountdown');
            if (!badgeText || !badgeCountdown) {
                return;
            }

            if (!dbToolsSessionToken || dbToolsRemainingSeconds <= 0) {
                badgeText.textContent = 'Session Locked';
                badgeCountdown.classList.add('hidden');
                return;
            }

            badgeText.textContent = 'Unlocked';
            badgeCountdown.textContent = formatCountdown(dbToolsRemainingSeconds);
            badgeCountdown.classList.remove('hidden');
        }

        function stopDbCountdownTimer() {
            if (dbToolsCountdownTimer !== null) {
                window.clearInterval(dbToolsCountdownTimer);
                dbToolsCountdownTimer = null;
            }
        }

        function setDbActionTokenOnForms(token) {
            document.querySelectorAll('.db-action-token-input').forEach(input => {
                input.value = token || '';
            });
        }

        function setDbToolsLockedState(isLocked) {
            const lockedBox = document.getElementById('dbLockedBox');
            const toolsBox = document.getElementById('dbToolsBox');

            if (lockedBox) {
                lockedBox.classList.toggle('hidden', !isLocked);
            }
            if (toolsBox) {
                toolsBox.classList.toggle('hidden', isLocked);
            }

            updateDbSessionCountdownUi();
        }

        function clearDbToolsSession() {
            dbToolsSessionToken = '';
            dbToolsRemainingSeconds = 0;
            if (dbToolsExpiryTimer !== null) {
                window.clearTimeout(dbToolsExpiryTimer);
                dbToolsExpiryTimer = null;
            }
            stopDbCountdownTimer();
            setDbActionTokenOnForms('');
            setDbToolsLockedState(true);
        }

        function scheduleDbToolsSessionExpiry(ttlSeconds) {
            if (dbToolsExpiryTimer !== null) {
                window.clearTimeout(dbToolsExpiryTimer);
            }

            const ttl = Number(ttlSeconds || 0);
            if (!Number.isFinite(ttl) || ttl <= 0) {
                return;
            }

            dbToolsRemainingSeconds = Math.floor(ttl);
            updateDbSessionCountdownUi();
            stopDbCountdownTimer();
            dbToolsCountdownTimer = window.setInterval(function() {
                dbToolsRemainingSeconds -= 1;
                if (dbToolsRemainingSeconds <= 0) {
                    dbToolsRemainingSeconds = 0;
                    updateDbSessionCountdownUi();
                    stopDbCountdownTimer();
                    return;
                }
                updateDbSessionCountdownUi();
            }, 1000);

            dbToolsExpiryTimer = window.setTimeout(function() {
                clearDbToolsSession();
                const dbTab = document.getElementById('content-database');
                if (dbTab && !dbTab.classList.contains('hidden')) {
                    openDbUnlockModal();
                }
            }, ttl * 1000);
        }

        function switchTab(tabName, updateHistory = true) {
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });

            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active', 'border-orange-500', 'text-orange-500');
                button.classList.add('border-transparent', 'text-gray-500');
            });

            const content = document.getElementById('content-' + tabName);
            const activeTab = document.getElementById('tab-' + tabName);
            if (content) {
                content.classList.remove('hidden');
            }
            if (activeTab) {
                activeTab.classList.add('active', 'border-orange-500', 'text-orange-500');
                activeTab.classList.remove('border-transparent', 'text-gray-500');
            }

            const settingsSaveSection = document.getElementById('settingsSaveSection');
            if (settingsSaveSection) {
                settingsSaveSection.classList.toggle('hidden', tabName === 'users');
            }

            if (tabName !== 'database') {
                clearDbToolsSession();
            } else {
                if (!dbToolsSessionToken) {
                    setDbToolsLockedState(true);
                    openDbUnlockModal();
                } else {
                    setDbToolsLockedState(false);
                }
            }

            if (updateHistory) {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tabName);
                if (tabName !== 'users') {
                    url.searchParams.delete('search');
                    url.searchParams.delete('role');
                    url.searchParams.delete('users_page');
                }
                window.history.replaceState({}, '', url.toString());
            }
        }

        function editUser(userId, fullName, email, phone, role) {
            document.getElementById('editUserId').value = userId;
            document.getElementById('editUserName').value = fullName;
            document.getElementById('editUserEmail').value = email;
            document.getElementById('editUserPhone').value = phone || '';
            document.getElementById('editUserRole').value = role;

            document.getElementById('editUserModal').classList.remove('hidden');
            document.getElementById('editUserModal').classList.add('flex');
        }

        function closeEditUserModal() {
            document.getElementById('editUserModal').classList.add('hidden');
            document.getElementById('editUserModal').classList.remove('flex');
        }

        function deleteUser(userId, fullName) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = fullName;

            document.getElementById('deleteUserModal').classList.remove('hidden');
            document.getElementById('deleteUserModal').classList.add('flex');
        }

        function closeDeleteUserModal() {
            document.getElementById('deleteUserModal').classList.add('hidden');
            document.getElementById('deleteUserModal').classList.remove('flex');
        }

        function openDbUnlockModal() {
            const modal = document.getElementById('dbUnlockModal');
            const passwordInput = document.getElementById('dbUnlockPassword');
            const error = document.getElementById('dbUnlockError');

            if (!modal) {
                return;
            }

            error.classList.add('hidden');
            error.textContent = '';
            passwordInput.value = '';

            modal.classList.remove('hidden');
            modal.classList.add('flex');

            setTimeout(function() {
                passwordInput.focus();
            }, 0);
        }

        function closeDbUnlockModal() {
            const modal = document.getElementById('dbUnlockModal');
            const error = document.getElementById('dbUnlockError');
            const submit = document.getElementById('dbUnlockSubmit');

            if (!modal) {
                return;
            }

            error.classList.add('hidden');
            error.textContent = '';
            submit.disabled = false;
            submit.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>Unlock';

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        let clearDatabaseCountdownTimer = null;
        let clearDatabaseSeconds = 5;
        let clearDatabaseAllowSubmit = false;

        function handleClearDatabaseFormSubmit(event) {
            const confirmationInput = document.getElementById('clear_confirmation');
            const value = ((confirmationInput && confirmationInput.value) ? confirmationInput.value : '').trim().toUpperCase();

            if (value !== 'CLEAR DATABASE') {
                alert('Please type CLEAR DATABASE exactly before continuing.');
                return false;
            }

            if (clearDatabaseAllowSubmit) {
                clearDatabaseAllowSubmit = false;
                return true;
            }

            event.preventDefault();
            openClearDatabaseModal();
            return false;
        }

        function openClearDatabaseModal() {
            const modal = document.getElementById('clearDatabaseModal');
            const countdownText = document.getElementById('clearDatabaseCountdownText');
            const proceedBtn = document.getElementById('clearDatabaseProceedBtn');

            if (!modal || !countdownText || !proceedBtn) {
                return;
            }

            clearClearDatabaseCountdown();
            clearDatabaseSeconds = 5;
            proceedBtn.disabled = true;
            countdownText.textContent = 'Proceed button will be enabled in ' + clearDatabaseSeconds + ' seconds.';

            modal.classList.remove('hidden');
            modal.classList.add('flex');

            clearDatabaseCountdownTimer = window.setInterval(function() {
                clearDatabaseSeconds -= 1;

                if (clearDatabaseSeconds <= 0) {
                    proceedBtn.disabled = false;
                    countdownText.textContent = 'You can now proceed or cancel.';
                    clearClearDatabaseCountdown();
                    return;
                }

                countdownText.textContent = 'Proceed button will be enabled in ' + clearDatabaseSeconds + ' seconds.';
            }, 1000);
        }

        function clearClearDatabaseCountdown() {
            if (clearDatabaseCountdownTimer !== null) {
                window.clearInterval(clearDatabaseCountdownTimer);
                clearDatabaseCountdownTimer = null;
            }
        }

        function closeClearDatabaseModal() {
            const modal = document.getElementById('clearDatabaseModal');

            clearClearDatabaseCountdown();
            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function proceedClearDatabase() {
            const proceedBtn = document.getElementById('clearDatabaseProceedBtn');
            const form = document.getElementById('clearDatabaseForm');

            if (!proceedBtn || proceedBtn.disabled || !form) {
                return;
            }

            clearDatabaseAllowSubmit = true;
            closeClearDatabaseModal();

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        }

        async function handleDbUnlock(event) {
            event.preventDefault();

            const passwordInput = document.getElementById('dbUnlockPassword');
            const csrfInput = document.getElementById('dbUnlockCsrf');
            const error = document.getElementById('dbUnlockError');
            const submit = document.getElementById('dbUnlockSubmit');

            const password = (passwordInput.value || '').trim();
            if (!password) {
                error.textContent = 'Please enter your admin password.';
                error.classList.remove('hidden');
                passwordInput.focus();
                return;
            }

            error.classList.add('hidden');
            error.textContent = '';
            submit.disabled = true;
            submit.textContent = 'Unlocking...';

            try {
                const payload = new FormData();
                payload.append('action', 'unlock_db_tools');
                payload.append('admin_password', password);
                payload.append('db_tools_unlock_csrf', csrfInput.value || '');

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: payload,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();
                if (!data.success) {
                    error.textContent = data.message || 'Unable to unlock database tools.';
                    error.classList.remove('hidden');
                    return;
                }

                dbToolsSessionToken = String(data.db_action_token || '');
                if (!dbToolsSessionToken) {
                    error.textContent = 'Unlock succeeded but no action token was issued. Try again.';
                    error.classList.remove('hidden');
                    return;
                }

                setDbActionTokenOnForms(dbToolsSessionToken);
                setDbToolsLockedState(false);
                scheduleDbToolsSessionExpiry(data.token_ttl);

                closeDbUnlockModal();
            } catch (e) {
                error.textContent = 'Network error while unlocking. Please try again.';
                error.classList.remove('hidden');
            } finally {
                submit.disabled = false;
                submit.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>Unlock';
            }
        }

        document.getElementById('editUserModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditUserModal();
            }
        });

        document.getElementById('deleteUserModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteUserModal();
            }
        });

        document.getElementById('clearDatabaseModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeClearDatabaseModal();
            }
        });

        document.getElementById('dbUnlockModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDbUnlockModal();
            }
        });

        document.getElementById('dbUnlockForm').addEventListener('submit', handleDbUnlock);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditUserModal();
                closeDeleteUserModal();
                closeClearDatabaseModal();
                closeDbUnlockModal();
            }
        });

        function previewImage(input, previewId) {
            const preview = document.getElementById(previewId);

            if (input.files && input.files[0]) {
                const reader = new FileReader();

                reader.onload = function(e) {
                    const isLogoPreview = previewId === 'site_logo_preview';
                    const previewClass = isLogoPreview
                        ? 'w-full h-40 object-contain rounded-lg mb-3 shadow-sm bg-gray-900/30 p-3'
                        : 'w-full h-40 object-cover rounded-lg mb-3 shadow-sm';

                    if (preview.tagName === 'IMG') {
                        preview.src = e.target.result;
                        preview.className = previewClass;
                    } else {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = previewClass;
                        img.id = previewId;
                        preview.parentNode.replaceChild(img, preview);
                    }
                };

                reader.readAsDataURL(input.files[0]);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            clearDbToolsSession();
            const initialTab = <?php echo json_encode($active_tab); ?>;
            switchTab(initialTab || 'general', false);
        });
    </script>
</body>
</html>
