<?php
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/helpers/sms_helper.php';
requireRole('admin');

$current_page = basename($_SERVER['PHP_SELF']);
$user = getUserInfo();

function ensureClaimAdminResponseColumn(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM claim_requests LIKE 'admin_response'");
    $exists = $stmt && $stmt->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE claim_requests ADD COLUMN admin_response TEXT DEFAULT NULL AFTER admin_user_id");
    }

    $checked = true;
}

function canApproveClaimAfterChat(PDO $pdo, int $matchId, int $claimantUserId): array {
    $claimantMsgStmt = $pdo->prepare("SELECT COUNT(*) FROM chats WHERE match_id = ? AND sender_id = ?");
    $claimantMsgStmt->execute([$matchId, $claimantUserId]);
    $claimantCount = (int)$claimantMsgStmt->fetchColumn();

    $adminMsgStmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM chats c\n        INNER JOIN users u ON u.user_id = c.sender_id\n        WHERE c.match_id = ?\n          AND u.role = 'admin'\n    ");
    $adminMsgStmt->execute([$matchId]);
    $adminCount = (int)$adminMsgStmt->fetchColumn();

    return [
        'allowed' => $claimantCount > 0 && $adminCount > 0,
        'claimant_count' => $claimantCount,
        'admin_count' => $adminCount,
    ];
}

function maskEmailForDisplay(string $email): string {
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

function maskPhoneForDisplay(?string $phone): string {
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

function buildClaimItemImageSrc(?string $imageValue): ?string {
    $imageValue = trim((string)$imageValue);
    if ($imageValue === '' || strtolower($imageValue) === 'null') {
        return null;
    }

    $normalized = str_replace('\\', '/', $imageValue);

    if (preg_match('#^(https?:)?//#i', $normalized) === 1 || strpos($normalized, 'data:') === 0) {
        return $normalized;
    }

    $projectRoot = dirname(__DIR__);
    $uploadsRoot = $projectRoot . '/uploads';

    $candidates = [];
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

    return null;
}

// Handle claim actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    ensureClaimAdminResponseColumn($pdo);
    
    try {
        switch ($_POST['action']) {
            case 'approve_claim':
                $claim_id = $_POST['claim_id'];
                $admin_response = $_POST['admin_response'] ?? '';
                
                // Start transaction
                $pdo->beginTransaction();
                
                // Get claim details
                $claim_stmt = $pdo->prepare("SELECT * FROM claim_requests WHERE claim_id = ?");
                $claim_stmt->execute([$claim_id]);
                $claim = $claim_stmt->fetch();
                
                if ($claim) {
                    $chatGate = canApproveClaimAfterChat($pdo, (int)$claim['match_id'], (int)$claim['claimant_user_id']);
                    if (!$chatGate['allowed']) {
                        $pdo->rollBack();
                        echo json_encode([
                            'success' => false,
                            'message' => 'Cannot approve yet. Require claimant message and admin reply in chat first.'
                        ]);
                        break;
                    }

                    // Update claim status
                    $update_stmt = $pdo->prepare("UPDATE claim_requests SET status = 'approved', admin_user_id = ?, admin_response = ? WHERE claim_id = ?");
                    $update_stmt->execute([$user['user_id'], $admin_response, $claim_id]);
                    
                    // Update match status
                    $match_stmt = $pdo->prepare("UPDATE itemmatches SET status = 'accepted', admin_user_id = ? WHERE match_id = ?");
                    $match_stmt->execute([$user['user_id'], $claim['match_id']]);
                    
                    // Commit transaction
                    $pdo->commit();
                    
                    echo json_encode(['success' => true, 'message' => 'Claim approved successfully']);
                } else {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Claim not found']);
                }
                break;
                
            case 'reject_claim':
                $claim_id = $_POST['claim_id'];
                $admin_response = $_POST['admin_response'] ?? '';
                
                // Start transaction
                $pdo->beginTransaction();
                
                // Update claim status
                $update_stmt = $pdo->prepare("UPDATE claim_requests SET status = 'rejected', admin_user_id = ?, admin_response = ? WHERE claim_id = ?");
                $update_stmt->execute([$user['user_id'], $admin_response, $claim_id]);
                
                // Get claim to update match
                $claim_stmt = $pdo->prepare("SELECT match_id FROM claim_requests WHERE claim_id = ?");
                $claim_stmt->execute([$claim_id]);
                $claim = $claim_stmt->fetch();
                
                if ($claim) {
                    $match_stmt = $pdo->prepare("UPDATE itemmatches SET status = 'rejected', admin_user_id = ? WHERE match_id = ?");
                    $match_stmt->execute([$user['user_id'], $claim['match_id']]);
                }
                
                // Commit transaction
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Claim rejected']);
                break;
                
            case 'ready_for_pickup':
                $claim_id = $_POST['claim_id'];
                
                // Start transaction
                $pdo->beginTransaction();
                
                // Get claim details with claimant info
                $claim_stmt = $pdo->prepare("
                    SELECT cr.*, i.title as item_title, u.full_name, u.phone
                    FROM claim_requests cr
                    JOIN items i ON cr.item_id = i.item_id
                    JOIN users u ON cr.claimant_user_id = u.user_id
                    WHERE cr.claim_id = ?
                ");
                $claim_stmt->execute([$claim_id]);
                $claim = $claim_stmt->fetch();
                
                if ($claim) {
                    // Update claim status to ready_for_pickup
                    $update_stmt = $pdo->prepare("UPDATE claim_requests SET status = 'ready_for_pickup' WHERE claim_id = ?");
                    $update_stmt->execute([$claim_id]);
                    
                    // Commit transaction
                    $pdo->commit();
                    
                    // Send SMS notification to claimant
                    $sms_message = "Good news! Your claimed item '{$claim['item_title']}' is ready for pickup. Please visit the office to collect it. - FindIt System";
                    
                    if (!empty($claim['phone'])) {
                        $sms_result = sendSMSNotification($claim['phone'], $sms_message);
                        
                        // Log SMS activity
                        logSMSActivity($pdo, $claim['full_name'], $claim['phone'], $sms_message, 
                            $sms_result['success'] ? 'success' : 'failed', 
                            $sms_result['message']);
                        
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Item marked as ready for pickup' . ($sms_result['success'] ? ' and SMS sent to claimant' : ' (SMS failed: ' . $sms_result['message'] . ')')
                        ]);
                    } else {
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Item marked as ready for pickup (no phone number available for SMS)'
                        ]);
                    }
                } else {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Claim not found']);
                }
                break;
                
            case 'confirm_pickup':
                $claim_id = $_POST['claim_id'];
                
                // Start transaction
                $pdo->beginTransaction();
                
                // Get claim details with claimant info
                $claim_stmt = $pdo->prepare("
                    SELECT cr.*, i.title as item_title, u.full_name, u.phone
                    FROM claim_requests cr
                    JOIN items i ON cr.item_id = i.item_id
                    JOIN users u ON cr.claimant_user_id = u.user_id
                    WHERE cr.claim_id = ?
                ");
                $claim_stmt->execute([$claim_id]);
                $claim = $claim_stmt->fetch();
                
                if ($claim) {
                    // Update claim status to claimed (completed)
                    $update_stmt = $pdo->prepare("UPDATE claim_requests SET status = 'claimed' WHERE claim_id = ?");
                    $update_stmt->execute([$claim_id]);
                    
                    // Update item status to claimed
                    $item_stmt = $pdo->prepare("UPDATE items SET status = 'claimed' WHERE item_id = ?");
                    $item_stmt->execute([$claim['item_id']]);
                    
                    // Award 10 points to finder if finder_name exists
                    $get_finder_stmt = $pdo->prepare("SELECT finder_name FROM items WHERE item_id = ?");
                    $get_finder_stmt->execute([$claim['item_id']]);
                    $item_data = $get_finder_stmt->fetch();
                    
                    if ($item_data && !empty($item_data['finder_name'])) {
                        $finder_name = $item_data['finder_name'];
                        
                        // Check if finder exists in leaderboard
                        $check_finder = $pdo->prepare("SELECT id, total_points FROM finder_leaderboard WHERE finder_name = ?");
                        $check_finder->execute([$finder_name]);
                        $finder_record = $check_finder->fetch();
                        
                        if ($finder_record) {
                            // Update existing finder points
                            $update_points = $pdo->prepare("UPDATE finder_leaderboard SET total_points = total_points + 10 WHERE finder_name = ?");
                            $update_points->execute([$finder_name]);
                        } else {
                            // Insert new finder with 10 points
                            $insert_finder = $pdo->prepare("INSERT INTO finder_leaderboard (finder_name, total_points) VALUES (?, 10)");
                            $insert_finder->execute([$finder_name]);
                        }
                    }
                    
                    // Commit transaction
                    $pdo->commit();
                    
                    // Send SMS confirmation to claimant
                    $sms_message = "Thank you for collecting your item '{$claim['item_title']}'! Your claim has been completed successfully. We're glad we could help reunite you with your belonging. - FindIt System";
                    
                    if (!empty($claim['phone'])) {
                        $sms_result = sendSMSNotification($claim['phone'], $sms_message);
                        
                        // Log SMS activity
                        logSMSActivity($pdo, $claim['full_name'], $claim['phone'], $sms_message, 
                            $sms_result['success'] ? 'success' : 'failed', 
                            $sms_result['message']);
                        
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Pickup confirmed and item marked as claimed' . ($sms_result['success'] ? ' (SMS confirmation sent)' : ' (SMS failed: ' . $sms_result['message'] . ')')
                        ]);
                    } else {
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Pickup confirmed and item marked as claimed (no phone number available for SMS)'
                        ]);
                    }
                } else {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Claim not found']);
                }
                break;
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Get all claim requests
$claims_sql = "SELECT 
    cr.*,
    im.lost_item_id,
    im.found_item_id,
    im.similarity_score,
    i.title as item_title,
    i.category,
    i.item_image,
    li.item_image as lost_image,
    fi.item_image as found_image,
    i.description as item_description,
    u.full_name as claimant_name,
    u.email as claimant_email,
    u.phone as claimant_phone,
    (SELECT COUNT(*) FROM chats c1 WHERE c1.match_id = cr.match_id AND c1.sender_id = cr.claimant_user_id) as claimant_message_count,
    (SELECT COUNT(*) FROM chats c2 INNER JOIN users uadm ON uadm.user_id = c2.sender_id WHERE c2.match_id = cr.match_id AND uadm.role = 'admin') as admin_message_count,
    CASE
        WHEN (SELECT COUNT(*) FROM chats c1 WHERE c1.match_id = cr.match_id AND c1.sender_id = cr.claimant_user_id) > 0
         AND (SELECT COUNT(*) FROM chats c2 INNER JOIN users uadm ON uadm.user_id = c2.sender_id WHERE c2.match_id = cr.match_id AND uadm.role = 'admin') > 0
        THEN 1 ELSE 0
    END as can_approve_chat
FROM claim_requests cr
JOIN itemmatches im ON cr.match_id = im.match_id
JOIN items i ON cr.item_id = i.item_id
LEFT JOIN items li ON im.lost_item_id = li.item_id
LEFT JOIN items fi ON im.found_item_id = fi.item_id
JOIN users u ON cr.claimant_user_id = u.user_id
ORDER BY 
    CASE cr.status 
        WHEN 'pending' THEN 1 
        WHEN 'approved' THEN 2 
        WHEN 'rejected' THEN 3 
    END,
    cr.created_at DESC";

$claims_stmt = $pdo->query($claims_sql);
$claims = $claims_stmt->fetchAll();
$claimItemPlaceholderSrc = finditAssetUrl('images/placeholders/item-default.svg');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Claim Requests</title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        .gradient-bg { background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 42%, #f23c00 100%); }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dbeafe; color: #1e40af; }
        .status-ready_for_pickup { background: #e0e7ff; color: #4338ca; }
        .status-claimed { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }

        .toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 70;
            min-width: 260px;
            max-width: 420px;
            padding: 0.85rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid transparent;
            box-shadow: 0 18px 32px rgba(0, 0, 0, 0.25);
            transform: translateY(-8px);
            opacity: 0;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast-success {
            background: #052e16;
            border-color: #166534;
            color: #bbf7d0;
        }

        .toast-error {
            background: #450a0a;
            border-color: #991b1b;
            color: #fecaca;
        }

        .table-compact td,
        .table-compact th {
            vertical-align: top;
        }

        .action-dropdown-menu {
            z-index: 9999;
            width: 12rem;
            background: #081627;
            border: 1px solid #1f2c3f;
            border-radius: 0.75rem;
            box-shadow: 0 20px 32px rgba(2, 6, 23, 0.45);
            padding: 0.35rem;
            overflow: hidden;
        }

        .action-trigger-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            min-width: 110px;
            border: 1px solid #1f2c3f;
            border-radius: 0.65rem;
            padding: 0.45rem 0.75rem;
            font-size: 0.76rem;
            font-weight: 700;
            color: #f8fafc;
            background: linear-gradient(180deg, #0f2238 0%, #081627 100%);
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .action-trigger-btn:hover {
            background: linear-gradient(180deg, #16314d 0%, #0b1d31 100%);
            border-color: #2f425b;
        }

        .action-menu-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.55rem 0.6rem;
            font-size: 0.76rem;
            line-height: 1.15rem;
            color: #dbe7f7;
            border-radius: 0.5rem;
            transition: background-color 0.18s ease;
        }

        .action-menu-item:hover {
            background: rgba(148, 163, 184, 0.18);
        }

        .action-menu-muted {
            color: #94a3b8;
        }

        .action-icon-view {
            color: #3b82f6;
        }

        .action-icon-chat {
            color: #22c55e;
        }

        .action-icon-success {
            color: #10b981;
        }

        .action-icon-warning {
            color: #f59e0b;
        }

        .action-icon-danger {
            color: #ef4444;
        }

        .ui-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            border: 0;
            border-radius: 10px;
            padding: 0.6rem 0.95rem;
            font-size: 0.86rem;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .ui-btn-primary {
            background: linear-gradient(132deg, #ff7b2c, #f23c00);
            color: #ffffff;
        }

        .ui-btn-primary:hover {
            filter: brightness(1.06);
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
                    <h2 class="text-3xl font-bold text-gray-800 mb-2">Claim Requests</h2>
                    <p class="text-gray-600">Manage item claim requests from claimants</p>
                </div>

                <!-- Claims Table -->
                <div class="bg-white rounded-xl shadow-lg overflow-visible border border-gray-200">
                    <div class="px-6 py-4 border-b bg-gray-50">
                        <h3 class="text-lg font-semibold text-gray-800">All Claim Requests</h3>
                    </div>

                    <div>
                        <table class="w-full min-w-[1050px] divide-y divide-gray-200 table-compact">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Claimant</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Match</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Request</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="claimsTableBody" class="bg-white divide-y divide-gray-200">
                                <?php if (count($claims) > 0): ?>
                                    <?php foreach ($claims as $claim): ?>
                                    <?php
                                        $claimImageSrc = buildClaimItemImageSrc($claim['item_image'] ?? null);
                                        if ($claimImageSrc === null) {
                                            $claimImageSrc = buildClaimItemImageSrc($claim['lost_image'] ?? null);
                                        }
                                        if ($claimImageSrc === null) {
                                            $claimImageSrc = buildClaimItemImageSrc($claim['found_image'] ?? null);
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50 align-top claim-row">
                                        <td class="px-4 py-3">
                                            <div class="flex items-start gap-3 min-w-[220px]">
                                                <?php if (!empty($claimImageSrc)): ?>
                                                    <img src="<?php echo htmlspecialchars($claimImageSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="Item" class="w-12 h-12 rounded-lg object-cover border border-gray-200" onerror="this.onerror=null; this.src='<?php echo htmlspecialchars($claimItemPlaceholderSrc, ENT_QUOTES, 'UTF-8'); ?>';">
                                                <?php else: ?>
                                                    <div class="w-12 h-12 rounded-lg bg-gray-200 border border-gray-200"></div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($claim['item_title']); ?></div>
                                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($claim['category']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($claim['claimant_name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars(maskEmailForDisplay((string)$claim['claimant_email'])); ?></div>
                                            <?php if (!empty($claim['claimant_phone'])): ?>
                                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars(maskPhoneForDisplay((string)$claim['claimant_phone'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700"><?php echo (float)$claim['similarity_score']; ?>%</td>
                                        <td class="px-4 py-3 text-sm text-gray-700 max-w-xs">
                                            <div class="line-clamp-3"><?php echo htmlspecialchars($claim['request_message'] ?: 'No message'); ?></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="status-<?php echo $claim['status']; ?> px-3 py-1 rounded-full text-xs font-medium">
                                                <?php echo ucfirst(str_replace('_', ' ', $claim['status'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap"><?php echo date('M d, Y H:i', strtotime($claim['created_at'])); ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap min-w-[140px]">
                                            <div class="relative inline-block text-left claim-action-dropdown action-dropdown">
                                                <button type="button"
                                                        onclick="toggleClaimActionDropdown(<?php echo $claim['claim_id']; ?>)"
                                                        class="action-trigger-btn">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01"></path>
                                                    </svg>
                                                    Actions
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                    </svg>
                                                </button>

                                                <div id="claimActionDropdown-<?php echo $claim['claim_id']; ?>" class="action-dropdown-menu hidden absolute right-0 mt-2">
                                                    <?php if ($claim['status'] === 'pending'): ?>
                                                        <a href="admin_chat_messages.php?claim_id=<?php echo $claim['claim_id']; ?>" class="action-menu-item" onclick="closeAllClaimActionDropdowns()">
                                                            <svg class="w-4 h-4 action-icon-chat" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
                                                            View In Chat
                                                        </a>
                                                        <?php if ((int)$claim['can_approve_chat'] === 1): ?>
                                                            <button type="button" onclick="openApproveModal(<?php echo $claim['claim_id']; ?>, '<?php echo htmlspecialchars($claim['item_title'], ENT_QUOTES); ?>'); closeAllClaimActionDropdowns();" class="action-menu-item">
                                                                <svg class="w-4 h-4 action-icon-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                                Approve Claim
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" onclick="openRestrictionModal(<?php echo (int)$claim['claimant_message_count']; ?>, <?php echo (int)$claim['admin_message_count']; ?>); closeAllClaimActionDropdowns();" class="action-menu-item action-menu-muted">
                                                                <svg class="w-4 h-4 action-icon-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"></path></svg>
                                                                Approve Locked
                                                            </button>
                                                        <?php endif; ?>
                                                        <button type="button" onclick="openRejectModal(<?php echo $claim['claim_id']; ?>, '<?php echo htmlspecialchars($claim['item_title'], ENT_QUOTES); ?>'); closeAllClaimActionDropdowns();" class="action-menu-item">
                                                            <svg class="w-4 h-4 action-icon-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                            Reject Claim
                                                        </button>
                                                    <?php elseif ($claim['status'] === 'approved'): ?>
                                                        <button type="button" onclick="markReadyForPickup(<?php echo $claim['claim_id']; ?>); closeAllClaimActionDropdowns();" class="action-menu-item">
                                                            <svg class="w-4 h-4 action-icon-view" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V7a2 2 0 00-2-2h-3V3H9v2H6a2 2 0 00-2 2v6m16 0l-2.586 2.586a2 2 0 01-1.414.586H8a2 2 0 01-1.414-.586L4 13m16 0H4"></path></svg>
                                                            Mark Ready For Pickup
                                                        </button>
                                                    <?php elseif ($claim['status'] === 'ready_for_pickup'): ?>
                                                        <button type="button" onclick="confirmPickup(<?php echo $claim['claim_id']; ?>); closeAllClaimActionDropdowns();" class="action-menu-item">
                                                            <svg class="w-4 h-4 action-icon-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                            Confirm Pickup
                                                        </button>
                                                    <?php elseif ($claim['status'] === 'claimed'): ?>
                                                        <div class="action-menu-item action-menu-muted">
                                                            <svg class="w-4 h-4 action-icon-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                            Completed
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="action-menu-item action-menu-muted">No Actions Available</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr class="no-claims-row">
                                        <td colspan="7" class="px-6 py-12 text-center">
                                            <p class="text-gray-500 text-lg">No claim requests yet</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div id="claimsPagination" class="hidden px-6 py-4 border-t border-gray-200 bg-white flex items-center justify-between">
                        <p id="claimsPaginationInfo" class="text-xs text-gray-600"></p>
                        <div class="flex items-center gap-2">
                            <button type="button" id="claimsPrevBtn" class="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">Previous</button>
                            <span id="claimsPageLabel" class="text-xs text-gray-600"></span>
                            <button type="button" id="claimsNextBtn" class="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">Next</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                <input type="hidden" id="approve_claim_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Item:</label>
                    <p id="approve_item_title" class="text-gray-900 font-semibold"></p>
                </div>
                
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
                <input type="hidden" id="reject_claim_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Item:</label>
                    <p id="reject_item_title" class="text-gray-900 font-semibold"></p>
                </div>
                
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

    <!-- Restriction Modal -->
    <div id="restrictionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-[30rem] max-w-[95vw] shadow-lg rounded-lg bg-white">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Approval Restriction</h3>
                <button onclick="closeRestrictionModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="space-y-3 text-sm text-gray-700">
                <p>Approval is allowed only after both chat steps are completed:</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li>Claimant has sent at least one message.</li>
                    <li>An admin has sent at least one reply.</li>
                </ul>
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900">
                    <p><strong>Current chat status</strong></p>
                    <p id="restrictionStatus" class="mt-1"></p>
                </div>
            </div>
            <div class="flex justify-end mt-5">
                <button type="button" onclick="closeRestrictionModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">Close</button>
            </div>
        </div>
    </div>

    <!-- Action Confirm Modal -->
    <div id="actionConfirmModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-[30rem] max-w-[95vw] shadow-lg rounded-lg bg-white">
            <div class="flex justify-between items-center mb-4">
                <h3 id="actionConfirmTitle" class="text-lg font-semibold text-gray-900">Confirm Action</h3>
                <button onclick="closeActionConfirmModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <p id="actionConfirmMessage" class="text-sm text-gray-700 mb-6"></p>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeActionConfirmModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">Cancel</button>
                <button type="button" id="actionConfirmExecuteBtn" class="px-4 py-2 bg-gradient-to-r from-orange-500 to-red-500 text-white rounded-lg hover:from-orange-400 hover:to-red-400 transition-colors">Continue</button>
            </div>
        </div>
    </div>

    <div id="claimsToast" class="toast"></div>

    <script>
        const ADMIN_CLAIMS_PER_PAGE = 5;
        let adminClaimRows = [];
        let adminClaimsCurrentPage = 1;
        let pendingConfirmAction = null;

        // Approve Modal Functions
        function openApproveModal(claimId, itemTitle) {
            document.getElementById('approve_claim_id').value = claimId;
            document.getElementById('approve_item_title').textContent = itemTitle;
            document.getElementById('approve_message').value = '';
            document.getElementById('approveModal').classList.remove('hidden');
        }

        function closeApproveModal() {
            document.getElementById('approveModal').classList.add('hidden');
        }

        function submitApprove(event) {
            event.preventDefault();
            const claimId = document.getElementById('approve_claim_id').value;
            const response = document.getElementById('approve_message').value;

            fetch('admin_claims.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=approve_claim&claim_id=${claimId}&admin_response=${encodeURIComponent(response)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showClaimsToast(data.message || 'Claim approved successfully.', 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showClaimsToast(data.message || 'Unable to approve claim.', 'error');
                }
            })
            .catch(error => {
                showClaimsToast(error.message || 'Request failed.', 'error');
            });
        }

        // Reject Modal Functions
        function openRejectModal(claimId, itemTitle) {
            document.getElementById('reject_claim_id').value = claimId;
            document.getElementById('reject_item_title').textContent = itemTitle;
            document.getElementById('reject_reason').value = '';
            document.getElementById('rejectModal').classList.remove('hidden');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.add('hidden');
        }

        function submitReject(event) {
            event.preventDefault();
            const claimId = document.getElementById('reject_claim_id').value;
            const response = document.getElementById('reject_reason').value;

            if (!response.trim()) {
                showClaimsToast('Please provide a rejection reason.', 'error');
                return;
            }

            fetch('admin_claims.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=reject_claim&claim_id=${claimId}&admin_response=${encodeURIComponent(response)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showClaimsToast(data.message || 'Claim rejected.', 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showClaimsToast(data.message || 'Unable to reject claim.', 'error');
                }
            })
            .catch(error => {
                showClaimsToast(error.message || 'Request failed.', 'error');
            });
        }

        function showClaimsToast(message, type) {
            const toast = document.getElementById('claimsToast');
            if (!toast) return;

            toast.className = 'toast';
            toast.classList.add(type === 'success' ? 'toast-success' : 'toast-error');
            toast.textContent = message;
            requestAnimationFrame(() => toast.classList.add('show'));

            setTimeout(() => {
                toast.classList.remove('show');
            }, 2600);
        }

        function openRestrictionModal(claimantCount, adminCount) {
            const statusText = `Claimant messages: ${claimantCount} | Admin replies: ${adminCount}`;
            document.getElementById('restrictionStatus').textContent = statusText;
            document.getElementById('restrictionModal').classList.remove('hidden');
        }

        function closeRestrictionModal() {
            document.getElementById('restrictionModal').classList.add('hidden');
        }

        function openActionConfirmModal(title, message, actionFn) {
            document.getElementById('actionConfirmTitle').textContent = title;
            document.getElementById('actionConfirmMessage').textContent = message;
            pendingConfirmAction = actionFn;
            document.getElementById('actionConfirmModal').classList.remove('hidden');
        }

        function closeActionConfirmModal() {
            document.getElementById('actionConfirmModal').classList.add('hidden');
            pendingConfirmAction = null;
        }

        document.getElementById('actionConfirmExecuteBtn').addEventListener('click', function() {
            if (typeof pendingConfirmAction === 'function') {
                pendingConfirmAction();
            }
            closeActionConfirmModal();
        });

        // Claim Action Dropdown Functions
        function closeAllClaimActionDropdowns() {
            document.querySelectorAll('[id^="claimActionDropdown-"]').forEach(dropdown => {
                dropdown.classList.add('hidden');
            });
        }

        function initAdminClaimsPagination() {
            const body = document.getElementById('claimsTableBody');
            const pagination = document.getElementById('claimsPagination');
            if (!body || !pagination) {
                return;
            }

            adminClaimRows = Array.from(body.querySelectorAll('tr.claim-row'));
            if (adminClaimRows.length <= ADMIN_CLAIMS_PER_PAGE) {
                pagination.classList.add('hidden');
                adminClaimRows.forEach(row => {
                    row.style.display = '';
                });
                return;
            }

            pagination.classList.remove('hidden');
            const prevBtn = document.getElementById('claimsPrevBtn');
            const nextBtn = document.getElementById('claimsNextBtn');

            prevBtn.addEventListener('click', function () {
                if (adminClaimsCurrentPage > 1) {
                    renderAdminClaimsPage(adminClaimsCurrentPage - 1);
                }
            });

            nextBtn.addEventListener('click', function () {
                const totalPages = Math.ceil(adminClaimRows.length / ADMIN_CLAIMS_PER_PAGE);
                if (adminClaimsCurrentPage < totalPages) {
                    renderAdminClaimsPage(adminClaimsCurrentPage + 1);
                }
            });

            renderAdminClaimsPage(1);
        }

        function renderAdminClaimsPage(page) {
            const totalPages = Math.max(1, Math.ceil(adminClaimRows.length / ADMIN_CLAIMS_PER_PAGE));
            adminClaimsCurrentPage = Math.min(Math.max(page, 1), totalPages);

            const start = (adminClaimsCurrentPage - 1) * ADMIN_CLAIMS_PER_PAGE;
            const end = start + ADMIN_CLAIMS_PER_PAGE;

            adminClaimRows.forEach((row, index) => {
                row.style.display = (index >= start && index < end) ? '' : 'none';
            });

            document.getElementById('claimsPaginationInfo').textContent = `Showing ${start + 1}-${Math.min(end, adminClaimRows.length)} of ${adminClaimRows.length} claims`;
            document.getElementById('claimsPageLabel').textContent = `Page ${adminClaimsCurrentPage} of ${totalPages}`;

            const prevBtn = document.getElementById('claimsPrevBtn');
            const nextBtn = document.getElementById('claimsNextBtn');

            prevBtn.disabled = adminClaimsCurrentPage === 1;
            nextBtn.disabled = adminClaimsCurrentPage === totalPages;

            prevBtn.classList.toggle('opacity-50', prevBtn.disabled);
            prevBtn.classList.toggle('cursor-not-allowed', prevBtn.disabled);
            nextBtn.classList.toggle('opacity-50', nextBtn.disabled);
            nextBtn.classList.toggle('cursor-not-allowed', nextBtn.disabled);

            closeAllClaimActionDropdowns();
        }

        function toggleClaimActionDropdown(claimId) {
            const target = document.getElementById('claimActionDropdown-' + claimId);
            if (!target) return;

            const isHidden = target.classList.contains('hidden');
            closeAllClaimActionDropdowns();

            if (isHidden) {
                target.classList.remove('hidden');
            }
        }

        // Close modals when clicking outside
        document.getElementById('approveModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeApproveModal();
            }
        });

        document.getElementById('rejectModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeRejectModal();
            }
        });

        document.getElementById('restrictionModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeRestrictionModal();
            }
        });

        document.getElementById('actionConfirmModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeActionConfirmModal();
            }
        });

        document.addEventListener('click', function(event) {
            const dropdownArea = event.target.closest('.claim-action-dropdown');
            if (!dropdownArea) {
                closeAllClaimActionDropdowns();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAllClaimActionDropdowns();
                closeRestrictionModal();
                closeActionConfirmModal();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            initAdminClaimsPagination();
        });


        function markReadyForPickup(claimId) {
            openActionConfirmModal(
                'Mark Ready for Pickup',
                'Mark this item as ready for pickup and send an SMS notification to the claimant?',
                () => runClaimAction('ready_for_pickup', claimId)
            );
        }

        function confirmPickup(claimId) {
            openActionConfirmModal(
                'Confirm Pickup',
                'Confirm that the claimant has received the item and complete this claim?',
                () => runClaimAction('confirm_pickup', claimId)
            );
        }

        function runClaimAction(action, claimId) {
            fetch('admin_claims.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=${encodeURIComponent(action)}&claim_id=${encodeURIComponent(claimId)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showClaimsToast(data.message || 'Claim updated successfully.', 'success');
                    setTimeout(() => location.reload(), 900);
                } else {
                    showClaimsToast(data.message || 'Unable to update claim.', 'error');
                }
            })
            .catch(error => {
                showClaimsToast(error.message || 'Request failed.', 'error');
            });
        }
    </script>
</body>
</html>

