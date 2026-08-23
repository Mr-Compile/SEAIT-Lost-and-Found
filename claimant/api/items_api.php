<?php
// items_api.php - API for managing user items

// Start output buffering to prevent any accidental output
ob_start();

// Disable error display to prevent breaking JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

try {
    require_once('../../config/db.php');
    require_once('../../includes/auth_check.php');
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Configuration error: ' . $e->getMessage()]);
    exit();
}

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Clear any output buffer and set JSON header
ob_clean();
header('Content-Type: application/json');

// Get the request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];

try {
    switch ($method) {
        case 'GET':
            if ($action === 'get_user_items') {
                getUserItems($pdo, $user_id);
            } elseif ($action === 'get_item' && isset($_GET['id'])) {
                getItem($pdo, $_GET['id'], $user_id);
            } elseif ($action === 'get_matches' && isset($_GET['id'])) {
                getItemMatches($pdo, $_GET['id'], $user_id);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        case 'POST':
            if ($action === 'add_item') {
                addItem($pdo, $user_id);
            } elseif ($action === 'update_item' && isset($_GET['id'])) {
                updateItem($pdo, $_GET['id'], $user_id);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        case 'PUT':
            if ($action === 'update_item' && isset($_GET['id'])) {
                updateItem($pdo, $_GET['id'], $user_id);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        case 'DELETE':
            if ($action === 'delete_item' && isset($_GET['id'])) {
                deleteItem($pdo, $_GET['id'], $user_id);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

// Function to get all items for the logged-in user
function getUserItems($pdo, $user_id) {
    $sql = "SELECT 
                i.item_id,
                i.title,
                i.description,
                i.category,
                i.location,
                i.item_image,
                i.status,
                i.report_date,
                i.hold_expiry,
                COUNT(CASE WHEN i.status = 'lost' THEN im.match_id END) as match_count,
                MAX(CASE WHEN i.status = 'lost' THEN im.similarity_score END) as best_match_score,
                MAX(CASE WHEN i.status = 'lost' THEN im.status END) as match_status
            FROM items i
            LEFT JOIN itemmatches im ON (
                (i.status = 'lost' AND i.item_id = im.lost_item_id) OR
                (i.status = 'found' AND i.item_id = im.found_item_id)
            )
            WHERE i.user_id = ?
            GROUP BY i.item_id
            ORDER BY i.report_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $items = $stmt->fetchAll();
    
    // Format the data for frontend consumption
    foreach ($items as &$item) {
        $item['report_date'] = date('M j, Y', strtotime($item['report_date']));
        $item['match_count'] = (int)$item['match_count'];
        $item['best_match_score'] = $item['best_match_score'] ? (int)$item['best_match_score'] : 0;
        
        // Format hold expiry date if it exists
        if ($item['hold_expiry']) {
            $item['hold_expiry_formatted'] = date('M j, Y', strtotime($item['hold_expiry']));
            $item['days_until_expiry'] = max(0, (strtotime($item['hold_expiry']) - time()) / (24 * 60 * 60));
            $item['is_expired'] = strtotime($item['hold_expiry']) < time();
            $item['expiry_status'] = $item['is_expired'] ? 'expired' : 
                                    ($item['days_until_expiry'] <= 7 ? 'expiring_soon' : 'active');
        } else {
            $item['hold_expiry_formatted'] = null;
            $item['days_until_expiry'] = null;
            $item['is_expired'] = false;
            $item['expiry_status'] = 'not_applicable';
        }
        
        // Add status-specific styling class
        $item['status_class'] = 'status-' . $item['status'];
        
        // Add match status information
        $item['match_status'] = $item['match_status'] ?? 'pending';
        $item['match_status_info'] = getMatchStatusInfo($item['match_status']);
        
        // Format image path - handle both cases
        if ($item['item_image']) {
            // If image path already contains 'uploads/', use it as is
            if (strpos($item['item_image'], 'uploads/') !== false) {
                $item['item_image'] = '../' . $item['item_image'];
            } else {
                $item['item_image'] = '../uploads/' . $item['item_image'];
            }
        } else {
            $item['item_image'] = null;
        }
    }
    
    echo json_encode(['success' => true, 'items' => $items]);
}

// Function to get match status styling information
function getMatchStatusInfo($status) {
    switch($status) {
        case 'processing':
            return ['class' => 'bg-blue-100 text-blue-800', 'label' => 'Processing', 'icon' => 'âš™ï¸'];
        case 'claimed':
            return ['class' => 'bg-green-100 text-green-800', 'label' => 'Claimed', 'icon' => 'âœ…'];
        case 'rejected':
            return ['class' => 'bg-red-100 text-red-800', 'label' => 'Rejected', 'icon' => 'âŒ'];
        default:
            return ['class' => 'bg-gray-100 text-gray-600', 'label' => 'Pending', 'icon' => 'â³'];
    }
}

// Function to get a specific item
function getItem($pdo, $item_id, $user_id) {
    $sql = "SELECT * FROM items WHERE item_id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$item_id, $user_id]);
    $item = $stmt->fetch();
    
    if ($item) {
        // Format image path - handle both cases
        if ($item['item_image']) {
            // If image path already contains 'uploads/', use it as is
            if (strpos($item['item_image'], 'uploads/') !== false) {
                $item['item_image'] = '../' . $item['item_image'];
            } else {
                $item['item_image'] = '../uploads/' . $item['item_image'];
            }
        } else {
            $item['item_image'] = null;
        }
        echo json_encode(['success' => true, 'item' => $item]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Item not found']);
    }
}

// Function to get matches for an item
function getItemMatches($pdo, $item_id, $user_id) {
    // First verify the item belongs to the user
    $verify_sql = "SELECT status FROM items WHERE item_id = ? AND user_id = ?";
    $verify_stmt = $pdo->prepare($verify_sql);
    $verify_stmt->execute([$item_id, $user_id]);
    $item = $verify_stmt->fetch();
    
    if (!$item) {
        http_response_code(404);
        echo json_encode(['error' => 'Item not found']);
        return;
    }
    
    if ($item['status'] === 'lost') {
        // Get found items that match this lost item
        $sql = "SELECT 
                    im.match_id,
                    im.similarity_score,
                    im.matched_at,
                    i.item_id,
                    i.title,
                    i.description,
                    i.category,
                    i.location,
                    i.item_image,
                    i.report_date,
                    u.full_name as finder_name,
                    u.email as finder_email
                FROM itemmatches im
                JOIN items i ON im.found_item_id = i.item_id
                JOIN users u ON i.user_id = u.user_id
                WHERE im.lost_item_id = ?
                ORDER BY im.similarity_score DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$item_id]);
    } else {
        // Get lost items that match this found item
        $sql = "SELECT 
                    im.match_id,
                    im.similarity_score,
                    im.matched_at,
                    i.item_id,
                    i.title,
                    i.description,
                    i.category,
                    i.location,
                    i.item_image,
                    i.report_date,
                    u.full_name as owner_name,
                    u.email as owner_email
                FROM itemmatches im
                JOIN items i ON im.lost_item_id = i.item_id
                JOIN users u ON i.user_id = u.user_id
                WHERE im.found_item_id = ?
                ORDER BY im.similarity_score DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$item_id]);
    }
    
    $matches = $stmt->fetchAll();
    
    // Format the data
    foreach ($matches as &$match) {
        $match['matched_at'] = date('M j, Y', strtotime($match['matched_at']));
        $match['report_date'] = date('M j, Y', strtotime($match['report_date']));
        // Format image path - handle both cases
        if ($match['item_image']) {
            // If image path already contains 'uploads/', use it as is
            if (strpos($match['item_image'], 'uploads/') !== false) {
                $match['item_image'] = '../' . $match['item_image'];
            } else {
                $match['item_image'] = '../uploads/' . $match['item_image'];
            }
        } else {
            $match['item_image'] = null;
        }
    }
    
    echo json_encode(['success' => true, 'matches' => $matches]);
}

// Function to add a new item
function addItem($pdo, $user_id) {
    // Log request for debugging
    error_log('AddItem called for user: ' . $user_id);
    
    // Check if it's a form submission (multipart/form-data) or JSON
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    error_log('Content-Type: ' . $contentType);
    
    if (strpos($contentType, 'multipart/form-data') !== false) {
        // Handle form data with file upload
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $item_image = null;
        
        error_log('Form data received - Title: ' . $title . ', Category: ' . $category . ', Status: ' . $status);
        
        // Handle image upload if provided
        if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
            error_log('Image upload detected, processing...');
            $item_image = handleImageUpload($_FILES['item_image']);
            if ($item_image === false) {
                error_log('Image upload failed');
                http_response_code(400);
                echo json_encode(['error' => 'Failed to upload image']);
                return;
            }
            error_log('Image uploaded successfully: ' . $item_image);
        } elseif (isset($_FILES['item_image']) && $_FILES['item_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            error_log('File upload error: ' . $_FILES['item_image']['error']);
        }
    } else {
        // Handle JSON data (for backward compatibility)
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $category = trim($input['category'] ?? '');
        $location = trim($input['location'] ?? '');
        $status = trim($input['status'] ?? '');
        $item_image = $input['item_image'] ?? null;
        
        error_log('JSON data received - Title: ' . $title . ', Category: ' . $category . ', Status: ' . $status);
    }
    
    // Validate required fields
    if (empty($title) || empty($status) || empty($category) || empty($location)) {
        error_log('Validation failed - Title: ' . $title . ', Status: ' . $status . ', Category: ' . $category . ', Location: ' . $location);
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing required fields: title, status, category, and location are required',
            'received' => [
                'title' => $title ?: 'empty',
                'status' => $status ?: 'empty', 
                'category' => $category ?: 'empty',
                'location' => $location ?: 'empty'
            ]
        ]);
        return;
    }
    
    // Calculate hold expiry date (60 days from now for found items)
    $hold_expiry = ($status === 'found') ? date('Y-m-d', strtotime('+60 days')) : null;
    
        $sql = "INSERT INTO items (user_id, title, description, category, location, status, item_image, hold_expiry) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        $user_id,
        $title,
        $description,
        $category,
        $location,
        $status,
        $item_image,
        $hold_expiry
    ]);
    
    if ($result) {
        $item_id = $pdo->lastInsertId();
        
        // Trigger smart matching system for new items
        $matchResult = triggerAutoMatching($pdo);
        
        $response = [
            'success' => true, 
            'item_id' => $item_id, 
            'message' => 'Item added successfully'
        ];
        
        // Add matching information to response
        if ($matchResult['success'] && $matchResult['new_matches'] > 0) {
            $response['matches_found'] = $matchResult['new_matches'];
            $response['message'] .= ". Smart matching found {$matchResult['new_matches']} potential match" . 
                                   ($matchResult['new_matches'] > 1 ? 'es' : '') . "!";
        } elseif ($matchResult['success']) {
            $response['matches_found'] = 0;
            $response['message'] .= '. Smart matching is active - matches will appear as more items are reported.';
        }
        
        echo json_encode($response);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add item']);
    }
}

// Function to update an item
function updateItem($pdo, $item_id, $user_id) {
    // Check if it's a form submission (multipart/form-data) or JSON
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'multipart/form-data') !== false) {
        // Handle form data with potential file upload
        $updates = [];
        $params = [];
        
        if (!empty($_POST['title'])) {
            $updates[] = "title = ?";
            $params[] = $_POST['title'];
        }
        if (isset($_POST['description'])) {
            $updates[] = "description = ?";
            $params[] = $_POST['description'];
        }
        if (!empty($_POST['category'])) {
            $updates[] = "category = ?";
            $params[] = $_POST['category'];
        }
        if (isset($_POST['location'])) {
            $updates[] = "location = ?";
            $params[] = trim($_POST['location']);
        }
        if (!empty($_POST['status'])) {
            $updates[] = "status = ?";
            $params[] = $_POST['status'];
        }
        
        // Handle image upload if provided
        if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
            $item_image = handleImageUpload($_FILES['item_image']);
            if ($item_image === false) {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to upload image']);
                return;
            }
            
            // Delete old image if exists
            $old_image_sql = "SELECT item_image FROM items WHERE item_id = ? AND user_id = ?";
            $old_image_stmt = $pdo->prepare($old_image_sql);
            $old_image_stmt->execute([$item_id, $user_id]);
            $old_item = $old_image_stmt->fetch();
            if ($old_item && $old_item['item_image'] && file_exists('../../uploads/' . $old_item['item_image'])) {
                unlink('../../uploads/' . $old_item['item_image']);
            }
            
            $updates[] = "item_image = ?";
            $params[] = $item_image;
        }
    } else {
        // Handle JSON data (for backward compatibility)
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Build dynamic update query
        $updates = [];
        $params = [];
        
        if (isset($input['title'])) {
            $updates[] = "title = ?";
            $params[] = $input['title'];
        }
        if (isset($input['description'])) {
            $updates[] = "description = ?";
            $params[] = $input['description'];
        }
        if (isset($input['category'])) {
            $updates[] = "category = ?";
            $params[] = $input['category'];
        }
        if (isset($input['location'])) {
            $updates[] = "location = ?";
            $params[] = trim($input['location']);
        }
        if (isset($input['status'])) {
            $updates[] = "status = ?";
            $params[] = $input['status'];
        }
        if (isset($input['item_image'])) {
            $updates[] = "item_image = ?";
            $params[] = $input['item_image'];
        }
    }
    
    // Verify the item belongs to the user
    $verify_sql = "SELECT item_id FROM items WHERE item_id = ? AND user_id = ?";
    $verify_stmt = $pdo->prepare($verify_sql);
    $verify_stmt->execute([$item_id, $user_id]);
    
    if (!$verify_stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Item not found']);
        return;
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }
    
    $params[] = $item_id;
    $params[] = $user_id;
    
    $sql = "UPDATE items SET " . implode(', ', $updates) . " WHERE item_id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);
    
    if ($result) {
        // Trigger smart matching system after item update
        $matchResult = triggerAutoMatching($pdo);
        
        $response = ['success' => true, 'message' => 'Item updated successfully'];
        
        // Add matching information to response
        if ($matchResult['success'] && $matchResult['new_matches'] > 0) {
            $response['matches_found'] = $matchResult['new_matches'];
            $response['message'] .= ". Smart matching found {$matchResult['new_matches']} new potential match" . 
                                   ($matchResult['new_matches'] > 1 ? 'es' : '') . "!";
        }
        
        echo json_encode($response);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update item']);
    }
}

// Function to delete an item
function deleteItem($pdo, $item_id, $user_id) {
    // Verify the item belongs to the user
    $verify_sql = "SELECT item_image FROM items WHERE item_id = ? AND user_id = ?";
    $verify_stmt = $pdo->prepare($verify_sql);
    $verify_stmt->execute([$item_id, $user_id]);
    $item = $verify_stmt->fetch();
    
    if (!$item) {
        http_response_code(404);
        echo json_encode(['error' => 'Item not found']);
        return;
    }
    
    // Delete the item
    $sql = "DELETE FROM items WHERE item_id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([$item_id, $user_id]);
    
    if ($result) {
        // Delete the image file if it exists
        if ($item['item_image'] && file_exists('../../uploads/' . $item['item_image'])) {
            unlink('../../uploads/' . $item['item_image']);
        }
        
        echo json_encode(['success' => true, 'message' => 'Item deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete item']);
    }
}

// Function to handle image upload
function handleImageUpload($file) {
    $uploadDir = '../../uploads/';
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB

    try {
        // Create uploads directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }

        $fileName = $file['name'];
        $fileTmpName = $file['tmp_name'];
        $fileSize = $file['size'];
        $fileType = $file['type'];

        // Validate file type
        if (!in_array($fileType, $allowedTypes)) {
            throw new Exception('Invalid file type. Only JPEG, PNG, and GIF are allowed.');
        }

        // Validate file size
        if ($fileSize > $maxFileSize) {
            throw new Exception('File size too large. Maximum size is 5MB.');
        }

        // Generate unique filename to prevent conflicts
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
        $uniqueFileName = uniqid('item_') . '_' . time() . '.' . $fileExtension;
        $targetPath = $uploadDir . $uniqueFileName;

        // Additional security: verify it's actually an image
        $imageInfo = getimagesize($fileTmpName);
        if ($imageInfo === false) {
            throw new Exception('Invalid image file.');
        }

        // Move uploaded file to target directory
        if (move_uploaded_file($fileTmpName, $targetPath)) {
            return $uniqueFileName;
        } else {
            throw new Exception('Failed to move uploaded file');
        }

    } catch (Exception $e) {
        error_log('Image upload error: ' . $e->getMessage());
        return false;
    }
}

// Function to trigger the AutoMatchItems stored procedure
function triggerAutoMatching($pdo) {
    try {
        // Get count of items before matching
        $beforeStmt = $pdo->query("SELECT COUNT(*) as before_count FROM itemmatches");
        $beforeCount = $beforeStmt->fetch()['before_count'];
        
        // Call the stored procedure for automatic item matching
        $stmt = $pdo->prepare("CALL AutoMatchItems()");
        $stmt->execute();
        
        // Get count of items after matching
        $afterStmt = $pdo->query("SELECT COUNT(*) as after_count FROM itemmatches");
        $afterCount = $afterStmt->fetch()['after_count'];
        
        $newMatches = $afterCount - $beforeCount;
        
        // Log successful matching trigger with details
        error_log("AutoMatchItems procedure executed successfully. New matches created: $newMatches");
        
        return ['success' => true, 'new_matches' => $newMatches];
    } catch (PDOException $e) {
        // Log error but don't fail the main operation
        error_log('AutoMatchItems procedure failed: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>

