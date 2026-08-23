<?php
require_once '../../config/db.php';
require_once '../../includes/auth_check.php';
requireRole('claimant');

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Get user info
$user_id = $_SESSION['user_id'];

function buildClaimImageSrc(?string ...$candidates): ?string
{
    $uploadsRoot = dirname(__DIR__, 2) . '/uploads';

    foreach ($candidates as $candidateValue) {
        $value = trim((string)$candidateValue);
        if ($value === '' || strtolower($value) === 'null') {
            continue;
        }

        $normalized = str_replace('\\', '/', $value);

        if (preg_match('#^(https?:)?//#i', $normalized) === 1 || strpos($normalized, 'data:') === 0) {
            return $normalized;
        }

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
        $pathOptions = [];

        if ($trimmed !== '') {
            $pathOptions[] = $trimmed;
        }

        $fileName = basename($trimmed);
        if ($fileName !== '' && $fileName !== '.') {
            $pathOptions[] = $fileName;
            $pathOptions[] = 'backup_img/' . $fileName;
        }

        foreach (array_values(array_unique($pathOptions)) as $relativePath) {
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
    }

    return null;
}

// Handle different actions
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'submit_claim':
            // Submit a new claim request
            $match_id = (int)($_POST['match_id'] ?? 0);
            $item_id = (int)($_POST['item_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            
            if (!$match_id || !$item_id) {
                throw new Exception('Invalid match or item ID');
            }
            
            // Verify the match exists and involves this user
            $verify_stmt = $pdo->prepare("
                SELECT im.match_id, im.lost_item_id, im.found_item_id,
                       li.user_id as lost_user, fi.user_id as found_user
                FROM itemmatches im
                JOIN items li ON im.lost_item_id = li.item_id
                JOIN items fi ON im.found_item_id = fi.item_id
                WHERE im.match_id = ?
                AND (li.user_id = ? OR fi.user_id = ?)
            ");
            $verify_stmt->execute([$match_id, $user_id, $user_id]);
            $match = $verify_stmt->fetch();
            
            if (!$match) {
                throw new Exception('Match not found or unauthorized');
            }
            
            // Check if claim already exists
            $check_stmt = $pdo->prepare("
                SELECT claim_id FROM claim_requests 
                WHERE match_id = ? AND claimant_user_id = ?
            ");
            $check_stmt->execute([$match_id, $user_id]);
            
            if ($check_stmt->fetch()) {
                throw new Exception('You have already submitted a claim for this item');
            }
            
            // Insert claim request
            $insert_stmt = $pdo->prepare("
                INSERT INTO claim_requests 
                (match_id, claimant_user_id, item_id, request_message, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())
            ");
            $insert_stmt->execute([$match_id, $user_id, $item_id, $message]);
            
            $claim_id = $pdo->lastInsertId();
            
            // Create notification for admin
            $notify_stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, item_id, type, message, sent_at, is_read)
                SELECT 1, ?, 'system', CONCAT('New claim request from ', u.full_name), NOW(), 0
                FROM users u WHERE u.user_id = ?
            ");
            $notify_stmt->execute([$item_id, $user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Claim request submitted successfully',
                'claim_id' => $claim_id
            ]);
            break;
            
        case 'get_my_claims':
            // Get all claim requests for this user
            $stmt = $pdo->prepare("
                SELECT 
                    cr.*,
                    i.title as item_title,
                    i.description as item_description,
                    i.category as item_category,
                    i.item_image,
                    li.item_image as lost_image,
                    fi.item_image as found_image,
                    im.similarity_score,
                    im.matched_at,
                    CASE 
                        WHEN cr.status = 'pending' THEN 'Pending Review'
                        WHEN cr.status = 'approved' THEN 'Approved'
                        WHEN cr.status = 'ready_for_pickup' THEN 'Ready for Pickup'
                        WHEN cr.status = 'claimed' THEN 'Claimed'
                        WHEN cr.status = 'rejected' THEN 'Rejected'
                    END as status_text,
                    admin.full_name as admin_name
                FROM claim_requests cr
                JOIN items i ON cr.item_id = i.item_id
                JOIN itemmatches im ON cr.match_id = im.match_id
                LEFT JOIN items li ON im.lost_item_id = li.item_id
                LEFT JOIN items fi ON im.found_item_id = fi.item_id
                LEFT JOIN users admin ON cr.admin_user_id = admin.user_id
                WHERE cr.claimant_user_id = ?
                ORDER BY cr.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $claims = $stmt->fetchAll();
            
            // Format image paths
            foreach ($claims as &$claim) {
                $claim['item_image'] = buildClaimImageSrc(
                    $claim['item_image'] ?? null,
                    $claim['lost_image'] ?? null,
                    $claim['found_image'] ?? null
                );
                unset($claim['lost_image'], $claim['found_image']);
                $claim['created_at_formatted'] = date('M j, Y g:i A', strtotime($claim['created_at']));
                $claim['updated_at_formatted'] = date('M j, Y g:i A', strtotime($claim['updated_at']));
            }
            
            echo json_encode([
                'success' => true,
                'claims' => $claims
            ]);
            break;
            
        case 'get_claim_details':
            // Get detailed information about a specific claim
            $claim_id = (int)($_GET['claim_id'] ?? 0);
            
            if (!$claim_id) {
                throw new Exception('Invalid claim ID');
            }
            
            // Get claim details with verification
            $stmt = $pdo->prepare("
                SELECT 
                    cr.*,
                    i.title as item_title,
                    i.description as item_description,
                    i.category as item_category,
                    i.item_image,
                    li.item_image as lost_image,
                    fi.item_image as found_image,
                    i.location as item_location,
                    i.status as item_status,
                    im.similarity_score,
                    im.matched_at,
                    CASE 
                        WHEN cr.status = 'pending' THEN 'Pending Review'
                        WHEN cr.status = 'approved' THEN 'Approved'
                        WHEN cr.status = 'ready_for_pickup' THEN 'Ready for Pickup'
                        WHEN cr.status = 'claimed' THEN 'Claimed'
                        WHEN cr.status = 'rejected' THEN 'Rejected'
                    END as status_text,
                    admin.full_name as admin_name,
                    admin.email as admin_email
                FROM claim_requests cr
                JOIN items i ON cr.item_id = i.item_id
                JOIN itemmatches im ON cr.match_id = im.match_id
                LEFT JOIN items li ON im.lost_item_id = li.item_id
                LEFT JOIN items fi ON im.found_item_id = fi.item_id
                LEFT JOIN users admin ON cr.admin_user_id = admin.user_id
                WHERE cr.claim_id = ? AND cr.claimant_user_id = ?
            ");
            $stmt->execute([$claim_id, $user_id]);
            $claim = $stmt->fetch();
            
            if (!$claim) {
                throw new Exception('Claim not found or unauthorized');
            }
            
            $claim['item_image'] = buildClaimImageSrc(
                $claim['item_image'] ?? null,
                $claim['lost_image'] ?? null,
                $claim['found_image'] ?? null
            );
            unset($claim['lost_image'], $claim['found_image']);
            
            $claim['created_at_formatted'] = date('M j, Y g:i A', strtotime($claim['created_at']));
            $claim['updated_at_formatted'] = date('M j, Y g:i A', strtotime($claim['updated_at']));
            $claim['matched_at_formatted'] = date('M j, Y g:i A', strtotime($claim['matched_at']));
            
            echo json_encode([
                'success' => true,
                'claim' => $claim
            ]);
            break;
            
        case 'cancel_claim':
            // Cancel a pending claim request
            $claim_id = (int)($_POST['claim_id'] ?? 0);
            
            if (!$claim_id) {
                throw new Exception('Invalid claim ID');
            }
            
            // Verify ownership and status
            $verify_stmt = $pdo->prepare("
                SELECT claim_id FROM claim_requests 
                WHERE claim_id = ? AND claimant_user_id = ? AND status = 'pending'
            ");
            $verify_stmt->execute([$claim_id, $user_id]);
            
            if (!$verify_stmt->fetch()) {
                throw new Exception('Claim not found or cannot be cancelled');
            }
            
            // Delete the claim request
            $delete_stmt = $pdo->prepare("DELETE FROM claim_requests WHERE claim_id = ?");
            $delete_stmt->execute([$claim_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Claim request cancelled successfully'
            ]);
            break;
            
        case 'check_claim_status':
            // Check if user has already claimed an item
            $match_id = (int)($_GET['match_id'] ?? 0);
            
            $check_stmt = $pdo->prepare("
                SELECT claim_id, status FROM claim_requests 
                WHERE match_id = ? AND claimant_user_id = ?
            ");
            $check_stmt->execute([$match_id, $user_id]);
            $existing_claim = $check_stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'has_claim' => (bool)$existing_claim,
                'claim_status' => $existing_claim['status'] ?? null,
                'claim_id' => $existing_claim['claim_id'] ?? null
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

