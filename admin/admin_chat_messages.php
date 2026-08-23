    <?php
    require_once '../includes/auth_check.php';
    require_once '../config/db.php';
    require_once '../config/env.php';
    require_once '../includes/helpers/chat_crypto.php';
    require_once '../includes/helpers/chat_pin_helper.php';

    // Ensure .env is loaded for CHAT_ENCRYPTION_KEY
    if (function_exists('loadEnvFile')) {
      loadEnvFile(__DIR__ . '/../.env');
    }
    requireRole('admin');

    $user = getUserInfo();
    $current_page = basename($_SERVER['PHP_SELF']);
    $userId = (int)($user['user_id'] ?? 0);

    ensureChatPinTable($pdo);
    $chat_pin_scope = 'chat_pin_access_admin_' . $userId;
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
      clearChatPinVerifiedInSession($userId);
    }
    $chat_pin_status = getChatPinStatus($pdo, $userId);
    $chat_pin_needs_setup = !$chat_pin_status['has_pin'];
    $chat_pin_token = function_exists('getCsrfToken') ? getCsrfToken($chat_pin_scope) : '';
    $chat_pin_policy = getChatPinPolicy();

    function ensureClaimAdminResponseColumn(PDO $pdo): void
    {
      try {
        $stmt = $pdo->prepare(
          "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'claim_requests' AND COLUMN_NAME = 'admin_response'"
        );
        $stmt->execute();
        $exists = (int)$stmt->fetchColumn() > 0;

        if (!$exists) {
          $pdo->exec("ALTER TABLE claim_requests ADD COLUMN admin_response TEXT DEFAULT NULL AFTER admin_user_id");
        }
      } catch (Throwable $e) {
        error_log('ensureClaimAdminResponseColumn failed: ' . $e->getMessage());
      }
    }

    function canApproveClaimAfterChat(PDO $pdo, int $matchId, int $claimantUserId): array
    {
      $claimantCount = 0;
      $adminCount = 0;

      try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM chats WHERE match_id = ? AND sender_id = ?');
        $stmt->execute([$matchId, $claimantUserId]);
        $claimantCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM chats c INNER JOIN users u ON u.user_id = c.sender_id WHERE c.match_id = ? AND u.role = 'admin'");
        $stmt->execute([$matchId]);
        $adminCount = (int)$stmt->fetchColumn();
      } catch (Throwable $e) {
        error_log('canApproveClaimAfterChat failed: ' . $e->getMessage());
      }

      return [
        'allowed' => ($claimantCount > 0 && $adminCount > 0),
        'claimant_count' => $claimantCount,
        'admin_count' => $adminCount,
      ];
    }

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

    ensureClaimAdminResponseColumn($pdo);

// Get item ID from URL if provided (for direct linking to specific item matches)
$specific_item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : null;
$specific_claim_id = isset($_GET['claim_id']) ? (int)$_GET['claim_id'] : null;

try {
  $adminUserId = $userId;

    // Get all matches with chat data for admin view
    $stmt = $pdo->prepare("
        SELECT 
            im.match_id,
            im.similarity_score,
            im.matched_at,
            im.status as match_status,
            li.item_id as lost_item_id,
            li.title as lost_title,
            li.description as lost_description,
            li.category as lost_category,
            li.item_image as lost_image,
            li.user_id as lost_user_id,
            li.report_date as lost_report_date,
            li.status as lost_item_status,
            fi.item_id as found_item_id,
            fi.title as found_title,
            fi.description as found_description,
            fi.category as found_category,
            fi.item_image as found_image,
            fi.user_id as found_user_id,
            fi.report_date as found_report_date,
            fi.status as found_item_status,
            lu.full_name as lost_user_name,
            lu.email as lost_user_email,
            fu.full_name as found_user_name,
            fu.email as found_user_email,
            (SELECT COUNT(*) FROM chats WHERE match_id = im.match_id) as message_count,
            (SELECT MAX(sent_at) FROM chats WHERE match_id = im.match_id) as last_message_time,
            (SELECT COUNT(*) FROM claim_requests WHERE match_id = im.match_id AND status = 'pending') as pending_claims,
            (
              SELECT cr.claimant_user_id
              FROM claim_requests cr
              WHERE cr.match_id = im.match_id
              ORDER BY cr.created_at DESC
              LIMIT 1
            ) as claimant_user_id,
            (
              SELECT COALESCE(MAX(c.sent_at), '1970-01-01 00:00:00')
              FROM chats c
              WHERE c.match_id = im.match_id
              AND c.sender_id = (
                SELECT cr2.claimant_user_id
                FROM claim_requests cr2
                WHERE cr2.match_id = im.match_id
                ORDER BY cr2.created_at DESC
                LIMIT 1
              )
            ) as last_claimant_message_time,
            (
              SELECT COALESCE(MAX(ca.sent_at), '1970-01-01 00:00:00')
              FROM chats ca
              WHERE ca.match_id = im.match_id
              AND ca.sender_id = ?
            ) as last_admin_message_time,
            (
              SELECT COUNT(*)
              FROM chats cn
              WHERE cn.match_id = im.match_id
              AND cn.sender_id = (
                SELECT cr3.claimant_user_id
                FROM claim_requests cr3
                WHERE cr3.match_id = im.match_id
                ORDER BY cr3.created_at DESC
                LIMIT 1
              )
              AND cn.sent_at > COALESCE((
                SELECT MAX(ca2.sent_at)
                FROM chats ca2
                WHERE ca2.match_id = im.match_id
                AND ca2.sender_id = ?
              ), '1970-01-01 00:00:00')
            ) as new_claimant_messages
        FROM itemmatches im
        INNER JOIN items li ON im.lost_item_id = li.item_id
        INNER JOIN items fi ON im.found_item_id = fi.item_id
        INNER JOIN users lu ON li.user_id = lu.user_id
        INNER JOIN users fu ON fi.user_id = fu.user_id
          WHERE EXISTS (SELECT 1 FROM claim_requests crx WHERE crx.match_id = im.match_id)
        " . ($specific_item_id ? " AND (li.item_id = ? OR fi.item_id = ?)" : "") . "
        " . ($specific_claim_id ? " AND im.match_id = (SELECT match_id FROM claim_requests WHERE claim_id = ?)" : "") . "
          ORDER BY new_claimant_messages DESC, pending_claims DESC, im.similarity_score DESC, im.matched_at DESC
    ");
    
        $params = [$adminUserId, $adminUserId];
    if ($specific_item_id) {
        $params[] = $specific_item_id;
        $params[] = $specific_item_id;
    }
    if ($specific_claim_id) {
        $params[] = $specific_claim_id;
    }
    
    $stmt->execute($params);
    $matches = $stmt->fetchAll();

    $initialChatMatch = null;
    if ($specific_claim_id && !empty($matches)) {
      $m = $matches[0];
      $initialChatMatch = [
        'match_id' => (int)$m['match_id'],
        'lost_title' => (string)$m['lost_title'],
        'found_title' => (string)$m['found_title'],
        'lost_user_name' => (string)$m['lost_user_name'],
        'found_user_name' => (string)$m['found_user_name'],
        'item_image' => '',
      ];

      if (!empty($m['lost_image'])) {
        $imageName = basename((string)$m['lost_image']);
        $candidate = '../uploads/' . $imageName;
        if (file_exists($candidate)) {
          $initialChatMatch['item_image'] = $candidate;
        }
      }
    }

} catch (PDOException $e) {
    $matches = [];
    $initialChatMatch = null;
    error_log("Database error in admin matches: " . $e->getMessage());
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
    
    try {
      ensureClaimAdminResponseColumn($pdo);
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
          $currentPinStatus = getChatPinStatus($pdo, $userId);
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

          setChatPin($pdo, $userId, $pin);
          markChatPinVerifiedInSession($userId);

          echo json_encode([
            'success' => true,
            'message' => 'Chat PIN created and verified.',
            'status' => getChatPinStatus($pdo, $userId),
          ]);
          exit;
        }

        $verify = verifyChatPin($pdo, $userId, $pin);
        if ($verify['success']) {
          markChatPinVerifiedInSession($userId);
        }

        echo json_encode([
          'success' => (bool)$verify['success'],
          'message' => (string)$verify['message'],
          'requires_pin' => !(bool)$verify['success'],
          'locked' => (bool)$verify['locked'],
          'needs_setup' => (bool)$verify['needs_setup'],
          'remaining_attempts' => (int)$verify['remaining_attempts'],
          'locked_until' => $verify['locked_until'],
          'status' => getChatPinStatus($pdo, $userId),
        ]);
        exit;
      }

      if (!isChatPinVerifiedInSession($userId)) {
        echo json_encode([
          'success' => false,
          'requires_pin' => true,
          'message' => 'Chat PIN verification required before accessing messages.',
          'status' => getChatPinStatus($pdo, $userId),
        ]);
        exit;
      }

      switch ($action) {
            case 'get_messages':
                $match_id = (int)$_POST['match_id'];
                
                if ($match_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid match ID']);
                    exit;
                }
                
                try {
                    // Try to get messages with is_admin_message column first
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
                    } catch (PDOException $e) {
                        // If is_admin_message column doesn't exist, get messages without it
                        if (strpos($e->getMessage(), 'is_admin_message') !== false) {
                            $stmt = $pdo->prepare("
                                SELECT c.*, u.full_name as sender_name, 0 as is_admin_message
                                FROM chats c
                                INNER JOIN users u ON c.sender_id = u.user_id
                                WHERE c.match_id = ?
                                ORDER BY c.sent_at ASC
                            ");
                            $stmt->execute([$match_id]);
                            $messages = $stmt->fetchAll();
                        } else {
                            throw $e; // Re-throw if it's a different error
                        }
                    }

                    // Decrypt chat messages for response (plaintext stays as-is)
                    foreach ($messages as &$m) {
                      if (isset($m['message']) && is_string($m['message'])) {
                        $m['message'] = decryptChatMessage($m['message']);
                      }
                    }
                    unset($m);
                    
                    // Get claim requests for this match
                    $claim_stmt = $pdo->prepare("
                      SELECT 
                        cr.*, 
                        u.full_name as claimant_name, 
                        u.email as claimant_email,
                        (SELECT COUNT(*) FROM chats c1 WHERE c1.match_id = cr.match_id AND c1.sender_id = cr.claimant_user_id) as claimant_message_count,
                        (SELECT COUNT(*) FROM chats c2 INNER JOIN users uadm ON uadm.user_id = c2.sender_id WHERE c2.match_id = cr.match_id AND uadm.role = 'admin') as admin_message_count,
                        CASE
                          WHEN (SELECT COUNT(*) FROM chats c1 WHERE c1.match_id = cr.match_id AND c1.sender_id = cr.claimant_user_id) > 0
                           AND (SELECT COUNT(*) FROM chats c2 INNER JOIN users uadm ON uadm.user_id = c2.sender_id WHERE c2.match_id = cr.match_id AND uadm.role = 'admin') > 0
                          THEN 1 ELSE 0
                        END as can_approve_chat
                        FROM claim_requests cr
                        JOIN users u ON cr.claimant_user_id = u.user_id
                        WHERE cr.match_id = ?
                        ORDER BY cr.created_at DESC
                    ");
                    $claim_stmt->execute([$match_id]);
                    $claims = $claim_stmt->fetchAll();

                    foreach ($claims as &$claimRow) {
                      $claimRow['claimant_email'] = maskEmailForDisplay((string)($claimRow['claimant_email'] ?? ''));
                    }
                    unset($claimRow);
                    
                    echo json_encode(['success' => true, 'messages' => $messages, 'claims' => $claims]);
                } catch (PDOException $e) {
                    error_log("Admin chat get_messages error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Error loading messages: ' . $e->getMessage()]);
                }
                exit;
                
            case 'get_all_messages':
                // Get all messages across all matches for admin overview
                try {
                    // Try to get messages with is_admin_message column first
                    try {
                        $stmt = $pdo->prepare("
                            SELECT c.*, u.full_name as sender_name, u.email as sender_email,
                                   im.match_id, im.similarity_score,
                                   li.title as lost_title, fi.title as found_title,
                                   lu.full_name as lost_user_name, fu.full_name as found_user_name
                            FROM chats c
                            INNER JOIN users u ON c.sender_id = u.user_id
                            INNER JOIN itemmatches im ON c.match_id = im.match_id
                            INNER JOIN items li ON im.lost_item_id = li.item_id
                            INNER JOIN items fi ON im.found_item_id = fi.item_id
                            INNER JOIN users lu ON li.user_id = lu.user_id
                            INNER JOIN users fu ON fi.user_id = fu.user_id
                            ORDER BY c.sent_at DESC
                            LIMIT 100
                        ");
                        $stmt->execute();
                        $all_messages = $stmt->fetchAll();
                    } catch (PDOException $e) {
                        // If is_admin_message column doesn't exist, get messages without it
                        if (strpos($e->getMessage(), 'is_admin_message') !== false) {
                            $stmt = $pdo->prepare("
                                SELECT c.*, u.full_name as sender_name, u.email as sender_email,
                                       im.match_id, im.similarity_score,
                                       li.title as lost_title, fi.title as found_title,
                                       lu.full_name as lost_user_name, fu.full_name as found_user_name,
                                       0 as is_admin_message
                                FROM chats c
                                INNER JOIN users u ON c.sender_id = u.user_id
                                INNER JOIN itemmatches im ON c.match_id = im.match_id
                                INNER JOIN items li ON im.lost_item_id = li.item_id
                                INNER JOIN items fi ON im.found_item_id = fi.item_id
                                INNER JOIN users lu ON li.user_id = lu.user_id
                                INNER JOIN users fu ON fi.user_id = fu.user_id
                                ORDER BY c.sent_at DESC
                                LIMIT 100
                            ");
                            $stmt->execute();
                            $all_messages = $stmt->fetchAll();
                        } else {
                            throw $e; // Re-throw if it's a different error
                        }
                    }

                    // Decrypt chat messages for response (plaintext stays as-is)
                    foreach ($all_messages as &$m) {
                      if (isset($m['message']) && is_string($m['message'])) {
                        $m['message'] = decryptChatMessage($m['message']);
                      }
                    }
                    unset($m);
                    
                    echo json_encode(['success' => true, 'messages' => $all_messages]);
                } catch (PDOException $e) {
                    error_log("Admin get_all_messages error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Error loading all messages: ' . $e->getMessage()]);
                }
                exit;
                
            case 'admin_reply':
                $match_id = (int)$_POST['match_id'];
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
                
                // Verify the match exists and get the claimant for this match
                $verify_stmt = $pdo->prepare("
                  SELECT 
                    li.user_id as lost_user_id,
                    fi.user_id as found_user_id,
                    (
                      SELECT cr.claimant_user_id
                      FROM claim_requests cr
                      WHERE cr.match_id = im.match_id
                      ORDER BY cr.created_at DESC
                      LIMIT 1
                    ) as claimant_user_id
                    FROM itemmatches im
                    INNER JOIN items li ON im.lost_item_id = li.item_id
                    INNER JOIN items fi ON im.found_item_id = fi.item_id
                    WHERE im.match_id = ?
                ");
                $verify_stmt->execute([$match_id]);
                $users = $verify_stmt->fetch();
                
                if (!$users) {
                    echo json_encode(['success' => false, 'message' => 'Invalid match']);
                    exit;
                }

                $receiverId = (int)($users['claimant_user_id'] ?? 0);
                if ($receiverId <= 0) {
                  echo json_encode(['success' => false, 'message' => 'No claimant linked to this match yet']);
                  exit;
                }
                
                // Limit message length
                if (strlen($message) > 500) {
                    echo json_encode(['success' => false, 'message' => 'Message too long (max 500 characters)']);
                    exit;
                }
                
                try {
                    // Insert admin message targeted to the claimant linked with the claim.
                    $encryptedMessage = encryptChatMessage($message);
                    $stmt = $pdo->prepare("
                        INSERT INTO chats (match_id, sender_id, receiver_id, message) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$match_id, $user['user_id'], $receiverId, $encryptedMessage]);
                    
                    echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
                } catch (PDOException $e) {
                    error_log("Admin reply error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Error sending message: ' . $e->getMessage()]);
                }
                exit;
                
            case 'approve_claim':
                $claim_id = (int)($_POST['claim_id'] ?? 0);
                $admin_response = trim($_POST['admin_response'] ?? 'Claim approved by admin');
                
                if ($claim_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
                    exit;
                }
                
                // Get claim details
                $claim_stmt = $pdo->prepare("SELECT * FROM claim_requests WHERE claim_id = ?");
                $claim_stmt->execute([$claim_id]);
                $claim = $claim_stmt->fetch();
                
                if ($claim) {
                  $chatGate = canApproveClaimAfterChat($pdo, (int)$claim['match_id'], (int)$claim['claimant_user_id']);
                  if (!$chatGate['allowed']) {
                    echo json_encode([
                      'success' => false,
                      'message' => 'Cannot approve yet. Require claimant message and admin reply in chat first.'
                    ]);
                    exit;
                  }

                    $pdo->beginTransaction();
                    
                    try {
                        // Update claim status
                        $update_stmt = $pdo->prepare("UPDATE claim_requests SET status = 'approved', admin_user_id = ?, admin_response = ? WHERE claim_id = ?");
                        $update_stmt->execute([$user['user_id'], $admin_response, $claim_id]);
                        
                        // Update match status
                        $match_stmt = $pdo->prepare("UPDATE itemmatches SET status = 'accepted', admin_user_id = ? WHERE match_id = ?");
                        $match_stmt->execute([$user['user_id'], $claim['match_id']]);
                        
                        // Send notification message to chat
            $msg_stmt = $pdo->prepare("INSERT INTO chats (match_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
            $msg_stmt->execute([
                            $claim['match_id'],
                            $user['user_id'],
                            $claim['claimant_user_id'],
              encryptChatMessage("APPROVED: Your claim has been approved. " . $admin_response)
                        ]);
                        
                        // Commit transaction
                        $pdo->commit();
                        
                        echo json_encode(['success' => true, 'message' => 'Claim approved successfully']);
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        error_log("Approve claim error: " . $e->getMessage());
                        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Claim not found']);
                }
                exit;
                
            case 'reject_claim':
                $claim_id = (int)($_POST['claim_id'] ?? 0);
                $admin_response = trim($_POST['admin_response'] ?? 'Claim rejected by admin');
                
                if ($claim_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
                    exit;
                }
                
                // Get claim details
                $claim_stmt = $pdo->prepare("SELECT * FROM claim_requests WHERE claim_id = ?");
                $claim_stmt->execute([$claim_id]);
                $claim = $claim_stmt->fetch();
                
                if ($claim) {
                    $pdo->beginTransaction();
                    
                    try {
                        // Update claim status
                        $update_stmt = $pdo->prepare("UPDATE claim_requests SET status = 'rejected', admin_user_id = ?, admin_response = ? WHERE claim_id = ?");
                        $update_stmt->execute([$user['user_id'], $admin_response, $claim_id]);
                        
                        // Update match status
                        $match_stmt = $pdo->prepare("UPDATE itemmatches SET status = 'rejected', admin_user_id = ? WHERE match_id = ?");
                        $match_stmt->execute([$user['user_id'], $claim['match_id']]);
                        
                        // Send notification message to chat
            $msg_stmt = $pdo->prepare("INSERT INTO chats (match_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
            $msg_stmt->execute([
                            $claim['match_id'],
                            $user['user_id'],
                            $claim['claimant_user_id'],
              encryptChatMessage("REJECTED: Your claim has been rejected. " . $admin_response)
                        ]);
                        
                        // Commit transaction
                        $pdo->commit();
                        
                        echo json_encode(['success' => true, 'message' => 'Claim rejected']);
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        error_log("Reject claim error: " . $e->getMessage());
                        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Claim not found']);
                }
                exit;
                
        }
    } catch (Exception $e) {
        error_log("Admin chat AJAX error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Server error occurred',
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

$shouldRenderSidebar = ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']));

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

// Function to get match status styling
function getMatchStatusInfo($status) {
    $status = strtolower(trim((string)$status));

    switch($status) {
        case 'accepted':
        case 'approved':
      return ['class' => 'bg-emerald-500/20 text-emerald-100 border border-emerald-400/40', 'label' => 'Accepted', 'icon' => 'OK'];
        case 'claimed':
      return ['class' => 'bg-emerald-500/20 text-emerald-100 border border-emerald-400/40', 'label' => 'Claimed', 'icon' => 'DONE'];
        case 'rejected':
      return ['class' => 'bg-red-500/20 text-red-100 border border-red-400/40', 'label' => 'Rejected', 'icon' => 'REJ'];
        case 'cancelled':
      return ['class' => 'bg-zinc-500/20 text-zinc-100 border border-zinc-400/40', 'label' => 'Cancelled', 'icon' => 'CXL'];
        case 'processing':
      return ['class' => 'bg-cyan-500/20 text-cyan-100 border border-cyan-400/40', 'label' => 'Processing', 'icon' => 'PROC'];
        case 'pending':
        default:
      return ['class' => 'bg-slate-700 text-slate-100 border border-slate-500', 'label' => 'Pending', 'icon' => 'WAIT'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin - Chat Messages - FindIt Dashboard</title>
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
      color: #ecf2ff;
    }

    .ml-sidebar {
      margin-left: 280px;
      height: 100vh;
      overflow: hidden;
      padding: 14px;
      background: transparent;
    }

    #mainContainer {
      height: calc(100vh - 28px);
      display: flex;
      gap: 12px;
    }

    .matches-panel {
      width: 100%;
      max-width: 360px;
      display: flex;
      flex-direction: column;
      height: 100%;
      background: #131922;
      border: 1px solid #273040;
      border-radius: 14px;
      box-shadow: 0 8px 22px rgba(0, 0, 0, 0.34);
      overflow: hidden;
    }

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

    .chat-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      height: 100%;
      min-width: 0;
    }

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

    #adminReplyInput {
      flex-shrink: 0;
      background: #131922;
      border: 1px solid #273040;
      border-radius: 14px;
      box-shadow: 0 -1px 8px rgba(0, 0, 0, 0.34);
    }

    .match-card {
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      background: #111824;
      border: 1px solid #273040;
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

    .chat-bubble-left {
      background: #1a212d;
      border: 1px solid #2e394c;
      color: #dbe5f6;
      border-radius: 16px 16px 16px 5px;
    }
    
    .chat-bubble-right {
      background: linear-gradient(135deg, #ff5b1f, #ff7b2c);
      color: white;
      border-radius: 16px 16px 5px 16px;
    }

    .chip-subtle {
      background: #1c2533;
      border: 1px solid #2c3749;
      color: #a6b7d1;
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

    @media (max-width: 768px) {
      .ml-sidebar {
        margin-left: 0;
        padding: 8px;
      }

      #mainContainer {
        height: calc(100vh - 16px);
      }

      .mobile-chat-view .matches-panel {
        display: none;
      }

      .mobile-chat-view .chat-panel {
        width: 100%;
      }
    }
  </style>
</head>
<body class="admin-theme font-sans">

  <?php if ($shouldRenderSidebar): ?>
    <?php include "../includes/components/shared_sidebar.php"; ?>
  <?php endif; ?>

  <!-- Main Content -->
  <div class="ml-sidebar">
    <div class="flex flex-col md:flex-row h-full" id="mainContainer">

      <!-- Left Panel: Matches List -->
      <div class="w-full md:w-1/3 border rounded-xl flex flex-col matches-panel mb-6 md:mb-0">
        <div class="p-6 border-b border-slate-700/60">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-xl font-bold text-gray-900 inline-flex items-center gap-2">
                <svg class="w-5 h-5 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12H15M9 16H13M4 7C4 5.89543 4.89543 5 6 5H18C19.1046 5 20 5.89543 20 7V17C20 18.1046 19.1046 19 18 19H6C4.89543 19 4 18.1046 4 17V7Z"/></svg>
                <span class="text-orange-300">Smart Matches</span>
              </h2>
              <p class="text-sm text-gray-600 mt-1">Messenger-style admin conversations</p>
            </div>
            <div class="text-right">
              <div class="text-2xl font-bold text-orange-300"><?php echo count($matches); ?></div>
              <div class="text-xs text-gray-500">Total Matches</div>
            </div>
          </div>
        </div>
        <div class="flex-1 overflow-y-auto p-4" id="matchesList">
          <?php if (!empty($matches)): ?>
            <?php foreach ($matches as $match): ?>
              <?php 
                $confidence = getConfidenceLevel($match['similarity_score']);
                // Fix image path handling
                $displayImage = '';
                if ($match['lost_image']) {
                    $imageName = basename($match['lost_image']);
                    $displayImage = '../uploads/' . $imageName;
                    
                    if (!file_exists($displayImage)) {
                        $displayImage = '';
                    }
                }
              ?>
                <div class="match-card rounded-lg p-4 mb-4 cursor-pointer transition-all duration-200"
                  data-match-id="<?php echo (int)$match['match_id']; ?>"
                    data-confidence-class="<?php echo htmlspecialchars($confidence['class']); ?>"
                    data-confidence-label="<?php echo htmlspecialchars($confidence['label']); ?>"
                    data-similarity="<?php echo (float)$match['similarity_score']; ?>"
                    onclick='loadChat(<?php echo (int)$match['match_id']; ?>, <?php echo json_encode((string)$match['lost_title']); ?>, <?php echo json_encode((string)$match['found_title']); ?>, <?php echo json_encode((string)$displayImage); ?>, <?php echo json_encode((string)$match['lost_user_name']); ?>, <?php echo json_encode((string)$match['found_user_name']); ?>)'>
                
                <!-- Status and Match Info -->
                <div class="flex items-center justify-between mb-3">
                  <div class="flex items-center space-x-2">
                    <span class="<?php echo $confidence['class']; ?> px-3 py-1 rounded-full text-xs font-medium">
                      <?php echo $confidence['icon']; ?> <?php echo $match['similarity_score']; ?>% Match
                    </span>
                    <?php if ((int)($match['new_claimant_messages'] ?? 0) > 0): ?>
                      <span class="bg-red-500/20 text-red-100 border border-red-400/40 px-3 py-1 rounded-full text-xs font-semibold">
                        NEW <?php echo (int)$match['new_claimant_messages']; ?>
                      </span>
                    <?php endif; ?>
                    <?php 
                      $statusInfo = getMatchStatusInfo($match['match_status'] ?? 'pending');
                    ?>
                    <span class="<?php echo $statusInfo['class']; ?> px-3 py-1 rounded-full text-xs font-medium">
                      <?php echo $statusInfo['icon']; ?> <?php echo $statusInfo['label']; ?>
                    </span>
                  </div>
                  <span class="text-xs text-gray-500">
                    <?php echo date('M j, Y', strtotime($match['matched_at'])); ?>
                  </span>
                </div>
                
                <div class="flex items-start space-x-4">
                  <!-- Item Image -->
                  <div class="w-16 h-16 bg-slate-900/60 border border-slate-700 rounded-lg overflow-hidden flex-shrink-0">
                    <?php if ($displayImage && file_exists($displayImage)): ?>
                      <img src="<?php echo htmlspecialchars($displayImage); ?>" 
                           alt="<?php echo htmlspecialchars($match['lost_title']); ?>" 
                           class="w-full h-full object-cover"
                           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                      <div class="w-full h-full flex items-center justify-center text-xs font-bold tracking-wide text-orange-100" style="display: none;">
                        <?php echo getCategoryEmoji($match['lost_category']); ?>
                      </div>
                    <?php else: ?>
                      <div class="w-full h-full flex items-center justify-center text-xs font-bold tracking-wide text-orange-100">
                        <?php echo getCategoryEmoji($match['lost_category']); ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  
                  <!-- Match Info -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between mb-2">
                      <h4 class="text-sm font-semibold text-gray-900 truncate">
                        Lost: <?php echo htmlspecialchars($match['lost_title']); ?>
                      </h4>
                    </div>
                    
                    <div class="bg-orange-500/10 border border-orange-400/20 rounded-lg p-2 mb-2">
                      <p class="text-xs text-orange-100 font-medium">
                        Found: <?php echo htmlspecialchars($match['found_title']); ?>
                      </p>
                      <p class="text-xs text-orange-200 mt-1">
                        Smart algorithm detected <?php echo $confidence['label']; ?>
                      </p>
                    </div>
                    
                    <div class="flex items-center justify-between text-xs text-gray-500">
                      <span class="flex items-center">
                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                        </svg>
                        <?php echo htmlspecialchars($match['lost_user_name']); ?> - <?php echo htmlspecialchars($match['found_user_name']); ?>
                      </span>
                      <span class="text-xs text-gray-400">
                        <?php echo date('M j', strtotime($match['matched_at'])); ?>
                      </span>
                    </div>
                    
                    <?php if ($match['message_count'] > 0): ?>
                      <div class="mt-2 flex items-center justify-between gap-2">
                        <div class="flex items-center text-xs text-cyan-100 bg-cyan-500/15 border border-cyan-400/25 rounded-full px-2 py-1">
                          <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
                          </svg>
                          <?php echo $match['message_count']; ?> message<?php echo $match['message_count'] > 1 ? 's' : ''; ?>
                        </div>
                        <?php if ((int)($match['new_claimant_messages'] ?? 0) > 0): ?>
                          <span class="text-[11px] font-semibold text-red-100 bg-red-500/20 border border-red-400/30 rounded-full px-2 py-1">
                            +<?php echo (int)$match['new_claimant_messages']; ?> new
                          </span>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <div class="mt-2 text-xs text-slate-300 bg-slate-800/80 border border-slate-600 rounded-full px-2 py-1 text-center">
                        No messages yet
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-center py-12">
              <div class="text-3xl font-black tracking-widest text-orange-300 mb-4">CHAT</div>
              <h3 class="text-lg font-medium text-gray-900 mb-2">No Claim Conversations Yet</h3>
              <p class="text-gray-600 text-sm mb-4">Claim-linked matches will appear here after a claimant submits a request.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right Panel: Chat Window -->
      <div class="flex-1 flex flex-col ml-0 md:ml-6">
        <!-- Chat Header -->
        <div class="p-4 rounded-xl mb-4" id="chatHeader" style="display: none;">
          <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
              <div class="relative w-10 h-10">
                <img id="chatItemImage" class="w-10 h-10 rounded-lg object-cover" src="" alt="">
                <div id="chatImageFallback" class="w-10 h-10 rounded-lg bg-slate-800 border border-slate-700 text-orange-200 flex items-center justify-center text-[11px] font-bold" style="display: none;">
                  ITEM
                </div>
              </div>
              <div>
                <h3 id="chatItemTitle" class="font-semibold text-gray-900"></h3>
                <p id="chatUserName" class="text-sm text-gray-600"></p>
              </div>
            </div>
            <div class="flex items-center space-x-2">
              <span id="matchConfidence" class="px-3 py-1 rounded-full text-xs font-medium"></span>
              <button onclick="loadAllMessages()" class="text-cyan-200 hover:text-cyan-100 text-sm font-medium">
                View All Messages
              </button>
            </div>
          </div>
        </div>

        <!-- Messages -->
        <div class="flex-1 overflow-y-auto p-4 rounded-xl mb-4" id="messagesArea">
          <div class="flex items-center justify-center h-full" id="defaultChatState">
            <div class="text-center">
              <div class="text-3xl font-black tracking-widest text-orange-300 mb-4">MESSAGES</div>
              <p class="text-gray-500 text-lg">Select a match to view chat messages</p>
              <button onclick="loadAllMessages()" class="mt-4 px-4 py-2 bg-gradient-to-r from-orange-500 to-red-500 text-white rounded-lg hover:from-orange-400 hover:to-red-400">
                View All Messages
              </button>
            </div>
          </div>
        </div>

        <!-- Admin Reply Input -->
        <div class="p-4 rounded-xl" id="adminReplyInput" style="display: none;">
          <form class="flex flex-col space-y-3" onsubmit="sendAdminReply(event)">
            <div class="flex space-x-3">
              <input 
                type="text" 
                id="adminMessageText"
                placeholder="Type your message..." 
                class="flex-1 px-4 py-2 border border-slate-700 bg-slate-900/80 text-slate-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-400/40 focus:border-orange-400"
                maxlength="500"
                oninput="updateAdminCharCount()"
                required
              >
              <button 
                type="submit"
                class="px-6 py-2 bg-gradient-to-r from-orange-500 to-red-500 text-white rounded-lg hover:from-orange-400 hover:to-red-400 focus:outline-none focus:ring-2 focus:ring-orange-400/40 transition-all duration-200"
              >
                Send Message
              </button>
            </div>
            <div class="flex justify-between items-center text-xs text-gray-500">
              <span>Your message will be sent to the claimant for this match</span>
              <span id="adminCharCount">0/500</span>
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
        <p id="chatPinSubtitle" class="text-xs text-slate-200 mt-1">Verify your 6-digit PIN to access admin chat.</p>
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
          <p id="chatPinFeedback" class="chat-pin-note">This check runs every time you open the admin chat page.</p>
          <button type="submit" id="chatPinSubmitBtn" class="chat-pin-btn">Verify PIN</button>
          <a href="admin_dashboard.php" class="inline-flex w-full justify-center rounded-lg border border-slate-600 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 transition-colors">Back to Dashboard</a>
        </form>
      </div>
    </div>
  </div>

  <script>
    let currentMatchId = null;
    let messageInterval = null;
    const initialChatMatch = <?php echo json_encode($initialChatMatch ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
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
        title.textContent = 'Create Admin Chat PIN';
        subtitle.textContent = 'Set a 6-digit PIN to protect every admin chat access.';
        confirmWrap.style.display = 'block';
        submitBtn.textContent = 'Create PIN';
      } else {
        title.textContent = 'Secure Chat PIN Required';
        subtitle.textContent = 'Verify your 6-digit PIN to access admin chat.';
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

      fetch('admin_chat_messages.php', {
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

          if (initialChatMatch && initialChatMatch.match_id) {
            loadChat(
              initialChatMatch.match_id,
              initialChatMatch.lost_title,
              initialChatMatch.found_title,
              initialChatMatch.item_image || '',
              initialChatMatch.lost_user_name,
              initialChatMatch.found_user_name
            );
          }
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

    function loadChat(matchId, lostTitle, foundTitle, itemImage, lostUserName, foundUserName) {
      if (!chatPinVerified) {
        handleChatPinRequired({ message: 'Verify your chat PIN before opening a conversation.' });
        return;
      }

      currentMatchId = matchId;

      document.querySelectorAll('.match-card').forEach(card => {
        card.classList.remove('active');
      });
      const activeCard = document.querySelector(`.match-card[data-match-id="${matchId}"]`);
      if (activeCard) {
        activeCard.classList.add('active');
      }

      const confidenceEl = document.getElementById('matchConfidence');
      if (confidenceEl && activeCard) {
        const confidenceClass = activeCard.getAttribute('data-confidence-class') || 'chip-subtle';
        const confidenceLabel = activeCard.getAttribute('data-confidence-label') || 'Match';
        const similarity = activeCard.getAttribute('data-similarity') || '0';
        confidenceEl.className = `px-3 py-1 rounded-full text-xs font-medium ${confidenceClass}`;
        confidenceEl.textContent = `${similarity}% ${confidenceLabel}`;
      }
      
      // Update chat header
      document.getElementById('chatItemTitle').textContent = `${lostTitle} - ${foundTitle}`;
      document.getElementById('chatUserName').textContent = `Claim chat: ${lostUserName} and ${foundUserName}`;
      
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
      
      // Show chat interface
      document.getElementById('chatHeader').style.display = 'block';
      document.getElementById('adminReplyInput').style.display = 'block';
      document.getElementById('defaultChatState').style.display = 'none';
      
      // Load messages
      loadMessages();
      
      // Set up auto-refresh for messages
      if (messageInterval) {
        clearInterval(messageInterval);
      }
      messageInterval = setInterval(loadMessages, 5000); // Refresh every 5 seconds
    }

    function loadMessages() {
      if (!currentMatchId || !chatPinVerified) return;
      
      const formData = new FormData();
      formData.append('action', 'get_messages');
      formData.append('match_id', currentMatchId);
      
      fetch('admin_chat_messages.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          return response.text().then(text => {
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
          displayMessages(data.messages, data.claims);
        } else {
          console.error('Error loading messages:', data.message);
          showErrorMessage('Failed to load messages: ' + (data.message || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Error loading messages:', error);
        if (!document.getElementById('messagesArea').innerHTML.includes('messages')) {
          showErrorMessage('Failed to load messages: ' + error.message);
        }
      });
    }

    function loadAllMessages() {
      if (!chatPinVerified) {
        handleChatPinRequired({ message: 'Verify your chat PIN before loading chat archives.' });
        return;
      }

      const formData = new FormData();
      formData.append('action', 'get_all_messages');
      
      fetch('admin_chat_messages.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          return response.text().then(text => {
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
          displayAllMessages(data.messages);
        } else {
          console.error('Error loading all messages:', data.message);
          showErrorMessage('Failed to load all messages: ' + (data.message || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Error loading all messages:', error);
        showErrorMessage('Failed to load all messages: ' + error.message);
      });
    }

    function displayMessages(messages, claims = []) {
      const messagesArea = document.getElementById('messagesArea');
      
      messagesArea.innerHTML = '';
      
      // Display claim requests at the top if any
      if (claims && claims.length > 0) {
        claims.forEach(claim => {
          const claimDiv = document.createElement('div');
          const claimStatus = String(claim.status || '').trim().toLowerCase();
          claimDiv.className = 'mb-4 p-4 border rounded-lg';
          
          let statusBadge = '';
          let actionButtons = '';
          
          if (claimStatus === 'pending') {
            claimDiv.classList.add('border-amber-400/35', 'bg-amber-500/10');
            statusBadge = '<span class="px-2 py-1 bg-amber-500/25 border border-amber-400/40 text-amber-100 text-xs font-bold rounded">WAIT PENDING CLAIM</span>';
            const canApprove = Number(claim.can_approve_chat) === 1;
            actionButtons = `
              <div class="mt-3 flex gap-2">
                ${canApprove
                  ? `<button onclick="approveClaim(${claim.claim_id})" class="px-3 py-1 bg-emerald-600 text-white rounded text-sm hover:bg-emerald-500">Approve</button>`
                  : `<button class="px-3 py-1 bg-slate-700 text-slate-300 rounded text-sm cursor-not-allowed" disabled>Approve</button>`
                }
                <button onclick="rejectClaim(${claim.claim_id})" class="px-3 py-1 bg-red-600 text-white rounded text-sm hover:bg-red-500">
                  Reject
                </button>
              </div>
              ${canApprove ? '' : '<p class="mt-2 text-xs text-amber-200">Approve disabled: waiting for claimant message and admin reply.</p>'}
            `;
          } else if (claimStatus === 'approved') {
            claimDiv.classList.add('border-emerald-400/35', 'bg-emerald-500/10');
            statusBadge = '<span class="px-2 py-1 bg-emerald-500/25 border border-emerald-400/40 text-emerald-100 text-xs font-bold rounded">DONE APPROVED</span>';
          } else if (claimStatus === 'ready_for_pickup') {
            claimDiv.classList.add('border-cyan-400/35', 'bg-cyan-500/10');
            statusBadge = '<span class="px-2 py-1 bg-cyan-500/25 border border-cyan-400/40 text-cyan-100 text-xs font-bold rounded">READY FOR PICKUP</span>';
          } else if (claimStatus === 'claimed') {
            claimDiv.classList.add('border-emerald-400/35', 'bg-emerald-500/10');
            statusBadge = '<span class="px-2 py-1 bg-emerald-500/25 border border-emerald-400/40 text-emerald-100 text-xs font-bold rounded">DONE CLAIM COMPLETED</span>';
          } else if (claimStatus === 'rejected') {
            claimDiv.classList.add('border-red-400/35', 'bg-red-500/10');
            statusBadge = '<span class="px-2 py-1 bg-red-500/25 border border-red-400/40 text-red-100 text-xs font-bold rounded">REJ REJECTED</span>';
          } else {
            claimDiv.classList.add('border-slate-500/35', 'bg-slate-700/20');
            statusBadge = `<span class="px-2 py-1 bg-slate-700/70 border border-slate-500 text-slate-100 text-xs font-bold rounded">${escapeHtml(claimStatus || 'unknown').toUpperCase()}</span>`;
          }
          
          claimDiv.innerHTML = `
            <div class="flex items-start justify-between mb-2">
              <div>
                <p class="font-bold text-slate-100">Claim Request</p>
                <p class="text-xs text-gray-600">From: ${escapeHtml(claim.claimant_name)} (${escapeHtml(claim.claimant_email)})</p>
              </div>
              ${statusBadge}
            </div>
            ${claim.request_message ? `<p class="text-sm text-gray-700 mb-2"><strong>Message:</strong> ${escapeHtml(claim.request_message)}</p>` : ''}
            ${claim.admin_response ? `<p class="text-sm text-cyan-100 mb-2"><strong>Admin Response:</strong> ${escapeHtml(claim.admin_response)}</p>` : ''}
            <p class="text-xs text-gray-500">Submitted: ${new Date(claim.created_at).toLocaleString()}</p>
            ${actionButtons}
          `;
          
          messagesArea.appendChild(claimDiv);
        });
      }
      
      if (messages.length === 0 && (!claims || claims.length === 0)) {
        messagesArea.innerHTML = `
          <div class="flex items-center justify-center h-full">
            <div class="text-center">
              <div class="text-2xl font-black tracking-wider text-orange-300 mb-4">CHAT</div>
              <p class="text-gray-500 mb-2">No messages in this conversation yet.</p>
            </div>
          </div>
        `;
        return;
      }
      
      messages.forEach(message => {
        const messageDiv = document.createElement('div');
        const isAdminMessage = message.sender_id == <?php echo $user['user_id']; ?>;
        
        if (isAdminMessage) {
          // Admin message aligned to right
          messageDiv.className = `flex justify-end mb-4`;
          messageDiv.innerHTML = `
            <div class="max-w-md px-4 py-2 chat-bubble-right">
              <p class="text-xs text-orange-100 font-medium mb-1">Admin</p>
              <p class="text-sm">${escapeHtml(message.message)}</p>
              <p class="text-xs text-orange-100 mt-1">
                ${new Date(message.sent_at).toLocaleString()}
              </p>
            </div>
          `;
        } else {
          // Claimant message aligned to left
          messageDiv.className = `flex justify-start mb-4`;
          messageDiv.innerHTML = `
            <div class="max-w-xs lg:max-w-md px-4 py-2 chat-bubble-left">
              <p class="text-xs text-slate-300 mb-1 font-medium">${message.sender_name}</p>
              <p class="text-sm">${escapeHtml(message.message)}</p>
              <p class="text-xs text-slate-400 mt-1">
                ${new Date(message.sent_at).toLocaleString()}
              </p>
            </div>
          `;
        }
        
        messagesArea.appendChild(messageDiv);
      });
      
      // Scroll to bottom
      messagesArea.scrollTop = messagesArea.scrollHeight;
    }

    function displayAllMessages(messages) {
      const messagesArea = document.getElementById('messagesArea');
      
      messagesArea.innerHTML = '';
      
      if (messages.length === 0) {
        messagesArea.innerHTML = `
          <div class="flex items-center justify-center h-full">
            <div class="text-center">
              <div class="text-2xl font-black tracking-wider text-orange-300 mb-4">CHAT</div>
              <p class="text-gray-500 mb-2">No messages found across all matches.</p>
            </div>
          </div>
        `;
        return;
      }
      
      // Group messages by match
      const groupedMessages = {};
      messages.forEach(message => {
        if (!groupedMessages[message.match_id]) {
          groupedMessages[message.match_id] = {
            match_info: {
              lost_title: message.lost_title,
              found_title: message.found_title,
              lost_user_name: message.lost_user_name,
              found_user_name: message.found_user_name,
              similarity_score: message.similarity_score
            },
            messages: []
          };
        }
        groupedMessages[message.match_id].messages.push(message);
      });
      
      // Display grouped messages
      Object.keys(groupedMessages).forEach(matchId => {
        const group = groupedMessages[matchId];
        const matchDiv = document.createElement('div');
        matchDiv.className = 'mb-6 p-4 bg-slate-900/80 border border-slate-700 rounded-lg';
        
        matchDiv.innerHTML = `
          <div class="flex items-center justify-between mb-3">
            <h4 class="font-semibold text-gray-900">Match #${matchId}</h4>
            <span class="px-2 py-1 bg-cyan-500/20 border border-cyan-400/40 text-cyan-100 rounded-full text-xs">
              ${group.match_info.similarity_score}% match
            </span>
          </div>
          <div class="text-sm text-gray-600 mb-3">
            <p><strong>Lost:</strong> ${group.match_info.lost_title} (${group.match_info.lost_user_name})</p>
            <p><strong>Found:</strong> ${group.match_info.found_title} (${group.match_info.found_user_name})</p>
          </div>
          <div class="space-y-2">
        `;
        
        group.messages.forEach(message => {
          const isAdminMessage = message.sender_id == <?php echo $user['user_id']; ?>;
          
          if (isAdminMessage) {
            matchDiv.innerHTML += `
              <div class="chat-bubble-right p-3 ml-8">
                <div class="flex justify-between items-start mb-1">
                  <span class="text-xs font-medium text-orange-100">Admin</span>
                  <span class="text-xs text-orange-100">${new Date(message.sent_at).toLocaleString()}</span>
                </div>
                <p class="text-sm">${escapeHtml(message.message)}</p>
              </div>
            `;
          } else {
            matchDiv.innerHTML += `
              <div class="chat-bubble-left p-3 mr-8">
                <div class="flex justify-between items-start mb-1">
                  <span class="text-xs font-medium text-slate-200">${message.sender_name}</span>
                  <span class="text-xs text-slate-400">${new Date(message.sent_at).toLocaleString()}</span>
                </div>
                <p class="text-sm">${escapeHtml(message.message)}</p>
              </div>
            `;
          }
        });
        
        matchDiv.innerHTML += `</div></div>`;
        messagesArea.appendChild(matchDiv);
      });
      
      // Scroll to top
      messagesArea.scrollTop = 0;
    }

    function showErrorMessage(message) {
      showToast(message, 'error');
    }

    function showSuccessMessage(message) {
      showToast(message, 'success');
    }

    function showToast(message, type) {
      const toast = document.createElement('div');
      toast.className = `fixed top-4 right-4 z-50 px-4 py-2 rounded-lg text-white font-medium transform transition-all duration-300 ${
        type === 'error' ? 'bg-red-500' : 'bg-green-500'
      }`;
      toast.textContent = message;
      toast.style.transform = 'translateX(100%)';
      
      document.body.appendChild(toast);
      
      setTimeout(() => {
        toast.style.transform = 'translateX(0)';
      }, 100);
      
      setTimeout(() => {
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => {
          if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
          }
        }, 300);
      }, 3000);
    }

    function sendAdminReply(event) {
      event.preventDefault();

      if (!chatPinVerified) {
        handleChatPinRequired({ message: 'Verify your chat PIN before sending admin replies.' });
        return;
      }
      
      const messageInput = document.getElementById('adminMessageText');
      const message = messageInput.value.trim();
      
      if (!message || !currentMatchId) {
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
      const sendButton = event.target.querySelector('button[type="submit"]');
      if (sendButton) {
        sendButton.disabled = true;
        sendButton.textContent = 'Sending...';
      }
      
      const formData = new FormData();
      formData.append('action', 'admin_reply');
      formData.append('match_id', currentMatchId);
      formData.append('message', message);
      
      fetch('admin_chat_messages.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          return response.text().then(text => {
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
          loadMessages(); // Refresh messages
          showSuccessMessage('Message sent successfully');
        } else {
          showErrorMessage('Error sending admin message: ' + (data.message || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Error sending admin message:', error);
        showErrorMessage('Network error - please try again. Details: ' + error.message);
      })
      .finally(() => {
        // Re-enable input
        messageInput.disabled = false;
        if (sendButton) {
          sendButton.disabled = false;
          sendButton.textContent = 'Send Message';
        }
        messageInput.focus();
      });
    }

    function updateAdminCharCount() {
      const messageInput = document.getElementById('adminMessageText');
      const charCount = document.getElementById('adminCharCount');
      const currentLength = messageInput.value.length;
      
      charCount.textContent = `${currentLength}/500`;
      
      // Change color as approaching limit
      if (currentLength > 450) {
        charCount.className = 'text-red-500 font-medium';
      } else if (currentLength > 400) {
        charCount.className = 'text-yellow-500';
      } else {
        charCount.className = 'text-gray-500';
      }
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Approve Modal Functions
    let currentApproveClaimId = null;
    
    function approveClaim(claimId) {
      currentApproveClaimId = claimId;
      document.getElementById('approve_message').value = '';
      document.getElementById('approveModal').classList.remove('hidden');
    }

    function closeApproveModal() {
      document.getElementById('approveModal').classList.add('hidden');
      currentApproveClaimId = null;
    }

    function submitApprove(event) {
      event.preventDefault();

      if (!chatPinVerified) {
        handleChatPinRequired({ message: 'Verify your chat PIN before approving claims.' });
        return;
      }

      const response = document.getElementById('approve_message').value;
      
      const formData = new FormData();
      formData.append('action', 'approve_claim');
      formData.append('claim_id', currentApproveClaimId);
      formData.append('admin_response', response || 'Claim approved by admin');
      
      fetch('admin_chat_messages.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
      })
      .then(res => res.json())
      .then(data => {
        if (data.requires_pin) {
          handleChatPinRequired(data);
          return;
        }

        if (data.success) {
          closeApproveModal();
          showSuccessMessage('Claim approved successfully!');
          loadMessages(); // Refresh to show updated status
        } else {
          showErrorMessage('Error: ' + data.message);
        }
      })
      .catch(error => {
        showErrorMessage('Network error: ' + error.message);
      });
    }

    // Reject Modal Functions
    let currentRejectClaimId = null;
    
    function rejectClaim(claimId) {
      currentRejectClaimId = claimId;
      document.getElementById('reject_reason').value = '';
      document.getElementById('rejectModal').classList.remove('hidden');
    }

    function closeRejectModal() {
      document.getElementById('rejectModal').classList.add('hidden');
      currentRejectClaimId = null;
    }

    function submitReject(event) {
      event.preventDefault();

      if (!chatPinVerified) {
        handleChatPinRequired({ message: 'Verify your chat PIN before rejecting claims.' });
        return;
      }

      const response = document.getElementById('reject_reason').value;
      
      if (!response.trim()) {
        showErrorMessage('Please provide a rejection reason');
        return;
      }
      
      const formData = new FormData();
      formData.append('action', 'reject_claim');
      formData.append('claim_id', currentRejectClaimId);
      formData.append('admin_response', response);
      
      fetch('admin_chat_messages.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
      })
      .then(res => res.json())
      .then(data => {
        if (data.requires_pin) {
          handleChatPinRequired(data);
          return;
        }

        if (data.success) {
          closeRejectModal();
          showSuccessMessage('Claim rejected');
          loadMessages(); // Refresh to show updated status
        } else {
          showErrorMessage('Error: ' + data.message);
        }
      })
      .catch(error => {
        showErrorMessage('Network error: ' + error.message);
      });
    }


    // Close modals when clicking outside
    document.addEventListener('DOMContentLoaded', function() {
      const approveModal = document.getElementById('approveModal');
      const rejectModal = document.getElementById('rejectModal');
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
          setChatPinFeedback('Create your 6-digit admin chat PIN to continue.', false);
        }
        openChatPinModal();
      }

      if (chatPinVerified && initialChatMatch && initialChatMatch.match_id) {
        loadChat(
          initialChatMatch.match_id,
          initialChatMatch.lost_title,
          initialChatMatch.found_title,
          initialChatMatch.item_image || '',
          initialChatMatch.lost_user_name,
          initialChatMatch.found_user_name
        );
      }
      
      if (approveModal) {
        approveModal.addEventListener('click', function(event) {
          if (event.target === this) {
            closeApproveModal();
          }
        });
      }
      
      if (rejectModal) {
        rejectModal.addEventListener('click', function(event) {
          if (event.target === this) {
            closeRejectModal();
          }
        });
      }
    });

    // Clean up interval when page is closed
    window.addEventListener('beforeunload', () => {
      if (messageInterval) {
        clearInterval(messageInterval);
      }
    });
  </script>

  <!-- Approve Modal -->
  <div id="approveModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-gray-900">Approve Claim</h3>
        <button onclick="closeApproveModal()" class="text-gray-400 hover:text-gray-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>
      
      <form id="approveForm" onsubmit="submitApprove(event)">
        <div class="mb-6">
          <label for="approve_message" class="block text-sm font-medium text-gray-700 mb-2">
            Approval Message (Optional)
          </label>
          <textarea id="approve_message" rows="3" 
                    placeholder="Add any notes or instructions for the claimant..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-400/40 focus:border-orange-400"></textarea>
        </div>
        
        <div class="flex justify-end gap-3">
          <button type="button" onclick="closeApproveModal()" 
                  class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
            Cancel
          </button>
          <button type="submit" 
                  class="px-4 py-2 bg-gradient-to-r from-orange-500 to-red-500 text-white rounded-lg hover:from-orange-400 hover:to-red-400 transition-colors">
            Approve Claim
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Reject Modal -->
  <div id="rejectModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-gray-900">Reject Claim</h3>
        <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>
      
      <form id="rejectForm" onsubmit="submitReject(event)">
        <div class="mb-6">
          <label for="reject_reason" class="block text-sm font-medium text-gray-700 mb-2">
            Rejection Reason <span class="text-red-500">*</span>
          </label>
          <textarea id="reject_reason" rows="3" required
                    placeholder="Please provide a reason for rejection..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"></textarea>
        </div>
        
        <div class="flex justify-end gap-3">
          <button type="button" onclick="closeRejectModal()" 
                  class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
            Cancel
          </button>
          <button type="submit" 
                  class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
            Reject Claim
          </button>
        </div>
      </form>
    </div>
  </div>

</body>
</html>

