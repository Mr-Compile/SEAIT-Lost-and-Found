<?php
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole('admin');

$current_page = basename($_SERVER['PHP_SELF']);
$user = getUserInfo();

// Helper: check if a table exists to avoid fatal queries on missing tables
function tableExists($pdo, $tableName) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$tableName]);
        $result = $stmt->fetch();
        return $result && $result['count'] > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Quick connection check to give a helpful error if DB isn't reachable
$db_connected = false;
$db_error_msg = '';
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        // simple ping
        $pdo->query('SELECT 1');
        $db_connected = true;
    } else {
        $db_error_msg = 'Database PDO object not initialized. Check config/db.php';
    }
} catch (PDOException $e) {
    $db_error_msg = $e->getMessage();
}

if ($db_connected) {
    try {
        // Total Users
        $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
        $total_users = $stmt->fetch()['total_users'];
        
        // Previous month users for comparison
        $stmt = $pdo->query("SELECT COUNT(*) as prev_users FROM users WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
        $prev_users = $stmt->fetch()['prev_users'];
        $user_growth = $prev_users > 0 ? round((($total_users - $prev_users) / $prev_users) * 100, 1) : 0;
        
        // Total Items
        $stmt = $pdo->query("SELECT COUNT(*) as total_items FROM items");
        $total_items = $stmt->fetch()['total_items'];
        
        // Previous month items
        $stmt = $pdo->query("SELECT COUNT(*) as prev_items FROM items WHERE report_date < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
        $prev_items = $stmt->fetch()['prev_items'];
        $item_growth = $prev_items > 0 ? round((($total_items - $prev_items) / $prev_items) * 100, 1) : 0;
        
        // Total Matches
        $stmt = $pdo->query("SELECT COUNT(*) as total_matches FROM itemmatches");
        $total_matches = $stmt->fetch()['total_matches'];
        
        // Previous month matches
        $stmt = $pdo->query("SELECT COUNT(*) as prev_matches FROM itemmatches WHERE matched_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
        $prev_matches = $stmt->fetch()['prev_matches'];
        $match_growth = $prev_matches > 0 ? round((($total_matches - $prev_matches) / $prev_matches) * 100, 1) : 0;
        
        // Claims (items with status 'claimed')
        $stmt = $pdo->query("SELECT COUNT(*) as total_claims FROM items WHERE status = 'claimed'");
        $total_claims = $stmt->fetch()['total_claims'];
        
        // Previous month claims
        $stmt = $pdo->query("SELECT COUNT(*) as prev_claims FROM items WHERE status = 'claimed' AND report_date < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
        $prev_claims = $stmt->fetch()['prev_claims'];
        $claim_growth = $prev_claims > 0 ? round((($total_claims - $prev_claims) / $prev_claims) * 100, 1) : 0;
        
        // Monthly data for charts
        $stmt = $pdo->query("
            SELECT 
                MONTH(report_date) as month,
                YEAR(report_date) as year,
                status,
                COUNT(*) as count
            FROM items 
            WHERE report_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY YEAR(report_date), MONTH(report_date), status
            ORDER BY year, month
        ");
        $monthly_data = $stmt->fetchAll();
        
        // Category statistics
        $stmt = $pdo->query("
            SELECT category, COUNT(*) as count 
            FROM items 
            WHERE category IS NOT NULL 
            GROUP BY category 
            ORDER BY count DESC 
            LIMIT 5
        ");
        $category_stats = $stmt->fetchAll();
        
        // Recent activity
        $stmt = $pdo->query("
            SELECT 
                i.title,
                i.status,
                i.report_date,
                u.full_name
            FROM items i
            JOIN users u ON i.user_id = u.user_id
            ORDER BY i.report_date DESC
            LIMIT 5
        ");
        $recent_activities = $stmt->fetchAll();
        
        // Check for items needing redistribution
        // If the optional `unclaimed_items` table exists, exclude those items; otherwise use a simpler query.
        if (tableExists($pdo, 'unclaimed_items')) {
            $stmt = $pdo->query("SELECT COUNT(*) as expired_count FROM items WHERE status = 'found' AND hold_expiry IS NOT NULL AND hold_expiry <= CURDATE() AND item_id NOT IN (SELECT DISTINCT item_id FROM unclaimed_items WHERE status IN ('holding','ready_for_redistribution','redistributed'))");
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) as expired_count FROM items WHERE status = 'found' AND hold_expiry IS NOT NULL AND hold_expiry <= CURDATE()");
        }
        $expired_items_count = $stmt->fetch()['expired_count'];
        
        // Check for items expiring soon (within 7 days)
        $stmt = $pdo->query("
            SELECT COUNT(*) as expiring_soon_count 
            FROM items 
            WHERE status = 'found' 
            AND hold_expiry IS NOT NULL 
            AND hold_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $expiring_soon_count = $stmt->fetch()['expiring_soon_count'];
        
        // Check for items without hold_expiry
        $stmt = $pdo->query("
            SELECT COUNT(*) as no_expiry_count 
            FROM items 
            WHERE status = 'found' 
            AND (hold_expiry IS NULL OR hold_expiry = '0000-00-00')
        ");
        $no_expiry_count = $stmt->fetch()['no_expiry_count'];
        
        // Pending claim requests
        if (tableExists($pdo, 'claim_requests')) {
            $stmt = $pdo->query("SELECT COUNT(*) as pending_claims FROM claim_requests WHERE status = 'pending'");
            $pending_claims = $stmt->fetch()['pending_claims'];
        } else {
            $pending_claims = 0;
        }

        // Recent matches (show last 5)
        if (tableExists($pdo, 'itemmatches')) {
            $stmt = $pdo->query("SELECT im.match_id, im.similarity_score, im.matched_at, l.title AS lost_title, f.title AS found_title FROM itemmatches im JOIN items l ON im.lost_item_id = l.item_id JOIN items f ON im.found_item_id = f.item_id ORDER BY im.matched_at DESC LIMIT 5");
            $recent_matches = $stmt->fetchAll();
        } else {
            $recent_matches = [];
        }

        // Users by role (small breakdown used in UI)
        $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
        $users_by_role = $stmt->fetchAll();

        // Item status distribution
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM items GROUP BY status");
        $status_rows = $stmt->fetchAll();
        $status_breakdown = [
            'lost' => 0,
            'found' => 0,
            'claimed' => 0,
            'redistributed' => 0
        ];
        foreach ($status_rows as $status_row) {
            $status_key = strtolower((string)$status_row['status']);
            if (array_key_exists($status_key, $status_breakdown)) {
                $status_breakdown[$status_key] = (int)$status_row['count'];
            }
        }

        // Redistribution method totals for analytics
        if (tableExists($pdo, 'redistribution_records')) {
            $stmt = $pdo->query("SELECT redistribution_method, COUNT(*) as count FROM redistribution_records WHERE status = 'completed' GROUP BY redistribution_method ORDER BY count DESC");
            $redistribution_method_stats = $stmt->fetchAll();
        } else {
            $redistribution_method_stats = [];
        }
    
    } catch (PDOException $e) {
    // Fallback values if database error
    $total_users = 0;
    $total_items = 0;
    $total_matches = 0;
    $total_claims = 0;
    $user_growth = 0;
    $item_growth = 0;
    $match_growth = 0;
    $claim_growth = 0;
    $monthly_data = [];
    $category_stats = [];
    $recent_activities = [];
    $expired_items_count = 0;
    $expiring_soon_count = 0;
    $no_expiry_count = 0;
    $pending_claims = 0;
    $recent_matches = [];
    $users_by_role = [];
    $status_breakdown = ['lost' => 0, 'found' => 0, 'claimed' => 0, 'redistributed' => 0];
    $redistribution_method_stats = [];
    }
} else {
    // DB not connected -> set fallback values and surface error in UI
    $total_users = 0;
    $total_items = 0;
    $total_matches = 0;
    $total_claims = 0;
    $user_growth = 0;
    $item_growth = 0;
    $match_growth = 0;
    $claim_growth = 0;
    $monthly_data = [];
    $category_stats = [];
    $recent_activities = [];
    $expired_items_count = 0;
    $expiring_soon_count = 0;
    $no_expiry_count = 0;
    $pending_claims = 0;
    $recent_matches = [];
    $users_by_role = [];
    $status_breakdown = ['lost' => 0, 'found' => 0, 'claimed' => 0, 'redistributed' => 0];
    $redistribution_method_stats = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Lost & Found System</title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --dash-bg-0: #090d12;
            --dash-bg-1: #0d1218;
            --dash-bg-2: #131a23;
            --dash-bg-3: #1a222d;
            --dash-line: #2b3646;
            --dash-text: #f2f5fa;
            --dash-muted: #a8b3c2;
            --dash-sheen: rgba(255, 255, 255, 0.08);
        }

        body {
            margin: 0;
            font-family: 'Manrope', 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background:
                radial-gradient(circle at 8% 6%, rgba(255, 255, 255, 0.06), transparent 36%),
                radial-gradient(circle at 92% 4%, rgba(255, 255, 255, 0.04), transparent 40%),
                linear-gradient(180deg, var(--dash-bg-0) 0%, var(--dash-bg-1) 48%, #0a0f14 100%);
        }

        .dash-stat-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(148deg, #202833 0%, #151c25 48%, #0f151d 100%);
            border: 1px solid var(--dash-line);
            border-left: 4px solid #9ea9b8;
            border-radius: 14px;
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.35);
        }

        .dash-stat-card::before,
        .dash-panel::before,
        .dash-alert::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(125deg, var(--dash-sheen) 0%, rgba(255, 255, 255, 0) 45%);
        }

        .dash-stat-primary {
            background: linear-gradient(130deg, #2a3441 0%, #1b232d 42%, #111821 100%);
            border-color: #7f8da0;
            border-left-color: #c8d0db;
        }

        .dash-icon-shell {
            background: linear-gradient(132deg, #ffad75 0%, #ff7b2c 45%, #f24e00 100%);
            border: 1px solid rgba(255, 143, 74, 0.65);
            border-radius: 999px;
            padding: 1rem;
            box-shadow: 0 8px 18px rgba(242, 76, 0, 0.28);
        }

        .dash-icon {
            color: #fff8f2;
        }

        .dash-accent-gradient {
            background: linear-gradient(132deg, #ffb987 0%, #ff8f4a 36%, #ff5b1f 68%, #f23c00 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
        }

        .dash-stat-card .dash-metric-label,
        .dash-stat-card .dash-metric-value,
        .dash-stat-card .dash-metric-meta,
        .dash-panel .dash-panel-title,
        .dash-panel .dash-panel-value,
        .dash-panel .dash-panel-accent {
            background: linear-gradient(132deg, #ffbb8d 0%, #ff934f 38%, #ff6728 70%, #f34400 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent !important;
        }

        .dash-alert {
            position: relative;
            overflow: hidden;
            background: linear-gradient(160deg, #1e252f 0%, #141b24 100%);
            border: 1px solid #324052;
            border-left: 4px solid #b6c1d0;
            border-radius: 12px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.32);
        }

        .dash-alert-btn {
            background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 42%, #f23c00 100%);
            color: #ffffff;
            font-weight: 700;
        }

        .dash-alert-btn:hover {
            filter: brightness(1.05);
        }

        .dash-panel {
            position: relative;
            overflow: hidden;
            background: linear-gradient(160deg, #1a222c 0%, #10161f 100%);
            border: 1px solid #2b3646;
            border-radius: 14px;
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.35);
        }

        .dash-panel .border-t {
            border-color: #273040 !important;
        }

        .dash-panel .text-gray-800,
        .dash-panel .text-gray-900 {
            color: #f5f7fb !important;
        }

        .dash-panel .text-gray-600,
        .dash-panel .text-gray-500 {
            color: #a9b7cc !important;
        }

        .dash-panel .bg-gray-200 {
            background-color: #273040 !important;
        }

        .dash-stat-card .text-gray-800 {
            color: #f5f7fb !important;
        }

        .dash-stat-card .text-gray-600 {
            color: #9fb0c7 !important;
        }

        .dash-stat-primary .text-gray-800,
        .dash-stat-primary .text-gray-600,
        .dash-stat-primary .text-gray-400 {
            color: #eef3fa !important;
        }

        .dash-panel table thead {
            color: #a9b7cc;
        }

        .dash-panel table tbody td {
            color: #d6deea;
        }

        .dash-panel table tbody td.text-gray-500 {
            color: #9fb0c7 !important;
        }
        
        .gradient-bg {
            background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 42%, #f23c00 100%);
        }
        
        .card-hover {
            transition: all 0.3s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(0, 0, 0, 0.42);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
        }
        
        .small-chart-container {
            position: relative;
            height: 300px;
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    </style>
</head>
<body class="admin-theme">
    <?php include "../includes/components/shared_sidebar.php"; ?>
    
    <div class="main-content">
        <div class="p-6 max-w-7xl mx-auto admin-shell">
            
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-white mb-1">Dashboard Overview</h1>
                    <p class="text-gray-300">Comprehensive insights into your Lost & Found system performance</p>
                </div>

            </div>

            <?php if (empty($db_connected)): ?>
            <div class="mb-6 p-4 bg-[#1f2732] border-l-4 border-[#aeb9c8] rounded-lg">
                <p class="text-sm text-gray-100 font-semibold">Database connection problem</p>
                <p class="text-xs text-gray-300"><?php echo htmlspecialchars($db_error_msg ?: 'Unable to connect to the database.'); ?></p>
                <p class="text-xs text-gray-300 mt-2">Check <code>config/db.php</code>, ensure MySQL is running and credentials are correct.</p>
            </div>
            <?php endif; ?>

            <!-- Redistribution Alerts -->
            <?php if ($expired_items_count > 0 || $expiring_soon_count > 0 || $no_expiry_count > 0): ?>
            <div class="mb-6 space-y-4">

                <!-- Expired Items Alert -->
                <?php if ($expired_items_count > 0): ?>
                <div class="dash-alert p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <svg class="w-6 h-6 text-orange-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <p class="text-sm font-semibold text-gray-100">
                                    <?php echo $expired_items_count; ?> item<?php echo $expired_items_count > 1 ? 's' : ''; ?> expired (30+ days)
                                </p>
                                <p class="text-xs text-gray-300">These items need to be processed for redistribution</p>
                            </div>
                        </div>
                        <a href="admin_redistribution.php" class="dash-alert-btn px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                            Process Now →
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Expiring Soon Alert -->
                <?php if ($expiring_soon_count > 0): ?>
                <div class="dash-alert p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <svg class="w-6 h-6 text-orange-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <p class="text-sm font-semibold text-gray-100">
                                    <?php echo $expiring_soon_count; ?> item<?php echo $expiring_soon_count > 1 ? 's' : ''; ?> expiring soon (within 7 days)
                                </p>
                                <p class="text-xs text-gray-300">Monitor these items closely for potential claims</p>
                            </div>
                        </div>
                        <a href="admin_items.php?tab=found" class="dash-alert-btn px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                            View Items →
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Items Without Expiry Alert -->
                <?php if ($no_expiry_count > 0): ?>
                <div class="dash-alert p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <svg class="w-6 h-6 text-orange-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <p class="text-sm font-semibold text-gray-100">
                                    <?php echo $no_expiry_count; ?> item<?php echo $no_expiry_count > 1 ? 's' : ''; ?> without hold_expiry date
                                </p>
                                <p class="text-xs text-gray-300">Run the fix script to set 30-day expiry for these items</p>
                            </div>
                        </div>
                        <a href="fix_existing_items.php" class="dash-alert-btn px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                            Fix Now →
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="dash-stat-card dash-stat-primary p-6 card-hover">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 dash-metric-label">Total Users</p>
                            <p class="text-3xl font-bold text-gray-800 pulse-animation dash-metric-value"><?php echo number_format($total_users); ?></p>
                            <p class="text-xs <?php echo $user_growth >= 0 ? 'text-gray-300' : 'text-gray-500'; ?> mt-1 dash-metric-meta">
                                <?php echo $user_growth >= 0 ? '↗' : '↘'; ?> <?php echo abs($user_growth); ?>% from last month
                            </p>
                        </div>
                        <div class="dash-icon-shell">
                            <svg class="w-8 h-8 dash-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="dash-stat-card p-6 card-hover">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 dash-metric-label">Total Items Reported</p>
                            <p class="text-3xl font-bold text-gray-800 pulse-animation dash-metric-value"><?php echo number_format($total_items); ?></p>
                            <p class="text-xs <?php echo $item_growth >= 0 ? 'text-gray-300' : 'text-gray-500'; ?> mt-1 dash-metric-meta">
                                <?php echo $item_growth >= 0 ? '↗' : '↘'; ?> <?php echo abs($item_growth); ?>% from last month
                            </p>
                        </div>
                        <div class="dash-icon-shell">
                            <svg class="w-8 h-8 dash-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="dash-stat-card p-6 card-hover">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 dash-metric-label">Total Matches</p>
                            <p class="text-3xl font-bold text-gray-800 pulse-animation dash-metric-value"><?php echo number_format($total_matches); ?></p>
                            <p class="text-xs <?php echo $match_growth >= 0 ? 'text-gray-300' : 'text-gray-500'; ?> mt-1 dash-metric-meta">
                                <?php echo $match_growth >= 0 ? '↗' : '↘'; ?> <?php echo abs($match_growth); ?>% from last month
                            </p>
                        </div>
                        <div class="dash-icon-shell">
                            <svg class="w-8 h-8 dash-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="dash-stat-card p-6 card-hover">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 dash-metric-label">Total Claims</p>
                            <p class="text-3xl font-bold text-gray-800 pulse-animation dash-metric-value"><?php echo number_format($total_claims); ?></p>
                            <p class="text-xs <?php echo $claim_growth >= 0 ? 'text-gray-300' : 'text-gray-500'; ?> mt-1 dash-metric-meta">
                                <?php echo $claim_growth >= 0 ? '↗' : '↘'; ?> <?php echo abs($claim_growth); ?>% from last month
                            </p>
                        </div>
                        <div class="dash-icon-shell">
                            <svg class="w-8 h-8 dash-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent matches & pending claims -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="dash-panel p-6 card-hover">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 dash-panel-title">Recent Matches</h3>
                    <?php if (!empty($recent_matches)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-gray-600">
                                <tr>
                                    <th class="pb-2">Match</th>
                                    <th class="pb-2">Score</th>
                                    <th class="pb-2">Matched At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_matches as $m): ?>
                                <tr class="border-t">
                                    <td class="py-3"><?php echo htmlspecialchars($m['lost_title']); ?> ↔ <?php echo htmlspecialchars($m['found_title']); ?></td>
                                    <td class="py-3 font-semibold"><?php echo htmlspecialchars($m['similarity_score']); ?>%</td>
                                    <td class="py-3 text-gray-500"><?php echo htmlspecialchars($m['matched_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-gray-500 py-6 text-center">No recent matches</div>
                    <?php endif; ?>
                </div>

                <div class="dash-panel p-6 card-hover">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 dash-panel-title">Pending Claim Requests</h3>
                    <div class="text-3xl font-bold text-gray-800 mb-2 dash-panel-value"><?php echo number_format($pending_claims ?? 0); ?></div>
                    <p class="text-sm text-gray-600 mb-4 dash-panel-accent">Pending claims awaiting admin review</p>
                    <a href="admin_claims.php" class="inline-block bg-gradient-to-r from-[#ff8f4a] to-[#ff5b1f] text-white px-4 py-2 rounded-lg font-semibold hover:brightness-105 transition-colors">Review Claims</a>
                </div>
            </div>

            <!-- Analytics Charts -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
                <div class="xl:col-span-2 dash-panel p-6 card-hover">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-semibold text-gray-800 dash-panel-title">Monthly Reporting Analytics</h2>
                        <span class="text-xs text-gray-500">Last 12 months</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>

                <div class="dash-panel p-6 card-hover">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 dash-panel-title">Item Status Distribution</h2>
                    <div class="small-chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                        <div class="bg-[#1b222d] text-[#d4dbe6] px-3 py-2 rounded-lg border border-[#6f7784]">Lost: <strong><?php echo (int)($status_breakdown['lost'] ?? 0); ?></strong></div>
                        <div class="bg-[#2c2119] text-[#ffd6b5] px-3 py-2 rounded-lg border border-[#ff8a3d]">Found: <strong><?php echo (int)($status_breakdown['found'] ?? 0); ?></strong></div>
                        <div class="bg-[#34271c] text-[#ffe3cb] px-3 py-2 rounded-lg border border-[#ffb26a]">Claimed: <strong><?php echo (int)($status_breakdown['claimed'] ?? 0); ?></strong></div>
                        <div class="bg-[#171d27] text-[#c4cddd] px-3 py-2 rounded-lg border border-[#4e5766]">Redistributed: <strong><?php echo (int)($status_breakdown['redistributed'] ?? 0); ?></strong></div>
                    </div>
                </div>
            </div>

            <div class="dash-panel p-6 card-hover mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold text-gray-800 dash-panel-title">Redistribution Method Trends</h2>
                    <span class="text-xs text-gray-500">Completed redistributions</span>
                </div>
                <div class="small-chart-container">
                    <canvas id="redistributionMethodChart"></canvas>
                </div>
            </div>

            <!-- Additional Insights -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8">
                <div class="dash-panel p-6 card-hover">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 dash-panel-title">Top Categories</h3>
                    <div class="space-y-3">
                        <?php 
                        $total_category_items = array_sum(array_column($category_stats, 'count'));
                        foreach ($category_stats as $category): 
                            $percentage = $total_category_items > 0 ? round(($category['count'] / $total_category_items) * 100) : 0;
                            $width_percentage = min($percentage, 100);
                        ?>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600"><?php echo htmlspecialchars($category['category'] ?: 'Other'); ?></span>
                            <div class="flex items-center">
                                <div class="w-24 h-2 bg-gray-200 rounded-full mr-3">
                                    <div class="h-2 gradient-bg rounded-full" style="width: <?php echo $width_percentage; ?>%"></div>
                                </div>
                                <span class="text-sm font-semibold text-gray-800"><?php echo $percentage; ?>%</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($category_stats)): ?>
                        <div class="text-gray-500 text-center py-4">No category data available</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dash-panel p-6 card-hover">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 dash-panel-title">Recent Activity</h3>
                    <div class="space-y-4">
                        <?php foreach ($recent_activities as $activity): 
                            $status_color = 'bg-gray-500';
                            $activity_text = '';
                            
                            switch ($activity['status']) {
                                case 'lost':
                                    $status_color = 'bg-gray-500';
                                    $activity_text = 'Lost item reported: ' . htmlspecialchars($activity['title']);
                                    break;
                                case 'found':
                                    $status_color = 'bg-orange-500';
                                    $activity_text = 'Found item reported: ' . htmlspecialchars($activity['title']);
                                    break;
                                case 'claimed':
                                    $status_color = 'bg-orange-400';
                                    $activity_text = 'Item claimed: ' . htmlspecialchars($activity['title']);
                                    break;
                                default:
                                    $activity_text = 'Item status updated: ' . htmlspecialchars($activity['title']);
                            }
                            
                            $time_ago = time() - strtotime($activity['report_date']);
                            if ($time_ago < 60) {
                                $time_display = $time_ago . ' seconds ago';
                            } elseif ($time_ago < 3600) {
                                $time_display = floor($time_ago / 60) . ' minutes ago';
                            } elseif ($time_ago < 86400) {
                                $time_display = floor($time_ago / 3600) . ' hours ago';
                            } else {
                                $time_display = floor($time_ago / 86400) . ' days ago';
                            }
                        ?>
                        <div class="flex items-start space-x-3">
                            <div class="w-2 h-2 <?php echo $status_color; ?> rounded-full mt-2"></div>
                            <div>
                                <p class="text-sm text-gray-800 font-medium"><?php echo $activity_text; ?></p>
                                <p class="text-xs text-gray-500">by <?php echo htmlspecialchars($activity['full_name']); ?> • <?php echo $time_display; ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($recent_activities)): ?>
                        <div class="text-gray-500 text-center py-4">No recent activity</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Process PHP data for charts
        const monthlyData = <?php echo json_encode($monthly_data); ?>;
        const statusBreakdown = <?php echo json_encode($status_breakdown); ?>;
        const redistributionMethodStats = <?php echo json_encode($redistribution_method_stats); ?>;

        // Prepare monthly chart data
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const lostData = new Array(12).fill(0);
        const foundData = new Array(12).fill(0);
        const claimedData = new Array(12).fill(0);
        const redistributedData = new Array(12).fill(0);

        monthlyData.forEach(item => {
            const monthIndex = Number(item.month) - 1;
            const count = Number(item.count) || 0;
            if (item.status === 'lost') {
                lostData[monthIndex] += count;
            } else if (item.status === 'found') {
                foundData[monthIndex] += count;
            } else if (item.status === 'claimed') {
                claimedData[monthIndex] += count;
            } else if (item.status === 'redistributed') {
                redistributedData[monthIndex] += count;
            }
        });

        // Monthly Lost vs Found Reports Chart
        const monthlyCtx = document.getElementById('monthlyChart');
        if (monthlyCtx) {
            const monthlyChart = new Chart(monthlyCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Lost Items',
                        data: lostData,
                        backgroundColor: '#6f7784',
                        borderColor: '#0f151d',
                        borderWidth: 1,
                        hoverBackgroundColor: '#7d8694',
                        borderRadius: 6,
                        borderSkipped: false,
                    }, {
                        label: 'Found Items',
                        data: foundData,
                        backgroundColor: '#ff8a3d',
                        borderColor: '#0f151d',
                        borderWidth: 1,
                        hoverBackgroundColor: '#ff9a54',
                        borderRadius: 6,
                        borderSkipped: false,
                    }, {
                        label: 'Claimed Items',
                        data: claimedData,
                        backgroundColor: '#ffb26a',
                        borderColor: '#0f151d',
                        borderWidth: 1,
                        hoverBackgroundColor: '#ffc286',
                        borderRadius: 6,
                        borderSkipped: false,
                    }, {
                        label: 'Redistributed Items',
                        data: redistributedData,
                        backgroundColor: '#4e5766',
                        borderColor: '#0f151d',
                        borderWidth: 1,
                        hoverBackgroundColor: '#5e6878',
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: '#e3e9f1',
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 10,
                                boxHeight: 10,
                                padding: 14
                            }
                        },
                        tooltip: {
                            backgroundColor: '#111821',
                            titleColor: '#f4f7fb',
                            bodyColor: '#dde5f0',
                            borderColor: '#2f3948',
                            borderWidth: 1
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#273040'
                            },
                            ticks: {
                                color: '#a9b7cc'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#a9b7cc'
                            }
                        }
                    }
                }
            });
        }

        // Item Status Distribution Chart
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            const statusChart = new Chart(statusCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Lost', 'Found', 'Claimed', 'Redistributed'],
                    datasets: [{
                        data: [
                            Number(statusBreakdown.lost || 0),
                            Number(statusBreakdown.found || 0),
                            Number(statusBreakdown.claimed || 0),
                            Number(statusBreakdown.redistributed || 0)
                        ],
                        backgroundColor: ['#6f7784', '#ff8a3d', '#ffb26a', '#4e5766'],
                        borderColor: '#0f151d',
                        borderWidth: 2,
                        hoverBackgroundColor: ['#808897', '#ff9a54', '#ffc286', '#5e6878'],
                        hoverOffset: 10,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                color: '#e3e9f1',
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 10,
                                boxHeight: 10,
                                padding: 16
                            }
                        }
                    },
                    cutout: '70%'
                }
            });
        }

        // Redistribution Method Trend Chart
        const redistributionMethodCtx = document.getElementById('redistributionMethodChart');
        if (redistributionMethodCtx) {
            const methodLabels = redistributionMethodStats.map(row => {
                const value = row.redistribution_method || 'other';
                return value
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, char => char.toUpperCase());
            });
            const methodValues = redistributionMethodStats.map(row => Number(row.count) || 0);

            new Chart(redistributionMethodCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: methodLabels.length ? methodLabels : ['No Data'],
                    datasets: [{
                        label: 'Completed Count',
                        data: methodValues.length ? methodValues : [0],
                        backgroundColor: ['#cfd7e2', '#aeb9c9', '#8f9cb0', '#6f7c8f', '#556174'],
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                color: '#a9b7cc'
                            },
                            grid: {
                                color: '#273040'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#a9b7cc'
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Add animation to numbers on page load
        document.addEventListener('DOMContentLoaded', function() {
            const numbers = document.querySelectorAll('.pulse-animation');
            numbers.forEach(number => {
                const text = number.textContent.replace(/,/g, '');
                const finalValue = parseInt(text) || 0;
                let currentValue = 0;
                const increment = finalValue / 50;
                const timer = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        currentValue = finalValue;
                        clearInterval(timer);
                        number.classList.remove('pulse-animation');
                    }
                    number.textContent = Math.floor(currentValue).toLocaleString();
                }, 30);
            });
        });
        
        
        function showMatchDetails(matches, count) {
            const details = matches.slice(0, 5).map(m => 
                `• ${m.lost_title} ↔️ ${m.found_title} (${m.similarity_score}% match)`
            ).join('<br>');
            
            const message = `
                <div class="text-left">
                    <p class="font-bold mb-2">Created ${count} new match(es):</p>
                    <div class="text-sm">${details}</div>
                    ${matches.length > 5 ? `<p class="text-xs mt-2 text-gray-600">...and ${matches.length - 5} more</p>` : ''}
                </div>
            `;
            
            showToast(message, 'success', 8000);
        }
        
        function showToast(message, type = 'info', duration = 4000) {
            const colors = {
                success: 'bg-orange-500',
                error: 'bg-red-700',
                info: 'bg-[#ff7b2c]',
                warning: 'bg-orange-600'
            };
            
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 ${colors[type]} text-white px-6 py-4 rounded-lg shadow-lg z-50 max-w-md`;
            toast.style.animation = 'slideIn 0.3s ease-out';
            toast.innerHTML = message;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }
        
        // Add animation styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
