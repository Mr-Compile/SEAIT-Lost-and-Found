<?php
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole('claimant');

// User info from auth_check
$user = getUserInfo();
$current_page = basename($_SERVER['PHP_SELF']);

// Get sorting parameters
$sort_by = $_GET['sort'] ?? 'report_date';
$sort_order = $_GET['order'] ?? 'DESC';
$category_filter = $_GET['category'] ?? '';
$search_query = $_GET['search'] ?? '';
$active_tab = $_GET['tab'] ?? 'found'; // Default to 'found' tab

// Validate sort parameters
$allowed_sorts = ['report_date', 'title', 'category', 'status'];
$sort_by = in_array($sort_by, $allowed_sorts) ? $sort_by : 'report_date';
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Validate active tab
$allowed_tabs = ['lost', 'found'];
$active_tab = in_array($active_tab, $allowed_tabs) ? $active_tab : 'found';

// First, check if the current user has any lost items posted
$user_id = $_SESSION['user_id'];
$check_stmt = $pdo->prepare("SELECT COUNT(*) as lost_count FROM items WHERE user_id = ? AND status = 'lost'");
$check_stmt->execute([$user_id]);
$user_has_lost_items = $check_stmt->fetchColumn() > 0;

// Build the SQL query for the active tab with match information
// ONLY show found items that have matches with the user's lost items
if ($active_tab === 'found') {
    // Only show found items if user has posted lost items
    if (!$user_has_lost_items) {
        // If user has no lost items, set empty result
        $items = [];
        $params = [];
        $sql = null;
    } else {
        // Show ONLY found items that have matches with user's lost items
        $sql = "SELECT DISTINCT i.*, u.full_name as reporter_name,
                COALESCE(i.finder_name, u.full_name) as finder_or_reporter,
                (SELECT COUNT(*) FROM itemmatches im 
                 INNER JOIN items my_items ON my_items.item_id = im.lost_item_id
                 WHERE my_items.user_id = ?
                 AND my_items.status = 'lost'
                 AND im.found_item_id = i.item_id) as match_count,
                (SELECT MAX(im2.similarity_score) FROM itemmatches im2 
                 INNER JOIN items my_items2 ON my_items2.item_id = im2.lost_item_id
                 WHERE my_items2.user_id = ?
                 AND my_items2.status = 'lost'
                 AND im2.found_item_id = i.item_id) as best_match_score,
                (SELECT im3.match_id FROM itemmatches im3
                 INNER JOIN items my_items ON my_items.item_id = im3.lost_item_id
                 WHERE my_items.user_id = ?
                 AND my_items.status = 'lost'
                 AND im3.found_item_id = i.item_id
                 ORDER BY im3.similarity_score DESC
                 LIMIT 1) as match_id
                FROM items i 
                JOIN users u ON i.user_id = u.user_id 
                WHERE i.status = 'found'
                AND i.user_id != ?
                AND i.item_id NOT IN (
                    SELECT item_id FROM claim_requests WHERE status IN ('claimed', 'ready_for_pickup')
                )
                AND EXISTS (
                    SELECT 1 FROM itemmatches im
                    INNER JOIN items my_items ON my_items.item_id = im.lost_item_id
                    WHERE my_items.user_id = ?
                    AND my_items.status = 'lost'
                    AND im.found_item_id = i.item_id
                )";
        
        $params = [$user_id, $user_id, $user_id, $user_id, $user_id];
    }
} else {
    // For lost tab, show all lost items EXCEPT those with completed claims
    $sql = "SELECT DISTINCT i.*, u.full_name as reporter_name,
            (SELECT COUNT(*) FROM itemmatches im 
             WHERE im.lost_item_id = i.item_id) as match_count,
            (SELECT MAX(im2.similarity_score) FROM itemmatches im2 
             WHERE im2.lost_item_id = i.item_id) as best_match_score,
            (SELECT im3.match_id FROM itemmatches im3
             WHERE im3.lost_item_id = i.item_id
             ORDER BY im3.similarity_score DESC
             LIMIT 1) as match_id
            FROM items i 
            JOIN users u ON i.user_id = u.user_id 
            WHERE i.status = 'lost'
            AND i.user_id != ?
            AND i.item_id NOT IN (
                SELECT item_id FROM claim_requests WHERE status IN ('claimed', 'ready_for_pickup')
            )";
    
    $params = [$user_id];
}

// Add search filter
if (!empty($search_query)) {
    if ($sql !== null) {
        $sql .= " AND (i.title LIKE ? OR i.description LIKE ? OR i.category LIKE ?)";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
}

// Add category filter
if (!empty($category_filter)) {
    if ($sql !== null) {
        $sql .= " AND i.category = ?";
        $params[] = $category_filter;
    }
}

// Only execute query if SQL is set
if ($sql !== null) {
    // Add sorting - handle NULL values for best_match_score (MariaDB compatible)
    $sql .= " ORDER BY COALESCE(best_match_score, 0) DESC, i.$sort_by $sort_order";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error_message = "Error fetching items: " . $e->getMessage();
        $items = [];
    }
} else {
    $items = [];
}

// Get counts for both tabs (only show items with matches for lost items)
try {
    $count_sql = "SELECT status, COUNT(*) as count FROM items i WHERE 1=1";
    $count_params = [];
    
    if (!empty($search_query)) {
        $count_sql .= " AND (i.title LIKE ? OR i.description LIKE ? OR i.category LIKE ?)";
        $search_param = "%$search_query%";
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        $count_params[] = $search_param;
    }
    
    if (!empty($category_filter)) {
        $count_sql .= " AND i.category = ?";
        $count_params[] = $category_filter;
    }
    
    $count_sql .= " AND i.status IN ('lost', 'found') GROUP BY i.status";
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $counts = $count_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $lost_count = $counts['lost'] ?? 0;
    $found_count = $counts['found'] ?? 0;
} catch (PDOException $e) {
    $lost_count = 0;
    $found_count = 0;
}

// Get unique categories for filter dropdown
try {
    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $categories = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FindIt - Claimant Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/security/slider-challenge.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="<?php echo htmlspecialchars(finditAssetUrl('js/security/slider-challenge.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif'],
                    },
                    colors: {
                        'blue-primary': '#ff5b1f',
                        'blue-light': '#fff1eb',
                        'blue-dark': '#e64912',
                    }
                }
            }
        }
    </script>
    <style>
        body {
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
            height: 100vh;
            overflow: hidden;
            background:
                radial-gradient(circle at 12% 10%, rgba(255, 123, 44, 0.14), transparent 36%),
                radial-gradient(circle at 88% 2%, rgba(148, 163, 184, 0.12), transparent 42%),
                #090e14;
        }

        /* Main content container with scrollable area */
        .main-content {
            margin-left: 280px;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            scroll-behavior: smooth;
            scrollbar-width: thin;
            scrollbar-color: #3d4655 #0f151e;
        }

        /* Custom scrollbar for webkit browsers */
        .main-content::-webkit-scrollbar {
            width: 8px;
        }

        .main-content::-webkit-scrollbar-track {
            background: #0f151e;
            border-radius: 4px;
        }

        .main-content::-webkit-scrollbar-thumb {
            background: #3d4655;
            border-radius: 4px;
            transition: background 0.2s ease;
        }

        .main-content::-webkit-scrollbar-thumb:hover {
            background: #526079;
        }

        /* Content wrapper with proper padding */
        .content-wrapper {
            padding: 2rem;
            max-width: 1600px;
            margin: 0 auto;
            min-height: calc(100vh - 4rem);
            padding-top: 6rem; /* Account for fixed search bar */
        }
        
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .search-focus:focus {
            box-shadow: 0 0 0 3px rgba(255, 123, 44, 0.2);
        }
        
        .claim-button {
            background: linear-gradient(135deg, #ff5b1f 0%, #ff7b2c 100%);
            transition: all 0.2s ease;
        }
        
        .claim-button:hover {
            background: linear-gradient(135deg, #f24c12 0%, #ff6922 100%);
            transform: translateY(-1px);
        }
        
        .status-badge {
            animation: fadeIn 0.5s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Animation for smooth entrance */
        .fade-in {
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Staggered animation for grid items */
        .grid-item {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }

        .grid-item.animate {
            opacity: 1;
            transform: translateY(0);
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .content-wrapper {
                padding: 1rem;
            }
        }

        /* Sticky header for filters */
        .sticky-filters {
            position: sticky;
            top: 0;
            z-index: 10;
            background: rgba(15, 20, 29, 0.88);
            backdrop-filter: blur(10px);
            margin: -2rem -2rem 2rem -2rem;
            padding: 2rem;
        }

        .claimant-modal-panel {
            border: 1px solid #273040;
            background: #131922;
            box-shadow: 0 24px 40px rgba(0, 0, 0, 0.45);
        }

        body.claimant-theme #viewItemModal .bg-green-50,
        body.claimant-theme #viewItemModal .bg-blue-50,
        body.claimant-theme #viewItemModal .bg-gray-50,
        body.claimant-theme #viewItemModal .bg-green-100,
        body.claimant-theme #viewItemModal .bg-blue-100,
        body.claimant-theme #viewItemModal .bg-gray-100 {
            background-color: #0f151e !important;
            border-color: #273040 !important;
            color: #ecf2ff !important;
        }

        body.claimant-theme #viewItemModal .text-green-900,
        body.claimant-theme #viewItemModal .text-green-800,
        body.claimant-theme #viewItemModal .text-green-700,
        body.claimant-theme #viewItemModal .text-blue-900,
        body.claimant-theme #viewItemModal .text-blue-800,
        body.claimant-theme #viewItemModal .text-blue-700,
        body.claimant-theme #viewItemModal .text-gray-900,
        body.claimant-theme #viewItemModal .text-gray-800,
        body.claimant-theme #viewItemModal .text-gray-700,
        body.claimant-theme #viewItemModal .text-gray-600,
        body.claimant-theme #viewItemModal .text-gray-500 {
            color: #d9e4f7 !important;
        }

        body.claimant-theme #viewItemModal .text-green-400,
        body.claimant-theme #viewItemModal .text-blue-400 {
            color: #ff8b4c !important;
        }
    </style>
</head>
<body class="claimant-theme min-h-full">
    <?php include '../includes/components/shared_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="content-wrapper">
            <!-- Fixed Search and Filter Bar -->
            <div class="fixed top-0 left-280 right-0 z-50 border-b border-slate-700 bg-[#0f151e]/95 shadow-[0_14px_24px_rgba(0,0,0,0.42)] backdrop-blur" style="left: 280px;">
                <form method="GET" class="p-4">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                    <div class="flex gap-3 items-center max-w-7xl mx-auto">
                        <!-- Search Input -->
                        <div class="flex-1 max-w-md">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <input type="text" 
                                       name="search"
                                       value="<?php echo htmlspecialchars($search_query); ?>"
                                       placeholder="Search items..." 
                                        class="search-focus block w-full rounded-lg border border-slate-600 bg-[#111923] py-2 pl-9 pr-3 text-sm leading-5 text-slate-100 placeholder-slate-400 transition-all focus:border-orange-400 focus:outline-none focus:placeholder-slate-500 focus:ring-1 focus:ring-orange-400">
                            </div>
                        </div>
                        
                        <!-- Category Filter -->
                        <div class="w-40">
                            <select name="category" class="block w-full rounded-lg border border-slate-600 bg-[#111923] px-3 py-2 text-sm text-slate-100 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>" 
                                            <?php echo ($category_filter === $category) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($category)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Sort Controls -->
                        <div class="flex gap-2 items-center">
                            <select name="sort" class="rounded-lg border border-slate-600 bg-[#111923] px-2 py-2 text-sm text-slate-100 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400">
                                <option value="report_date" <?php echo ($sort_by === 'report_date') ? 'selected' : ''; ?>>Date</option>
                                <option value="title" <?php echo ($sort_by === 'title') ? 'selected' : ''; ?>>Title</option>
                                <option value="category" <?php echo ($sort_by === 'category') ? 'selected' : ''; ?>>Category</option>
                            </select>
                            
                            <select name="order" class="rounded-lg border border-slate-600 bg-[#111923] px-2 py-2 text-sm text-slate-100 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400">
                                <option value="DESC" <?php echo ($sort_order === 'DESC') ? 'selected' : ''; ?>>Newest</option>
                                <option value="ASC" <?php echo ($sort_order === 'ASC') ? 'selected' : ''; ?>>Oldest</option>
                            </select>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="flex gap-2">
                            <button type="submit" class="bg-gradient-to-r from-orange-500 to-orange-600 px-4 py-2 text-sm font-medium text-white transition-all hover:from-orange-600 hover:to-orange-700 rounded-lg">
                                Search
                            </button>
                            <a href="claimant_all_items.php?tab=<?php echo $active_tab; ?>" class="rounded-lg border border-slate-600 bg-[#151e2b] px-4 py-2 text-sm font-medium text-slate-200 transition-all hover:bg-[#1a2433]">
                                Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

 

        <!-- Error Message -->
        <?php if (isset($error_message)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4 fade-in">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Smart Matching Info Banner -->
        <?php if (!empty($items) && $active_tab === 'found'): ?>
        <div class="mb-6 fade-in">
            <div class="rounded-xl border border-slate-700 bg-gradient-to-r from-[#121a25] via-[#161f2b] to-[#1c2531] p-4 shadow-[0_10px_22px_rgba(0,0,0,0.35)]">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <svg class="h-8 w-8 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="ml-4 flex-1">
                        <h3 class="mb-1 text-lg font-bold text-slate-100">
                            Smart Match Results
                        </h3>
                        <p class="text-sm text-slate-300">
                            These found items have been automatically matched with your lost item reports based on title, description, category, and location. Items are sorted by match score (highest matches first).
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Results Summary -->
        <?php if (!empty($items)): ?>
        <div class="mb-6 fade-in">
            <div class="flex items-center justify-between">
                <p class="text-slate-300">
                    <?php 
                    $total_items = count($items);
                    if ($total_items > 0) {
                        echo "Showing <strong>$total_items potential " . ($total_items === 1 ? 'match' : 'matches') . "</strong> for your lost items";
                        if (!empty($search_query) || !empty($category_filter)) {
                            echo " (filtered)";
                        }
                    }
                    ?>
                </p>
                <div class="flex items-center space-x-4 text-sm text-slate-400">
                    <span class="flex items-center">
                        <svg class="mr-1 h-4 w-4 text-orange-300" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        Matches: <?php echo $total_items; ?>
                    </span>
                    <a href="claimant_my_items.php" class="font-medium text-orange-300 hover:text-orange-200">
                        View My Lost Items ->
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Items Table -->
        <div id="itemsGrid" class="overflow-hidden rounded-xl border border-slate-700 bg-[#111923] shadow-[0_18px_34px_rgba(0,0,0,0.42)]">
            <?php if (empty($items)): ?>
                <div class="text-center py-16 fade-in">
                    <div class="max-w-lg mx-auto px-6">
                        <svg class="mx-auto mb-4 h-16 w-16 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <?php if ($active_tab === 'found' && !$user_has_lost_items): ?>
                            <h3 class="mb-3 text-2xl font-bold text-slate-100">No Lost Items Posted Yet</h3>
                            <p class="mb-5 text-slate-300">Post a lost item first so the system can show matching found items.</p>
                            <a href="claimant_my_items.php" class="inline-flex items-center rounded-lg bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 font-semibold text-white hover:from-orange-600 hover:to-orange-700">
                                Report Lost Item
                            </a>
                        <?php elseif ($active_tab === 'found'): ?>
                            <h3 class="mb-3 text-2xl font-bold text-slate-100">No Matching Found Items Yet</h3>
                            <p class="mb-5 text-slate-300">Try updating your lost item details to improve matching accuracy.</p>
                            <a href="claimant_my_items.php" class="inline-flex items-center rounded-lg bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 font-semibold text-white hover:from-orange-600 hover:to-orange-700">
                                Update My Lost Items
                            </a>
                        <?php else: ?>
                            <h3 class="mb-3 text-2xl font-bold text-slate-100">No Items Found</h3>
                            <p class="text-slate-300">No records match your current search and filters.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-700 text-sm">
                        <thead class="bg-[#0d141d]">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-300">Item</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-300">Category</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-300">Location</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-300">Reporter</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-300">Reported</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-300">Match</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-300">Status</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php foreach ($items as $item): ?>
                                <?php
                                $match_count = (int)($item['match_count'] ?? 0);
                                $best_match_score = (int)($item['best_match_score'] ?? 0);
                                $status_classes = [
                                    'found' => 'bg-emerald-500/20 text-emerald-100 border border-emerald-400/30',
                                    'lost' => 'bg-red-500/20 text-red-100 border border-red-400/30',
                                    'claimed' => 'bg-cyan-500/20 text-cyan-100 border border-cyan-400/30',
                                    'unclaimed' => 'bg-amber-500/20 text-amber-100 border border-amber-400/30',
                                    'redistributed' => 'bg-slate-600/30 text-slate-100 border border-slate-500/40'
                                ];
                                $status_class = $status_classes[$item['status']] ?? 'bg-slate-700/40 text-slate-100 border border-slate-500/40';
                                $report_date = date('M j, Y', strtotime($item['report_date']));
                                $can_claim = ($item['status'] === 'found');

                                $image_src = null;
                                if (!empty($item['item_image']) && $item['item_image'] !== 'null') {
                                    $image_src = (strpos($item['item_image'], 'uploads/') !== false)
                                        ? ('../' . $item['item_image'])
                                        : ('../uploads/' . $item['item_image']);
                                }
                                $itemPlaceholderSrc = finditAssetUrl('images/placeholders/item-default.svg');
                                ?>
                                <tr class="transition-colors hover:bg-slate-900/60" data-item-id="<?php echo (int)$item['item_id']; ?>">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3 min-w-[220px]">
                                            <?php if ($image_src): ?>
                                                <img src="<?php echo htmlspecialchars($image_src); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="h-12 w-12 rounded-md border border-slate-700 object-cover" onerror="this.onerror=null; this.src='<?php echo htmlspecialchars($itemPlaceholderSrc, ENT_QUOTES, 'UTF-8'); ?>';">
                                            <?php else: ?>
                                                <div class="h-12 w-12 rounded-md border border-slate-700 bg-slate-800"></div>
                                            <?php endif; ?>
                                            <div class="min-w-0">
                                                <p class="truncate font-semibold text-slate-100"><?php echo htmlspecialchars($item['title']); ?></p>
                                                <p class="truncate text-xs text-slate-400"><?php echo htmlspecialchars($item['description'] ?: 'No description provided'); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-300"><?php echo htmlspecialchars($item['category'] ?? 'N/A'); ?></td>
                                    <td class="px-4 py-3 text-slate-300"><?php echo htmlspecialchars($item['location'] ?? 'Not provided'); ?></td>
                                    <td class="px-4 py-3 text-slate-300"><?php echo htmlspecialchars($item['reporter_name']); ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-slate-300"><?php echo $report_date; ?></td>
                                    <td class="px-4 py-3">
                                        <?php if ($match_count > 0): ?>
                                            <span class="inline-flex items-center rounded-full border border-orange-400/30 bg-orange-500/20 px-2.5 py-1 text-xs font-semibold text-orange-100">
                                                <?php echo $best_match_score; ?>% (<?php echo $match_count; ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500">No match</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars(ucfirst($item['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <button class="theme-btn-soft border text-xs font-medium py-1.5 px-3 rounded-lg inline-flex items-center" onclick="viewItemDetails(<?php echo (int)$item['item_id']; ?>, <?php echo $item['match_id'] ?? 'null'; ?>)">
                                                View
                                            </button>
                                            <?php if ($can_claim): ?>
                                                <button class="theme-btn text-xs font-semibold py-1.5 px-3 rounded-lg inline-flex items-center" onclick="claimItem('<?php echo htmlspecialchars($item['title'], ENT_QUOTES); ?>', <?php echo (int)$item['item_id']; ?>, <?php echo $item['match_id'] ?? 'null'; ?>)">
                                                    Claim
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        </div>
    </main>

    <!-- Modern Claim Confirmation Modal -->
    <div id="claimConfirmModal" class="fixed inset-0 theme-modal-backdrop items-center justify-center z-50 hidden">
        <div class="claimant-modal-panel theme-modal-panel rounded-2xl max-w-md w-full mx-4 transform transition-all">
            <div class="p-6">
                <!-- Icon -->
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-orange-500/20 border border-orange-400/40 mb-4">
                    <svg class="h-8 w-8 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                
                <!-- Content -->
                <div class="text-center">
                    <h3 class="text-lg font-semibold text-gray-100 mb-2 inline-flex items-center gap-2"><svg class="w-5 h-5 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0"></path></svg>Claim This Item?</h3>
                    <p class="text-sm text-gray-300 mb-1" id="claimItemTitle"></p>
                    <p class="text-sm text-gray-400 mt-4">
                        You'll complete object verification, and your claim will be submitted automatically.
                    </p>
                </div>
                
                <!-- Actions -->
                <div class="mt-6 flex flex-col-reverse sm:flex-row sm:gap-3 gap-2">
                    <button onclick="closeClaimModal()" 
                            class="w-full theme-btn-soft inline-flex items-center justify-center gap-2 rounded-lg border border-gray-600 shadow-sm px-4 py-2.5 text-base font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        Cancel
                    </button>
                    <button onclick="confirmClaimItem()" 
                            class="w-full theme-btn inline-flex items-center justify-center gap-2 rounded-lg border border-transparent shadow-sm px-4 py-2.5 text-base font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        Yes, Claim Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="claimSliderModal" class="fixed inset-0 theme-modal-backdrop hidden items-center justify-center p-4">
        <div id="claimSliderPanel" class="claim-slider-panel p-5">
            <div class="claim-slider-header">
                <h3>Security Check Required</h3>
                <p class="claim-slider-caption">Complete the slider puzzle to continue claiming <span id="claimSliderItemName">this item</span>.</p>
            </div>

            <div id="claimSliderStage" class="claim-slider-stage" aria-hidden="true">
                <canvas id="claimSliderBackgroundCanvas" class="claim-slider-canvas" width="320" height="170"></canvas>
                <canvas id="claimSliderPieceCanvas" class="claim-slider-piece-canvas" width="60" height="60" aria-label="Puzzle piece"></canvas>
            </div>

            <div id="claimSliderTrack" class="claim-slider-track" role="slider" aria-label="Puzzle slider handle" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div id="claimSliderFill" class="claim-slider-fill"></div>
                <div class="claim-slider-label">Drag the handle and piece diagonally to fit the slot</div>
                <button id="claimSliderHandle" type="button" class="claim-slider-handle" aria-label="Drag puzzle handle">
                    <span class="claim-slider-handle-icon">⇄</span>
                </button>
            </div>

            <p id="claimSliderStatus" class="claim-slider-status">Preparing challenge...</p>

            <div class="claim-slider-actions">
                <button id="claimSliderCloseBtn" type="button" class="claim-slider-btn claim-slider-btn-soft">Cancel</button>
                <button id="claimSliderRetryBtn" type="button" class="claim-slider-btn claim-slider-btn-soft">Retry</button>
                <button id="claimSliderVerifyBtn" type="button" class="claim-slider-btn claim-slider-btn-main" disabled>Verify</button>
            </div>
        </div>
    </div>

    <!-- View Item Details Modal -->
    <div id="viewItemModal" class="fixed inset-0 theme-modal-backdrop items-center justify-center z-50 hidden">
        <div class="claimant-modal-panel theme-modal-panel rounded-2xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
            <div class="p-6">
                <!-- Modal Header -->
                <div class="flex justify-between items-center mb-6 border-b pb-4">
                    <h2 class="text-2xl font-bold text-gray-100 inline-flex items-center gap-2"><svg class="w-6 h-6 text-orange-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01"></path></svg>Item Details</h2>
                    <button onclick="closeViewItemModal()" class="text-gray-300 hover:text-white transition-colors" aria-label="Close item details modal">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <!-- Modal Content -->
                <div id="viewItemContent" class="space-y-6">
                    <!-- Content will be loaded dynamically -->
                    <div class="flex items-center justify-center py-8">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-orange-400"></div>
                        <span class="ml-3 text-gray-300">Loading item details...</span>
                    </div>
                </div>
                
                <!-- Modal Footer -->
                <div class="flex justify-end space-x-3 mt-6 pt-4 border-t">
                    <button onclick="closeViewItemModal()" 
                            class="theme-btn-soft px-6 py-2 rounded-xl font-medium transition-colors inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        Close
                    </button>
                    <button id="claimItemBtn" onclick="claimItemFromModal()" 
                            class="theme-btn px-6 py-2 rounded-xl font-medium transition-all hidden inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        Claim Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Enhanced search functionality and scrollable features
        document.addEventListener('DOMContentLoaded', function() {
            initScrollFeatures();
            initFilterFunctionality();
            animateGridItems();
            addBackToTopButton();
            initClaimSliderChallenge();
            showClaimFlowMessagesFromQuery();
        });

        function initScrollFeatures() {
            const mainContent = document.querySelector('.main-content');
            
            // Add scroll progress indicator
            const progressBar = document.createElement('div');
            progressBar.style.cssText = `
                position: fixed;
                top: 0;
                left: 280px;
                right: 0;
                height: 3px;
                background: linear-gradient(135deg, #0f766e, #0d9488);
                transform-origin: left;
                transform: scaleX(0);
                transition: transform 0.1s ease;
                z-index: 1000;
            `;
            document.body.appendChild(progressBar);

            // Update progress bar on scroll
            mainContent.addEventListener('scroll', function() {
                const scrollTop = this.scrollTop;
                const scrollHeight = this.scrollHeight - this.clientHeight;
                const scrollPercent = scrollTop / scrollHeight;
                progressBar.style.transform = `scaleX(${scrollPercent})`;
            });

            // Smooth scroll to results when filters are applied
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('search') || urlParams.has('category') || urlParams.has('status')) {
                setTimeout(() => {
                    const itemsGrid = document.getElementById('itemsGrid');
                    if (itemsGrid) {
                        itemsGrid.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }, 500);
            }
        }

        function initFilterFunctionality() {
            // Auto-submit form on filter changes for better UX
            const selects = document.querySelectorAll('select[name="category"], select[name="sort"], select[name="order"]');
            selects.forEach(select => {
                select.addEventListener('change', function() {
                    // Add loading state
                    const submitBtn = document.querySelector('button[type="submit"]');
                    const originalText = submitBtn.textContent;
                    submitBtn.textContent = 'Applying...';
                    submitBtn.disabled = true;
                    
                    // Submit form
                    this.form.submit();
                });
            });

            // Real-time search with debounce
            const searchInput = document.querySelector('input[name="search"]');
            let searchTimeout;
            
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    // Optional: Auto-submit search after typing stops
                    // this.form.submit();
                }, 1000);
            });
        }

        function animateGridItems() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);

            // Observe all grid items
            document.querySelectorAll('.grid-item').forEach(item => {
                observer.observe(item);
            });

            // Animate fade-in elements
            document.querySelectorAll('.fade-in').forEach((el, index) => {
                el.style.animationDelay = `${index * 0.1}s`;
            });
        }

        function addBackToTopButton() {
            const backToTop = document.createElement('button');
            backToTop.innerHTML = '&uarr;';
            backToTop.style.cssText = `
                position: fixed;
                bottom: 2rem;
                right: 2rem;
                width: 50px;
                height: 50px;
                border-radius: 50%;
                background: linear-gradient(135deg, #0f766e, #0d9488);
                color: white;
                border: none;
                font-size: 20px;
                cursor: pointer;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                opacity: 0;
                transform: scale(0);
                transition: all 0.3s ease;
                z-index: 1000;
            `;
            
            backToTop.addEventListener('click', function() {
                document.querySelector('.main-content').scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            document.body.appendChild(backToTop);

            // Show/hide back to top button
            document.querySelector('.main-content').addEventListener('scroll', function() {
                if (this.scrollTop > 300) {
                    backToTop.style.opacity = '1';
                    backToTop.style.transform = 'scale(1)';
                } else {
                    backToTop.style.opacity = '0';
                    backToTop.style.transform = 'scale(0)';
                }
            });
        }

        // Modern Claim Modal Functions
        let pendingClaimItemId = null;
        let pendingClaimItemName = null;
        let pendingClaimMatchId = null;
        let claimSliderChallenge = null;

        function initClaimSliderChallenge() {
            if (typeof window.FindItClaimSliderChallenge !== 'function') {
                return;
            }

            claimSliderChallenge = new window.FindItClaimSliderChallenge({
                endpoint: 'api/security/slider_challenge_api.php'
            });
        }

        function showClaimFlowMessagesFromQuery() {
            const params = new URLSearchParams(window.location.search);
            const error = params.get('error');

            const errorMessages = {
                security_slider_required: 'Complete the security slider before claiming this item.',
                invalid_claim_request: 'Invalid claim request. Please try again from the list.',
                unauthorized_match: 'That match cannot be claimed from your account.',
                claim_flow_error: 'Claim flow failed. Please retry your request.'
            };

            if (error && errorMessages[error]) {
                showErrorToast(errorMessages[error]);
            }

            if (error) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        }

        function clearPendingClaimState() {
            pendingClaimItemId = null;
            pendingClaimItemName = null;
            pendingClaimMatchId = null;
        }

        function claimItem(itemName, itemId, matchId) {
            pendingClaimItemId = itemId;
            pendingClaimItemName = itemName;
            pendingClaimMatchId = matchId;
            
            // Update modal content
            document.getElementById('claimItemTitle').textContent = `"${itemName}"`;
            
            // Show modal
            const modal = document.getElementById('claimConfirmModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Add animation
            setTimeout(() => {
                modal.querySelector('.claimant-modal-panel').classList.add('scale-100');
                modal.querySelector('.claimant-modal-panel').classList.remove('scale-95');
            }, 10);
        }

        function closeClaimModal(resetState = true) {
            const modal = document.getElementById('claimConfirmModal');
            const modalContent = modal.querySelector('.claimant-modal-panel');
            
            // Animate out
            modalContent.classList.add('scale-95');
            modalContent.classList.remove('scale-100');
            
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                if (resetState) {
                    clearPendingClaimState();
                }
            }, 200);
        }

        function confirmClaimItem() {
            if (!pendingClaimItemId || !pendingClaimMatchId) {
                showErrorToast('Invalid item or match information. Please refresh and try again.');
                return;
            }

            const claimContext = {
                itemId: pendingClaimItemId,
                matchId: pendingClaimMatchId,
                itemName: pendingClaimItemName || 'this item'
            };

            closeClaimModal(false);

            if (!claimSliderChallenge) {
                startObjectVerification(claimContext.itemId, claimContext.matchId);
                clearPendingClaimState();
                return;
            }

            claimSliderChallenge.open(claimContext)
                .then(() => {
                    showSuccessToast('Security check passed. Redirecting to object verification...');
                    setTimeout(() => {
                        startObjectVerification(claimContext.itemId, claimContext.matchId);
                        clearPendingClaimState();
                    }, 700);
                })
                .catch((error) => {
                    if (error && error.message && error.message !== 'cancelled') {
                        showErrorToast(error.message);
                    }
                    clearPendingClaimState();
                });
        }

        function startObjectVerification(itemId, matchId) {
            if (!itemId || !matchId) {
                showErrorToast('Missing item or match details for verification.');
                return;
            }

            const targetUrl = `claim-item.php?item_id=${encodeURIComponent(itemId)}&match_id=${encodeURIComponent(matchId)}`;
            window.location.href = targetUrl;
        }

        // Toast notification functions
        function showSuccessToast(message) {
            const toast = document.createElement('div');
            toast.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 fade-in';
            toast.innerHTML = `
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    ${message}
                </div>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-20px)';
                setTimeout(() => toast.remove(), 200);
            }, 2000);
        }
        
        function showErrorToast(message) {
            const toast = document.createElement('div');
            toast.className = 'fixed top-4 right-4 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 fade-in';
            toast.innerHTML = `
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    ${message}
                </div>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-20px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Helper function to escape HTML
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        // View Item Details Modal Functions
        let currentViewingItemId = null;
        let currentViewingMatchId = null;

        function viewItemDetails(itemId, matchId = null) {
            currentViewingItemId = itemId;
            currentViewingMatchId = matchId;
            const modal = document.getElementById('viewItemModal');
            const content = document.getElementById('viewItemContent');
            const claimBtn = document.getElementById('claimItemBtn');
            
            // Show modal and loading state
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Reset content to loading state
            content.innerHTML = `
                <div class="flex items-center justify-center py-8">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-orange-400"></div>
                    <span class="ml-3 text-gray-300">Loading item details...</span>
                </div>
            `;
            
            // Hide claim button initially
            claimBtn.classList.add('hidden');
            
            // Simulate API call to get item details (replace with actual API call)
            setTimeout(() => {
                // In a real implementation, you would fetch from get_item_details.php or similar
                loadItemDetails(itemId);
            }, 500);
        }

        function loadItemDetails(itemId) {
            fetch(`api/get_item_details.php?id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.item) {
                        displayItemDetails(data.item);
                    } else {
                        showItemDetailsError(data.error || 'Item not found');
                    }
                })
                .catch(error => {
                    showItemDetailsError(error.message || 'Network error');
                });
        }

        function displayItemDetails(item) {
            const content = document.getElementById('viewItemContent');
            const claimBtn = document.getElementById('claimItemBtn');
            
            // Determine if this item can be claimed
            const canClaim = item.status === 'found';
            
            // Prepare image URL
            let imageHtml = '';
            if (item.item_image && item.item_image !== 'null' && item.item_image !== '') {
                imageHtml = `<img src="${item.item_image}" 
                                  alt="${escapeHtml(item.title)}" 
                                  class="w-full h-64 object-cover"
                                  onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\\'w-full h-64 flex items-center justify-center bg-gray-100\\'><svg class=\\'w-16 h-16 text-gray-400\\' fill=\\'currentColor\\' viewBox=\\'0 0 24 24\\'><path d=\\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.94-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z\\'/></svg><p class=\\'text-sm text-gray-500 mt-2\\'>Image not available</p></div>';">`;
            } else {
                imageHtml = `<div class="w-full h-64 flex items-center justify-center bg-gradient-to-br from-gray-100 to-gray-200">
                    <div class="text-center">
                        <svg class="w-16 h-16 text-gray-400 mx-auto mb-2" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.94-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                        </svg>
                        <p class="text-sm text-gray-500">No image available</p>
                    </div>
                </div>`;
            }
            
            content.innerHTML = `
                <!-- Item Image -->
                <div class="aspect-w-16 aspect-h-12 bg-gray-100 rounded-xl overflow-hidden">
                    ${imageHtml}
                </div>
                
                <!-- Item Information -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">${escapeHtml(item.title)}</h3>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${getStatusClasses(item.status)}">
                                ${item.status.charAt(0).toUpperCase() + item.status.slice(1)}
                            </span>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Description</label>
                            <p class="text-gray-900">${escapeHtml(item.description || 'No description provided.')}</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Category</label>
                            <p class="text-gray-900">${escapeHtml(item.category)}</p>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Reported By</label>
                            <p class="text-gray-900">${escapeHtml(item.reporter_name)}</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Date Reported</label>
                            <p class="text-gray-900">${new Date(item.report_date).toLocaleDateString('en-US', {
                                year: 'numeric',
                                month: 'long',
                                day: 'numeric'
                            })}</p>
                        </div>
                        
                        ${item.location ? `
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Location</label>
                            <p class="text-gray-900">${escapeHtml(item.location)}</p>
                        </div>
                        ` : ''}
                    </div>
                </div>
                
                ${item.matches && item.matches.length > 0 ? `
                <!-- Smart Matches Section -->
                <div class="border-t pt-6 mt-6">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-lg font-semibold text-gray-900">Smart Matches Found</h4>
                        <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium">
                            ${item.matches.length} potential match${item.matches.length > 1 ? 'es' : ''}
                        </span>
                    </div>
                    <div class="space-y-3 max-h-60 overflow-y-auto">
                        ${item.matches.map(match => {
                            const matchImageHtml = (match.match_image && match.match_image !== 'null' && match.match_image !== '') 
                                ? `<img src="${match.match_image}" 
                                        alt="${escapeHtml(match.match_title)}" 
                                        class="w-12 h-12 object-cover rounded-lg"
                                        onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\\'w-12 h-12 bg-gray-200 rounded-lg flex items-center justify-center\\'><svg class=\\'w-6 h-6 text-gray-400\\' fill=\\'currentColor\\' viewBox=\\'0 0 24 24\\'><path d=\\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z\\'/></svg></div>';">`
                                : `<div class="w-12 h-12 bg-gradient-to-br from-gray-200 to-gray-300 rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                                    </svg>
                                </div>`;
                            
                            return `
                            <div class="flex items-center space-x-3 p-3 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100 transition-colors">
                                <div class="flex-shrink-0">
                                    ${matchImageHtml}
                                </div>
                                <div class="flex-grow min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate">${escapeHtml(match.match_title)}</p>
                                        <span class="ml-2 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            ${Math.round(match.similarity_score)}% match
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate">${escapeHtml(match.match_description || 'No description')}</p>
                                    <p class="text-xs text-gray-600 mt-1">
                                        Contact: ${escapeHtml(match.finder_name || match.owner_name || 'N/A')}
                                    </p>
                                </div>
                            </div>`;
                        }).join('')}
                    </div>
                    <div class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="flex items-start">
                            <svg class="h-5 w-5 text-blue-400 mr-2 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                            </svg>
                            <div>
                                <h5 class="text-sm font-semibold text-blue-800">Smart Matching System</h5>
                                <p class="text-xs text-blue-700 mt-1">
                                    These matches were automatically found using AI-powered keyword analysis and item descriptions. 
                                    Higher percentages indicate better potential matches.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                ` : ''}
                
                <!-- Additional Information -->
                ${item.status === 'found' ? `
                <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                    <div class="flex">
                        <svg class="h-5 w-5 text-green-400 mr-2 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <h4 class="text-sm font-semibold text-green-800">This item has been found!</h4>
                            <p class="text-sm text-green-700 mt-1">If this is your item, you can claim it by clicking the claim button below. The finder will be notified and you'll receive contact information.</p>
                        </div>
                    </div>
                </div>
                ` : ''}
            `;
            
            // Show/hide claim button based on item status
            if (canClaim) {
                claimBtn.classList.remove('hidden');
                claimBtn.setAttribute('data-item-id', item.item_id);
                claimBtn.setAttribute('data-item-title', item.title);
                const selectedMatchId = currentViewingMatchId || item.match_id || (Array.isArray(item.matches) && item.matches.length > 0 ? item.matches[0].match_id : '');
                claimBtn.setAttribute('data-match-id', selectedMatchId || '');
            } else {
                claimBtn.classList.add('hidden');
            }
        }

        function getStatusClasses(status) {
            const statusClasses = {
                'found': 'bg-emerald-500/20 text-emerald-100 border border-emerald-400/30',
                'lost': 'bg-red-500/20 text-red-100 border border-red-400/30',
                'claimed': 'bg-cyan-500/20 text-cyan-100 border border-cyan-400/30',
                'unclaimed': 'bg-amber-500/20 text-amber-100 border border-amber-400/30',
                'redistributed': 'bg-slate-600/30 text-slate-100 border border-slate-500/40'
            };
            return statusClasses[status] || 'bg-slate-700/40 text-slate-100 border border-slate-500/40';
        }

        function closeViewItemModal() {
            const modal = document.getElementById('viewItemModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            currentViewingItemId = null;
        }

        function claimItemFromModal() {
            const claimBtn = document.getElementById('claimItemBtn');
            const itemId = claimBtn.getAttribute('data-item-id');
            const matchId = claimBtn.getAttribute('data-match-id');
            const itemTitle = claimBtn.getAttribute('data-item-title');
            
            if (itemId && itemTitle && matchId) {
                claimItem(itemTitle, Number(itemId), Number(matchId));
                closeViewItemModal();
            } else {
                showErrorToast('Unable to start claim verification for this item.');
            }
        }

        function showItemDetailsError(error) {
            const content = document.getElementById('viewItemContent');
            content.innerHTML = `
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Error Loading Item Details</h3>
                    <p class="text-gray-600">Unable to load item details. Please try again later.</p>
                </div>
            `;
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const viewModal = document.getElementById('viewItemModal');
            const claimModal = document.getElementById('claimConfirmModal');
            const sliderModal = document.getElementById('claimSliderModal');
            
            if (event.target === viewModal) {
                closeViewItemModal();
            }
            if (event.target === claimModal) {
                closeClaimModal();
            }
            if (event.target === sliderModal && claimSliderChallenge) {
                claimSliderChallenge.cancel('cancelled');
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const viewModal = document.getElementById('viewItemModal');
                const claimModal = document.getElementById('claimConfirmModal');
                
                if (!viewModal.classList.contains('hidden')) {
                    closeViewItemModal();
                }
                if (!claimModal.classList.contains('hidden')) {
                    closeClaimModal();
                }
            }
        });

        // Smooth scroll to specific item (if coming from a link)
        function scrollToItem(itemId) {
            const item = document.querySelector(`[data-item-id="${itemId}"]`);
            if (item) {
                item.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                item.classList.add('highlight');
                setTimeout(() => {
                    item.classList.remove('highlight');
                }, 2000);
            }
        }

        // Add highlight effect for direct links
        const style = document.createElement('style');
        style.textContent = `
            .highlight {
                animation: highlightPulse 2s ease-in-out;
            }
            
            @keyframes highlightPulse {
                0%, 100% { transform: scale(1); box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
                50% { transform: scale(1.02); box-shadow: 0 8px 25px rgba(25, 118, 210, 0.3); }
            }
            
            /* Match badge animations */
            @keyframes matchPulse {
                0%, 100% { transform: scale(1); opacity: 1; }
                50% { transform: scale(1.05); opacity: 0.9; }
            }
            
            .match-badge-high {
                animation: matchPulse 2s ease-in-out infinite;
            }
            
            /* Shimmer effect for high matches */
            @keyframes shimmer {
                0% { background-position: -1000px 0; }
                100% { background-position: 1000px 0; }
            }
            
            .match-shimmer {
                background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
                background-size: 1000px 100%;
                animation: shimmer 3s infinite;
            }
            
            /* Modal animations */
            #claimConfirmModal .claimant-modal-panel {
                transform: scale(0.95);
                transition: transform 0.2s ease-out;
            }
            
            #claimConfirmModal .claimant-modal-panel.scale-100 {
                transform: scale(1);
            }
            
            #claimConfirmModal .claimant-modal-panel.scale-95 {
                transform: scale(0.95);
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>


