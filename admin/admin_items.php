<?php
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole('admin');

$current_page = basename($_SERVER['PHP_SELF']);
$user = getUserInfo();

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

// Get active tab first (before handling POST requests)
$active_tab = $_GET['tab'] ?? 'found'; // Default to 'found' tab
$allowed_tabs = ['lost', 'found'];
$active_tab = in_array($active_tab, $allowed_tabs) ? $active_tab : 'found';

// Handle item actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_item':
                $title = $_POST['title'];
                $description = $_POST['description'] ?? null;
                $location = $_POST['location'] ?? null;
                $category = $_POST['category'];
                $status = $active_tab; // Use the current active tab (lost or found)
                $finder_name = isset($_POST['finder_name']) ? trim($_POST['finder_name']) : null;
                $finder_phone = isset($_POST['finder_phone']) ? trim($_POST['finder_phone']) : null;
                $item_image = null;
                
                // Handle image upload if provided
                if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
                    $item_image = handleImageUpload($_FILES['item_image']);
                    if ($item_image === false) {
                        $error = "Failed to upload image";
                        break;
                    }
                }
                
                // Use admin user ID (assuming admin has user_id = 1 or get from session)
                $admin_user_id = $user['user_id']; // Get from auth_check.php
                
                // Calculate hold expiry date (30 days from now for found items)
                $hold_expiry = ($status === 'found') ? date('Y-m-d', strtotime('+30 days')) : null;
                
                $stmt = $pdo->prepare("INSERT INTO items (user_id, title, description, location, category, status, item_image, hold_expiry, finder_name, finder_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $result = $stmt->execute([$admin_user_id, $title, $description, $location, $category, $status, $item_image, $hold_expiry, $finder_name, $finder_phone]);
                
                if ($result) {
                    // If finder_name is provided and status is 'found', add points to finder_leaderboard
                    if ($status === 'found' && !empty($finder_name)) {
                        try {
                            // Check if finder already exists in leaderboard
                            $check_stmt = $pdo->prepare("SELECT id, total_points FROM finder_leaderboard WHERE finder_name = ?");
                            $check_stmt->execute([$finder_name]);
                            $finder = $check_stmt->fetch();
                            
                            if ($finder) {
                                // Update existing finder's points
                                $new_points = $finder['total_points'] + 10; // 10 points for finding an item
                                $update_stmt = $pdo->prepare("UPDATE finder_leaderboard SET total_points = ? WHERE id = ?");
                                $update_stmt->execute([$new_points, $finder['id']]);
                            } else {
                                // Insert new finder with initial points
                                $insert_stmt = $pdo->prepare("INSERT INTO finder_leaderboard (finder_name, total_points) VALUES (?, 10)");
                                $insert_stmt->execute([$finder_name]);
                            }
                        } catch (PDOException $e) {
                            error_log("Failed to update finder leaderboard: " . $e->getMessage());
                        }
                    }
                    
                    $message = ucfirst($active_tab) . " item added successfully!" . 
                               (!empty($finder_name) ? " Finder '$finder_name' credited with points." : "");
                } else {
                    $error = "Failed to add item";
                }
                break;
                
            case 'update_item':
                $item_id = $_POST['item_id'];
                $title = $_POST['title'];
                $description = $_POST['description'] ?? null;
                $location = $_POST['location'] ?? null;
                $category = $_POST['category'];
                $status = $_POST['status'];
                
                // Handle image upload if provided
                $new_image = null;
                if (isset($_FILES['edit_item_image']) && $_FILES['edit_item_image']['error'] === UPLOAD_ERR_OK) {
                    $new_image = handleImageUpload($_FILES['edit_item_image']);
                    if ($new_image) {
                        // Delete old image if exists
                        $stmt = $pdo->prepare("SELECT item_image FROM items WHERE item_id = ?");
                        $stmt->execute([$item_id]);
                        $old_image = $stmt->fetch()['item_image'];
                        if ($old_image && file_exists('../uploads/' . $old_image)) {
                            unlink('../uploads/' . $old_image);
                        }
                        
                        $stmt = $pdo->prepare("UPDATE items SET title = ?, description = ?, location = ?, category = ?, status = ?, item_image = ? WHERE item_id = ?");
                        $stmt->execute([$title, $description, $location, $category, $status, $new_image, $item_id]);
                    } else {
                        $error = "Failed to upload image. Updating other fields only.";
                        $stmt = $pdo->prepare("UPDATE items SET title = ?, description = ?, location = ?, category = ?, status = ? WHERE item_id = ?");
                        $stmt->execute([$title, $description, $location, $category, $status, $item_id]);
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE items SET title = ?, description = ?, location = ?, category = ?, status = ? WHERE item_id = ?");
                    $stmt->execute([$title, $description, $location, $category, $status, $item_id]);
                }
                $message = "Item updated successfully!";
                break;
                
            case 'delete_item':
                $item_id = $_POST['item_id'];
                $stmt = $pdo->prepare("DELETE FROM items WHERE item_id = ?");
                $stmt->execute([$item_id]);
                $message = "Item deleted successfully!";
                break;
        }
    }
}

// Get sorting parameters
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

// Build WHERE clause - filter by active tab first
$where_conditions = ["status = ?"];
$params = [$active_tab];

if ($search) {
    $where_conditions[] = "(title LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($category_filter && $category_filter !== 'all') {
    $where_conditions[] = "category = ?";
    $params[] = $category_filter;
}

// Remove the status filter since we're filtering by tab
// if ($status_filter && $status_filter !== 'all') {
//     $where_conditions[] = "status = ?";
//     $params[] = $status_filter;
// }

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for current tab
$count_sql = "SELECT COUNT(*) as total FROM items $where_clause";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_items = $count_stmt->fetch()['total'];
$total_pages = ceil($total_items / $per_page);

// Get counts for both tabs
try {
    $count_sql = "SELECT status, COUNT(*) as count FROM items WHERE 1=1";
    $count_params = [];
    
    if (!empty($search)) {
        $count_sql .= " AND (title LIKE ? OR description LIKE ?)";
        $search_param = "%$search%";
        $count_params[] = $search_param;
        $count_params[] = $search_param;
    }
    
    if (!empty($category_filter)) {
        $count_sql .= " AND category = ?";
        $count_params[] = $category_filter;
    }
    
    $count_sql .= " AND status IN ('lost', 'found') GROUP BY status";
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $counts = $count_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $lost_count = $counts['lost'] ?? 0;
    $found_count = $counts['found'] ?? 0;
} catch (PDOException $e) {
    $lost_count = 0;
    $found_count = 0;
}

// Get items for current page
$sql = "SELECT i.*, u.full_name FROM items i 
        JOIN users u ON i.user_id = u.user_id 
        $where_clause 
        ORDER BY i.report_date DESC 
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Get categories for filter
$cat_stmt = $pdo->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL ORDER BY category");
$categories = $cat_stmt->fetchAll();

// Function to handle image upload
function handleImageUpload($file) {
    $uploadDir = '../uploads/';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Manage Items</title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        .gradient-bg { background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 42%, #f23c00 100%); }
        .gradient-button { background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 42%, #f23c00 100%); transition: all 0.3s ease; }
        .gradient-button:hover { background: linear-gradient(132deg, #ff8f4a 0%, #ff6a2b 42%, #ff4b1f 100%); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(255, 91, 31, 0.3); }
        .status-lost { background: linear-gradient(135deg, #4b5563 0%, #1f2937 100%); }
        .status-found { background: linear-gradient(135deg, #ff8f4a 0%, #ff5b1f 100%); }
        .status-claimed { background: linear-gradient(135deg, #d1d5db 0%, #9ca3af 100%); color: #111827; }
        .status-unclaimed { background: linear-gradient(135deg, #ffb47a 0%, #ff7b2c 100%); color: #111827; }
        .status-redistributed { background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%); }
        .card-hover { transition: all 0.3s ease; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); }
        .message { padding: 1rem; margin: 1rem 0; border-radius: 8px; }
        .message.success { background: #2a1a12; color: #ffd8c2; border: 1px solid #ff7b2c; }
        .message.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        body.admin-theme .focus\:ring-green-500:focus {
            --tw-ring-color: rgba(255, 123, 44, 0.35) !important;
        }

        body.admin-theme .text-green-500,
        body.admin-theme .text-green-600,
        body.admin-theme .border-green-500,
        body.admin-theme .hover\:border-green-400:hover,
        body.admin-theme .focus\:border-green-500:focus {
            color: #ff9b68 !important;
            border-color: #ff7b2c !important;
        }

        body.admin-theme .text-blue-700,
        body.admin-theme .text-blue-800,
        body.admin-theme .text-blue-600 {
            color: #ffbe95 !important;
        }

        body.admin-theme .hover\:bg-blue-50:hover,
        body.admin-theme .bg-blue-50 {
            background-color: #22150e !important;
            border-color: #6c3418 !important;
        }

        .content-only-modal {
            position: fixed;
            top: 0;
            left: 280px;
            width: calc(100% - 280px);
            height: 100vh;
            z-index: 1400;
        }

        .image-modal-panel {
            background: linear-gradient(160deg, #131922 0%, #0f151e 100%);
            border: 1px solid #2a3444;
            box-shadow: 0 24px 42px rgba(0, 0, 0, 0.5);
        }

        .items-table-shell {
            overflow: visible;
        }

        .items-table-scroll {
            position: relative;
            overflow: visible;
            z-index: 1;
            isolation: isolate;
        }

        .action-dropdown-container {
            position: relative;
            z-index: auto;
        }

        .action-dropdown-container.dropdown-open {
            z-index: 2200;
        }

        .items-action-dropdown {
            z-index: 1200 !important;
        }

        @media (max-width: 1100px) {
            .items-table-scroll {
                overflow-x: auto;
                overflow-y: visible;
            }

            .items-table-scroll table {
                min-width: 1050px;
            }
        }

        @media (max-width: 768px) {
            .content-only-modal {
                left: 0;
                width: 100%;
            }
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
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-3xl font-bold text-gray-800 mb-2">Item Management</h2>
                            <p class="text-gray-600">Track and manage all lost and found items</p>
                        </div>
                        <button onclick="openAddItemModal()" class="gradient-button text-white px-6 py-3 rounded-lg font-semibold hover:opacity-90">
                            + Add <?php echo $active_tab === 'found' ? 'Found' : 'Lost'; ?> Item
                        </button>
                    </div>
                </div>

                <!-- Messages -->
                <?php if (isset($message)): ?>
                    <div class="message success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                    <div class="message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Search and Filter Bar -->
                <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                    <form method="GET" class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                        <div class="flex flex-col sm:flex-row gap-4 flex-1">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Search items..." 
                                   class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none flex-1 min-w-0">
                            <select name="category" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                            <?php echo $category_filter === $cat['category'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="px-4 py-2 gradient-button text-white rounded-lg font-medium">Search</button>
                        </div>
                    </form>
                </div>

                <!-- Tab Navigation -->
                <div class="mb-8">
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex space-x-8">
                            <a href="?tab=found<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?>" 
                               class="group inline-flex items-center py-2 px-1 border-b-2 font-medium text-sm <?php echo $active_tab === 'found' ? 'border-orange-500 text-orange-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                                <svg class="w-5 h-5 mr-2 <?php echo $active_tab === 'found' ? 'text-orange-400' : 'text-gray-400 group-hover:text-gray-500'; ?>" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                Found Items
                                <span class="ml-2 bg-gray-100 text-gray-900 py-0.5 px-2.5 rounded-full text-xs font-medium"><?php echo $found_count; ?></span>
                            </a>
                            <a href="?tab=lost<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?>" 
                               class="group inline-flex items-center py-2 px-1 border-b-2 font-medium text-sm <?php echo $active_tab === 'lost' ? 'border-orange-500 text-orange-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                                <svg class="w-5 h-5 mr-2 <?php echo $active_tab === 'lost' ? 'text-orange-400' : 'text-gray-400 group-hover:text-gray-500'; ?>" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                Lost Items
                                <span class="ml-2 bg-gray-100 text-gray-900 py-0.5 px-2.5 rounded-full text-xs font-medium"><?php echo $lost_count; ?></span>
                            </a>
                        </nav>
                    </div>
                </div>

                <!-- Results Summary -->
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <p class="text-gray-600">
                            <?php 
                            $total_items = count($items);
                            $tab_label = $active_tab === 'found' ? 'Found' : 'Lost';
                            echo "Showing $total_items " . ($total_items === 1 ? 'item' : 'items') . " in $tab_label Items";
                            if (!empty($search) || !empty($category_filter)) {
                                echo " (filtered)";
                            }
                            ?>
                        </p>
                        <div class="flex items-center space-x-4 text-sm text-gray-500">
                            <span class="flex items-center">
                                <svg class="w-4 h-4 mr-1 text-orange-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                Found: <?php echo $found_count; ?>
                            </span>
                            <span class="flex items-center">
                                <svg class="w-4 h-4 mr-1 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                Lost: <?php echo $lost_count; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="bg-white rounded-xl shadow-md border border-gray-200 items-table-shell mb-8">
                    <div class="items-table-scroll">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Image</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reporter / Finder</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($items)): ?>
                                    <?php foreach ($items as $item): 
                                        $status_class = "status-" . $item['status'];
                                        $time_ago = time() - strtotime($item['report_date']);
                                        if ($time_ago < 86400) {
                                            $time_display = floor($time_ago / 3600) . 'h ago';
                                        } else {
                                            $time_display = floor($time_ago / 86400) . 'd ago';
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <?php if ($item['item_image'] && file_exists('../uploads/' . $item['item_image'])): ?>
                                                <img src="../uploads/<?php echo htmlspecialchars($item['item_image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="w-14 h-14 object-cover rounded-lg border border-gray-200">
                                            <?php else: ?>
                                                <div class="w-14 h-14 rounded-lg bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-400 text-xs">No Img</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($item['title']); ?></div>
                                            <div class="text-xs text-gray-500 mt-1 max-w-xs"><?php echo htmlspecialchars($item['description'] ?: 'No description'); ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($item['category'] ?: 'Other'); ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-700 max-w-xs"><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            <div><span class="font-medium">Reporter:</span> <?php echo htmlspecialchars($item['full_name']); ?></div>
                                            <?php if ($item['status'] === 'found' && !empty($item['finder_name'])): ?>
                                                <div class="mt-1"><span class="font-medium">Finder:</span> <?php echo htmlspecialchars($item['finder_name']); ?></div>
                                                <?php if (!empty($item['finder_phone'])): ?>
                                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars(maskPhoneForDisplay((string)$item['finder_phone'])); ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="<?php echo $status_class; ?> text-white text-xs px-2 py-1 rounded-full whitespace-nowrap"><?php echo ucfirst($item['status']); ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            <div><span class="font-medium">Reported:</span> <?php echo $time_display; ?></div>
                                            <div class="text-xs text-gray-500"><?php echo date('M j, Y h:i A', strtotime($item['report_date'])); ?></div>
                                            <?php if ($item['hold_expiry'] && $item['status'] === 'found'): ?>
                                                <?php 
                                                    $is_expired = strtotime($item['hold_expiry']) < time();
                                                    $days_until_expiry = max(0, (strtotime($item['hold_expiry']) - time()) / (24 * 60 * 60));
                                                    $expiry_status = $is_expired ? 'expired' : ($days_until_expiry <= 7 ? 'expiring_soon' : 'active');
                                                    $expiry_class = $is_expired ? 'text-orange-300 font-medium' : ($expiry_status === 'expiring_soon' ? 'text-orange-200 font-medium' : 'text-gray-300');
                                                ?>
                                                <div class="mt-1 text-xs <?php echo $expiry_class; ?>">
                                                    Hold: <?php echo date('M j, Y', strtotime($item['hold_expiry'])); ?>
                                                    <?php if ($is_expired): ?>(Expired)<?php elseif ($expiry_status === 'expiring_soon'): ?>(Soon)<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="relative inline-block text-left action-dropdown-container">
                                                <button type="button"
                                                        onclick="toggleActionDropdown(<?php echo $item['item_id']; ?>)"
                                                        class="inline-flex items-center justify-center px-3 py-2 border border-gray-300 rounded-lg bg-white text-gray-700 hover:bg-gray-50 text-xs font-medium min-w-[90px]">
                                                    <svg class="h-4 w-4 mr-1.5 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6h.01M12 12h.01M12 18h.01M7 6h.01M7 12h.01M7 18h.01M17 6h.01M17 12h.01M17 18h.01"></path>
                                                    </svg>
                                                    Actions
                                                    <svg class="ml-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                    </svg>
                                                </button>

                                                <div id="actionDropdown-<?php echo $item['item_id']; ?>"
                                                       class="hidden absolute right-0 mt-2 w-44 rounded-lg shadow-lg bg-white border border-gray-200 z-30 overflow-hidden items-action-dropdown">
                                                    <button type="button"
                                                            onclick="editItem(<?php echo $item['item_id']; ?>, '<?php echo htmlspecialchars($item['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['description'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['location'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['category'], ENT_QUOTES); ?>', '<?php echo $item['status']; ?>', '<?php echo htmlspecialchars($item['item_image'] ?? '', ENT_QUOTES); ?>'); closeAllActionDropdowns();"
                                                            class="w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 inline-flex items-center gap-2">
                                                        <svg class="w-4 h-4 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5h2m-7 4h12M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zm1-6l2 2 4-4"></path>
                                                        </svg>
                                                        <span>Edit Item</span>
                                                    </button>
                                                    <button type="button"
                                                            onclick="deleteItem(<?php echo $item['item_id']; ?>, '<?php echo htmlspecialchars($item['title'], ENT_QUOTES); ?>'); closeAllActionDropdowns();"
                                                            class="w-full text-left px-4 py-2.5 text-sm text-red-700 hover:bg-red-50 inline-flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-8 0l1 12h8l1-12"></path>
                                                        </svg>
                                                        <span>Delete Item</span>
                                                    </button>
                                                    <?php if ($item['item_image'] && file_exists('../uploads/' . $item['item_image'])): ?>
                                                    <button type="button"
                                                            onclick="viewImage('<?php echo htmlspecialchars($item['item_image']); ?>', '<?php echo htmlspecialchars($item['title']); ?>'); closeAllActionDropdowns();"
                                                            class="w-full text-left px-4 py-2.5 text-sm text-orange-300 hover:bg-[#21160f] inline-flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.55-2.27a1 1 0 011.45.9v6.74a1 1 0 01-1.45.9L15 14m-10 6h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                                        </svg>
                                                        <span>View Image</span>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="px-6 py-12 text-center">
                                            <?php if ($active_tab === 'found'): ?>
                                                <h3 class="text-sm font-medium text-gray-900">No found items</h3>
                                                <p class="mt-1 text-sm text-gray-500">No items have been found yet. Try adjusting filters or switch to Lost Items.</p>
                                            <?php else: ?>
                                                <h3 class="text-sm font-medium text-gray-900">No lost items</h3>
                                                <p class="mt-1 text-sm text-gray-500">No items have been reported as lost yet. Try adjusting filters or switch to Found Items.</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                    <div class="text-gray-600">
                        Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $per_page, $total_items); ?> of <?php echo $total_items; ?> items
                    </div>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&tab=<?php echo urlencode($active_tab); ?>" 
                               class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Previous</a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&tab=<?php echo urlencode($active_tab); ?>" 
                               class="px-4 py-2 rounded-lg <?php echo $i == $page ? 'gradient-button text-white' : 'border border-gray-300 hover:bg-gray-50'; ?> transition-colors">
                               <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&tab=<?php echo urlencode($active_tab); ?>" 
                               class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Item Modal -->
    <div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-2xl p-8 max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
            <h3 class="text-2xl font-bold text-gray-800 mb-2">Edit Item</h3>
            <p class="text-sm text-gray-600 mb-6">Use this two-panel editor to update details and image in one place.</p>
            <form id="editForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="item_id" id="editItemId">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="space-y-4 bg-gray-50 border border-gray-200 rounded-xl p-5">
                        <h4 class="text-lg font-semibold text-gray-800 border-b pb-2">Item Information</h4>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Title</label>
                            <input type="text" name="title" id="editTitle" required 
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Location</label>
                            <input type="text" name="location" id="editLocation" 
                                   placeholder="Where was this item found/lost?"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Description</label>
                            <textarea name="description" id="editDescription" rows="4"
                                      placeholder="Additional details about the item..."
                                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none"></textarea>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Category</label>
                            <input type="text" name="category" id="editCategory" 
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Status</label>
                            <select name="status" id="editStatus" required 
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                                <option value="lost">Lost</option>
                                <option value="found">Found</option>
                                <option value="claimed">Claimed</option>
                                <option value="unclaimed">Unclaimed</option>
                                <option value="redistributed">Redistributed</option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-4 bg-white border border-gray-200 rounded-xl p-5">
                        <h4 class="text-lg font-semibold text-gray-800 border-b pb-2">Image Management</h4>
                        <div id="editImagePreview" class="hidden">
                            <label class="block text-gray-700 font-medium mb-2">Current Image</label>
                            <div class="border border-gray-300 rounded-lg p-4 text-center bg-gray-50">
                                <img id="editCurrentImage" src="" alt="Current Image" class="max-w-full max-h-48 mx-auto rounded-lg">
                                <p class="text-sm text-gray-500 mt-2">Current item image</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Upload New Image (Optional)</label>
                            <input type="file" name="edit_item_image" id="editItemImage" accept="image/*" 
                                   onchange="handleEditImagePreview(event)"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                            <p class="text-xs text-gray-500 mt-1">Leave empty to keep current image</p>
                        </div>

                        <div id="editNewImagePreview" class="hidden">
                            <label class="block text-gray-700 font-medium mb-2">New Image Preview</label>
                            <div class="relative border border-gray-300 rounded-lg p-4 text-center bg-gray-50">
                                <img id="editNewImagePreviewImg" src="" alt="New Image Preview" class="max-w-full max-h-48 mx-auto rounded-lg">
                                <button type="button" onclick="removeEditImagePreview()" 
                                        class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1 hover:bg-red-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex gap-4 mt-8">
                    <button type="button" onclick="closeEditModal()" 
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
                    <button type="submit" 
                            class="flex-1 gradient-button text-white px-4 py-2 rounded-lg font-medium">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Item Modal -->
    <div id="addItemModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-2xl p-8 max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
            <h3 class="text-2xl font-bold text-gray-800 mb-2">Add <?php echo $active_tab === 'found' ? 'Found' : 'Lost'; ?> Item</h3>
            <p class="text-sm text-gray-600 mb-6">Use the two-panel form for faster admin entry and better data quality.</p>
            <form id="addItemForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_item">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    <!-- Left Column - Core Item Details -->
                    <div class="space-y-4 bg-gray-50 border border-gray-200 rounded-xl p-5">
                        <h4 class="text-lg font-semibold text-gray-800 border-b pb-2">Item Details</h4>
                        
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">
                                Item Title <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="title" required maxlength="50"
                                   placeholder="e.g., Blue Backpack, iPhone 13, etc."
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                            <p class="text-xs text-gray-500 mt-1">Maximum 50 characters</p>
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">
                                Location <span class="text-gray-500">(Optional)</span>
                            </label>
                            <input type="text" name="location" maxlength="255"
                                   placeholder="Where was this item found/lost? e.g., Library 3rd Floor, Cafeteria"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                            <p class="text-xs text-gray-500 mt-1">Maximum 255 characters</p>
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">
                                Description <span class="text-gray-500">(Optional)</span>
                            </label>
                            <textarea name="description" rows="3" maxlength="100"
                                      placeholder="Additional details about the item..."
                                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none resize-none"></textarea>
                            <p class="text-xs text-gray-500 mt-1">Maximum 100 characters</p>
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">
                                Category <span class="text-red-500">*</span>
                            </label>
                            <select name="category" required onchange="handleCategoryChange()"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                                <option value="">Select Category</option>
                                <option value="Electronics">Electronics</option>
                                <option value="Clothing">Clothing & Accessories</option>
                                <option value="Books">Books & Documents</option>
                                <option value="Bags">Bags & Luggage</option>
                                <option value="Jewelry">Jewelry & Watches</option>
                                <option value="Sports Equipment">Sports & Recreation</option>
                                <option value="Personal Items">Personal Items</option>
                                <option value="Keys">Keys & Cards</option>
                                <option value="Other">Other (Specify)</option>
                            </select>
                        </div>
                        
                        <!-- Custom Category Input (Hidden by default) -->
                        <div id="customCategoryDiv" class="hidden">
                            <label class="block text-gray-700 font-medium mb-2">
                                Specify Category <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="customCategory" name="custom_category" maxlength="20"
                                   placeholder="Enter custom category"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                            <p class="text-xs text-gray-500 mt-1">Maximum 20 characters</p>
                        </div>
                        
                    </div>
                    
                    <!-- Right Column - Finder + Image -->
                    <div class="space-y-4 bg-white border border-gray-200 rounded-xl p-5">
                        <h4 class="text-lg font-semibold text-gray-800 border-b pb-2"><?php echo $active_tab === 'found' ? 'Finder & Image Details' : 'Image Details'; ?></h4>

                        <?php if ($active_tab === 'found'): ?>
                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label class="block text-gray-700 font-medium mb-2">
                                    Finder Name <span class="text-gray-500">(Optional)</span>
                                </label>
                                <input type="text" name="finder_name" maxlength="100"
                                       placeholder="Name of person who found this item"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                                <p class="text-xs text-gray-500 mt-1">Credits finder points in leaderboard when provided.</p>
                            </div>

                            <div>
                                <label class="block text-gray-700 font-medium mb-2">
                                    Finder Phone <span class="text-gray-500">(Optional)</span>
                                </label>
                                <input type="tel" name="finder_phone" maxlength="15"
                                       placeholder="Phone number of the finder"
                                       pattern="[0-9]{10,15}"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none">
                                <p class="text-xs text-gray-500 mt-1">Optional contact number (10-15 digits).</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Upload Image</label>
                            <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-green-400 transition-colors">
                                <input type="file" id="item_image" name="item_image" accept="image/jpeg,image/jpg,image/png,image/gif"
                                       class="hidden" onchange="handleImagePreview(event)">
                                <div id="uploadArea" onclick="document.getElementById('item_image').click()" class="cursor-pointer">
                                    <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    <p class="text-gray-600 mb-2">Click to upload an image</p>
                                    <p class="text-xs text-gray-500">JPEG, PNG, GIF up to 5MB</p>
                                </div>
                            </div>
                            
                            <!-- Image Preview -->
                            <div id="imagePreview" class="mt-4 hidden">
                                <div class="relative">
                                    <img id="previewImg" src="" alt="Preview" class="w-full h-48 object-cover rounded-lg border">
                                    <button type="button" onclick="removeImagePreview()" 
                                            class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-2 hover:bg-red-600 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                                <p class="text-sm text-gray-600 mt-2 text-center">Image preview</p>
                            </div>
                        </div>
                        
                        <!-- Upload Guidelines -->
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <h5 class="text-sm font-semibold text-blue-800 mb-2">Image Guidelines</h5>
                            <ul class="text-xs text-blue-700 space-y-1">
                                <li>• Use clear, high-quality images</li>
                                <li>• Show the item from multiple angles if possible</li>
                                <li>• Ensure good lighting for better visibility</li>
                                <li>• Avoid blurry or dark images</li>
                                <li>• File size must be under 5MB</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="flex gap-4 mt-8 pt-6 border-t">
                    <button type="button" onclick="closeAddItemModal()" 
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
                    <button type="submit" 
                            class="flex-1 gradient-button text-white px-4 py-2 rounded-lg font-medium">Add <?php echo $active_tab === 'found' ? 'Found' : 'Lost'; ?> Item</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-2xl p-8 max-w-md w-full mx-4">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">Delete Item</h3>
            <p class="text-gray-600 mb-6">Are you sure you want to delete "<span id="deleteItemName" class="font-semibold"></span>"? This action cannot be undone.</p>
            <form id="deleteForm" method="POST">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="item_id" id="deleteItemId">
                <div class="flex gap-4">
                    <button type="button" onclick="closeDeleteModal()" 
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
                    <button type="submit" 
                            class="flex-1 bg-red-500 text-white px-4 py-2 rounded-lg font-medium hover:bg-red-600 transition-colors">Delete Item</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Image View Modal -->
    <div id="imageViewModal" class="content-only-modal bg-black bg-opacity-75 hidden items-center justify-center">
        <div class="image-modal-panel rounded-xl max-w-5xl w-full mx-4 overflow-hidden" style="max-height: 88vh;">
            <div class="flex justify-between items-center p-6 border-b border-gray-200">
                <h3 id="imageViewTitle" class="text-xl font-bold text-white">Item Image</h3>
                <button onclick="closeImageViewModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-6 text-center">
                <img id="fullImageView" src="" alt="Full Image" class="max-w-full mx-auto rounded-lg shadow-lg border border-gray-700" style="max-height: 72vh; object-fit: contain;">
            </div>
        </div>
    </div>

    <script>
        // Add Item Modal Functions
        function openAddItemModal() {
            document.getElementById('addItemModal').classList.remove('hidden');
            document.getElementById('addItemModal').classList.add('flex');
            document.getElementById('addItemForm').reset();
            document.getElementById('imagePreview').classList.add('hidden');
            document.getElementById('uploadArea').classList.remove('hidden');
            document.getElementById('customCategoryDiv').classList.add('hidden');
            document.getElementById('customCategory').required = false;
        }

        function closeAddItemModal() {
            document.getElementById('addItemModal').classList.add('hidden');
            document.getElementById('addItemModal').classList.remove('flex');
            document.getElementById('imagePreview').classList.add('hidden');
            document.getElementById('uploadArea').classList.remove('hidden');
            document.getElementById('customCategoryDiv').classList.add('hidden');
            document.getElementById('customCategory').required = false;
        }

        // Handle category dropdown change
        function handleCategoryChange() {
            const categorySelect = document.querySelector('select[name="category"]');
            const customCategoryDiv = document.getElementById('customCategoryDiv');
            const customCategoryInput = document.getElementById('customCategory');
            
            if (categorySelect.value === 'Other') {
                customCategoryDiv.classList.remove('hidden');
                customCategoryInput.required = true;
                customCategoryInput.focus();
            } else {
                customCategoryDiv.classList.add('hidden');
                customCategoryInput.required = false;
                customCategoryInput.value = '';
            }
        }

        // Handle image preview
        function handleImagePreview(event) {
            const file = event.target.files[0];
            const previewContainer = document.getElementById('imagePreview');
            const previewImg = document.getElementById('previewImg');
            const uploadArea = document.getElementById('uploadArea');
            
            if (file) {
                // Validate file type
                if (!file.type.startsWith('image/')) {
                    alert('Please select an image file');
                    event.target.value = '';
                    return;
                }
                
                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB');
                    event.target.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewContainer.classList.remove('hidden');
                    uploadArea.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            } else {
                previewContainer.classList.add('hidden');
                uploadArea.classList.remove('hidden');
            }
        }

        // Remove image preview
        function removeImagePreview() {
            document.getElementById('item_image').value = '';
            document.getElementById('imagePreview').classList.add('hidden');
            document.getElementById('uploadArea').classList.remove('hidden');
        }

        // Handle edit image preview
        function handleEditImagePreview(event) {
            const file = event.target.files[0];
            const previewContainer = document.getElementById('editNewImagePreview');
            const previewImg = document.getElementById('editNewImagePreviewImg');
            
            if (file) {
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Invalid file type. Please upload a JPEG, PNG, or GIF image.');
                    event.target.value = '';
                    return;
                }
                
                // Validate file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size too large. Maximum size is 5MB.');
                    event.target.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewContainer.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            } else {
                previewContainer.classList.add('hidden');
            }
        }

        // Remove edit image preview
        function removeEditImagePreview() {
            document.getElementById('editItemImage').value = '';
            document.getElementById('editNewImagePreview').classList.add('hidden');
        }

        // Edit Item Modal Functions
        function editItem(itemId, title, description, location, category, status, itemImage = null) {
            document.getElementById('editItemId').value = itemId;
            document.getElementById('editTitle').value = title;
            document.getElementById('editDescription').value = description || '';
            document.getElementById('editLocation').value = location || '';
            document.getElementById('editCategory').value = category;
            document.getElementById('editStatus').value = status;
            
            // Reset image previews
            document.getElementById('editItemImage').value = '';
            document.getElementById('editNewImagePreview').classList.add('hidden');
            
            // Handle image display
            const imagePreview = document.getElementById('editImagePreview');
            const currentImage = document.getElementById('editCurrentImage');
            
            if (itemImage && itemImage !== '') {
                currentImage.src = '../uploads/' + itemImage;
                imagePreview.classList.remove('hidden');
            } else {
                imagePreview.classList.add('hidden');
            }
            
            document.getElementById('editModal').classList.remove('hidden');
            document.getElementById('editModal').classList.add('flex');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
            document.getElementById('editModal').classList.remove('flex');
            document.getElementById('editItemImage').value = '';
            document.getElementById('editNewImagePreview').classList.add('hidden');
        }

        function deleteItem(itemId, title) {
            document.getElementById('deleteItemId').value = itemId;
            document.getElementById('deleteItemName').textContent = title;
            document.getElementById('deleteModal').classList.remove('hidden');
            document.getElementById('deleteModal').classList.add('flex');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
            document.getElementById('deleteModal').classList.remove('flex');
        }

        // Image View Modal Functions
        function viewImage(imagePath, itemTitle) {
            document.getElementById('imageViewTitle').textContent = itemTitle + ' - Image';
            document.getElementById('fullImageView').src = '../uploads/' + imagePath;
            document.getElementById('imageViewModal').classList.remove('hidden');
            document.getElementById('imageViewModal').classList.add('flex');
        }

        // Row Action Dropdown Functions
        function closeAllActionDropdowns() {
            document.querySelectorAll('[id^="actionDropdown-"]').forEach(dropdown => {
                dropdown.classList.add('hidden');
            });

            document.querySelectorAll('.action-dropdown-container.dropdown-open').forEach(container => {
                container.classList.remove('dropdown-open');
            });
        }

        function toggleActionDropdown(itemId) {
            const target = document.getElementById('actionDropdown-' + itemId);
            if (!target) return;

            const isHidden = target.classList.contains('hidden');
            closeAllActionDropdowns();

            if (isHidden) {
                target.classList.remove('hidden');
                const container = target.closest('.action-dropdown-container');
                if (container) {
                    container.classList.add('dropdown-open');
                }
            }
        }

        function closeImageViewModal() {
            document.getElementById('imageViewModal').classList.add('hidden');
            document.getElementById('imageViewModal').classList.remove('flex');
        }

        // Close modals when clicking outside
        document.getElementById('addItemModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddItemModal();
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });

        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });

        document.getElementById('imageViewModal').addEventListener('click', function(e) {
            if (e.target === this) closeImageViewModal();
        });

        // Close action dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const isDropdownTrigger = e.target.closest('.action-dropdown-container');
            if (!isDropdownTrigger) {
                closeAllActionDropdowns();
            }
        });

        // ESC key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddItemModal();
                closeEditModal();
                closeDeleteModal();
                closeImageViewModal();
                closeAllActionDropdowns();
            }
        });

        // Form submission handler for add item
        document.getElementById('addItemForm').addEventListener('submit', function(e) {
            const categorySelect = document.querySelector('select[name="category"]');
            const customCategory = document.getElementById('customCategory').value.trim();
            
            if (categorySelect.value === 'Other') {
                if (!customCategory) {
                    e.preventDefault();
                    alert('Please specify the custom category');
                    return;
                }
                // Replace the category with the custom value
                categorySelect.value = customCategory;
            }
            
            // Validate title length
            const title = document.querySelector('input[name="title"]').value.trim();
            if (title.length > 50) {
                e.preventDefault();
                alert('Title must be 50 characters or less');
                return;
            }
            
            // Validate description length
            const description = document.querySelector('textarea[name="description"]').value.trim();
            if (description.length > 100) {
                e.preventDefault();
                alert('Description must be 100 characters or less');
                return;
            }
        });
    </script>
</body>
</html>
