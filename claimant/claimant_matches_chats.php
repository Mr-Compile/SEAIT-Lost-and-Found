<?php 
require_once '../config/db.php';
require_once '../includes/auth_check.php';
require_once '../config/env.php';
require_once '../includes/helpers/chat_crypto.php';
require_once '../includes/helpers/chat_pin_helper.php';
requireRole('claimant');

loadEnvFile(__DIR__ . '/../.env');

// Get user info from session
$user_id = $_SESSION['user_id'];
$user = getUserInfo();
$current_page = basename($_SERVER['PHP_SELF']);

ensureChatPinTable($pdo);
$chat_pin_scope = 'chat_pin_access_claimant_' . $user_id;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  clearChatPinVerifiedInSession($user_id);
}
$chat_pin_status = getChatPinStatus($pdo, $user_id);
$chat_pin_needs_setup = !$chat_pin_status['has_pin'];
$chat_pin_token = function_exists('getCsrfToken') ? getCsrfToken($chat_pin_scope) : '';

function buildClaimantChatImageSrc(?string $imageValue): string
{
  $imageValue = trim((string)$imageValue);
  if ($imageValue === '' || strtolower($imageValue) === 'null') {
    return '';
  }

  $normalized = str_replace('\\', '/', $imageValue);
  if (preg_match('#^(https?:)?//#i', $normalized) === 1 || strpos($normalized, 'data:') === 0) {
    return $normalized;
  }

  $projectRoot = dirname(__DIR__);
  $uploadsRoot = $projectRoot . '/uploads';

  $trimmed = ltrim($normalized, '/');
  $uploadsMarkerPos = stripos($trimmed, 'uploads/');
  if ($uploadsMarkerPos !== false) {
    $trimmed = substr($trimmed, $uploadsMarkerPos + strlen('uploads/'));
  }

  if (strpos($trimmed, '../uploads/') === 0) {
    $trimmed = substr($trimmed, 11);
  }
  if (strpos($trimmed, 'uploads/') === 0) {
    $trimmed = substr($trimmed, 8);
  }

  $trimmed = ltrim($trimmed, '/');
  $candidates = [];

  if ($trimmed !== '') {
    $candidates[] = $trimmed;
  }

  $fileName = basename($trimmed);
  if ($fileName !== '' && $fileName !== '.') {
    $candidates[] = $fileName;
    $candidates[] = 'backup_img/' . $fileName;
  }

  foreach (array_values(array_unique($candidates)) as $relativePath) {
    $relativePath = ltrim(str_replace('\\', '/', (string)$relativePath), '/');
    if ($relativePath === '') {
      continue;
    }

    $absolutePath = $uploadsRoot . '/' . $relativePath;
    if (!is_file($absolutePath)) {
      continue;
    }

    $encodedRelative = implode('/', array_map('rawurlencode', explode('/', trim($relativePath, '/'))));
    return '../uploads/' . $encodedRelative;
  }

  return '';
}

// Get item ID from URL if provided (for direct linking to specific item matches)
$specific_item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : null;

try {
    // Get matches for this user's items
    $stmt = $pdo->prepare("
        SELECT 
            im.match_id,
            im.similarity_score,
            im.matched_at,
            li.item_id as lost_item_id,
            li.title as lost_title,
            li.description as lost_description,
            li.category as lost_category,
            li.item_image as lost_image,
            li.user_id as lost_user_id,
            li.report_date as lost_report_date,
            fi.item_id as found_item_id,
            fi.title as found_title,
            fi.description as found_description,
            fi.category as found_category,
            fi.item_image as found_image,
            fi.user_id as found_user_id,
            fi.report_date as found_report_date,
            lu.full_name as lost_user_name,
            lu.email as lost_user_email,
            fu.full_name as found_user_name,
            fu.email as found_user_email,
            CASE 
                WHEN li.user_id = ? THEN 'lost'
                WHEN fi.user_id = ? THEN 'found'
            END as user_item_type,
            CASE 
                WHEN li.user_id = ? THEN fi.user_id
                WHEN fi.user_id = ? THEN li.user_id
            END as other_user_id,
            (
              SELECT uadm.user_id
              FROM users uadm
              WHERE uadm.role = 'admin'
              ORDER BY uadm.user_id ASC
              LIMIT 1
            ) as admin_user_id,
            CASE 
                WHEN li.user_id = ? THEN fu.full_name
                WHEN fi.user_id = ? THEN lu.full_name
            END as other_user_name,
            CASE 
                WHEN li.user_id = ? THEN fi.title
                WHEN fi.user_id = ? THEN li.title
            END as other_item_title,
            CASE 
                WHEN li.user_id = ? THEN fi.item_image
                WHEN fi.user_id = ? THEN li.item_image
            END as other_item_image,
            (SELECT COUNT(*) FROM chats WHERE match_id = im.match_id) as message_count,
            (SELECT MAX(sent_at) FROM chats WHERE match_id = im.match_id) as last_message_time,
            (
                SELECT cr.status
                FROM claim_requests cr
                WHERE cr.match_id = im.match_id
                  AND cr.claimant_user_id = ?
                ORDER BY cr.created_at DESC
                LIMIT 1
            ) as claim_status
        FROM itemmatches im
        INNER JOIN items li ON im.lost_item_id = li.item_id
        INNER JOIN items fi ON im.found_item_id = fi.item_id
        INNER JOIN users lu ON li.user_id = lu.user_id
        INNER JOIN users fu ON fi.user_id = fu.user_id
        WHERE (li.user_id = ? OR fi.user_id = ?)
        AND EXISTS (
          SELECT 1
          FROM claim_requests crx
          WHERE crx.match_id = im.match_id
            AND crx.claimant_user_id = ?
        )
        " . ($specific_item_id ? " AND (li.item_id = ? OR fi.item_id = ?)" : "") . "
        ORDER BY im.similarity_score DESC, im.matched_at DESC
    ");
    
      $params = array_fill(0, 14, $user_id);
    if ($specific_item_id) {
        $params[] = $specific_item_id;
        $params[] = $specific_item_id;
    }
    
    $stmt->execute($params);
    $matches = $stmt->fetchAll();

    foreach ($matches as &$match) {
      $match['other_item_image_src'] = buildClaimantChatImageSrc($match['other_item_image'] ?? null);
      $match['lost_image_src'] = buildClaimantChatImageSrc($match['lost_image'] ?? null);
      $match['found_image_src'] = buildClaimantChatImageSrc($match['found_image'] ?? null);

      $match['chat_display_image_src'] = '';
      foreach (['other_item_image_src', 'found_image_src', 'lost_image_src'] as $imageKey) {
        $candidate = (string)($match[$imageKey] ?? '');
        if ($candidate !== '') {
          $match['chat_display_image_src'] = $candidate;
          break;
        }
      }
    }
    unset($match);

} catch (PDOException $e) {
    $matches = [];
    error_log("Database error in matches: " . $e->getMessage());
}

// Handle AJAX requests for chat functionality
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Disable error reporting to prevent HTML output
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Clear any previous output and set JSON header
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Log the request for debugging
    error_log("Chat AJAX request: " . $_POST['action'] . " from user " . ($_SESSION['user_id'] ?? 'NONE'));
    
    try {
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            echo json_encode([
                'success' => false, 
                'message' => 'User not logged in',
                'session_id' => session_id(),
                'session_status' => session_status()
            ]);
            exit;
        }

          $action = (string)($_POST['action'] ?? '');

          if ($action === 'verify_chat_pin' || $action === 'create_chat_pin') {
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!function_exists('isValidCsrfToken') || !isValidCsrfToken($chat_pin_scope, $csrfToken)) {
              echo json_encode([
                'success' => false,
                'message' => 'Invalid PIN request token.',
              ]);
              exit;
            }

            $pin = normalizeChatPin((string)($_POST['pin'] ?? ''));

            if ($action === 'create_chat_pin') {
              $currentPinStatus = getChatPinStatus($pdo, $user_id);
              if (!empty($currentPinStatus['has_pin'])) {
                echo json_encode([
                  'success' => false,
                  'message' => 'Chat PIN already exists. Please verify PIN.',
                  'status' => $currentPinStatus,
                ]);
                exit;
              }

              $confirmPin = normalizeChatPin((string)($_POST['confirm_pin'] ?? ''));

              if (!isValidChatPinFormat($pin) || !isValidChatPinFormat($confirmPin)) {
                echo json_encode([
                  'success' => false,
                  'message' => 'PIN must be exactly 6 digits.',
                ]);
                exit;
              }

              if (!hash_equals($pin, $confirmPin)) {
                echo json_encode([
                  'success' => false,
                  'message' => 'PIN and confirmation do not match.',
                ]);
                exit;
              }

              setChatPin($pdo, $user_id, $pin);
              markChatPinVerifiedInSession($user_id);

              echo json_encode([
                'success' => true,
                'message' => 'Chat PIN created and verified.',
                'status' => getChatPinStatus($pdo, $user_id),
              ]);
              exit;
            }

            $verify = verifyChatPin($pdo, $user_id, $pin);
            if ($verify['success']) {
              markChatPinVerifiedInSession($user_id);
            }

            echo json_encode([
              'success' => (bool)$verify['success'],
              'message' => (string)$verify['message'],
              'requires_pin' => !(bool)$verify['success'],
              'locked' => (bool)$verify['locked'],
              'needs_setup' => (bool)$verify['needs_setup'],
              'remaining_attempts' => (int)$verify['remaining_attempts'],
              'locked_until' => $verify['locked_until'],
              'status' => getChatPinStatus($pdo, $user_id),
            ]);
            exit;
          }

          if (!isChatPinVerifiedInSession($user_id)) {
            echo json_encode([
              'success' => false,
              'requires_pin' => true,
              'message' => 'Chat PIN verification required before accessing messages.',
              'status' => getChatPinStatus($pdo, $user_id),
            ]);
            exit;
          }
        
          switch ($action) {
        case 'test_connection':
            // Test endpoint to verify everything is working
            echo json_encode([
                'success' => true, 
                'message' => 'Connection test successful',
                'user_id' => $_SESSION['user_id'],
                'timestamp' => date('Y-m-d H:i:s'),
                'php_version' => PHP_VERSION
            ]);
            exit;
            
        case 'get_messages':
            $match_id = (int)$_POST['match_id'];
            
            if ($match_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid match ID']);
                exit;
            }
            
            // Verify the match exists and the current user is part of it
            $verify_stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM itemmatches im
                INNER JOIN items li ON im.lost_item_id = li.item_id
                INNER JOIN items fi ON im.found_item_id = fi.item_id
                WHERE im.match_id = ? 
                AND (li.user_id = ? OR fi.user_id = ?)
            ");
            $verify_stmt->execute([$match_id, $user_id, $user_id]);
            $match_exists = $verify_stmt->fetch()['count'] > 0;
            
            if (!$match_exists) {
                echo json_encode(['success' => false, 'message' => 'Invalid match or you are not authorized']);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("
                    SELECT c.*, u.full_name as sender_name 
                    FROM chats c
                    INNER JOIN users u ON c.sender_id = u.user_id
                    WHERE c.match_id = ?
                    ORDER BY c.sent_at ASC
                ");
                $stmt->execute([$match_id]);
                $messages = $stmt->fetchAll();

        foreach ($messages as &$row) {
          if (isset($row['message']) && is_string($row['message'])) {
            $row['message'] = decryptChatMessage($row['message']);
          }
        }
        unset($row);
                echo json_encode(['success' => true, 'messages' => $messages]);
            } catch (PDOException $e) {
                error_log("Chat get_messages error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error loading messages']);
            }
            exit;
            
        case 'send_message':
            $match_id = (int)$_POST['match_id'];
          $receiver_id = (int)$_POST['receiver_id'];
            $message = trim($_POST['message'] ?? '');
            
            // Validate inputs
            if (empty($message)) {
                echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
                exit;
            }
            
            if ($match_id <= 0) {
              echo json_encode(['success' => false, 'message' => 'Invalid match ID']);
                exit;
            }
            
            // Verify the match exists and the current user is part of it
            $verify_stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM itemmatches im
                INNER JOIN items li ON im.lost_item_id = li.item_id
                INNER JOIN items fi ON im.found_item_id = fi.item_id
                WHERE im.match_id = ? 
                AND (li.user_id = ? OR fi.user_id = ?)
            ");
            $verify_stmt->execute([$match_id, $user_id, $user_id]);
            $match_exists = $verify_stmt->fetch()['count'] > 0;
            
            if (!$match_exists) {
                echo json_encode(['success' => false, 'message' => 'Invalid match or you are not authorized']);
                exit;
            }

            // Route claimant messages to admin chat for the match claim workflow.
            $adminStmt = $pdo->prepare("SELECT user_id FROM users WHERE role = 'admin' ORDER BY user_id ASC LIMIT 1");
            $adminStmt->execute();
            $adminUserId = (int)($adminStmt->fetchColumn() ?? 0);
            if ($adminUserId <= 0) {
              echo json_encode(['success' => false, 'message' => 'No admin account available for chat']);
              exit;
            }

            if ($receiver_id <= 0 || $receiver_id !== $adminUserId) {
              $receiver_id = $adminUserId;
            }
            
            // Limit message length
            if (strlen($message) > 500) {
                echo json_encode(['success' => false, 'message' => 'Message too long (max 500 characters)']);
                exit;
            }
            
            try {
                $encryptedMessage = encryptChatMessage($message);
                $stmt = $pdo->prepare("
                    INSERT INTO chats (match_id, sender_id, receiver_id, message) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$match_id, $user_id, $receiver_id, $encryptedMessage]);
                echo json_encode(['success' => true, 'message' => 'Message sent']);
            } catch (PDOException $e) {
                error_log("Chat message error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error sending message']);
            }
            exit;
        }
    } catch (Exception $e) {
        error_log("Chat AJAX error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Server error occurred',
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

$shouldRenderSidebar = ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']));
$chat_pin_policy = getChatPinPolicy();

// Function to get category emoji
function getCategoryEmoji($category) {
  switch(strtolower(trim($category))) {
    case 'electronics': return 'EL';
    case 'clothing':
    case 'clothing & accessories': return 'CL';
    case 'bags':
    case 'bags & luggage': return 'BG';
    case 'accessories': return 'AC';
    case 'documents':
    case 'books & documents': return 'DC';
    case 'books': return 'BK';
    case 'jewelry':
    case 'jewelry & watches': return 'JW';
    case 'keys':
    case 'keys & cards': return 'KY';
    case 'sports equipment':
    case 'sports & recreation': return 'SP';
    case 'personal items': return 'PI';
    case 'other': return 'IT';
    default: return 'IT';
    }
}

// Function to get confidence level styling
function getConfidenceLevel($score) {
  if ($score >= 80) return ['class' => 'bg-orange-500/20 text-orange-100 border border-orange-400/40', 'label' => 'High Match', 'icon' => 'HIGH'];
  if ($score >= 60) return ['class' => 'bg-cyan-500/20 text-cyan-100 border border-cyan-400/40', 'label' => 'Good Match', 'icon' => 'GOOD'];
  if ($score >= 40) return ['class' => 'bg-indigo-500/20 text-indigo-100 border border-indigo-400/40', 'label' => 'Possible Match', 'icon' => 'POSS'];
  return ['class' => 'bg-slate-700 text-slate-100 border border-slate-500', 'label' => 'Low Match', 'icon' => 'LOW'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Matches & Chats - FindIt Dashboard</title>
  <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      padding: 0;
      background:
        radial-gradient(circle at 8% 8%, rgba(255, 122, 28, 0.16), transparent 38%),
        radial-gradient(circle at 92% 4%, rgba(34, 211, 238, 0.12), transparent 42%),
        #0a0f14;
    }

    .ml-sidebar {
      margin-left: 280px;
      height: 100vh;
      overflow: hidden;
      padding: 14px;
      background: transparent;
    }

    /* Main container with proper height */
    #mainContainer {
      height: calc(100vh - 28px);
      display: flex;
      gap: 12px;
    }

    /* Matches Panel - Left Side */
    .matches-panel {
      width: 100%;
      max-width: 330px;
      display: flex;
      flex-direction: column;
      height: 100%;
      background: #131922;
      border: 1px solid #273040;
      border-radius: 14px;
      box-shadow: 0 8px 22px rgba(0, 0, 0, 0.34);
      overflow: hidden;
    }

    .matches-panel .p-6 {
      flex-shrink: 0;
    }

    /* Scrollable matches list */
    #matchesList {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 10px;
      scrollbar-width: thin;
      scrollbar-color: #364152 #11161f;
    }

    #matchesList::-webkit-scrollbar {
      width: 8px;
    }

    #matchesList::-webkit-scrollbar-track {
      background: #11161f;
      border-radius: 4px;
    }

    #matchesList::-webkit-scrollbar-thumb {
      background: #364152;
      border-radius: 4px;
    }

    #matchesList::-webkit-scrollbar-thumb:hover {
      background: #4c5a70;
    }

    /* Chat Panel - Right Side */
    .chat-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      height: 100%;
      min-width: 0;
    }

    /* Chat Header - Sticky */
    #chatHeader {
      flex-shrink: 0;
      position: sticky;
      top: 0;
      z-index: 10;
      background: #131922;
      border: 1px solid #273040;
      border-radius: 14px;
      box-shadow: 0 8px 22px rgba(0, 0, 0, 0.34);
    }

    /* Messages Area - Scrollable */
    #messagesArea {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 14px;
      background: #0f151e;
      border: 1px solid #273040;
      border-radius: 14px;
      box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.32);
      margin: 10px 0;
      scrollbar-width: thin;
      scrollbar-color: #364152 #11161f;
      scroll-behavior: smooth;
    }

    #messagesArea::-webkit-scrollbar {
      width: 8px;
    }

    #messagesArea::-webkit-scrollbar-track {
      background: #11161f;
      border-radius: 4px;
    }

    #messagesArea::-webkit-scrollbar-thumb {
      background: #364152;
      border-radius: 4px;
    }

    #messagesArea::-webkit-scrollbar-thumb:hover {
      background: #4c5a70;
    }

    /* Message Input - Sticky at Bottom */
    #messageInput {
      flex-shrink: 0;
      background: #131922;
      border: 1px solid #273040;
      border-radius: 14px;
      box-shadow: 0 -1px 8px rgba(0, 0, 0, 0.34);
    }

    /* Match Card Styling */
    .match-card {
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
    }

    .match-card:hover {
      background: #1a212d;
      border-color: #ff7b2c;
      transform: translateY(-2px);
      box-shadow: 0 6px 14px rgba(255, 91, 31, 0.18);
    }

    .match-card.active {
      background: #1a212d;
      border-color: #ff7b2c;
      border-width: 2px;
      box-shadow: 0 6px 14px rgba(255, 91, 31, 0.24);
    }

    .match-card.active::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 4px;
      background: linear-gradient(180deg, #ff7b2c, #ff5b1f);
      border-radius: 4px 0 0 4px;
    }

    /* Chat Bubbles */
    .chat-bubble-left {
      background: #1a212d;
      border: 1px solid #2e394c;
      color: #dbe5f6;
      border-radius: 16px 16px 16px 5px;
      animation: slideInLeft 0.3s ease-out;
    }
    
    .chat-bubble-right {
      background: linear-gradient(135deg, #ff5b1f, #ff7b2c);
      color: white;
      border-radius: 16px 16px 5px 16px;
      animation: slideInRight 0.3s ease-out;
    }

    @keyframes slideInLeft {
      from {
        opacity: 0;
        transform: translateX(-20px);
      }
      to {
        opacity: 1;
        transform: translateX(0);
      }
    }

    @keyframes slideInRight {
      from {
        opacity: 0;
        transform: translateX(20px);
      }
      to {
        opacity: 1;
        transform: translateX(0);
      }
    }

    /* Loading State */
    .loading-messages {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 20px;
    }

    .loading-spinner {
      border: 3px solid #f3f4f6;
      border-top: 3px solid #ff5b1f;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    /* Confidence Badges */
    .confidence-high {
      background: rgba(255, 123, 44, 0.2);
      color: #ffd4bd;
      animation: pulse-green 2s infinite;
    }
    
    .confidence-medium {
      background: rgba(56, 189, 248, 0.2);
      color: #bdefff;
    }
    
    @keyframes pulse-green {
      0%, 100% { 
        background-color: rgba(255, 123, 44, 0.2); 
      }
      50% { 
        background-color: rgba(255, 123, 44, 0.3); 
      }
    }
    
    /* Back Button for Mobile */
    #backToMatches {
      display: none;
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
      .ml-sidebar {
        margin-left: 0;
        padding: 12px;
      }

      #mainContainer {
        height: calc(100vh - 24px);
      }

      .matches-panel {
        max-width: 100%;
      }

      .mobile-chat-view .matches-panel {
        display: none;
      }
      
      .mobile-chat-view .chat-panel {
        width: 100%;
        max-width: 100%;
      }

      .mobile-chat-view #backToMatches {
        display: inline-flex;
      }

      #chatHeader {
        border-radius: 12px;
      }

      #messagesArea {
        border-radius: 12px;
      }

      #messageInput {
        border-radius: 12px;
      }
    }

    /* Smooth Transitions */
    .transition-smooth {
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Image Hover Effect */
    .match-card img {
      transition: transform 0.3s ease;
    }

    .match-card:hover img {
      transform: scale(1.05);
    }

    /* Image Preview Modal */
    .image-preview-modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.9);
      z-index: 100;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      transition: opacity 0.3s ease;
    }

    .image-preview-modal.show {
      opacity: 1;
    }

    .image-preview-modal img {
      max-width: 90%;
      max-height: 90vh;
      object-fit: contain;
      border-radius: 12px;
      box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
      transform: scale(0.9);
      transition: transform 0.3s ease;
    }

    .image-preview-modal.show img {
      transform: scale(1);
    }

    .image-preview-close {
      position: absolute;
      top: 20px;
      right: 20px;
      background: #131922;
      color: #ecf2ff;
      border: 1px solid #2e394c;
      width: 48px;
      height: 48px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }

    .image-preview-close:hover {
      background: #1a212d;
      transform: scale(1.1);
    }

    /* Image zoom cursor */
    .cursor-zoom {
      cursor: zoom-in;
    }

    .chat-pin-modal-backdrop {
      position: fixed;
      inset: 0;
      z-index: 1800;
      background: rgba(2, 6, 16, 0.82);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .chat-pin-modal {
      width: 100%;
      max-width: 420px;
      background: #131922;
      border: 1px solid #2b3646;
      border-radius: 16px;
      box-shadow: 0 18px 38px rgba(0, 0, 0, 0.48);
      overflow: hidden;
    }

    .chat-pin-modal-head {
      padding: 16px 18px;
      border-bottom: 1px solid #273040;
      background: linear-gradient(130deg, #2a3441 0%, #1b232d 42%, #111821 100%);
      color: #f3f6fb;
    }

    .chat-pin-modal-body {
      padding: 16px 18px 18px;
    }

    .chat-pin-input {
      width: 100%;
      border: 1px solid #334155;
      border-radius: 10px;
      padding: 0.72rem 0.9rem;
      letter-spacing: 0.3em;
      text-align: center;
      font-weight: 700;
      background: #0f151e;
      color: #f2f5fa;
    }

    .chat-pin-input:focus {
      outline: none;
      border-color: #94a3b8;
      box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.2);
    }

    .chat-pin-btn {
      width: 100%;
      border: 0;
      border-radius: 10px;
      padding: 0.7rem 0.9rem;
      font-weight: 700;
      color: #ffffff;
      background: linear-gradient(130deg, #64748b 0%, #475569 42%, #334155 100%);
      transition: filter 0.2s ease;
      cursor: pointer;
    }

    .chat-pin-btn:hover {
      filter: brightness(1.08);
    }

    .chat-pin-note {
      font-size: 0.78rem;
      color: #9fb0c7;
    }
  </style>
</head>
<body class="font-sans claimant-theme">
  <?php if ($shouldRenderSidebar): ?>
    <?php include "../includes/components/shared_sidebar.php"; ?>
  <?php endif; ?>

  <!-- Main Content -->
  <div class="ml-sidebar">
    <div class="flex flex-col md:flex-row h-full" id="mainContainer">

      <!-- Left Panel: Matches List -->
      <div class="w-full md:w-1/3 border rounded-xl flex flex-col matches-panel mb-4 md:mb-0">
        <div class="p-4 border-b border-slate-700">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-lg font-bold text-slate-100 inline-flex items-center gap-2">
                <svg class="w-5 h-5 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12H15M9 16H13M4 7C4 5.89543 4.89543 5 6 5H18C19.1046 5 20 5.89543 20 7V17C20 18.1046 19.1046 19 18 19H6C4.89543 19 4 18.1046 4 17V7Z"/></svg>
                <span class="text-orange-200">Smart Matches</span>
              </h2>
              <p class="text-xs text-slate-400 mt-1">Compact conversation list</p>
            </div>
            <div class="text-right">
              <div class="text-xl font-bold text-orange-300"><?php echo count($matches); ?></div>
              <div class="text-xs text-slate-400">Total Matches</div>
            </div>
          </div>
        </div>
        <div class="flex-1 overflow-y-auto p-4" id="matchesList">
          <?php if (!empty($matches)): ?>
            <?php foreach ($matches as $match): ?>
              <?php 
                $confidence = getConfidenceLevel($match['similarity_score']);
                $isMyLostItem = ($match['user_item_type'] === 'lost');
              $displayImage = (string)($match['chat_display_image_src'] ?? '');
              $imageExists = ($displayImage !== '');
              ?>
                  <div class="match-card bg-white rounded-lg border p-3 mb-3 cursor-pointer transition-all duration-200 hover:shadow-md" 
                    onclick='loadChat(<?php echo (int)$match['match_id']; ?>, <?php echo (int)($match['admin_user_id'] ?? 0); ?>, <?php echo json_encode((string)$match['other_item_title']); ?>, <?php echo json_encode((string)$displayImage); ?>, "Admin")'>
                
                <!-- Smart Match Badge -->
                <div class="flex items-center justify-between mb-3">
                  <span class="<?php echo $confidence['class']; ?> px-3 py-1 rounded-full text-xs font-medium">
                    <?php echo $confidence['icon']; ?> <?php echo $match['similarity_score']; ?>% Match
                  </span>
                  <span class="text-xs text-gray-500">
                    <?php echo date('M j, Y', strtotime($match['matched_at'])); ?>
                  </span>
                </div>
                
                <div class="flex items-start space-x-3">
                  <!-- Item Image -->
                  <div class="w-14 h-14 bg-gradient-to-br from-orange-500/30 to-cyan-500/20 rounded-lg overflow-hidden flex-shrink-0 shadow-sm border border-slate-600">
                    <?php if ($imageExists): ?>
                        <img src="<?php echo htmlspecialchars($displayImage, ENT_QUOTES, 'UTF-8'); ?>" 
                           alt="<?php echo htmlspecialchars($match['other_item_title']); ?>" 
                           class="w-full h-full object-cover hover:scale-110 transition-transform duration-300"
                           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                           loading="lazy">
                      <div class="w-full h-full flex items-center justify-center text-xs font-bold text-teal-700 bg-gradient-to-br from-teal-100 to-emerald-200" style="display: none;">
                        <?php echo getCategoryEmoji($isMyLostItem ? $match['lost_category'] : $match['found_category']); ?>
                      </div>
                    <?php else: ?>
                      <div class="w-full h-full flex items-center justify-center text-xs font-bold text-teal-700 bg-gradient-to-br from-teal-100 to-emerald-200">
                        <?php echo getCategoryEmoji($isMyLostItem ? $match['lost_category'] : $match['found_category']); ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  
                  <!-- Match Info -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between mb-2">
                      <h4 class="text-sm font-semibold text-gray-900 truncate">
                        <?php echo htmlspecialchars($match['other_item_title']); ?>
                      </h4>
                    </div>
                    
                    <div class="bg-slate-900/80 rounded-lg p-2 mb-2 border border-slate-700">
                      <p class="text-xs text-slate-200 font-medium">
                        <?php echo $isMyLostItem ? 'Possible finder match for your lost item.' : 'Possible owner match for your found item.'; ?>
                      </p>
                      <p class="text-xs text-slate-400 mt-1">
                        Smart algorithm detected <?php echo $confidence['label']; ?>
                      </p>
                    </div>
                    
                    <div class="flex items-center justify-between text-xs text-slate-400">
                      <span class="flex items-center">
                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                        </svg>
                        <?php echo htmlspecialchars($match['other_user_name']); ?>
                      </span>
                      <span class="text-xs text-slate-500">
                        <?php echo $isMyLostItem ? 'Found: ' . date('M j', strtotime($match['found_report_date'])) : 'Lost: ' . date('M j', strtotime($match['lost_report_date'])); ?>
                      </span>
                    </div>

                    <?php if (!empty($match['claim_status'])): ?>
                      <div class="mt-2 text-xs rounded-full px-2 py-1 inline-block
                        <?php echo $match['claim_status'] === 'pending' ? 'bg-amber-500/20 text-amber-100 border border-amber-400/30' : ($match['claim_status'] === 'approved' ? 'bg-cyan-500/20 text-cyan-100 border border-cyan-400/30' : ($match['claim_status'] === 'ready_for_pickup' ? 'bg-indigo-500/20 text-indigo-100 border border-indigo-400/30' : ($match['claim_status'] === 'claimed' ? 'bg-emerald-500/20 text-emerald-100 border border-emerald-400/30' : 'bg-red-500/20 text-red-100 border border-red-400/30'))); ?>">
                        Claim: <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($match['claim_status']))); ?>
                      </div>
                    <?php endif; ?>
                    
                    <?php if ($match['message_count'] > 0): ?>
                      <div class="mt-2 flex items-center text-xs text-cyan-100 bg-cyan-500/20 border border-cyan-400/30 rounded-full px-2 py-1">
                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
                        </svg>
                        <?php echo $match['message_count']; ?> message<?php echo $match['message_count'] > 1 ? 's' : ''; ?>
                      </div>
                    <?php else: ?>
                      <div class="mt-2 text-xs text-slate-300 bg-slate-800 rounded-full px-2 py-1 text-center border border-slate-700">
                        Click to start chat
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-center py-12">
              <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-orange-500/20 border border-orange-400/40 flex items-center justify-center">
                <svg class="w-8 h-8 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12H15M9 16H13M4 7C4 5.89543 4.89543 5 6 5H18C19.1046 5 20 5.89543 20 7V17C20 18.1046 19.1046 19 18 19H6C4.89543 19 4 18.1046 4 17V7ZM7 3V5M17 3V5"/>
                </svg>
              </div>
              <h3 class="text-lg font-medium text-slate-100 mb-2">No Smart Matches Yet</h3>
              <p class="text-slate-400 text-sm mb-4">Our AI-powered matching system is still analyzing your items.</p>
              <div class="bg-slate-900 border border-slate-700 rounded-lg p-4 text-left max-w-sm mx-auto">
                <h4 class="text-sm font-semibold text-orange-200 mb-2">How Smart Matching Works:</h4>
                <ul class="text-xs text-slate-300 space-y-1">
                  <li>- AI analyzes item descriptions and keywords</li>
                  <li>- Compares lost and found items automatically</li>
                  <li>- Generates confidence scores for matches</li>
                  <li>- Updates in real-time as new items are reported</li>
                </ul>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right Panel: Chat Window -->
      <div class="flex-1 flex flex-col chat-panel">
        <!-- Chat Header -->
        <div class="bg-slate-900 p-4 border border-slate-700 rounded-xl" id="chatHeader" style="display: none;">
          <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center space-x-3 min-w-0 flex-1">
              <!-- Back Button (Mobile Only) -->
              <button id="backToMatches" onclick="backToMatchesList()" 
                      class="text-orange-300 hover:text-orange-200 p-2 hover:bg-slate-800 rounded-lg transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
              </button>
              
              <div class="relative w-12 h-12 flex-shrink-0 cursor-zoom" onclick="previewImage(document.getElementById('chatItemImage').src)" title="Click to preview">
                <img id="chatItemImage" class="w-12 h-12 rounded-lg object-cover shadow-sm transition-all hover:scale-105" src="" alt="">
                <div id="chatImageFallback" class="w-12 h-12 rounded-lg bg-gradient-to-br from-teal-100 to-emerald-200 flex items-center justify-center text-xs font-bold text-teal-700" style="display: none;">
                  IT
                </div>
              </div>
              
              <div class="min-w-0 flex-1">
                <h3 id="chatItemTitle" class="font-bold text-slate-100 truncate text-lg"></h3>
                <p id="chatUserName" class="text-sm text-slate-400 truncate"></p>
              </div>
            </div>
            
            <div class="flex items-center space-x-2 flex-wrap gap-2">
              <span id="matchConfidence" class="px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap"></span>
              <button onclick="showMatchDetails()" 
                      class="text-cyan-200 hover:text-cyan-100 text-sm font-medium px-3 py-1 hover:bg-slate-800 rounded-lg transition-colors whitespace-nowrap">
                Details
              </button>
            </div>
          </div>
        </div>

        <!-- Messages -->
        <div id="messagesArea">
          <div class="flex items-center justify-center h-full" id="defaultChatState">
            <div class="text-center p-8">
              <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-orange-500/20 border border-orange-400/40 flex items-center justify-center">
                <svg class="w-8 h-8 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10H16M8 14H13M20 12C20 16.4183 16.4183 20 12 20C10.4148 20 8.93732 19.5399 7.69385 18.7468L4 20L5.25323 16.3062C4.46009 15.0627 4 13.5852 4 12C4 7.58172 7.58172 4 12 4C16.4183 4 20 7.58172 20 12Z"/>
                </svg>
              </div>
              <h3 class="text-xl font-bold text-slate-100 mb-2">Start a Conversation</h3>
              <p class="text-slate-400 text-base mb-4">Select a match from the left to begin chatting</p>
              <div class="bg-slate-900 border border-slate-700 rounded-xl p-4 max-w-md mx-auto">
                <p class="text-sm text-cyan-100">
                  <strong>Smart Tip:</strong> Choose a match on the left, verify details, and start a secure conversation.
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Message Input -->
        <div class="bg-slate-900 p-4 border border-slate-700 rounded-xl" id="messageInput" style="display: none;">
          <form class="flex flex-col space-y-3" onsubmit="sendMessage(event)">
            <div class="flex space-x-3">
              <input 
                type="text" 
                id="messageText"
                placeholder="Type your message here..." 
                class="flex-1 px-4 py-3 border border-slate-600 bg-[#0f151e] text-slate-100 rounded-full focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-shadow"
                maxlength="500"
                oninput="updateCharCount()"
                required
              >
              <button 
                type="submit"
                id="sendButton"
                class="px-5 py-3 bg-gradient-to-r from-orange-500 to-orange-600 text-white rounded-full hover:from-orange-600 hover:to-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 transition-all duration-200 font-medium shadow-sm hover:shadow-md"
              >
                <span class="hidden sm:inline">Send</span>
                <span class="sm:hidden">Go</span>
              </button>
            </div>
            <div class="flex justify-between items-center text-xs text-slate-400">
              <span class="flex items-center">
                <svg class="w-4 h-4 mr-1 text-orange-300" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                Be specific about your item's details
              </span>
              <span id="charCount" class="font-medium">0/500</span>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div id="chatPinModal" class="chat-pin-modal-backdrop" style="display: none;">
    <div class="chat-pin-modal" role="dialog" aria-modal="true" aria-labelledby="chatPinTitle">
      <div class="chat-pin-modal-head">
        <h3 id="chatPinTitle" class="text-base font-semibold">Secure Chat PIN Required</h3>
        <p id="chatPinSubtitle" class="text-xs text-slate-200 mt-1">Verify your 6-digit PIN to access chat.</p>
      </div>
      <div class="chat-pin-modal-body">
        <form id="chatPinForm" class="space-y-3">
          <div>
            <label for="chatPinInput" class="block text-xs text-slate-300 mb-1">Chat PIN</label>
            <input type="password" id="chatPinInput" name="pin" class="chat-pin-input" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="off" required>
          </div>
          <div id="chatPinConfirmWrap" style="display: none;">
            <label for="chatPinConfirmInput" class="block text-xs text-slate-300 mb-1">Confirm Chat PIN</label>
            <input type="password" id="chatPinConfirmInput" name="confirm_pin" class="chat-pin-input" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
          </div>
          <p id="chatPinFeedback" class="chat-pin-note">This check runs every time you open the chat page.</p>
          <button type="submit" id="chatPinSubmitBtn" class="chat-pin-btn">Verify PIN</button>
          <a href="claimant_dashboard.php" class="inline-flex w-full justify-center rounded-lg border border-slate-600 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 transition-colors">Back to Dashboard</a>
        </form>
      </div>
    </div>
  </div>

  <script>
    let currentMatchId = null;
    let currentReceiverId = null;
    let currentMatchData = null;
    let messageInterval = null;
    let chatPinVerified = false;
    let chatPinNeedsSetup = <?php echo $chat_pin_needs_setup ? 'true' : 'false'; ?>;
    let chatPinLocked = <?php echo !empty($chat_pin_status['locked']) ? 'true' : 'false'; ?>;
    const chatPinPolicy = <?php echo json_encode($chat_pin_policy, JSON_UNESCAPED_SLASHES); ?>;
    const chatPinToken = <?php echo json_encode($chat_pin_token, JSON_UNESCAPED_SLASHES); ?>;

    function maskPinValue(value) {
      return (value || '').replace(/\D/g, '').slice(0, 6);
    }

    function openChatPinModal() {
      const modal = document.getElementById('chatPinModal');
      if (!modal) return;

      const title = document.getElementById('chatPinTitle');
      const subtitle = document.getElementById('chatPinSubtitle');
      const confirmWrap = document.getElementById('chatPinConfirmWrap');
      const submitBtn = document.getElementById('chatPinSubmitBtn');
      const pinInput = document.getElementById('chatPinInput');
      const confirmInput = document.getElementById('chatPinConfirmInput');

      if (chatPinNeedsSetup) {
        title.textContent = 'Create Chat Security PIN';
        subtitle.textContent = 'Set a 6-digit PIN to protect every chat-page access.';
        confirmWrap.style.display = 'block';
        submitBtn.textContent = 'Create PIN';
      } else {
        title.textContent = 'Secure Chat PIN Required';
        subtitle.textContent = 'Verify your 6-digit PIN to access chat.';
        confirmWrap.style.display = 'none';
        submitBtn.textContent = 'Verify PIN';
      }

      pinInput.value = '';
      confirmInput.value = '';
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      setTimeout(() => pinInput.focus(), 40);
    }

    function closeChatPinModal() {
      const modal = document.getElementById('chatPinModal');
      if (!modal) return;
      modal.style.display = 'none';
      document.body.style.overflow = '';
    }

    function setChatPinFeedback(message, isError = false) {
      const feedback = document.getElementById('chatPinFeedback');
      if (!feedback) return;
      feedback.textContent = message;
      feedback.className = isError ? 'chat-pin-note text-red-300' : 'chat-pin-note';
    }

    function handleChatPinRequired(payload = null) {
      chatPinVerified = false;
      const status = payload && payload.status ? payload.status : null;
      if (status && typeof status.has_pin !== 'undefined') {
        chatPinNeedsSetup = !Boolean(status.has_pin);
      }
      chatPinLocked = status ? Boolean(status.locked) : false;

      if (messageInterval) {
        clearInterval(messageInterval);
        messageInterval = null;
      }

      if (chatPinLocked && status && status.locked_until) {
        setChatPinFeedback('Chat PIN is locked until ' + status.locked_until + '.', true);
      } else if (payload && payload.message) {
        setChatPinFeedback(payload.message, true);
      } else {
        setChatPinFeedback('Chat PIN verification required.', true);
      }

      openChatPinModal();
    }

    function submitChatPin(event) {
      event.preventDefault();

      const pinInput = document.getElementById('chatPinInput');
      const confirmInput = document.getElementById('chatPinConfirmInput');
      const submitBtn = document.getElementById('chatPinSubmitBtn');
      const pin = maskPinValue(pinInput.value);
      const confirmPin = maskPinValue(confirmInput.value);

      pinInput.value = pin;
      confirmInput.value = confirmPin;

      if (!/^\d{6}$/.test(pin)) {
        setChatPinFeedback('PIN must be exactly 6 digits.', true);
        return;
      }

      if (chatPinNeedsSetup && pin !== confirmPin) {
        setChatPinFeedback('PIN and confirmation must match.', true);
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = chatPinNeedsSetup ? 'Creating...' : 'Verifying...';

      const formData = new FormData();
      formData.append('action', chatPinNeedsSetup ? 'create_chat_pin' : 'verify_chat_pin');
      formData.append('pin', pin);
      if (chatPinNeedsSetup) {
        formData.append('confirm_pin', confirmPin);
      }
      formData.append('csrf_token', chatPinToken);

      fetch('claimant_matches_chats.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          chatPinVerified = true;
          chatPinNeedsSetup = false;
          chatPinLocked = false;
          closeChatPinModal();
          setChatPinFeedback('PIN verified.');
          showSuccessMessage('Chat security verification complete.');
          return;
        }

        if (data.status) {
          chatPinNeedsSetup = !Boolean(data.status.has_pin);
          chatPinLocked = Boolean(data.status.locked);
        }

        const message = data.message || 'PIN verification failed.';
        if (data.locked && data.locked_until) {
          setChatPinFeedback(message + ' Locked until ' + data.locked_until + '.', true);
        } else if (typeof data.remaining_attempts !== 'undefined') {
          setChatPinFeedback(message + ' Attempts left: ' + data.remaining_attempts + '.', true);
        } else {
          setChatPinFeedback(message, true);
        }
      })
      .catch(() => {
        setChatPinFeedback('Unable to verify PIN right now. Please try again.', true);
      })
      .finally(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = chatPinNeedsSetup ? 'Create PIN' : 'Verify PIN';
      });
    }

    function loadChat(matchId, receiverId, itemTitle, itemImage, userName) {
      if (!chatPinVerified) {
        handleChatPinRequired({ message: 'Verify your chat PIN before opening a conversation.' });
        return;
      }

      currentMatchId = matchId;
      currentReceiverId = receiverId;
      
      // Remove active class from all match cards
      document.querySelectorAll('.match-card').forEach(card => {
        card.classList.remove('active');
      });
      
      // Add active class to selected match
      event.currentTarget?.classList.add('active');
      
      // Find match data for additional information
      currentMatchData = findMatchData(matchId);
      
      // Update chat header
      document.getElementById('chatItemTitle').textContent = itemTitle;
      document.getElementById('chatUserName').textContent = 'Claim Chat with ' + userName;
      
      // Handle image display with fallback
      const chatImage = document.getElementById('chatItemImage');
      const chatImageFallback = document.getElementById('chatImageFallback');
      
      if (itemImage && itemImage.trim() !== '') {
        chatImage.src = itemImage;
        chatImage.style.display = 'block';
        chatImageFallback.style.display = 'none';
        
        chatImage.onerror = function() {
          chatImage.style.display = 'none';
          chatImageFallback.style.display = 'flex';
        };
      } else {
        chatImage.style.display = 'none';
        chatImageFallback.style.display = 'flex';
      }
      
      // Update match confidence badge
      if (currentMatchData) {
        const confidence = getConfidenceLevelJS(currentMatchData.similarity_score);
        const confidenceBadge = document.getElementById('matchConfidence');
        confidenceBadge.textContent = `${confidence.icon} ${currentMatchData.similarity_score}%`;
        confidenceBadge.className = `px-3 py-1 rounded-full text-xs font-medium ${confidence.class}`;
      }
      
      // Show chat interface FIRST before loading
      document.getElementById('chatHeader').style.display = 'block';
      document.getElementById('messageInput').style.display = 'block';
      document.getElementById('defaultChatState').style.display = 'none';
      
      // Show loading state briefly
      showLoadingState();
      
      // Load messages immediately
      loadMessages();
      
      // Set up auto-refresh for messages
      if (messageInterval) {
        clearInterval(messageInterval);
      }
      messageInterval = setInterval(loadMessages, 3000); // Refresh every 3 seconds
      
      // Add mobile responsiveness
      if (window.innerWidth < 768) {
        document.querySelector('.matches-panel').style.display = 'none';
        document.querySelector('.chat-panel').style.width = '100%';
        document.body.classList.add('mobile-chat-view');
      }
      
      // Focus message input
      setTimeout(() => {
        document.getElementById('messageText')?.focus();
      }, 500);
    }

    function backToMatchesList() {
      document.querySelector('.matches-panel').style.display = 'flex';
      document.querySelector('.chat-panel').style.width = '';
      document.body.classList.remove('mobile-chat-view');
      
      // Clear interval
      if (messageInterval) {
        clearInterval(messageInterval);
        messageInterval = null;
      }
      
      // Hide chat interface
      document.getElementById('chatHeader').style.display = 'none';
      document.getElementById('messageInput').style.display = 'none';
      document.getElementById('defaultChatState').style.display = 'flex';
      
      // Remove active class from all match cards
      document.querySelectorAll('.match-card').forEach(card => {
        card.classList.remove('active');
      });
    }

    function showLoadingState() {
      const messagesArea = document.getElementById('messagesArea');
      messagesArea.innerHTML = `
        <div class="flex items-center justify-center h-full">
          <div class="text-center">
            <div class="loading-spinner mx-auto mb-3"></div>
            <p class="text-gray-500 text-sm">Loading messages...</p>
          </div>
        </div>
      `;
    }

function findMatchData(matchId) {
  // Get match data from PHP matches array
  const matches = <?php echo json_encode($matches); ?>;
  const match = matches.find(m => m.match_id == matchId);
  
  if (match) {
    return {
      match_id: matchId,
      similarity_score: parseFloat(match.similarity_score),
      matched_at: new Date(match.matched_at),
      description: 'Smart AI matching found ' + getConfidenceLevelJS(parseFloat(match.similarity_score)).label + ' based on keywords and description analysis.',
      lost_title: match.lost_title,
      found_title: match.found_title,
      category: match.lost_category || match.found_category
    };
  }
  
  return {
    match_id: matchId,
    similarity_score: 0,
    matched_at: new Date(),
    description: 'Match details unavailable.'
  };
}    function getConfidenceLevelJS(score) {
  if (score >= 80) return {class: 'bg-orange-500/20 text-orange-100 border border-orange-400/40', label: 'High Match', icon: 'HIGH'};
  if (score >= 60) return {class: 'bg-cyan-500/20 text-cyan-100 border border-cyan-400/40', label: 'Good Match', icon: 'GOOD'};
  if (score >= 40) return {class: 'bg-indigo-500/20 text-indigo-100 border border-indigo-400/40', label: 'Possible Match', icon: 'POSS'};
  return {class: 'bg-slate-700 text-slate-100 border border-slate-500', label: 'Low Match', icon: 'LOW'};
    }

    function showMatchDetails() {
      if (!currentMatchData) return;
      
      const confidence = getConfidenceLevelJS(currentMatchData.similarity_score);
      
      const modal = document.createElement('div');
      modal.className = 'fixed inset-0 theme-modal-backdrop flex items-center justify-center z-50';
      modal.innerHTML = `
        <div class="theme-modal-panel rounded-xl max-w-md w-full mx-4 p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Smart Match Details</h3>
            <button onclick="this.parentElement.parentElement.parentElement.remove()" class="text-gray-400 hover:text-gray-600">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>
          <div class="space-y-4">
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
              <span class="text-sm text-gray-600">Match Confidence</span>
              <span class="${confidence.class} px-3 py-1 rounded-full text-xs font-medium">
                ${confidence.icon} ${currentMatchData.similarity_score}% ${confidence.label}
              </span>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
              <h4 class="text-sm font-semibold text-blue-800 mb-2">How This Match Was Found</h4>
              <ul class="text-xs text-blue-700 space-y-1">
                <li>- AI analyzed item titles and descriptions</li>
                <li>- Compared keywords and phrases for similarity</li>
                <li>- Matched categories and item characteristics</li>
                <li>- Generated confidence score based on similarity</li>
              </ul>
            </div>
            <div class="text-xs text-gray-500">
              <p><strong>Matched on:</strong> ${new Date(currentMatchData.matched_at).toLocaleDateString()}</p>
              <p><strong>Algorithm:</strong> Natural Language Processing + Keyword Analysis</p>
            </div>
          </div>
          <div class="mt-6 flex justify-end">
              <button onclick="this.parentElement.parentElement.parentElement.remove()" 
                    class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors">
              Got it
            </button>
          </div>
        </div>
      `;
      
      document.body.appendChild(modal);
    }

    function loadMessages() {
      if (!currentMatchId || !chatPinVerified) return;
      const messagesArea = document.getElementById('messagesArea');
      
      const formData = new FormData();
      formData.append('action', 'get_messages');
      formData.append('match_id', currentMatchId);
      
      fetch('claimant_matches_chats.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Check if response is actually JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          return response.text().then(() => {
            throw new Error('Server returned non-JSON response');
          });
        }
        
        return response.json();
      })
      .then(data => {
        if (data.requires_pin) {
          handleChatPinRequired(data);
          return;
        }

        if (data.success) {
          displayMessages(data.messages);
        } else {
          console.error('Error loading messages:', data.message);
          showErrorMessage('Failed to load messages: ' + (data.message || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Error loading messages:', error);
        // Only show error on first load, not on routine refreshes
        if (!messagesArea.innerHTML.includes('messages')) {
          showErrorMessage('Failed to load messages: ' + error.message);
        }
      });
    }

    function displayMessages(messages) {
      const messagesArea = document.getElementById('messagesArea');
      const currentUserId = <?php echo $user_id; ?>;
      
      // Store current scroll position
      const wasAtBottom = messagesArea.scrollHeight - messagesArea.scrollTop <= messagesArea.clientHeight + 100;
      
      messagesArea.innerHTML = '';
      
      if (messages.length === 0) {
        const matchData = currentMatchData || {};
        const confidence = getConfidenceLevelJS(matchData.similarity_score || 0);
        
        messagesArea.innerHTML = `
          <div class="flex items-center justify-center h-full">
            <div class="w-full max-w-xl rounded-2xl border border-slate-700 bg-slate-900 p-5 text-left shadow-sm">
              <div class="flex items-center justify-between gap-3">
                <h4 class="text-lg font-bold text-slate-100">Start Your Conversation</h4>
                <span class="${confidence.class} px-3 py-1 rounded-full text-xs font-semibold">${confidence.icon} ${matchData.similarity_score || 0}%</span>
              </div>
              <p class="mt-2 text-sm text-slate-400">No messages yet. Keep it short and specific so verification is easier.</p>
              <div class="mt-4 rounded-xl border border-slate-700 bg-slate-800 p-4">
                <p class="text-xs font-semibold text-orange-200">Suggested first message</p>
                <p class="mt-2 text-sm text-slate-200">"Hi, I believe this may be my item. Can you confirm unique details and where it was found?"</p>
              </div>
              <button onclick="document.getElementById('messageText').focus()" class="mt-4 inline-flex items-center rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Write first message</button>
            </div>
          </div>
        `;
        return;
      }
      
      messages.forEach((message, index) => {
        const isMyMessage = message.sender_id == currentUserId;
        const messageDiv = document.createElement('div');
        messageDiv.className = `flex ${isMyMessage ? 'justify-end' : 'justify-start'} mb-4`;
        messageDiv.style.animationDelay = `${index * 0.05}s`;
        
        messageDiv.innerHTML = `
          <div class="max-w-[78%] md:max-w-[65%] px-3.5 py-2.5 ${isMyMessage ? 'chat-bubble-right' : 'chat-bubble-left'} shadow-sm">
            ${!isMyMessage ? `<p class="text-xs font-medium text-gray-700 mb-1">${escapeHtml(message.sender_name)}</p>` : ''}
            <p class="text-sm leading-relaxed">${escapeHtml(message.message)}</p>
            <div class="flex items-center justify-end mt-2 space-x-2">
              <p class="text-xs ${isMyMessage ? 'text-teal-100' : 'text-gray-500'}">
                ${formatMessageTime(message.sent_at)}
              </p>
            </div>
          </div>
        `;
        
        messagesArea.appendChild(messageDiv);
      });
      
      // Smooth scroll to bottom if user was already at bottom or it's a new message
      if (wasAtBottom || messages.length === 1) {
        scrollToBottom(messagesArea);
      }
    }

    function formatMessageTime(timestamp) {
      const date = new Date(timestamp);
      const now = new Date();
      const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      const messageDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
      
      const time = date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
      
      if (messageDate.getTime() === today.getTime()) {
        return time;
      } else if (messageDate.getTime() === today.getTime() - 86400000) {
        return 'Yesterday ' + time;
      } else {
        return date.toLocaleDateString([], {month: 'short', day: 'numeric'}) + ' ' + time;
      }
    }

    function scrollToBottom(element, smooth = true) {
      if (smooth) {
        element.scrollTo({
          top: element.scrollHeight,
          behavior: 'smooth'
        });
      } else {
        element.scrollTop = element.scrollHeight;
      }
    }

    function sendMessage(event) {
      event.preventDefault();

      if (!chatPinVerified) {
        handleChatPinRequired({ message: 'Verify your chat PIN before sending messages.' });
        return;
      }
      
      const messageInput = document.getElementById('messageText');
      const message = messageInput.value.trim();
      
      if (!message || !currentMatchId || !currentReceiverId) {
        if (!message) {
          showErrorMessage('Please enter a message');
        }
        return;
      }
      
      // Check message length
      if (message.length > 500) {
        showErrorMessage('Message too long (max 500 characters)');
        return;
      }
      
      // Disable input while sending
      messageInput.disabled = true;
      const sendButton = document.getElementById('sendButton');
      const originalButtonText = sendButton.innerHTML;
      
      if (sendButton) {
        sendButton.disabled = true;
        sendButton.innerHTML = `
          <svg class="animate-spin h-5 w-5 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
        `;
      }
      
      const formData = new FormData();
      formData.append('action', 'send_message');
      formData.append('match_id', currentMatchId);
      formData.append('receiver_id', currentReceiverId);
      formData.append('message', message);
      
      fetch('claimant_matches_chats.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Check if response is actually JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          return response.text().then(() => {
            throw new Error('Server returned non-JSON response');
          });
        }
        
        return response.json();
      })
      .then(data => {
        if (data.requires_pin) {
          handleChatPinRequired(data);
          return;
        }

        if (data.success) {
          messageInput.value = '';
          updateCharCount();
          loadMessages(); // Refresh messages
          showSuccessMessage('Message sent');
        } else {
          showErrorMessage('Error: ' + (data.message || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Error sending message:', error);
        showErrorMessage('Failed to send. Please try again.');
      })
      .finally(() => {
        // Re-enable input
        messageInput.disabled = false;
        if (sendButton) {
          sendButton.disabled = false;
          sendButton.innerHTML = originalButtonText;
        }
        messageInput.focus();
      });
    }

    function showErrorMessage(message) {
      showToast(message, 'error');
    }

    function showSuccessMessage(message) {
      showToast(message, 'success');
    }

    function showToast(message, type) {
      // Create toast notification
      const toast = document.createElement('div');
      toast.className = `fixed top-4 right-4 z-50 px-4 py-2 rounded-lg text-white font-medium transform transition-all duration-300 ${
        type === 'error' ? 'bg-red-500' : 'bg-green-500'
      }`;
      toast.textContent = message;
      toast.style.transform = 'translateX(100%)';
      
      document.body.appendChild(toast);
      
      // Animate in
      setTimeout(() => {
        toast.style.transform = 'translateX(0)';
      }, 100);
      
      // Remove after 3 seconds
      setTimeout(() => {
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => {
          if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
          }
        }, 300);
      }, 3000);
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    function updateCharCount() {
      const messageInput = document.getElementById('messageText');
      const charCount = document.getElementById('charCount');
      
      if (!messageInput || !charCount) return;
      
      const currentLength = messageInput.value.length;
      
      charCount.textContent = `${currentLength}/500`;
      
      // Change color as approaching limit
      if (currentLength > 450) {
        charCount.className = 'text-red-600 font-bold';
        messageInput.classList.add('border-red-500');
        messageInput.classList.remove('border-gray-300');
      } else if (currentLength > 400) {
        charCount.className = 'text-yellow-600 font-semibold';
        messageInput.classList.add('border-yellow-500');
        messageInput.classList.remove('border-gray-300', 'border-red-500');
      } else {
        charCount.className = 'font-medium text-gray-500';
        messageInput.classList.remove('border-red-500', 'border-yellow-500');
        messageInput.classList.add('border-gray-300');
      }
    }

    // Image Preview Modal
    function previewImage(imageSrc) {
      // Don't preview if it's the fallback
      if (!imageSrc || imageSrc === '' || imageSrc.includes('data:')) {
        return;
      }

      // Create modal
      const modal = document.createElement('div');
      modal.className = 'image-preview-modal';
      modal.innerHTML = `
        <div class="image-preview-close" onclick="closeImagePreview()">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </div>
        <img src="${imageSrc}" alt="Item Preview" onclick="event.stopPropagation()">
        <div class="absolute bottom-4 left-1/2 transform -translate-x-1/2 text-white text-sm bg-black bg-opacity-50 px-4 py-2 rounded-full">
          Click outside or press ESC to close
        </div>
      `;

      document.body.appendChild(modal);

      // Show with animation
      setTimeout(() => {
        modal.classList.add('show');
      }, 10);

      // Close on click outside
      modal.addEventListener('click', closeImagePreview);

      // Close on ESC key
      document.addEventListener('keydown', handleEscKey);
    }

    function closeImagePreview() {
      const modal = document.querySelector('.image-preview-modal');
      if (modal) {
        modal.classList.remove('show');
        setTimeout(() => {
          if (modal.parentNode) {
            modal.parentNode.removeChild(modal);
          }
        }, 300);
      }
      document.removeEventListener('keydown', handleEscKey);
    }

    function handleEscKey(e) {
      if (e.key === 'Escape') {
        closeImagePreview();
      }
    }

    // Add click to zoom functionality for match card images
    document.addEventListener('DOMContentLoaded', function() {
      const pinForm = document.getElementById('chatPinForm');
      const pinInput = document.getElementById('chatPinInput');
      const pinConfirmInput = document.getElementById('chatPinConfirmInput');

      if (pinForm) {
        pinForm.addEventListener('submit', submitChatPin);
      }

      if (pinInput) {
        pinInput.addEventListener('input', function() {
          pinInput.value = maskPinValue(pinInput.value);
        });
      }

      if (pinConfirmInput) {
        pinConfirmInput.addEventListener('input', function() {
          pinConfirmInput.value = maskPinValue(pinConfirmInput.value);
        });
      }

      if (!chatPinVerified) {
        if (chatPinLocked) {
          setChatPinFeedback('Chat PIN is temporarily locked. Please try again later.', true);
        } else if (chatPinNeedsSetup) {
          setChatPinFeedback('Create your 6-digit chat PIN to continue.', false);
        }
        openChatPinModal();
      }

      // Make all match card images clickable for preview
      document.addEventListener('click', function(e) {
        const matchImage = e.target.closest('.match-card img');
        if (matchImage && matchImage.src && !matchImage.src.includes('data:')) {
          e.stopPropagation();
          previewImage(matchImage.src);
        }
      });
    });

    // Enhanced showMatchDetails with images
    function showMatchDetailsWithImages() {
      if (!currentMatchData) return;
      
      const confidence = getConfidenceLevelJS(currentMatchData.similarity_score);
      
      const modal = document.createElement('div');
      modal.className = 'fixed inset-0 theme-modal-backdrop flex items-center justify-center z-50 p-4';
      modal.innerHTML = `
        <div class="theme-modal-panel rounded-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="sticky top-0 bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl z-10">
            <div class="flex items-center justify-between">
              <h3 class="text-xl font-semibold inline-flex items-center gap-2"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01"/></svg>Smart Match Details</h3>
              <button onclick="this.closest('.fixed').remove()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-full p-2 transition-all">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>
          </div>
          
          <div class="p-6 space-y-6">
            <!-- Images Side by Side -->
            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-2">
                <h4 class="text-sm font-semibold text-slate-200">Lost Item</h4>
                <div class="relative h-48 bg-slate-900 border border-slate-700 rounded-xl overflow-hidden cursor-zoom" onclick="previewCurrentMatchImage('lost')">
                  <img id="lostItemImg" class="w-full h-full object-cover hover:scale-105 transition-transform" src="" alt="Lost Item" onerror="this.style.display='none'">
                  <div class="absolute inset-0 flex items-center justify-center text-white/80">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 10L12 4L18 10M6 14L12 20L18 14"/></svg>
                  </div>
                </div>
                <p class="text-xs text-slate-300 text-center font-medium">${currentMatchData.lost_title || 'Lost Item'}</p>
              </div>
              
              <div class="space-y-2">
                <h4 class="text-sm font-semibold text-slate-200">Found Item</h4>
                <div class="relative h-48 bg-slate-900 border border-slate-700 rounded-xl overflow-hidden cursor-zoom" onclick="previewCurrentMatchImage('found')">
                  <img id="foundItemImg" class="w-full h-full object-cover hover:scale-105 transition-transform" src="" alt="Found Item" onerror="this.style.display='none'">
                  <div class="absolute inset-0 flex items-center justify-center text-white/80">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 12H20M12 4L20 12L12 20"/></svg>
                  </div>
                </div>
                <p class="text-xs text-slate-300 text-center font-medium">${currentMatchData.found_title || 'Found Item'}</p>
              </div>
            </div>

            <!-- Match Confidence -->
            <div class="flex items-center justify-between p-4 bg-slate-900 border border-slate-700 rounded-xl">
              <span class="text-sm font-medium text-slate-200">Match Confidence</span>
              <span class="${confidence.class} px-4 py-2 rounded-full text-sm font-semibold">
                ${confidence.icon} ${currentMatchData.similarity_score}% ${confidence.label}
              </span>
            </div>

            <!-- How It Works -->
            <div class="bg-slate-900 border border-slate-700 rounded-xl p-5">
              <h4 class="text-sm font-semibold text-orange-200 mb-3 flex items-center">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                How This Match Was Found
              </h4>
              <ul class="text-xs text-slate-300 space-y-2">
                <li class="flex items-start">
                  <span class="mr-2">-</span>
                  <span>AI analyzed item titles and descriptions</span>
                </li>
                <li class="flex items-start">
                  <span class="mr-2">-</span>
                  <span>Compared keywords and phrases for similarity</span>
                </li>
                <li class="flex items-start">
                  <span class="mr-2">-</span>
                  <span>Matched categories and item characteristics</span>
                </li>
                <li class="flex items-start">
                  <span class="mr-2">-</span>
                  <span>Generated confidence score based on similarity</span>
                </li>
              </ul>
            </div>

            <!-- Additional Info -->
            <div class="grid grid-cols-2 gap-4 text-xs text-slate-300">
              <div class="bg-slate-900 border border-slate-700 rounded-lg p-3">
                <span class="font-semibold text-slate-200">Matched on:</span>
                <div class="mt-1">${new Date(currentMatchData.matched_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div>
              </div>
              <div class="bg-slate-900 border border-slate-700 rounded-lg p-3">
                <span class="font-semibold text-slate-200">Algorithm:</span>
                <div class="mt-1">NLP + Keyword Analysis</div>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-3 pt-4">
              <button onclick="this.closest('.fixed').remove()" 
                      class="flex-1 px-6 py-3 bg-gray-600 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all hover:shadow-lg">
                Close
              </button>
            </div>
          </div>
        </div>
      `;
      
      document.body.appendChild(modal);

      // Load images if available
      setTimeout(() => {
        loadMatchImages();
      }, 100);
    }

    function loadMatchImages() {
      const matches = <?php echo json_encode($matches); ?>;
      const match = matches.find(m => m.match_id == currentMatchId);
      
      if (match) {
        const lostImg = document.getElementById('lostItemImg');
        const foundImg = document.getElementById('foundItemImg');
        
        if (match.lost_image_src) {
          if (lostImg) lostImg.src = match.lost_image_src;
        }
        
        if (match.found_image_src) {
          if (foundImg) foundImg.src = match.found_image_src;
        }
      }
    }

    function loadEmptyStateImages() {
      const matches = <?php echo json_encode($matches); ?>;
      const match = matches.find(m => m.match_id == currentMatchId);
      
      if (match) {
        const emptyLostImg = document.getElementById('emptyLostImg');
        const emptyFoundImg = document.getElementById('emptyFoundImg');
        
        if (match.lost_image_src && emptyLostImg) {
          emptyLostImg.src = match.lost_image_src;
          emptyLostImg.style.display = 'block';
          emptyLostImg.onerror = function() {
            this.style.display = 'none';
          };
        }
        
        if (match.found_image_src && emptyFoundImg) {
          emptyFoundImg.src = match.found_image_src;
          emptyFoundImg.style.display = 'block';
          emptyFoundImg.onerror = function() {
            this.style.display = 'none';
          };
        }
      }
    }

    function previewCurrentMatchImage(type) {
      const img = type === 'lost' ? document.getElementById('lostItemImg') : document.getElementById('foundItemImg');
      if (img && img.src && img.style.display !== 'none') {
        previewImage(img.src);
      }
    }

    // Override showMatchDetails to use the enhanced version
    function showMatchDetails() {
      showMatchDetailsWithImages();
    }

    // Clean up interval when page is closed
    window.addEventListener('beforeunload', () => {
      if (messageInterval) {
        clearInterval(messageInterval);
      }
    });
  </script>

</body>
</html>

