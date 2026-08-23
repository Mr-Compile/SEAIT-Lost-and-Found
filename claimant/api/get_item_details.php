<?php
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
requireRole('claimant');

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'Item ID is required']);
    exit;
}

$item_id = (int)$_GET['id'];

try {
    $stmt = $pdo->prepare("
        SELECT i.*, u.full_name as reporter_name, u.email as reporter_email
        FROM items i
        JOIN users u ON i.user_id = u.user_id
        WHERE i.item_id = ?
    ");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($item) {
        // Format date
        $item['report_date'] = date('Y-m-d', strtotime($item['report_date']));
        
        // Image path - handle both cases
        if (!empty($item['item_image'])) {
            // If image path already contains 'uploads/', use it as is
            if (strpos($item['item_image'], 'uploads/') !== false) {
                $item['item_image'] = '../' . $item['item_image'];
            } else {
                $item['item_image'] = '../uploads/' . $item['item_image'];
            }
        } else {
            $item['item_image'] = null;
        }
        
        // Get potential matches for this item
        $matches = [];
        if ($item['status'] === 'lost') {
            // Get found items that might match this lost item
            $match_stmt = $pdo->prepare("
                SELECT 
                    im.match_id,
                    im.similarity_score,
                    fi.item_id as match_item_id,
                    fi.title as match_title,
                    fi.description as match_description,
                    fi.item_image as match_image,
                    fu.full_name as finder_name,
                    fu.email as finder_email
                FROM itemmatches im
                JOIN items fi ON im.found_item_id = fi.item_id
                JOIN users fu ON fi.user_id = fu.user_id
                WHERE im.lost_item_id = ?
                ORDER BY im.similarity_score DESC
                LIMIT 5
            ");
            $match_stmt->execute([$item_id]);
            $matches = $match_stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($item['status'] === 'found') {
            // Get lost items that might match this found item
            $match_stmt = $pdo->prepare("
                SELECT 
                    im.match_id,
                    im.similarity_score,
                    li.item_id as match_item_id,
                    li.title as match_title,
                    li.description as match_description,
                    li.item_image as match_image,
                    lu.full_name as owner_name,
                    lu.email as owner_email
                FROM itemmatches im
                JOIN items li ON im.lost_item_id = li.item_id
                JOIN users lu ON li.user_id = lu.user_id
                WHERE im.found_item_id = ?
                ORDER BY im.similarity_score DESC
                LIMIT 5
            ");
            $match_stmt->execute([$item_id]);
            $matches = $match_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Format match data
        foreach ($matches as &$match) {
            if (!empty($match['match_image'])) {
                // If image path already contains 'uploads/', use it as is
                if (strpos($match['match_image'], 'uploads/') !== false) {
                    $match['match_image'] = '../' . $match['match_image'];
                } else {
                    $match['match_image'] = '../uploads/' . $match['match_image'];
                }
            } else {
                $match['match_image'] = null;
            }
        }
        
        $item['matches'] = $matches;
        $item['match_count'] = count($matches);
        $item['has_match'] = count($matches) > 0;
        
        echo json_encode(['success' => true, 'item' => $item]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Item not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

