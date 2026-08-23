<?php
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/helpers/redistribution_helper.php';
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

$result_message = '';
$result_type = '';

// History modal filters and pagination
$history_method_filter = $_GET['history_method'] ?? 'all';
$history_date_from = $_GET['history_date_from'] ?? '';
$history_date_to = $_GET['history_date_to'] ?? '';
$history_search = trim($_GET['history_search'] ?? '');
$history_page = max(1, intval($_GET['history_page'] ?? 1));
$history_per_page = 10;
$history_offset = ($history_page - 1) * $history_per_page;

$history_where_conditions = ["rr.status = 'completed'"];
$history_params = [];

if ($history_method_filter && $history_method_filter !== 'all') {
    $history_where_conditions[] = "rr.redistribution_method = ?";
    $history_params[] = $history_method_filter;
}

if (!empty($history_date_from)) {
    $history_where_conditions[] = "DATE(rr.redistribution_date) >= ?";
    $history_params[] = $history_date_from;
}

if (!empty($history_date_to)) {
    $history_where_conditions[] = "DATE(rr.redistribution_date) <= ?";
    $history_params[] = $history_date_to;
}

if (!empty($history_search)) {
    $history_where_conditions[] = "(i.title LIKE ? OR rr.recipient_info LIKE ? OR rr.notes LIKE ?)";
    $history_params[] = "%$history_search%";
    $history_params[] = "%$history_search%";
    $history_params[] = "%$history_search%";
}

$history_where_clause = 'WHERE ' . implode(' AND ', $history_where_conditions);

$history_count_sql = "SELECT COUNT(*) as total FROM redistribution_records rr JOIN items i ON rr.item_id = i.item_id $history_where_clause";
$history_count_stmt = $pdo->prepare($history_count_sql);
$history_count_stmt->execute($history_params);
$history_total_records = (int)($history_count_stmt->fetch()['total'] ?? 0);
$history_total_pages = max(1, (int)ceil($history_total_records / $history_per_page));

$history_records_query = "
    SELECT rr.*, 
           i.title, 
           i.category, 
           i.item_image,
           i.finder_name,
           i.finder_phone,
           u.full_name as admin_name
    FROM redistribution_records rr
    JOIN items i ON rr.item_id = i.item_id
    LEFT JOIN users u ON rr.admin_user_id = u.user_id
    $history_where_clause
    ORDER BY rr.redistribution_date DESC
    LIMIT ? OFFSET ?
";
$history_query_params = $history_params;
$history_query_params[] = $history_per_page;
$history_query_params[] = $history_offset;
$history_records_stmt = $pdo->prepare($history_records_query);
$history_records_stmt->execute($history_query_params);
$history_records = $history_records_stmt->fetchAll();

$history_stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN redistribution_method = 'donation' THEN 1 ELSE 0 END) as donations,
        SUM(CASE WHEN redistribution_method = 'repurposing' THEN 1 ELSE 0 END) as repurposing,
        SUM(CASE WHEN redistribution_method = 'give_to_finder' THEN 1 ELSE 0 END) as given_to_finder,
        SUM(CASE WHEN redistribution_method = 'dispose' THEN 1 ELSE 0 END) as disposed,
        SUM(CASE WHEN redistribution_method = 'store_again' THEN 1 ELSE 0 END) as stored
    FROM redistribution_records 
    WHERE status = 'completed'
";
$history_stats = $pdo->query($history_stats_query)->fetch();

$history_link_base_params = [
    'history_open' => 1,
    'history_search' => $history_search,
    'history_method' => $history_method_filter,
    'history_date_from' => $history_date_from,
    'history_date_to' => $history_date_to,
];

// Handle manual processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'redistribute_item':
                // Manual redistribution
                $item_id = (int)$_POST['item_id'];
                $method = $_POST['redistribution_method'];
                $recipient = trim($_POST['recipient_info'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                
                try {
                    // Start transaction
                    $pdo->beginTransaction();
                    
                    // Get item details to check for finder_name
                    $stmt = $pdo->prepare("SELECT finder_name FROM items WHERE item_id = ?");
                    $stmt->execute([$item_id]);
                    $item = $stmt->fetch();
                    $finder_name = $item['finder_name'] ?? null;
                    
                    // Create redistribution record
                    $stmt = $pdo->prepare("
                        INSERT INTO redistribution_records 
                        (item_id, redistribution_method, admin_user_id, notes, recipient_info, status)
                        VALUES (?, ?, ?, ?, ?, 'completed')
                    ");
                    $stmt->execute([$item_id, $method, $user['user_id'], $notes, $recipient]);
                    
                    // If method is 'give_to_finder', award 5 bonus points to the finder
                    if ($method === 'give_to_finder' && !empty($finder_name)) {
                        // Check if finder exists in leaderboard
                        $check_stmt = $pdo->prepare("SELECT id, total_points FROM finder_leaderboard WHERE finder_name = ?");
                        $check_stmt->execute([$finder_name]);
                        $finder = $check_stmt->fetch();
                        
                        if ($finder) {
                            // Add 5 bonus points to existing finder
                            $new_points = $finder['total_points'] + 5;
                            $update_stmt = $pdo->prepare("UPDATE finder_leaderboard SET total_points = ?, updated_at = NOW() WHERE id = ?");
                            $update_stmt->execute([$new_points, $finder['id']]);
                        } else {
                            // Create new finder entry with 5 points (shouldn't happen normally)
                            $insert_stmt = $pdo->prepare("INSERT INTO finder_leaderboard (finder_name, total_points) VALUES (?, 5)");
                            $insert_stmt->execute([$finder_name]);
                        }
                    }
                    
                    // Update item status
                    $stmt = $pdo->prepare("UPDATE items SET status = 'redistributed' WHERE item_id = ?");
                    $stmt->execute([$item_id]);
                    
                    $pdo->commit();
                    
                    // Success message with bonus points notification
                    if ($method === 'give_to_finder' && !empty($finder_name)) {
                        $result_message = "Item successfully redistributed to finder '{$finder_name}'! Bonus 5 points awarded.";
                    } else {
                        $result_message = "Item successfully redistributed!";
                    }
                    $result_type = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $result_message = "Error: " . $e->getMessage();
                    $result_type = 'error';
                }
                break;
        }
    }
}

// Get current statistics
$stats = getRedistributionStats($pdo);

// Get expired items (past 30 days) from items table based on hold_expiry
$expired_items_query = "
    SELECT i.*, 
           i.finder_name,
           u.full_name as reporter_name,
           DATEDIFF(CURDATE(), i.report_date) as days_held,
           i.hold_expiry
    FROM items i
    JOIN users u ON i.user_id = u.user_id
    WHERE i.status = 'found'
    AND i.hold_expiry IS NOT NULL
    AND i.hold_expiry <= CURDATE()
    ORDER BY i.hold_expiry ASC
";
$expired_items = $pdo->query($expired_items_query)->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Redistribution - Admin</title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        .redis-surface {
            background: linear-gradient(160deg, #131922 0%, #10161e 100%);
            border: 1px solid #273040;
            border-radius: 14px;
            box-shadow: 0 16px 32px rgba(0, 0, 0, 0.38);
        }

        .redis-stat-card {
            background: linear-gradient(160deg, #141b25 0%, #10161e 100%);
            border: 1px solid #2a3444;
            border-left: 4px solid #ff7b2c;
            border-radius: 14px;
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.35);
        }

        .redis-message {
            border-radius: 12px;
            border: 1px solid;
            padding: 1rem;
        }

        .redis-message-success {
            background: #21160f;
            color: #ffd3ba;
            border-color: #ff7b2c;
        }

        .redis-message-error {
            background: #2a1415;
            color: #fecaca;
            border-color: #ef4444;
        }
    </style>
</head>
<body class="admin-theme min-h-full">
    <?php include "../includes/components/shared_sidebar.php"; ?>
    
    <div class="main-content p-5 admin-shell">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-white mb-2">Item Redistribution Management</h1>
            <p class="text-gray-300">Manually review and redistribute expired items with a clean, auditable workflow</p>
        </div>

        <!-- Result Message -->
        <?php if ($result_message): ?>
            <div class="mb-6 redis-message <?php echo $result_type === 'success' ? 'redis-message-success' : 'redis-message-error'; ?>">
                <?php echo htmlspecialchars($result_message); ?>
            </div>
        <?php endif; ?>

        <!-- Current Status Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <!-- Total Donations -->
            <div class="redis-stat-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-300 font-medium">Total Donations</p>
                        <p class="text-3xl font-bold text-white"><?php echo $stats['total_donations']; ?></p>
                    </div>
                    <div class="icon-badge">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 21C12 21 4 13.74 4 8.5A4.5 4.5 0 018.5 4C10.24 4 11.91 4.81 13 6.09 14.09 4.81 15.76 4 17.5 4A4.5 4.5 0 0122 8.5C22 13.74 14 21 14 21H12z"/></svg>
                    </div>
                </div>
            </div>

            <!-- Total Repurposed -->
            <div class="redis-stat-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-300 font-medium">Total Repurposed</p>
                        <p class="text-3xl font-bold text-white"><?php echo $stats['total_repurposed']; ?></p>
                    </div>
                    <div class="icon-badge">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v6h6M20 20v-6h-6M5.5 14A7 7 0 0018 9m.5 1A7 7 0 016 15"/></svg>
                    </div>
                </div>
            </div>

            <!-- Total Awarded -->
            <div class="redis-stat-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-300 font-medium">Awarded to Finders</p>
                        <p class="text-3xl font-bold text-white"><?php echo $stats['total_awarded']; ?></p>
                    </div>
                    <div class="icon-badge">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 21h8M12 17v4M7 4h10v3a5 5 0 01-10 0V4zM5 7h2M17 7h2"/></svg>
                    </div>
                </div>
            </div>

            <!-- Ready for Redistribution -->
            <div class="redis-stat-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-300 font-medium">Ready for Action</p>
                        <p class="text-3xl font-bold text-white"><?php echo $stats['ready_for_redistribution']; ?></p>
                    </div>
                    <div class="icon-badge">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- EXPIRED ITEMS (Past 30 Days) -->
        <?php if (count($expired_items) > 0): ?>
        <div class="redis-surface mb-8 overflow-hidden">
            <div class="px-6 py-4 bg-[#141b25] border-b border-[#2e3644]">
                <h2 class="text-lg font-semibold text-white">Expired Items - Require Redistribution (<?php echo count($expired_items); ?>)</h2>
                <p class="text-sm text-gray-300">These items have exceeded the 30-day holding period and must be redistributed</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-[#273040]">
                    <thead class="bg-[#0f151e]">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Item</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Finder</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Expired Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Days Held</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-transparent divide-y divide-[#273040]">
                        <?php foreach ($expired_items as $item): ?>
                        <tr class="hover:bg-[#1a212d]">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <?php if ($item['item_image']): ?>
                                        <img class="h-12 w-12 rounded-lg object-cover" src="../uploads/<?php echo htmlspecialchars($item['item_image']); ?>" alt="">
                                    <?php else: ?>
                                        <div class="h-12 w-12 rounded-lg bg-[#22150f] flex items-center justify-center border border-[#5f2b13]">
                                            <span class="text-orange-300 text-xs font-bold">ITEM</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="ml-4">
                                        <div class="text-sm font-semibold text-white"><?php echo htmlspecialchars($item['title']); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($item['description'] ?? ''); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-[#111827] text-gray-200 border border-[#273040]">
                                    <?php echo htmlspecialchars($item['category']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-200">
                                    <?php echo $item['finder_name'] ? htmlspecialchars($item['finder_name']) : '<span class="text-gray-500 italic">No finder info</span>'; ?>
                                </div>
                                <?php if (!empty($item['finder_phone'])): ?>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars(maskPhoneForDisplay((string)$item['finder_phone'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-orange-300 font-medium">
                                <?php echo date('M j, Y', strtotime($item['hold_expiry'])); ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm font-bold text-orange-300"><?php echo $item['days_held']; ?> days</span>
                                <span class="text-xs text-gray-400">(30 day limit)</span>
                            </td>
                            <td class="px-6 py-4">
                                <button onclick="openRedistributeModal(<?php echo $item['item_id']; ?>, '<?php echo htmlspecialchars($item['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['finder_name'] ?? '', ENT_QUOTES); ?>')" 
                                        class="theme-btn px-4 py-2 text-sm font-medium">
                                    Redistribute Now
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- REDISTRIBUTION HISTORY MODAL ENTRY -->
        <div class="redis-surface mb-8">
            <div class="px-6 py-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-white mb-2">Redistribution History</h2>
                        <p class="text-sm text-gray-300">View complete redistribution history with search, filters, and pagination without leaving this page</p>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            <span class="px-2 py-1 rounded-full bg-[#141f2f] text-[#c6dcff] border border-[#2a3444]">Total: <?php echo (int)($history_stats['total'] ?? 0); ?></span>
                            <span class="px-2 py-1 rounded-full bg-[#21160f] text-[#ffd5bf] border border-[#5f2b13]">Donations: <?php echo (int)($history_stats['donations'] ?? 0); ?></span>
                            <span class="px-2 py-1 rounded-full bg-[#111827] text-gray-200 border border-[#273040]">Repurposed: <?php echo (int)($history_stats['repurposing'] ?? 0); ?></span>
                            <span class="px-2 py-1 rounded-full bg-[#26160e] text-[#ffbe95] border border-[#5f2b13]">Finder Awards: <?php echo (int)($history_stats['given_to_finder'] ?? 0); ?></span>
                        </div>
                    </div>
                    <button type="button" onclick="openHistoryModal()"
                            class="px-6 py-3 theme-btn whitespace-nowrap">
                        View History
                    </button>
                </div>
            </div>
        </div>

        <!-- System Information -->
        <div class="redis-surface p-6">
            <h2 class="text-lg font-semibold text-white mb-4">System Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-medium text-gray-100 mb-2">Redistribution Settings</h3>
                    <ul class="text-sm text-gray-300 space-y-1">
                        <li><strong>Holding Period:</strong> <?php echo getRedistributionSetting($pdo, 'default_holding_period_days'); ?> days (30-day policy)</li>
                        <li><strong>Donation Organizations:</strong> <?php echo getRedistributionSetting($pdo, 'donation_organizations'); ?></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-medium text-gray-100 mb-2">How It Works</h3>
                    <ol class="text-sm text-gray-300 space-y-1 list-decimal list-inside">
                        <li>Items marked as "Found" get a 30-day hold period</li>
                        <li>After 30 days with no claim, items become eligible for redistribution</li>
                        <li>Admin manually reviews and redistributes expired items</li>
                        <li>Items are redistributed via donation, repurposing, or awarded to finder</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- HISTORY MODAL -->
    <div id="historyModal" class="hidden fixed inset-0 bg-black bg-opacity-70 overflow-y-auto h-full w-full" style="z-index: 1200;">
        <div class="relative top-8 mx-auto p-0 border border-[#273040] w-11/12 max-w-6xl shadow-lg rounded-lg bg-[#131922]">
            <div class="flex justify-between items-center px-6 py-4 border-b border-[#273040] bg-[#0f151e] rounded-t-lg sticky top-0">
                <div>
                    <h3 class="text-lg font-semibold text-white">Redistribution History</h3>
                    <p class="text-xs text-gray-400">Showing <?php echo count($history_records); ?> of <?php echo $history_total_records; ?> records</p>
                </div>
                <button onclick="closeHistoryModal()" class="text-gray-400 hover:text-gray-200">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="p-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                    <input type="hidden" name="history_open" value="1">
                    <input type="text" name="history_search" value="<?php echo htmlspecialchars($history_search); ?>"
                           placeholder="Search items, recipient, notes..."
                           class="px-4 py-2 border border-[#273040] rounded-lg bg-[#0f151e] text-gray-100 focus:ring-2 focus:ring-orange-400 focus:border-orange-300 outline-none">

                    <select name="history_method" class="px-4 py-2 border border-[#273040] rounded-lg bg-[#0f151e] text-gray-100 focus:ring-2 focus:ring-orange-400 focus:border-orange-300 outline-none">
                        <option value="all" <?php echo $history_method_filter === 'all' ? 'selected' : ''; ?>>All Methods</option>
                        <option value="donation" <?php echo $history_method_filter === 'donation' ? 'selected' : ''; ?>>Donation</option>
                        <option value="repurposing" <?php echo $history_method_filter === 'repurposing' ? 'selected' : ''; ?>>Repurposing</option>
                        <option value="give_to_finder" <?php echo $history_method_filter === 'give_to_finder' ? 'selected' : ''; ?>>Given to Finder</option>
                        <option value="dispose" <?php echo $history_method_filter === 'dispose' ? 'selected' : ''; ?>>Disposed</option>
                        <option value="store_again" <?php echo $history_method_filter === 'store_again' ? 'selected' : ''; ?>>Stored Again</option>
                    </select>

                    <input type="date" name="history_date_from" value="<?php echo htmlspecialchars($history_date_from); ?>"
                           class="px-4 py-2 border border-[#273040] rounded-lg bg-[#0f151e] text-gray-100 focus:ring-2 focus:ring-orange-400 focus:border-orange-300 outline-none">

                    <input type="date" name="history_date_to" value="<?php echo htmlspecialchars($history_date_to); ?>"
                           class="px-4 py-2 border border-[#273040] rounded-lg bg-[#0f151e] text-gray-100 focus:ring-2 focus:ring-orange-400 focus:border-orange-300 outline-none">

                    <div class="flex gap-2">
                        <button type="submit" class="w-full px-4 py-2 theme-btn font-medium">
                            Search
                        </button>
                        <a href="?history_open=1" class="px-4 py-2 bg-[#1a212d] text-gray-200 border border-[#273040] rounded-lg hover:bg-[#222b39] whitespace-nowrap">Reset</a>
                    </div>
                </form>

                <div class="overflow-x-auto border border-[#273040] rounded-lg">
                    <table class="min-w-full divide-y divide-[#273040]">
                        <thead class="bg-[#0f151e]">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Item</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Method</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Recipient</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Finder</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Admin</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-[#9fb0c7] uppercase">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="bg-transparent divide-y divide-[#273040]">
                            <?php if (count($history_records) > 0): ?>
                                <?php foreach ($history_records as $record): ?>
                                <tr class="hover:bg-[#1a212d]">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center">
                                            <?php if (!empty($record['item_image'])): ?>
                                                <img class="h-10 w-10 rounded-lg object-cover" src="../uploads/<?php echo htmlspecialchars($record['item_image']); ?>" alt="">
                                            <?php else: ?>
                                                <div class="h-10 w-10 rounded-lg bg-[#0f151e] border border-[#273040] flex items-center justify-center">
                                                    <span class="text-gray-500 text-[10px] font-bold">ITEM</span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="ml-3">
                                                <div class="text-sm font-medium text-white"><?php echo htmlspecialchars($record['title']); ?></div>
                                                <div class="text-xs text-gray-400"><?php echo htmlspecialchars($record['category']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $history_badges = [
                                            'donation' => '<span class="px-2 py-1 text-xs font-medium rounded-full bg-[#21160f] text-[#ffd5bf] border border-[#5f2b13]">Donation</span>',
                                            'repurposing' => '<span class="px-2 py-1 text-xs font-medium rounded-full bg-[#111827] text-gray-200 border border-[#273040]">Repurposing</span>',
                                            'give_to_finder' => '<span class="px-2 py-1 text-xs font-medium rounded-full bg-[#26160e] text-[#ffbe95] border border-[#5f2b13]">Given to Finder</span>',
                                            'dispose' => '<span class="px-2 py-1 text-xs font-medium rounded-full bg-[#151b24] text-gray-200 border border-[#273040]">Disposed</span>',
                                            'store_again' => '<span class="px-2 py-1 text-xs font-medium rounded-full bg-[#201a16] text-[#e8d1bf] border border-[#4b3f34]">Stored Again</span>'
                                        ];
                                        echo $history_badges[$record['redistribution_method']] ?? htmlspecialchars($record['redistribution_method']);
                                        ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-200"><?php echo htmlspecialchars($record['recipient_info'] ?: 'N/A'); ?></td>
                                    <td class="px-4 py-3">
                                        <?php if (!empty($record['finder_name'])): ?>
                                            <div class="text-sm text-gray-200"><?php echo htmlspecialchars($record['finder_name']); ?></div>
                                            <?php if (!empty($record['finder_phone'])): ?>
                                                <div class="text-xs text-gray-400"><?php echo htmlspecialchars(maskPhoneForDisplay((string)$record['finder_phone'])); ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-500">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-300"><?php echo htmlspecialchars($record['admin_name'] ?: 'System'); ?></td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm text-gray-200"><?php echo date('M j, Y', strtotime($record['redistribution_date'])); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo date('h:i A', strtotime($record['redistribution_date'])); ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-300 max-w-xs">
                                        <div class="truncate" title="<?php echo htmlspecialchars($record['notes']); ?>">
                                            <?php echo htmlspecialchars(substr($record['notes'] ?: 'No notes', 0, 50)); ?>
                                            <?php if (strlen($record['notes']) > 50): ?>...<?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-gray-400">No redistribution records found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($history_total_pages > 1): ?>
                <div class="mt-4 flex items-center justify-between">
                    <div class="text-sm text-gray-300">
                        Showing <?php echo ($history_offset + 1); ?> to <?php echo min($history_offset + $history_per_page, $history_total_records); ?> of <?php echo $history_total_records; ?> records
                    </div>
                    <div class="flex gap-2">
                        <?php if ($history_page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($history_link_base_params, ['history_page' => $history_page - 1])); ?>"
                               class="px-3 py-2 border border-[#273040] bg-[#0f151e] text-gray-200 rounded-lg hover:bg-[#1a212d] transition-colors text-sm">Previous</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $history_page - 2); $i <= min($history_total_pages, $history_page + 2); $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($history_link_base_params, ['history_page' => $i])); ?>"
                               class="px-3 py-2 rounded-lg text-sm <?php echo $i == $history_page ? 'theme-btn text-white' : 'border border-[#273040] bg-[#0f151e] text-gray-200 hover:bg-[#1a212d]'; ?> transition-colors">
                               <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($history_page < $history_total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($history_link_base_params, ['history_page' => $history_page + 1])); ?>"
                               class="px-3 py-2 border border-[#273040] bg-[#0f151e] text-gray-200 rounded-lg hover:bg-[#1a212d] transition-colors text-sm">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- REDISTRIBUTION MODAL -->
    <div id="redistributeModal" class="hidden fixed inset-0 bg-black bg-opacity-70 overflow-y-auto h-full w-full" style="z-index: 1300;">
        <div class="relative top-20 mx-auto p-5 border border-[#273040] w-96 shadow-lg rounded-lg bg-[#131922]">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-white">Redistribute Item</h3>
                <button onclick="closeRedistributeModal()" class="text-gray-400 hover:text-gray-200">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="redistribute_item">
                <input type="hidden" name="item_id" id="redistribute_item_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-300 mb-2">Item:</label>
                    <p id="redistribute_item_title" class="text-gray-100 font-semibold"></p>
                </div>
                
                <div class="mb-4">
                    <label for="redistribution_method" class="block text-sm font-medium text-gray-300 mb-2">
                        Redistribution Method <span class="text-red-500">*</span>
                    </label>
                    <select name="redistribution_method" id="redistribution_method" required
                            class="w-full px-3 py-2 border border-[#273040] bg-[#0f151e] text-gray-100 rounded-lg focus:ring-2 focus:ring-orange-400 focus:border-orange-300">
                        <option value="">-- Select Method --</option>
                        <option value="donation">Donation - Give to charity/organization</option>
                        <option value="repurposing">Repurposing - Reuse for institution purposes</option>
                        <option value="give_to_finder">Give to Finder - Award to the person who found it</option>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label for="recipient_info" class="block text-sm font-medium text-gray-300 mb-2">
                        Recipient Information (Optional)
                    </label>
                    <input type="text" name="recipient_info" id="recipient_info" 
                           placeholder="e.g., Local Charity, School Library, John Doe"
                           class="w-full px-3 py-2 border border-[#273040] bg-[#0f151e] text-gray-100 rounded-lg focus:ring-2 focus:ring-orange-400 focus:border-orange-300">
                </div>
                
                <div class="mb-6">
                    <label for="notes" class="block text-sm font-medium text-gray-300 mb-2">
                        Notes (Optional)
                    </label>
                    <textarea name="notes" id="notes" rows="3" 
                              placeholder="Add any additional notes about this redistribution..."
                              class="w-full px-3 py-2 border border-[#273040] bg-[#0f151e] text-gray-100 rounded-lg focus:ring-2 focus:ring-orange-400 focus:border-orange-300"></textarea>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeRedistributeModal()" 
                            class="px-4 py-2 bg-[#1a212d] text-gray-200 border border-[#273040] rounded-lg hover:bg-[#222b39] transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 theme-btn">
                        Confirm Redistribution
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    let currentFinderName = '';
    
    function openRedistributeModal(itemId, itemTitle, finderName) {
        currentFinderName = finderName || '';
        document.getElementById('redistribute_item_id').value = itemId;
        document.getElementById('redistribute_item_title').textContent = itemTitle;
        document.getElementById('redistributeModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeRedistributeModal() {
        document.getElementById('redistributeModal').classList.add('hidden');
        document.getElementById('redistribution_method').value = '';
        document.getElementById('recipient_info').value = '';
        document.getElementById('notes').value = '';
        currentFinderName = '';
        if (document.getElementById('historyModal').classList.contains('hidden')) {
            document.body.style.overflow = '';
        }
    }

    function openHistoryModal() {
        document.getElementById('historyModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeHistoryModal() {
        document.getElementById('historyModal').classList.add('hidden');
        if (document.getElementById('redistributeModal').classList.contains('hidden')) {
            document.body.style.overflow = '';
        }
        const url = new URL(window.location.href);
        if (url.searchParams.has('history_open')) {
            url.searchParams.delete('history_open');
            window.history.replaceState({}, '', url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : ''));
        }
    }
    
    // Auto-populate recipient when method changes
    document.addEventListener('DOMContentLoaded', function() {
        const methodSelect = document.getElementById('redistribution_method');
        const recipientInput = document.getElementById('recipient_info');

        const params = new URLSearchParams(window.location.search);
        if (params.get('history_open') === '1') {
            openHistoryModal();
        }
        
        if (methodSelect && recipientInput) {
            methodSelect.addEventListener('change', function() {
                if (this.value === 'give_to_finder' && currentFinderName) {
                    recipientInput.value = currentFinderName;
                    recipientInput.focus();
                } else if (this.value === 'donation') {
                    recipientInput.value = '';
                    recipientInput.placeholder = 'e.g., Local Charity, Red Cross, Community Center';
                } else if (this.value === 'repurposing') {
                    recipientInput.value = '';
                    recipientInput.placeholder = 'e.g., School Library, Lost & Found Office';
                } else {
                    recipientInput.value = '';
                    recipientInput.placeholder = 'e.g., Local Charity, School Library, John Doe';
                }
            });
        }
    });

    // Close modal when clicking outside
    document.getElementById('redistributeModal').addEventListener('click', function(event) {
        if (event.target === this) {
            closeRedistributeModal();
        }
    });

    document.getElementById('historyModal').addEventListener('click', function(event) {
        if (event.target === this) {
            closeHistoryModal();
        }
    });
    </script>

</body>
</html>

