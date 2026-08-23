<?php
if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/db.php';
}

if (!function_exists('getTfaInstance')) {
  require_once __DIR__ . '/../helpers/mfa_helper.php';
}

if (!function_exists('ensureChatPinTable')) {
  require_once __DIR__ . '/../helpers/chat_pin_helper.php';
}

if (!function_exists('getAppBasePath')) {
  require_once __DIR__ . '/../helpers/assets_helper.php';
}

if (!isset($profile_role) || !in_array($profile_role, ['admin', 'claimant'], true)) {
    $profile_role = (string)($_SESSION['role'] ?? 'claimant');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$message = null;
$error = null;
$open_mfa_modal = false;
$open_reset_modal = false;
$open_chat_pin_modal = false;
$open_chat_pin_reset_modal = false;

$mfa_scope = 'profile_mfa_manage_' . $profile_role;
$mfa_enable_scope = 'profile_mfa_enable_' . $profile_role;
$mfa_backup_scope = 'profile_mfa_backup_download_' . $profile_role;
$mfa_reveal_scope = 'profile_mfa_reveal_full_' . $profile_role;
$mfa_reveal_session_key = 'profile_mfa_backup_codes_reveal_until_' . $profile_role;
$chat_pin_scope = 'profile_chat_pin_manage_' . $profile_role;
$chat_pin_reset_scope = 'profile_chat_pin_reset_' . $profile_role;

$mfa_runtime_available = function_exists('getTfaInstance');

$profileScriptDir = trim(str_replace('\\', '/', dirname((string)($_SERVER['PHP_SELF'] ?? ''))), '/');
$profileDirName = basename($profileScriptDir);
$profileInRoleFolder = in_array($profileDirName, ['admin', 'claimant'], true);
$profileAssetPrefix = $profileInRoleFolder ? '../' : '';

function profileNormalizePhone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function profileMaskEmail(string $email): string
{
  $email = trim($email);
  if ($email === '') {
    return 'Not set';
  }

  $parts = explode('@', $email, 2);
  if (count($parts) !== 2 || $parts[1] === '') {
    $first = substr($email, 0, 1);
    return ($first !== '' ? $first : '*') . '****';
  }

  $local = $parts[0];
  $domain = $parts[1];
  $firstLocal = substr($local, 0, 1);
  $maskedLocal = ($firstLocal !== '' ? $firstLocal : '*') . '****';

  return $maskedLocal . '@' . $domain;
}

function profileMaskPhone(string $phone): string
{
  $digits = profileNormalizePhone($phone);
  if ($digits === '') {
    return 'Not set';
  }

  $len = strlen($digits);
  if ($len <= 4) {
    return str_repeat('*', $len);
  }

  $prefix = substr($digits, 0, 2);
  $suffix = substr($digits, -2);

  return $prefix . str_repeat('*', max(2, $len - 4)) . $suffix;
}

function profileValidateImageUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, null, 'Failed to upload profile image.'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [false, null, 'Invalid uploaded file.'];
    }

    $maxFileSize = 5 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxFileSize) {
        return [false, null, 'Image file size too large. Maximum 5MB allowed.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($map[$mime])) {
        return [false, null, 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.'];
    }

    $ext = $map[$mime];
    $filename = 'profile_' . bin2hex(random_bytes(8)) . '.' . $ext;

    return [true, $filename, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'update_profile');

  if ($action === 'save_chat_pin') {
    $open_chat_pin_modal = true;
    $chat_pin_csrf_token = $_POST['csrf_token'] ?? null;
    $current_chat_pin = normalizeChatPin((string)($_POST['current_chat_pin'] ?? ''));
    $new_chat_pin = normalizeChatPin((string)($_POST['new_chat_pin'] ?? ''));
    $confirm_chat_pin = normalizeChatPin((string)($_POST['confirm_chat_pin'] ?? ''));

    try {
      if (!isValidCsrfToken($chat_pin_scope, is_string($chat_pin_csrf_token) ? $chat_pin_csrf_token : null)) {
        $error = 'Invalid chat PIN request token. Please try again.';
      } elseif (!isValidChatPinFormat($new_chat_pin) || !isValidChatPinFormat($confirm_chat_pin)) {
        $error = 'Chat PIN must be exactly 6 digits.';
      } elseif (!hash_equals($new_chat_pin, $confirm_chat_pin)) {
        $error = 'New chat PIN and confirmation do not match.';
      } else {
        $chat_pin_status_snapshot = getChatPinStatus($pdo, $userId);
        if (!empty($chat_pin_status_snapshot['has_pin'])) {
          if (!isValidChatPinFormat($current_chat_pin)) {
            $error = 'Current chat PIN is required to change your PIN.';
          } else {
            $verify_current_pin = verifyChatPin($pdo, $userId, $current_chat_pin);
            if (!$verify_current_pin['success']) {
              if (!empty($verify_current_pin['locked']) && !empty($verify_current_pin['locked_until'])) {
                $error = 'Chat PIN is locked until ' . (string)$verify_current_pin['locked_until'] . '.';
              } else {
                $remaining_attempts = (int)($verify_current_pin['remaining_attempts'] ?? 0);
                $error = 'Current chat PIN is incorrect. Remaining attempts: ' . $remaining_attempts . '.';
              }
            }
          }
        }
      }

      if ($error === null) {
        setChatPin($pdo, $userId, $new_chat_pin);
        clearChatPinVerifiedInSession($userId);
        $message = 'Chat PIN saved successfully. You will be asked for this PIN every time you open chat.';
        $open_chat_pin_modal = false;
      }
    } catch (Throwable $e) {
      $error = 'Error saving chat PIN: ' . $e->getMessage();
    }
  } elseif ($action === 'reset_chat_pin') {
    $open_chat_pin_reset_modal = true;
    $chat_pin_reset_csrf_token = $_POST['csrf_token'] ?? null;
    $chat_pin_reset_password = (string)($_POST['chat_pin_reset_password'] ?? '');

    try {
      if (!isValidCsrfToken($chat_pin_reset_scope, is_string($chat_pin_reset_csrf_token) ? $chat_pin_reset_csrf_token : null)) {
        $error = 'Invalid chat PIN reset token. Please try again.';
      } elseif ($chat_pin_reset_password === '') {
        $error = 'Enter your current account password to reset chat PIN.';
      } else {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $stored_hash = (string)($stmt->fetchColumn() ?? '');

        if ($stored_hash === '' || !password_verify($chat_pin_reset_password, $stored_hash)) {
          $error = 'Current password is incorrect. Chat PIN was not reset.';
        }
      }

      if ($error === null) {
        resetChatPin($pdo, $userId);
        $message = 'Chat PIN reset completed. Set a new PIN before entering chat again.';
        $open_chat_pin_reset_modal = false;
        $open_chat_pin_modal = true;
      }
    } catch (Throwable $e) {
      $error = 'Error resetting chat PIN: ' . $e->getMessage();
    }
  } elseif ($action === 'enable_mfa_from_profile') {
        $open_mfa_modal = true;
        $mfa_otp = preg_replace('/\D/', '', (string)($_POST['mfa_otp'] ?? ''));
        $mfa_csrf_token = $_POST['csrf_token'] ?? null;

        try {
            if (!$mfa_runtime_available) {
              $error = 'MFA library is unavailable in this environment.';
            } elseif (!isValidCsrfToken($mfa_enable_scope, is_string($mfa_csrf_token) ? $mfa_csrf_token : null)) {
                $error = 'Invalid MFA request token. Please try again.';
            } elseif (!preg_match('/^\d{6}$/', $mfa_otp)) {
                $error = 'Please enter a valid 6-digit authenticator code.';
            } elseif (hasTooManyOtpAttempts('profile_enable_mfa_' . $profile_role)) {
                $error = 'Too many invalid attempts. Please wait a few minutes and try again.';
            } else {
                $setup_secret = $_SESSION['profile_mfa_setup_secret'] ?? '';
                if (!is_string($setup_secret) || $setup_secret === '') {
                    $error = 'MFA setup session expired. Refresh the page and try again.';
                } else {
                    $tfa = getTfaInstance();
                    $is_valid = $tfa->verifyCode($setup_secret, $mfa_otp, 1);

                    if (!$is_valid) {
                        recordOtpAttempt('profile_enable_mfa_' . $profile_role);
                        $error = 'Invalid verification code. Please try again with the latest code.';
                    } else {
                        $update_stmt = $pdo->prepare('UPDATE users SET mfa_secret = ?, mfa_enabled = 1, mfa_backup_codes_downloaded_at = NULL WHERE user_id = ?');
                        $update_stmt->execute([$setup_secret, $userId]);

                        $backup_codes = generateBackupCodes(8);
                        saveBackupCodes($pdo, $userId, $backup_codes);
                        saveBackupCodeSnapshot($pdo, $userId, $backup_codes, normalizeMfaSecret($setup_secret));
                        $_SESSION['profile_mfa_new_backup_codes'] = $backup_codes;

                        clearOtpAttempts('profile_enable_mfa_' . $profile_role);
                        unset($_SESSION['profile_mfa_setup_secret']);
                        $message = 'MFA enabled successfully. Download your backup codes below.';
                    }
                }
            }
        } catch (Throwable $e) {
            $error = 'Error enabling MFA: ' . $e->getMessage();
        }
    } elseif ($action === 'regenerate_backup_codes') {
        $open_mfa_modal = true;
        $mfa_csrf_token = $_POST['csrf_token'] ?? null;

        try {
            if (!isValidCsrfToken($mfa_backup_scope, is_string($mfa_csrf_token) ? $mfa_csrf_token : null)) {
                $error = 'Invalid backup code request token.';
            } else {
                $stmt = $pdo->prepare('SELECT mfa_enabled, mfa_secret FROM users WHERE user_id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $mfa_row = $stmt->fetch();
                $is_enabled = $mfa_row && (int)($mfa_row['mfa_enabled'] ?? 0) === 1 && !empty($mfa_row['mfa_secret']);

                if (!$is_enabled) {
                    $error = 'Enable MFA first before generating backup codes.';
                } else {
                    $backup_codes = generateBackupCodes(8);
                    saveBackupCodes($pdo, $userId, $backup_codes);
                  saveBackupCodeSnapshot($pdo, $userId, $backup_codes, normalizeMfaSecret((string)$mfa_row['mfa_secret']));
                    $_SESSION['profile_mfa_new_backup_codes'] = $backup_codes;
                  unset($_SESSION[$mfa_reveal_session_key]);

                    $update_backup_stmt = $pdo->prepare('UPDATE users SET mfa_backup_codes_downloaded_at = NULL WHERE user_id = ?');
                    $update_backup_stmt->execute([$userId]);

                    $message = 'New backup codes generated. Download and store them securely.';
                }
            }
        } catch (Throwable $e) {
            $error = 'Error generating backup codes: ' . $e->getMessage();
        }
    } elseif ($action === 'download_backup_codes_profile') {
        $open_mfa_modal = true;
        $mfa_csrf_token = $_POST['csrf_token'] ?? null;

        if (!isValidCsrfToken($mfa_backup_scope, is_string($mfa_csrf_token) ? $mfa_csrf_token : null)) {
            $error = 'Invalid request token while downloading backup codes.';
        } else {
        $secret_stmt = $pdo->prepare('SELECT mfa_secret FROM users WHERE user_id = ? LIMIT 1');
        $secret_stmt->execute([$userId]);
        $mfa_secret = normalizeMfaSecret((string)($secret_stmt->fetchColumn() ?? ''));

        if ($mfa_secret === '') {
          $error = 'MFA secret is missing. Reconfigure MFA first.';
        } else {
          $backup_code_rows = getBackupCodeSnapshotWithStatus($pdo, $userId, $mfa_secret);
          $backup_codes = array_map(static fn(array $row): string => (string)($row['code'] ?? ''), $backup_code_rows);
          $_SESSION['profile_mfa_new_backup_codes'] = $backup_codes;

          if (count($backup_codes) === 0) {
            $error = 'No backup codes found. Generate codes first.';
          } else {
            $email_stmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
            $email_stmt->execute([$userId]);
            $email_row = $email_stmt->fetch();
            $email = (string)($email_row['email'] ?? 'user');
            $filename_safe_email = preg_replace('/[^a-zA-Z0-9._-]/', '_', $email) ?? 'user';
            $filename = 'findit-mfa-backup-codes-' . $filename_safe_email . '.txt';

            $content = [];
            $content[] = 'FindIt MFA Backup Codes';
            $content[] = 'Generated: ' . date('Y-m-d H:i:s');
            $content[] = 'Account: ' . $email;
            $content[] = '';
            $content[] = 'Important:';
            $content[] = '- Each code can be used once.';
            $content[] = '- Store this file securely and offline.';
            $content[] = '- Do not share these codes.';
            $content[] = '';
            $content[] = 'Backup Codes:';
            foreach ($backup_code_rows as $index => $row) {
              $code = (string)($row['code'] ?? '');
              if ($code === '') {
                continue;
              }
              $status = !empty($row['used']) ? ' [USED]' : ' [UNUSED]';
              $content[] = sprintf('%d. %s%s', $index + 1, $code, $status);
            }
            $content[] = '';

            $update_stmt = $pdo->prepare('UPDATE users SET mfa_backup_codes_downloaded_at = NOW() WHERE user_id = ?');
            $update_stmt->execute([$userId]);

            header('Content-Description: File Transfer');
            header('Content-Type: text/plain; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Pragma: public');
            echo implode(PHP_EOL, $content);
            exit;
          }
        }
        }
    } elseif ($action === 'view_full_backup_codes_profile') {
      $open_mfa_modal = true;
      $mfa_csrf_token = $_POST['csrf_token'] ?? null;
      $backup_codes_password = (string)($_POST['backup_codes_password'] ?? '');

      try {
        if (!isValidCsrfToken($mfa_reveal_scope, is_string($mfa_csrf_token) ? $mfa_csrf_token : null)) {
          $error = 'Invalid request token while revealing backup codes.';
        } elseif ($backup_codes_password === '') {
          $error = 'Enter your current account password to reveal full backup codes.';
        } else {
          $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
          $stmt->execute([$userId]);
          $stored_hash = (string)($stmt->fetchColumn() ?? '');

          if ($stored_hash === '' || !password_verify($backup_codes_password, $stored_hash)) {
            $error = 'Current password is incorrect. Full backup codes remain hidden.';
          }
        }

        if ($error === null) {
          $_SESSION[$mfa_reveal_session_key] = time() + 180;
          $message = 'Full backup codes are visible for 3 minutes in this session.';
        }
      } catch (Throwable $e) {
        $error = 'Error revealing backup codes: ' . $e->getMessage();
      }
    } elseif ($action === 'reset_mfa') {
        $open_reset_modal = true;
        $mfa_current_password = (string)($_POST['mfa_current_password'] ?? '');
        $mfa_csrf_token = $_POST['csrf_token'] ?? null;

        try {
            if (!isValidCsrfToken($mfa_scope, is_string($mfa_csrf_token) ? $mfa_csrf_token : null)) {
                $error = 'Invalid MFA request token. Please try again.';
            } elseif ($mfa_current_password === '') {
                $error = 'Please enter your current password to reset MFA.';
            } else {
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
                $stmt->execute([$userId]);
                $stored_hash = (string)($stmt->fetch()['password_hash'] ?? '');

                if ($stored_hash === '' || !password_verify($mfa_current_password, $stored_hash)) {
                    $error = 'Current password is incorrect. MFA was not reset.';
                }
            }

            if ($error === null) {
                ensureMfaBackupCodesTable($pdo);

                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE users SET mfa_enabled = 0, mfa_secret = NULL, mfa_backup_codes_downloaded_at = NULL WHERE user_id = ?');
                $stmt->execute([$userId]);

                $delete_codes_stmt = $pdo->prepare('DELETE FROM user_mfa_backup_codes WHERE user_id = ?');
                $delete_codes_stmt->execute([$userId]);
                clearBackupCodeSnapshot($pdo, $userId);
                $pdo->commit();

                unset(
                    $_SESSION['mfa_setup_secret'],
                    $_SESSION['mfa_new_backup_codes'],
                    $_SESSION['mfa_pending_user'],
                    $_SESSION['profile_mfa_setup_secret'],
                    $_SESSION['profile_mfa_new_backup_codes'],
                    $_SESSION[$mfa_reveal_session_key]
                );
                $message = 'MFA has been reset. Open the MFA modal to set it up again.';
                $open_reset_modal = false;
                $open_mfa_modal = true;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Error resetting MFA: ' . $e->getMessage();
        }
    } else {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = profileNormalizePhone((string)($_POST['phone'] ?? ''));
        $current_password = (string)($_POST['current_password'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        $errors = [];

        if ($full_name === '') {
            $errors[] = 'Full name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        if ($phone !== '' && !preg_match('/^9[0-9]{9}$/', $phone)) {
            $errors[] = 'Phone number must be 10 digits starting with 9.';
        }

        $password_change_requested = $new_password !== '' || $confirm_password !== '';
        if ($password_change_requested) {
            if ($current_password === '') {
                $errors[] = 'Current password is required to change password.';
            }
            if ($new_password !== $confirm_password) {
                $errors[] = 'New password and confirmation do not match.';
            }
            if (strlen($new_password) < 6) {
                $errors[] = 'New password must be at least 6 characters long.';
            }
        }

        if (count($errors) === 0) {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1');
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                $errors[] = 'Email address is already registered to another account.';
            }
        }

        if (count($errors) === 0 && $phone !== '') {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE phone = ? AND user_id != ? LIMIT 1');
            $stmt->execute([$phone, $userId]);
            if ($stmt->fetch()) {
                $errors[] = 'Phone number is already registered to another account.';
            }
        }

        if (count($errors) === 0 && $password_change_requested) {
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $stored_hash = (string)$stmt->fetchColumn();
            if ($stored_hash === '' || !password_verify($current_password, $stored_hash)) {
                $errors[] = 'Current password is incorrect.';
            }
        }

        $newImageRelPath = null;
        if (count($errors) === 0 && isset($_FILES['profile_image']) && (int)($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            [$isValid, $newFilename, $uploadError] = profileValidateImageUpload($_FILES['profile_image']);
            if (!$isValid || $newFilename === null) {
                $errors[] = (string)$uploadError;
            } else {
                $uploadDirFs = dirname(__DIR__, 2) . '/uploads/';
                if (!is_dir($uploadDirFs) && !mkdir($uploadDirFs, 0755, true) && !is_dir($uploadDirFs)) {
                    $errors[] = 'Failed to prepare upload directory.';
                } else {
                    $targetFs = $uploadDirFs . $newFilename;
                    if (!move_uploaded_file((string)$_FILES['profile_image']['tmp_name'], $targetFs)) {
                        $errors[] = 'Failed to upload profile image.';
                    } else {
                        $newImageRelPath = 'uploads/' . $newFilename;
                    }
                }
            }
        }

        if (count($errors) === 0) {
            $stmt = $pdo->prepare('SELECT image_path FROM users WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $oldImage = (string)($stmt->fetchColumn() ?? '');

            $fields = ['full_name = ?', 'email = ?', 'phone = ?'];
            $params = [$full_name, $email, $phone === '' ? null : $phone];

            if ($newImageRelPath !== null) {
                $fields[] = 'image_path = ?';
                $params[] = $newImageRelPath;
            }

            if ($password_change_requested) {
                $fields[] = 'password_hash = ?';
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            }

            $params[] = $userId;
            $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE user_id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($newImageRelPath !== null && $oldImage !== '' && str_starts_with($oldImage, 'uploads/')) {
                $oldFs = dirname(__DIR__, 2) . '/' . $oldImage;
                if (is_file($oldFs)) {
                    @unlink($oldFs);
                }
            }

            $_SESSION['full_name'] = $full_name;
            $_SESSION['email'] = $email;
            $message = 'Profile updated successfully!';
        } else {
            $error = implode(' ', $errors);
        }
    }
}

$current_user_stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
$current_user_stmt->execute([$userId]);
$current_user = $current_user_stmt->fetch();
if (!$current_user) {
    header('Location: ../login.php?reason=auth_required');
    exit;
}

  $current_user_email = (string)($current_user['email'] ?? '');
  $current_user_phone = (string)($current_user['phone'] ?? '');
  $masked_email_display = profileMaskEmail($current_user_email);
  $masked_phone_display = profileMaskPhone($current_user_phone);

ensureMfaUsersCompletionColumn($pdo);
ensureMfaBackupCodesTable($pdo);
ensureMfaBackupCodeSnapshotsTable($pdo);
ensureChatPinTable($pdo);
$mfa_stmt = $pdo->prepare('SELECT mfa_enabled, mfa_secret, mfa_backup_codes_downloaded_at FROM users WHERE user_id = ?');
$mfa_stmt->execute([$userId]);
$mfa_data = $mfa_stmt->fetch();

$is_mfa_enabled = !empty($mfa_data) && (int)($mfa_data['mfa_enabled'] ?? 0) === 1 && !empty($mfa_data['mfa_secret']);
$is_mfa_backup_downloaded = !empty($mfa_data['mfa_backup_codes_downloaded_at']);
$is_mfa_fully_active = $is_mfa_enabled && $is_mfa_backup_downloaded;
$mfa_remaining_backup_codes = getRemainingBackupCodeCount($pdo, $userId);
$mfa_last_backup_download = $mfa_data['mfa_backup_codes_downloaded_at'] ?? null;
$mfa_form_token = getCsrfToken($mfa_scope);
$mfa_enable_token = getCsrfToken($mfa_enable_scope);
$mfa_backup_token = getCsrfToken($mfa_backup_scope);
$mfa_reveal_token = getCsrfToken($mfa_reveal_scope);
$profile_show_full_backup_codes = ((int)($_SESSION[$mfa_reveal_session_key] ?? 0)) > time();
if (!$profile_show_full_backup_codes && isset($_SESSION[$mfa_reveal_session_key])) {
  unset($_SESSION[$mfa_reveal_session_key]);
}

$chat_pin_status = getChatPinStatus($pdo, $userId);
$chat_pin_has_pin = !empty($chat_pin_status['has_pin']);
$chat_pin_locked = !empty($chat_pin_status['locked']);
$chat_pin_lock_until = $chat_pin_status['locked_until'] ?? null;
$chat_pin_remaining_attempts = (int)($chat_pin_status['remaining_attempts'] ?? 0);
$chat_pin_token = getCsrfToken($chat_pin_scope);
$chat_pin_reset_token = getCsrfToken($chat_pin_reset_scope);

$profile_mfa_setup_secret = $_SESSION['profile_mfa_setup_secret'] ?? '';
$profile_mfa_qr_data_uri = '';
if (!$is_mfa_enabled && $mfa_runtime_available) {
    if (!is_string($profile_mfa_setup_secret) || $profile_mfa_setup_secret === '') {
        $tfa = getTfaInstance();
        $profile_mfa_setup_secret = $tfa->createSecret();
        $_SESSION['profile_mfa_setup_secret'] = $profile_mfa_setup_secret;
    }

    $tfa = getTfaInstance();
    $profile_mfa_qr_data_uri = $tfa->getQRCodeImageAsDataUri('FindIt:' . ($current_user['email'] ?? 'user'), $profile_mfa_setup_secret, 220);
}
  $profile_mfa_code_rows = [];
  $profile_mfa_new_backup_codes = [];
  if ($is_mfa_enabled) {
    $mfa_secret_for_codes = normalizeMfaSecret((string)($mfa_data['mfa_secret'] ?? ''));
    if ($mfa_secret_for_codes !== '') {
      $profile_mfa_code_rows = getBackupCodeSnapshotWithStatus($pdo, $userId, $mfa_secret_for_codes);
      $profile_mfa_new_backup_codes = array_map(static fn(array $row): string => (string)($row['code'] ?? ''), $profile_mfa_code_rows);
      $_SESSION['profile_mfa_new_backup_codes'] = $profile_mfa_new_backup_codes;
    }
  }

  if (count($profile_mfa_code_rows) === 0 && is_array($_SESSION['profile_mfa_new_backup_codes'] ?? null)) {
    $profile_mfa_new_backup_codes = $_SESSION['profile_mfa_new_backup_codes'];
    foreach ($profile_mfa_new_backup_codes as $code) {
      $profile_mfa_code_rows[] = [
        'code' => (string)$code,
        'used' => false,
      ];
    }
  }

  $profile_mfa_used_count = 0;
  foreach ($profile_mfa_code_rows as $row) {
    if (!empty($row['used'])) {
      $profile_mfa_used_count++;
    }
  }

$stats = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0];
if ($profile_role === 'admin') {
    $stats_sql = 'SELECT
        (SELECT COUNT(*) FROM items WHERE user_id = ?) as a,
        (SELECT COUNT(*) FROM itemmatches im
         JOIN items i ON (im.lost_item_id = i.item_id OR im.found_item_id = i.item_id)
         WHERE i.user_id = ?) as b,
        (SELECT COUNT(*) FROM claim_requests WHERE admin_user_id = ?) as c,
        (SELECT COUNT(*) FROM users WHERE role = "claimant") as d';
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute([$userId, $userId, $userId]);
    $stats = $stats_stmt->fetch() ?: $stats;
    $stat_labels = ['Items Managed', 'Matches Involved', 'Claims Processed', 'Claimant Accounts'];
} else {
    $stats_sql = 'SELECT
        (SELECT COUNT(*) FROM items WHERE user_id = ? AND status = "lost") as a,
        (SELECT COUNT(*) FROM items WHERE user_id = ? AND status = "found") as b,
        (SELECT COUNT(*) FROM claim_requests WHERE claimant_user_id = ?) as c,
        (SELECT COALESCE(SUM(points), 0) FROM leaderboard WHERE user_id = ?) as d';
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute([$userId, $userId, $userId, $userId]);
    $stats = $stats_stmt->fetch() ?: $stats;
    $stat_labels = ['Lost Items', 'Found Items', 'Claims Submitted', 'Points'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo ucfirst($profile_role); ?> Profile</title>
  <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <style>
    :root {
      --surface: #131922;
      --line: #273040;
      --text: #ecf2ff;
      --muted: #95a4bc;
      --brand: #ff5b1f;
      --brand-2: #ff7b2c;
      --brand-3: #22d3ee;
    }

    body {
      margin: 0;
      color: var(--text);
      font-family: 'Manrope', 'Inter', sans-serif;
      background:
        radial-gradient(circle at 8% 8%, rgba(255, 122, 28, 0.16), transparent 38%),
        radial-gradient(circle at 92% 4%, rgba(34, 211, 238, 0.12), transparent 42%),
        #0a0f14;
    }

    .main-content {
      margin-left: 280px;
      min-height: 100vh;
      background: transparent;
    }

    .profile-shell {
      padding: 1.25rem;
    }

    .profile-hero {
      border: 1px solid rgba(255, 123, 44, 0.35);
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 18px 32px rgba(0, 0, 0, 0.34);
      background: linear-gradient(132deg, var(--brand-2) 0%, var(--brand) 38%, #ef4444 68%, var(--brand-3) 100%);
      color: #ecfeff;
    }

    .ui-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 16px;
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }

    .ui-kpi {
      border-top: 3px solid #ff7b2c;
    }

    .ui-label {
      font-size: 0.73rem;
      letter-spacing: 0.07em;
      text-transform: uppercase;
      color: var(--muted);
      font-weight: 700;
    }

    .form-label {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      font-size: 0.84rem;
      font-weight: 700;
      color: #dbe7f8;
      margin-bottom: 0.55rem;
    }

    .form-label svg {
      width: 1rem;
      height: 1rem;
      color: #fb923c;
      flex-shrink: 0;
    }

    .profile-fallback-hero {
      width: 80px;
      height: 80px;
      border-radius: 16px;
      border: 1px solid rgba(255, 255, 255, 0.44);
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.28), rgba(255, 255, 255, 0.12));
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      box-shadow: 0 12px 18px rgba(2, 6, 23, 0.24);
    }

    .profile-fallback-card {
      width: 112px;
      height: 112px;
      border-radius: 999px;
      border: 4px solid #fdba74;
      background: linear-gradient(145deg, #ff7b2c 0%, #ff5b1f 55%, #22d3ee 100%);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      margin: 0 auto;
      color: #fff7ed;
      box-shadow: 0 16px 26px rgba(255, 91, 31, 0.25);
    }

    .profile-fallback-initials {
      font-size: 1.25rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      line-height: 1;
      margin-top: 0.2rem;
    }

    .ui-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 10px;
      padding: 0.66rem 1rem;
      font-size: 0.87rem;
      font-weight: 700;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .ui-btn-primary {
      color: #ffffff;
      background: linear-gradient(132deg, var(--brand), var(--brand-2));
    }

    .ui-btn-primary:hover {
      filter: brightness(1.05);
    }

    .ui-btn-soft {
      color: #ffd5bf;
      background: rgba(255, 123, 44, 0.14);
      border: 1px solid rgba(255, 123, 44, 0.35);
    }

    .ui-btn-soft:hover {
      background: rgba(255, 123, 44, 0.24);
    }

    .ui-btn-danger {
      color: #ffffff;
      background: linear-gradient(135deg, #dc2626, #b91c1c);
    }

    .ui-input {
      width: 100%;
      border: 1px solid #273040;
      border-radius: 10px;
      padding: 0.72rem 0.9rem;
      outline: none;
      transition: all 0.2s ease;
      background: #0f151e;
      color: #ecf2ff;
    }

    .ui-input:focus {
      border-color: #ff7b2c;
      box-shadow: 0 0 0 3px rgba(255, 123, 44, 0.22);
    }

    .ui-modal-backdrop {
      background: rgba(2, 6, 23, 0.58);
      z-index: 1250;
    }

    .ui-modal-panel {
      border: 1px solid #273040;
      border-radius: 18px;
      background: #131922;
      box-shadow: 0 24px 40px rgba(0, 0, 0, 0.45);
    }

    .mfa-stat-card {
      border-radius: 12px;
      padding: 0.95rem;
      border: 1px solid #273040;
      background: #0f151e;
    }

    .mfa-block {
      border: 1px solid #273040;
      border-radius: 14px;
      padding: 1.15rem;
      background: #0f151e;
    }

    .mfa-muted {
      color: #a2b2ca;
    }
    :is(body.claimant-theme, body.admin-theme) .ui-card .text-gray-800,
    :is(body.claimant-theme, body.admin-theme) .ui-card .text-gray-700,
    :is(body.claimant-theme, body.admin-theme) .ui-card .text-gray-600,
    :is(body.claimant-theme, body.admin-theme) .ui-card .text-gray-500 {
      color: #dbe7f8 !important;
    }

    :is(body.claimant-theme, body.admin-theme) .ui-card .border-gray-200 {
      border-color: #273040 !important;
    }

    :is(body.claimant-theme, body.admin-theme) .mfa-stat-card,
    :is(body.claimant-theme, body.admin-theme) .mfa-block {
      background: #0f151e;
      border-color: #273040;
    }

    :is(body.claimant-theme, body.admin-theme) .mfa-muted {
      color: #a2b2ca;
    }

    :is(body.claimant-theme, body.admin-theme) #mfaManageModal .text-gray-800,
    :is(body.claimant-theme, body.admin-theme) #mfaManageModal .text-gray-700,
    :is(body.claimant-theme, body.admin-theme) #mfaManageModal .text-gray-600,
    :is(body.claimant-theme, body.admin-theme) #resetMfaModal .text-gray-700 {
      color: #dbe7f8 !important;
    }

    @media (max-width: 768px) {
      .main-content {
        margin-left: 0;
      }

      .profile-shell {
        padding: 0.8rem;
      }
    }
  </style>
</head>
<body class="<?php echo $profile_role === 'claimant' ? 'claimant-theme' : 'admin-theme'; ?>">
<?php
$sidebarAbsolutePath = dirname(__DIR__, 2) . '/includes/components/shared_sidebar.php';
include $sidebarAbsolutePath;
?>

<div class="main-content">
  <div class="profile-shell lg:p-8">
    <div class="max-w-7xl mx-auto space-y-6">
      <div class="profile-hero">
        <div class="px-6 py-8 lg:px-8 text-white">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div class="flex items-center gap-4">
              <?php if (!empty($current_user['image_path']) && file_exists(dirname(__DIR__, 2) . '/' . $current_user['image_path'])): ?>
                <img src="<?php echo htmlspecialchars($profileAssetPrefix . $current_user['image_path']); ?>" alt="Profile" class="w-20 h-20 rounded-2xl border-4 border-white/40 object-cover shadow-xl">
              <?php else: ?>
                <div class="profile-fallback-hero">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A10.96 10.96 0 0112 15c2.625 0 5.04.922 6.879 2.46M15 9a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                  <span class="profile-fallback-initials"><?php echo strtoupper(substr((string)$current_user['full_name'], 0, 2)); ?></span>
                </div>
              <?php endif; ?>
              <div>
                <p class="inline-flex items-center gap-1 rounded-full bg-white/20 border border-white/30 px-3 py-1 text-xs font-semibold uppercase tracking-wide">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3L4 7V12C4 17 7.5 20 12 21C16.5 20 20 17 20 12V7L12 3Z"/></svg>
                  Account Profile
                </p>
                <h1 class="text-3xl font-bold leading-tight"><?php echo htmlspecialchars((string)$current_user['full_name']); ?></h1>
                <p class="text-orange-100 text-sm mt-1"><?php echo htmlspecialchars($masked_email_display); ?></p>
              </div>
            </div>
            <div class="flex flex-wrap gap-2">
              <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-white/20 border border-white/30">
                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A10.96 10.96 0 0112 15c2.625 0 5.04.922 6.879 2.46M15 9a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <?php echo ucfirst((string)$current_user['role']); ?>
              </span>
              <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold border <?php echo $is_mfa_fully_active ? 'bg-cyan-400/20 text-cyan-100 border-cyan-300/30' : 'bg-amber-400/20 text-amber-100 border-amber-300/30'; ?>">
                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11V16M12 7H12.01M5 10V8A7 7 0 1119 8V10M5 10H19V21H5V10Z"/></svg>
                MFA <?php echo $is_mfa_fully_active ? 'Active' : 'Needs Setup'; ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <?php if ($message !== null): ?>
        <div class="p-4 rounded-lg border border-cyan-500/40 bg-cyan-500/10 text-cyan-100"><?php echo htmlspecialchars($message); ?></div>
      <?php endif; ?>
      <?php if ($error !== null): ?>
        <div class="p-4 rounded-lg border border-red-300 bg-red-50 text-red-700"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="ui-card ui-kpi p-5"><p class="ui-label inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg><?php echo $stat_labels[0]; ?></p><p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format((int)($stats['a'] ?? 0)); ?></p></div>
        <div class="ui-card ui-kpi p-5"><p class="ui-label inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V7a2 2 0 00-2-2h-4V3H10v2H6a2 2 0 00-2 2v6m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4"/></svg><?php echo $stat_labels[1]; ?></p><p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format((int)($stats['b'] ?? 0)); ?></p></div>
        <div class="ui-card ui-kpi p-5"><p class="ui-label inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg><?php echo $stat_labels[2]; ?></p><p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format((int)($stats['c'] ?? 0)); ?></p></div>
        <div class="ui-card ui-kpi p-5"><p class="ui-label inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg><?php echo $stat_labels[3]; ?></p><p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format((int)($stats['d'] ?? 0)); ?></p></div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        <aside class="xl:col-span-4 2xl:col-span-3 space-y-6">
          <div class="ui-card p-6">
            <div class="text-center">
              <?php if (!empty($current_user['image_path']) && file_exists(dirname(__DIR__, 2) . '/' . $current_user['image_path'])): ?>
                <img id="profile-preview" src="<?php echo htmlspecialchars($profileAssetPrefix . $current_user['image_path']); ?>" alt="Profile Picture" class="w-28 h-28 rounded-full object-cover mx-auto border-4 border-orange-300 shadow-lg">
              <?php else: ?>
                <div id="profile-preview" class="profile-fallback-card">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A10.96 10.96 0 0112 15c2.625 0 5.04.922 6.879 2.46M15 9a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                  <span class="profile-fallback-initials"><?php echo strtoupper(substr((string)$current_user['full_name'], 0, 2)); ?></span>
                </div>
              <?php endif; ?>
              <h2 class="text-xl font-bold text-gray-800 mt-4"><?php echo htmlspecialchars((string)$current_user['full_name']); ?></h2>
              <p class="text-sm text-gray-500"><?php echo htmlspecialchars($masked_email_display); ?></p>
            </div>

            <div class="mt-6 pt-5 border-t border-gray-200 space-y-3 text-sm text-gray-600">
              <p><strong>Phone:</strong> <?php echo htmlspecialchars($masked_phone_display); ?></p>
              <p><strong>MFA:</strong> <?php echo $is_mfa_fully_active ? 'Fully configured' : 'Needs attention'; ?></p>
              <p><strong>Backup Codes Left:</strong> <?php echo number_format((int)$mfa_remaining_backup_codes); ?></p>
              <p><strong>Chat PIN:</strong> <?php echo $chat_pin_has_pin ? ($chat_pin_locked ? 'Locked until ' . htmlspecialchars((string)$chat_pin_lock_until) : 'Configured') : 'Not set'; ?></p>
            </div>
          </div>

          <div class="ui-card p-6">
            <h3 class="text-base font-semibold text-gray-800 mb-3 inline-flex items-center gap-2">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11V16M12 7H12.01M5 10V8A7 7 0 1119 8V10M5 10H19V21H5V10Z"/></svg>
              Security Shortcuts
            </h3>
            <div class="space-y-3">
              <button type="button" onclick="openMfaModal()" class="w-full ui-btn ui-btn-primary"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11V16M12 7H12.01M5 10V8A7 7 0 1119 8V10M5 10H19V21H5V10Z"/></svg>Manage MFA</button>
              <button type="button" onclick="openChatPinModal()" class="w-full ui-btn ui-btn-soft"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 0h12a2 2 0 002-2v-6a2 2 0 00-2-2h-1V6a5 5 0 00-10 0v1H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg><?php echo $chat_pin_has_pin ? 'Change Chat PIN' : 'Set Chat PIN'; ?></button>
              <button type="button" onclick="openResetChatPinModal()" class="w-full ui-btn ui-btn-danger"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5h6m-6 4h6m-8 8h8a2 2 0 002-2V7a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2zm-4-8h.01"/></svg>Reset Chat PIN</button>
              <button type="button" onclick="openResetMfaModal()" class="w-full ui-btn ui-btn-danger"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.065 2.573c.94 1.543-.827 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.572-1.065c-1.543.94-3.31-.827-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.572c-.94-1.544.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.066z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>Reset MFA</button>
            </div>
          </div>
        </aside>

        <section class="xl:col-span-8 2xl:col-span-9 space-y-6">
          <div class="ui-card p-6 lg:p-8">
            <div class="flex items-center justify-between mb-6 pb-4 border-b border-gray-200">
              <h3 class="text-xl font-semibold text-gray-800 inline-flex items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A10.96 10.96 0 0112 15c2.625 0 5.04.922 6.879 2.46M15 9a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Profile Information</h3>
            </div>

            <form id="profileUpdateForm" method="POST" enctype="multipart/form-data" class="space-y-6">
              <input type="hidden" name="action" value="update_profile">

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label class="form-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A10.96 10.96 0 0112 15c2.625 0 5.04.922 6.879 2.46M15 9a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Full Name</label>
                  <input type="text" name="full_name" required value="<?php echo htmlspecialchars((string)$current_user['full_name']); ?>" class="ui-input">
                </div>
                <div>
                  <label class="form-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8m-18 9h18a2 2 0 002-2V7a2 2 0 00-2-2H3a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>Email</label>
                  <input type="email" name="email" required value="<?php echo htmlspecialchars((string)$current_user['email']); ?>" class="ui-input">
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label class="form-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.95.684l1.5 4.5a1 1 0 01-.5 1.21l-2.15 1.08a11.04 11.04 0 005.52 5.52l1.08-2.15a1 1 0 011.21-.5l4.5 1.5a1 1 0 01.684.95V19a2 2 0 01-2 2h-1C9.268 21 3 14.732 3 7V5z"/></svg>Phone Number</label>
                  <input type="text" name="phone" maxlength="10" value="<?php echo htmlspecialchars((string)($current_user['phone'] ?? '')); ?>" placeholder="9XXXXXXXXX" class="ui-input">
                </div>
                <div>
                  <label class="form-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Profile Image</label>
                  <input type="file" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewProfileImage(this)" class="ui-input">
                </div>
              </div>

              <div class="border-t pt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                  <label class="form-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11V16M12 7H12.01M5 10V8A7 7 0 1119 8V10M5 10H19V21H5V10Z"/></svg>Current Password</label>
                  <input type="password" id="current_password" name="current_password" class="ui-input" placeholder="Required to change password">
                </div>
                <div>
                  <label class="form-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>New Password</label>
                  <input type="password" id="new_password" name="new_password" class="ui-input" placeholder="At least 6 characters">
                </div>
                <div>
                  <label class="form-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Confirm Password</label>
                  <input type="password" id="confirm_password" name="confirm_password" class="ui-input" placeholder="Repeat new password">
                </div>
              </div>

              <div class="flex justify-end">
                <button type="submit" class="ui-btn ui-btn-primary px-6 py-3 inline-flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Save Changes</button>
              </div>
            </form>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>

<div id="mfaManageModal" class="hidden fixed inset-0 ui-modal-backdrop z-[1200] p-4 overflow-y-auto">
  <div class="max-w-5xl mx-auto mt-10 ui-modal-panel overflow-hidden">
    <div class="px-6 py-4 bg-gradient-to-r from-orange-500 to-red-500 text-white flex items-center justify-between">
      <div>
        <h3 class="text-xl font-semibold">MFA Security Manager</h3>
        <p class="text-xs text-orange-100">Shared MFA controls for all roles</p>
      </div>
      <button type="button" onclick="closeMfaModal()" class="text-white/90 hover:text-white leading-none" aria-label="Close MFA modal">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <div class="p-6 space-y-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="mfa-stat-card"><p class="text-xs text-orange-300 inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11V16M12 7H12.01M5 10V8A7 7 0 1119 8V10M5 10H19V21H5V10Z"/></svg>MFA</p><p class="text-sm font-semibold text-gray-800 mt-1"><?php echo $is_mfa_enabled ? 'Enabled' : 'Disabled'; ?></p></div>
        <div class="mfa-stat-card"><p class="text-xs text-cyan-600 inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Backup Codes Left</p><p class="text-sm font-semibold text-gray-800 mt-1"><?php echo number_format((int)$mfa_remaining_backup_codes); ?></p></div>
        <div class="mfa-stat-card"><p class="text-xs text-violet-600 inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Backup Saved</p><p class="text-sm font-semibold text-gray-800 mt-1"><?php echo $mfa_last_backup_download ? date('M d, Y h:i A', strtotime((string)$mfa_last_backup_download)) : 'Not yet'; ?></p></div>
        <div class="mfa-stat-card"><p class="text-xs text-amber-600 inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01"/></svg>Security State</p><p class="text-sm font-semibold text-gray-800 mt-1"><?php echo $is_mfa_fully_active ? 'Protected' : 'Action Needed'; ?></p></div>
      </div>

      <?php if (!$is_mfa_enabled): ?>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="mfa-block">
          <h4 class="font-semibold text-gray-800 mb-3 inline-flex items-center gap-2"><svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm12 0h4m-4 4h4m-4-8h4m-2 2v8"/></svg>Step 1: Scan QR</h4>
          <?php if (!empty($profile_mfa_qr_data_uri)): ?>
            <img src="<?php echo htmlspecialchars($profile_mfa_qr_data_uri); ?>" alt="MFA QR" class="w-52 h-52 rounded-lg border border-gray-300 bg-white p-2">
          <?php else: ?>
            <div class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-3">QR unavailable. Please check internet access and PHP remote URL settings.</div>
          <?php endif; ?>
        </div>

        <div class="mfa-block">
          <h4 class="font-semibold text-gray-800 mb-2 inline-flex items-center gap-2"><svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 .826-.674 1.5-1.5 1.5S9 11.826 9 11s.674-1.5 1.5-1.5S12 10.174 12 11zm0 0V9m0 2v2m8-2a8 8 0 11-16 0 8 8 0 0116 0z"/></svg>Step 2: Verify OTP</h4>
          <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="enable_mfa_from_profile">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mfa_enable_token); ?>">
            <input type="text" name="mfa_otp" maxlength="6" required placeholder="123456" class="w-full px-4 py-3 border border-gray-300 rounded-lg">
            <button type="submit" class="ui-btn ui-btn-primary px-4 py-2 inline-flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Enable MFA</button>
          </form>
        </div>
      </div>
      <?php else: ?>
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="mfa-block space-y-3">
          <h4 class="font-semibold text-gray-800 mb-2 inline-flex items-center gap-2"><svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V9m-5-4l5 5m0 0l-5 5m5-5H9"/></svg>Backup Codes</h4>
          <p class="text-xs mfa-muted">Used: <?php echo number_format((int)$profile_mfa_used_count); ?> | Unused: <?php echo number_format(max(0, count($profile_mfa_code_rows) - (int)$profile_mfa_used_count)); ?></p>
          <form method="POST">
            <input type="hidden" name="action" value="regenerate_backup_codes">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mfa_backup_token); ?>">
            <button type="submit" class="ui-btn ui-btn-soft px-4 py-2 inline-flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Regenerate Backup Codes</button>
          </form>

          <form method="POST">
            <input type="hidden" name="action" value="download_backup_codes_profile">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mfa_backup_token); ?>">
            <button type="submit" class="ui-btn ui-btn-primary px-4 py-2 inline-flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14m0 0l-5-5m5 5l5-5"/></svg>Download Backup Codes</button>
          </form>
        </div>

        <div class="mfa-block">
          <h4 class="font-semibold text-gray-800 mb-2 inline-flex items-center gap-2"><svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9h8M8 13h6m-9 8h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>Backup Code Session View</h4>
          <?php if (is_array($profile_mfa_code_rows) && count($profile_mfa_code_rows) > 0): ?>
            <div class="flex items-center justify-between gap-2 mb-2">
              <p class="text-xs mfa-muted">Codes match the downloadable TXT file.</p>
              <span class="text-xs <?php echo $profile_show_full_backup_codes ? 'text-emerald-300' : 'text-amber-200'; ?>"><?php echo $profile_show_full_backup_codes ? 'Full view unlocked' : 'Masked by default'; ?></span>
            </div>

            <ul class="grid grid-cols-2 gap-2 text-sm text-gray-800">
              <?php foreach ($profile_mfa_code_rows as $row): ?>
                <?php $code_value = (string)($row['code'] ?? ''); ?>
                <?php $is_code_used = !empty($row['used']); ?>
                <li class="rounded px-2 py-1 font-mono border <?php echo $is_code_used ? 'bg-red-900/20 border-red-400/40 text-red-200' : 'bg-white border-gray-200'; ?>">
                  <div class="flex items-center justify-between gap-2">
                    <span><?php echo htmlspecialchars($profile_show_full_backup_codes ? $code_value : maskBackupCode($code_value)); ?></span>
                    <span class="text-[10px] font-semibold <?php echo $is_code_used ? 'text-red-200' : 'text-emerald-300'; ?>"><?php echo $is_code_used ? 'USED' : 'UNUSED'; ?></span>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>

            <?php if (!$profile_show_full_backup_codes): ?>
              <form method="POST" class="mt-3 space-y-2">
                <input type="hidden" name="action" value="view_full_backup_codes_profile">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mfa_reveal_token); ?>">
                <label for="backup_codes_password_profile" class="block text-xs font-medium text-gray-700">Enter current password to reveal full codes</label>
                <div class="flex gap-2">
                  <input type="password" id="backup_codes_password_profile" name="backup_codes_password" required class="ui-input" placeholder="Current account password" autocomplete="current-password">
                  <button type="submit" class="ui-btn ui-btn-primary px-4 py-2">Reveal</button>
                </div>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-sm mfa-muted mt-3">No backup codes available. Regenerate to create a new one-time set.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div id="resetMfaModal" class="hidden fixed inset-0 ui-modal-backdrop z-[1250] p-4 overflow-y-auto">
  <div class="max-w-lg mx-auto mt-20 ui-modal-panel border border-red-100 overflow-hidden">
    <div class="px-6 py-4 bg-red-600 text-white flex items-center justify-between">
      <h3 class="text-lg font-semibold">Reset MFA</h3>
      <button type="button" onclick="closeResetMfaModal()" class="text-white/90 hover:text-white leading-none" aria-label="Close reset MFA modal">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form method="POST" class="p-6 space-y-4" onsubmit="return confirmResetMfa();">
      <input type="hidden" name="action" value="reset_mfa">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mfa_form_token); ?>">
      <p class="text-sm text-gray-700 inline-flex items-start gap-2"><svg class="w-4 h-4 text-red-300 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg><span>This will disable MFA and clear existing backup codes.</span></p>
      <div>
        <label for="mfa_current_password_modal" class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
        <input type="password" id="mfa_current_password_modal" name="mfa_current_password" required class="ui-input" placeholder="Enter current password">
      </div>
      <div class="flex justify-end gap-3 pt-2">
        <button type="button" onclick="closeResetMfaModal()" class="ui-btn ui-btn-soft px-4 py-2">Cancel</button>
        <button type="submit" class="ui-btn ui-btn-danger px-4 py-2">Disable MFA</button>
      </div>
    </form>
  </div>
</div>

<div id="chatPinModal" class="hidden fixed inset-0 ui-modal-backdrop z-[1260] p-4 overflow-y-auto">
  <div class="max-w-lg mx-auto mt-20 ui-modal-panel overflow-hidden">
    <div class="px-6 py-4 bg-gradient-to-r from-slate-600 to-slate-800 text-white flex items-center justify-between">
      <div>
        <h3 class="text-lg font-semibold"><?php echo $chat_pin_has_pin ? 'Change Chat PIN' : 'Set Chat PIN'; ?></h3>
        <p class="text-xs text-slate-200 mt-1">Chat pages will request this PIN every navigation.</p>
      </div>
      <button type="button" onclick="closeChatPinModal()" class="text-white/90 hover:text-white leading-none" aria-label="Close chat PIN modal">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <form method="POST" class="p-6 space-y-4">
      <input type="hidden" name="action" value="save_chat_pin">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($chat_pin_token); ?>">

      <div class="grid grid-cols-2 gap-3 text-xs">
        <div class="mfa-stat-card">
          <p class="mfa-muted">Status</p>
          <p class="text-sm font-semibold text-gray-800 mt-1"><?php echo $chat_pin_has_pin ? 'Configured' : 'Not Set'; ?></p>
        </div>
        <div class="mfa-stat-card">
          <p class="mfa-muted">Remaining Attempts</p>
          <p class="text-sm font-semibold text-gray-800 mt-1"><?php echo number_format((int)$chat_pin_remaining_attempts); ?> / <?php echo number_format((int)($chat_pin_status['max_attempts'] ?? 5)); ?></p>
        </div>
      </div>

      <?php if ($chat_pin_locked && $chat_pin_lock_until): ?>
        <div class="rounded-lg border border-red-300 bg-red-50 text-red-700 px-3 py-2 text-sm">
          Chat PIN is currently locked until <?php echo htmlspecialchars((string)$chat_pin_lock_until); ?>.
        </div>
      <?php endif; ?>

      <?php if ($chat_pin_has_pin): ?>
        <div>
          <label for="current_chat_pin" class="block text-sm font-medium text-gray-700 mb-2">Current Chat PIN</label>
          <input type="password" id="current_chat_pin" name="current_chat_pin" maxlength="6" inputmode="numeric" pattern="[0-9]*" class="ui-input" placeholder="Enter current 6-digit PIN" <?php echo $chat_pin_locked ? 'disabled' : ''; ?> required>
        </div>
      <?php endif; ?>

      <div>
        <label for="new_chat_pin" class="block text-sm font-medium text-gray-700 mb-2">New Chat PIN</label>
        <input type="password" id="new_chat_pin" name="new_chat_pin" maxlength="6" inputmode="numeric" pattern="[0-9]*" class="ui-input" placeholder="Enter new 6-digit PIN" <?php echo $chat_pin_locked ? 'disabled' : ''; ?> required>
      </div>

      <div>
        <label for="confirm_chat_pin" class="block text-sm font-medium text-gray-700 mb-2">Confirm New Chat PIN</label>
        <input type="password" id="confirm_chat_pin" name="confirm_chat_pin" maxlength="6" inputmode="numeric" pattern="[0-9]*" class="ui-input" placeholder="Confirm new 6-digit PIN" <?php echo $chat_pin_locked ? 'disabled' : ''; ?> required>
      </div>

      <p class="text-xs mfa-muted">Only numbers are allowed. Chat PIN lock applies after consecutive failed attempts.</p>

      <div class="flex justify-end gap-3 pt-2">
        <button type="button" onclick="closeChatPinModal()" class="ui-btn ui-btn-soft px-4 py-2">Cancel</button>
        <button type="submit" class="ui-btn ui-btn-primary px-4 py-2" <?php echo $chat_pin_locked ? 'disabled style="opacity:0.6;cursor:not-allowed;"' : ''; ?>><?php echo $chat_pin_has_pin ? 'Update Chat PIN' : 'Create Chat PIN'; ?></button>
      </div>
    </form>
  </div>
</div>

<div id="resetChatPinModal" class="hidden fixed inset-0 ui-modal-backdrop z-[1270] p-4 overflow-y-auto">
  <div class="max-w-lg mx-auto mt-20 ui-modal-panel border border-red-100 overflow-hidden">
    <div class="px-6 py-4 bg-red-600 text-white flex items-center justify-between">
      <h3 class="text-lg font-semibold">Reset Chat PIN</h3>
      <button type="button" onclick="closeResetChatPinModal()" class="text-white/90 hover:text-white leading-none" aria-label="Close reset chat PIN modal">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form method="POST" class="p-6 space-y-4" onsubmit="return confirmResetChatPin();">
      <input type="hidden" name="action" value="reset_chat_pin">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($chat_pin_reset_token); ?>">
      <p class="text-sm text-gray-700 inline-flex items-start gap-2"><svg class="w-4 h-4 text-red-300 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg><span>This removes your current chat PIN. You must create a new PIN before chat access.</span></p>
      <div>
        <label for="chat_pin_reset_password" class="block text-sm font-medium text-gray-700 mb-2">Current Account Password</label>
        <input type="password" id="chat_pin_reset_password" name="chat_pin_reset_password" required class="ui-input" placeholder="Enter current password">
      </div>
      <div class="flex justify-end gap-3 pt-2">
        <button type="button" onclick="closeResetChatPinModal()" class="ui-btn ui-btn-soft px-4 py-2">Cancel</button>
        <button type="submit" class="ui-btn ui-btn-danger px-4 py-2">Reset Chat PIN</button>
      </div>
    </form>
  </div>
</div>

<script>
function previewProfileImage(input) {
  const preview = document.getElementById('profile-preview');
  const file = input.files[0];
  if (!file) return;

  if (file.size > 5 * 1024 * 1024) {
    alert('File size must be less than 5MB');
    input.value = '';
    return;
  }

  const reader = new FileReader();
  reader.onload = function(e) {
    preview.outerHTML = '<img id="profile-preview" src="' + e.target.result + '" alt="Profile Preview" class="w-28 h-28 rounded-full object-cover mx-auto border-4 border-orange-300 shadow-lg">';
  };
  reader.readAsDataURL(file);
}

function openMfaModal() {
  const modal = document.getElementById('mfaManageModal');
  if (!modal) return;
  modal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closeMfaModal() {
  const modal = document.getElementById('mfaManageModal');
  if (!modal) return;
  modal.classList.add('hidden');
  if (
    document.getElementById('resetMfaModal').classList.contains('hidden') &&
    document.getElementById('chatPinModal').classList.contains('hidden') &&
    document.getElementById('resetChatPinModal').classList.contains('hidden')
  ) {
    document.body.style.overflow = '';
  }
}

function openResetMfaModal() {
  const modal = document.getElementById('resetMfaModal');
  if (!modal) return;
  modal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closeResetMfaModal() {
  const modal = document.getElementById('resetMfaModal');
  if (!modal) return;
  modal.classList.add('hidden');
  if (
    document.getElementById('mfaManageModal').classList.contains('hidden') &&
    document.getElementById('chatPinModal').classList.contains('hidden') &&
    document.getElementById('resetChatPinModal').classList.contains('hidden')
  ) {
    document.body.style.overflow = '';
  }
}

function openChatPinModal() {
  const modal = document.getElementById('chatPinModal');
  if (!modal) return;
  modal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closeChatPinModal() {
  const modal = document.getElementById('chatPinModal');
  if (!modal) return;
  modal.classList.add('hidden');
  if (
    document.getElementById('mfaManageModal').classList.contains('hidden') &&
    document.getElementById('resetMfaModal').classList.contains('hidden') &&
    document.getElementById('resetChatPinModal').classList.contains('hidden')
  ) {
    document.body.style.overflow = '';
  }
}

function openResetChatPinModal() {
  const modal = document.getElementById('resetChatPinModal');
  if (!modal) return;
  modal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closeResetChatPinModal() {
  const modal = document.getElementById('resetChatPinModal');
  if (!modal) return;
  modal.classList.add('hidden');
  if (
    document.getElementById('mfaManageModal').classList.contains('hidden') &&
    document.getElementById('resetMfaModal').classList.contains('hidden') &&
    document.getElementById('chatPinModal').classList.contains('hidden')
  ) {
    document.body.style.overflow = '';
  }
}

function confirmResetMfa() {
  return confirm('This will reset your MFA and require immediate re-setup. Continue?');
}

function confirmResetChatPin() {
  return confirm('This will clear your current chat PIN. Continue?');
}

document.getElementById('mfaManageModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeMfaModal();
  }
});

document.getElementById('resetMfaModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeResetMfaModal();
  }
});

document.getElementById('chatPinModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeChatPinModal();
  }
});

document.getElementById('resetChatPinModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeResetChatPinModal();
  }
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeResetChatPinModal();
    closeChatPinModal();
    closeResetMfaModal();
    closeMfaModal();
  }
});

<?php if ($open_mfa_modal): ?>
openMfaModal();
<?php endif; ?>

<?php if ($open_reset_modal): ?>
openResetMfaModal();
<?php endif; ?>

<?php if ($open_chat_pin_modal): ?>
openChatPinModal();
<?php endif; ?>

<?php if ($open_chat_pin_reset_modal): ?>
openResetChatPinModal();
<?php endif; ?>

['current_chat_pin', 'new_chat_pin', 'confirm_chat_pin'].forEach(function(id) {
  const field = document.getElementById(id);
  if (!field) return;
  field.addEventListener('input', function() {
    field.value = field.value.replace(/\D/g, '').slice(0, 6);
  });
});

document.getElementById('profileUpdateForm').addEventListener('submit', function(e) {
  const newPassword = document.getElementById('new_password').value;
  const currentPassword = document.getElementById('current_password').value;
  const confirmPassword = document.getElementById('confirm_password').value;

  if (newPassword && !currentPassword) {
    e.preventDefault();
    alert('Please enter your current password to change your password.');
    return false;
  }

  if (newPassword && newPassword.length < 6) {
    e.preventDefault();
    alert('New password must be at least 6 characters long.');
    return false;
  }

  if (newPassword !== confirmPassword) {
    e.preventDefault();
    alert('New password and confirmation do not match.');
    return false;
  }

  return true;
});
</script>
</body>
</html>
