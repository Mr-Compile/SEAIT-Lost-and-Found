<?php

require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole('claimant');

$user = getUserInfo();
$userId = (int)($user['user_id'] ?? 0);
$userName = (string)($user['full_name'] ?? 'User');
$currentPage = basename((string)($_SERVER['PHP_SELF'] ?? 'claimant_dashboard.php'));

$totalLost = 0;
$totalFound = 0;
$matchedItems = 0;
$claimedItems = 0;
$expiringSoon = 0;
$pendingMatches = 0;
$recentItems = [];
$recentNotifications = [];
$trendRows = [];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE user_id = ? AND status = 'lost'");
    $stmt->execute([$userId]);
    $totalLost = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE user_id = ? AND status = 'found'");
    $stmt->execute([$userId]);
    $totalFound = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE user_id = ? AND status = 'claimed'");
    $stmt->execute([$userId]);
    $claimedItems = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT i.item_id)
         FROM items i
         INNER JOIN itemmatches im ON (i.item_id = im.lost_item_id OR i.item_id = im.found_item_id)
         WHERE i.user_id = ?"
    );
    $stmt->execute([$userId]);
    $matchedItems = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM items
         WHERE user_id = ?
           AND status = 'found'
           AND hold_expiry IS NOT NULL
           AND hold_expiry > CURDATE()
           AND hold_expiry <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
    );
    $stmt->execute([$userId]);
    $expiringSoon = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM itemmatches im
         INNER JOIN items i ON (i.item_id = im.lost_item_id OR i.item_id = im.found_item_id)
         WHERE i.user_id = ? AND i.status IN ('lost', 'found')"
    );
    $stmt->execute([$userId]);
    $pendingMatches = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT
            i.item_id,
            i.title,
            i.description,
            i.category,
            i.status,
            i.report_date,
            i.hold_expiry,
            COALESCE(MAX(im.similarity_score), 0) AS match_score,
            CASE
                WHEN MAX(im.match_id) IS NOT NULL THEN 'Matched'
                WHEN i.status = 'claimed' THEN 'Claimed'
                WHEN i.status = 'found' AND i.hold_expiry IS NOT NULL AND i.hold_expiry <= CURDATE() THEN 'Expired'
                WHEN i.status = 'found' AND i.hold_expiry IS NOT NULL AND i.hold_expiry <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Expiring Soon'
                ELSE 'Searching'
            END AS display_status
         FROM items i
         LEFT JOIN itemmatches im ON (i.item_id = im.lost_item_id OR i.item_id = im.found_item_id)
         WHERE i.user_id = ?
         GROUP BY i.item_id, i.title, i.description, i.category, i.status, i.report_date, i.hold_expiry
         ORDER BY i.report_date DESC
         LIMIT 8"
    );
    $stmt->execute([$userId]);
    $recentItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare(
        "SELECT n.notification_id, n.type, n.message, n.sent_at, n.is_read, i.title AS item_title
         FROM notifications n
         INNER JOIN items i ON n.item_id = i.item_id
         WHERE n.user_id = ?
         ORDER BY n.sent_at DESC
         LIMIT 4"
    );
    $stmt->execute([$userId]);
    $recentNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare(
        "SELECT
            DATE_FORMAT(report_date, '%Y-%m') AS month_key,
            DATE_FORMAT(report_date, '%b') AS month_label,
            SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost_count,
            SUM(CASE WHEN status = 'found' THEN 1 ELSE 0 END) AS found_count,
            SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) AS claimed_count
         FROM items
         WHERE user_id = ?
           AND report_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY month_key, month_label
         ORDER BY month_key"
    );
    $stmt->execute([$userId]);
    $trendRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('Claimant dashboard load error: ' . $e->getMessage());
}

function claimantStatusBadge(string $status): array
{
    switch ($status) {
        case 'Matched':
            return ['Matched', 'bg-emerald-100 text-emerald-700 border border-emerald-200'];
        case 'Claimed':
            return ['Claimed', 'bg-sky-100 text-sky-700 border border-sky-200'];
        case 'Expired':
            return ['Expired', 'bg-red-100 text-red-700 border border-red-200'];
        case 'Expiring Soon':
            return ['Expiring Soon', 'bg-amber-100 text-amber-700 border border-amber-200'];
        default:
            return ['Searching', 'bg-slate-100 text-slate-700 border border-slate-200'];
    }
}

$trendMap = [];
foreach ($trendRows as $row) {
    $key = (string)($row['month_key'] ?? '');
    if ($key === '') {
        continue;
    }
    $trendMap[$key] = [
        'label' => (string)($row['month_label'] ?? ''),
        'lost' => (int)($row['lost_count'] ?? 0),
        'found' => (int)($row['found_count'] ?? 0),
        'claimed' => (int)($row['claimed_count'] ?? 0),
    ];
}

$trendLabels = [];
$trendLost = [];
$trendFound = [];
$trendClaimed = [];
for ($i = 5; $i >= 0; $i--) {
    $month = new DateTimeImmutable('first day of this month');
    $month = $month->modify('-' . $i . ' month');
    $key = $month->format('Y-m');
    $fallbackLabel = $month->format('M');
    $data = $trendMap[$key] ?? ['label' => $fallbackLabel, 'lost' => 0, 'found' => 0, 'claimed' => 0];
    $trendLabels[] = $data['label'];
    $trendLost[] = (int)$data['lost'];
    $trendFound[] = (int)$data['found'];
    $trendClaimed[] = (int)$data['claimed'];
}

$statusTotals = [
    'lost' => $totalLost,
    'found' => $totalFound,
    'claimed' => $claimedItems,
    'matched' => $matchedItems,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claimant Dashboard</title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0f14;
            --panel: #131922;
            --line: #273040;
            --text: #ecf2ff;
            --muted: #95a4bc;
            --accent: #ff5b1f;
        }

        body {
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(circle at 8% 8%, rgba(255, 122, 28, 0.16), transparent 38%),
                radial-gradient(circle at 92% 4%, rgba(34, 211, 238, 0.12), transparent 42%),
                var(--bg);
            font-family: 'Manrope', 'Segoe UI', sans-serif;
        }

        h1, h2, h3 {
            font-family: 'Plus Jakarta Sans', 'Manrope', sans-serif;
            letter-spacing: -0.02em;
        }

        .main-shell {
            margin-left: 280px;
            min-height: 100vh;
            padding: 24px;
        }

        .hero {
            background: linear-gradient(140deg, #ff7b2c, #ff5b1f 52%, #ef4444);
            border-radius: 22px;
            padding: 24px;
            color: #fff7f2;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.36);
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 18px;
            align-items: center;
        }

        .hero-kicker {
            font-size: 0.73rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            opacity: 0.94;
            font-weight: 700;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .hero-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 9999px;
            padding: 0.55rem 0.9rem;
            font-size: 0.78rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.28);
            color: #f0fdfa;
            text-decoration: none;
        }

        .hero-btn:hover {
            background: rgba(255, 255, 255, 0.24);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin-top: 18px;
        }

        .stat-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 11px 12px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 800;
            line-height: 1;
            margin-top: 0.3rem;
        }

        .analytics-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 14px;
            margin-top: 18px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.05);
        }

        .panel-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .panel-body {
            padding: 16px;
        }

        .chart-wrap {
            position: relative;
            height: 280px;
        }

        .items-grid {
            display: grid;
            grid-template-columns: 1.8fr 1fr;
            gap: 14px;
            margin-top: 16px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 0.8rem 0.85rem;
            text-align: left;
            vertical-align: middle;
        }

        .data-table th {
            font-size: 0.72rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #9fb0c7;
            background: #0f151e;
            border-bottom: 1px solid #273040;
            font-weight: 700;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #273040;
        }

        .data-table tbody tr:hover {
            background: #1a212d;
        }

        .finder-filter {
            width: 280px;
            max-width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 0.58rem 0.78rem;
            font-size: 0.88rem;
        }

        .finder-filter:focus {
            border-color: #ff5b1f;
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.16);
            outline: none;
        }

        .item-initial {
            width: 34px;
            height: 34px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.74rem;
            font-weight: 800;
            color: #ffffff;
            background: linear-gradient(135deg, #ff5b1f, #ff7b2c);
        }

        .muted-note {
            color: var(--muted);
            font-size: 0.84rem;
        }

        .empty-state {
            padding: 2.2rem 1rem;
            text-align: center;
            color: var(--muted);
        }

        @media (max-width: 1280px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 980px) {
            .main-shell {
                margin-left: 0;
                padding: 14px;
            }

            .hero-grid,
            .analytics-grid,
            .items-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body class="claimant-theme">
    <?php include '../includes/components/shared_sidebar.php'; ?>

    <main class="main-shell">
        <div class="max-w-7xl mx-auto">
            <section class="hero">
                <div class="hero-grid">
                    <div>
                        <p class="hero-kicker">Claimant Analytics Workspace</p>
                        <h1 class="text-3xl md:text-4xl font-extrabold mt-2">Welcome back, <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?></h1>
                        <p class="mt-3 text-sm md:text-base text-teal-50 max-w-2xl">
                            Monitor your reports, track match confidence, and follow claim progress using live account-specific data.
                        </p>
                        <div class="hero-actions">
                            <a class="hero-btn" href="claimant_my_items.php?action=report">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                Report Item
                            </a>
                            <a class="hero-btn" href="claimant_matches_chats.php">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 10H16M8 14H13M20 12C20 16.4183 16.4183 20 12 20C10.4148 20 8.93732 19.5399 7.69385 18.7468L4 20L5.25323 16.3062C4.46009 15.0627 4 13.5852 4 12C4 7.58172 7.58172 4 12 4C16.4183 4 20 7.58172 20 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                Matches and Chats
                            </a>
                            <a class="hero-btn" href="claimant_all_items.php">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11 5H6C4.89543 5 4 5.89543 4 7V18C4 19.1046 4.89543 20 6 20H18C19.1046 20 20 19.1046 20 18V13M18.5 5.5L12 12M16 5H19V8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                Browse Items
                            </a>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-white/25 bg-white/10 p-4">
                        <p class="text-xs uppercase tracking-wider text-teal-50/90 font-semibold">Session summary</p>
                        <div class="mt-3 space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-teal-50/90">Account role</span>
                                <span class="font-semibold uppercase">Claimant</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-teal-50/90">Pending matches</span>
                                <span class="font-semibold"><?php echo number_format($pendingMatches); ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-teal-50/90">Unread notices</span>
                                <span class="font-semibold"><?php echo number_format(count($recentNotifications)); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="stats-grid">
                <article class="stat-card">
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Lost Items</p>
                    <p class="stat-value text-slate-900"><?php echo number_format($totalLost); ?></p>
                    <p class="muted-note mt-2">Open reports currently unresolved.</p>
                </article>
                <article class="stat-card">
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Found Items</p>
                    <p class="stat-value text-slate-900"><?php echo number_format($totalFound); ?></p>
                    <p class="muted-note mt-2">Items you reported as found.</p>
                </article>
                <article class="stat-card">
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Matched Items</p>
                    <p class="stat-value text-slate-900"><?php echo number_format($matchedItems); ?></p>
                    <p class="muted-note mt-2">Records with at least one match.</p>
                </article>
                <article class="stat-card">
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Claimed Items</p>
                    <p class="stat-value text-slate-900"><?php echo number_format($claimedItems); ?></p>
                    <p class="muted-note mt-2">Completed ownership recovery.</p>
                </article>
                <article class="stat-card">
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Expiring Soon</p>
                    <p class="stat-value text-slate-900"><?php echo number_format($expiringSoon); ?></p>
                    <p class="muted-note mt-2">Found items close to hold expiry.</p>
                </article>
            </section>

            <section class="analytics-grid">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">Six-Month Reporting Trend</h2>
                            <p class="text-xs text-slate-500 mt-1">Account-level analytics for lost, found, and claimed statuses.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="chart-wrap">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">Status Mix</h2>
                            <p class="text-xs text-slate-500 mt-1">Distribution of your item states.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="chart-wrap" style="height: 240px;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </article>
            </section>

            <section class="items-grid">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">Recent Item Activity</h2>
                            <p class="text-xs text-slate-500 mt-1">Latest records with match confidence and lifecycle status.</p>
                        </div>
                        <label>
                            <span class="sr-only">Filter items</span>
                            <input
                                id="itemFilter"
                                type="text"
                                class="finder-filter"
                                maxlength="50"
                                pattern="[A-Za-z0-9 .,'-]*"
                                autocomplete="off"
                                placeholder="Filter by title or category"
                            >
                        </label>
                    </div>
                    <div class="panel-body table-wrap">
                        <?php if (!empty($recentItems)): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Status</th>
                                        <th>Match Score</th>
                                        <th>Reported</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTableBody">
                                    <?php foreach ($recentItems as $item): ?>
                                        <?php
                                            $title = (string)($item['title'] ?? 'Untitled Item');
                                            $category = (string)($item['category'] ?? 'General');
                                            $description = (string)($item['description'] ?? '');
                                            $score = (int)($item['match_score'] ?? 0);
                                            $search = strtolower($title . ' ' . $category);
                                            [$badgeLabel, $badgeClass] = claimantStatusBadge((string)($item['display_status'] ?? 'Searching'));
                                        ?>
                                        <tr data-search="<?php echo htmlspecialchars($search); ?>">
                                            <td>
                                                <div class="flex items-center gap-3">
                                                    <span class="item-initial"><?php echo htmlspecialchars(strtoupper(substr($title, 0, 1))); ?></span>
                                                    <div>
                                                        <p class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($title); ?></p>
                                                        <p class="text-xs text-slate-500"><?php echo htmlspecialchars($category); ?></p>
                                                        <?php if ($description !== ''): ?>
                                                            <p class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars(substr($description, 0, 70)); ?><?php echo strlen($description) > 70 ? '...' : ''; ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $badgeClass; ?>">
                                                    <?php echo htmlspecialchars($badgeLabel); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($score > 0): ?>
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-sm font-semibold text-slate-900"><?php echo $score; ?>%</span>
                                                        <div class="w-16 h-2 rounded-full bg-slate-200">
                                                            <div class="h-2 rounded-full bg-teal-600" style="width: <?php echo min($score, 100); ?>%;"></div>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-xs text-slate-400">No match yet</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-sm text-slate-600"><?php echo date('M j, Y', strtotime((string)$item['report_date'])); ?></td>
                                            <td>
                                                <a href="claimant_my_items.php?focus=<?php echo (int)($item['item_id'] ?? 0); ?>" class="inline-flex items-center rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-teal-300 hover:text-teal-700">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <h3 class="text-base font-bold text-slate-700">No items reported yet</h3>
                                <p class="mt-1 text-sm">Start by reporting your first lost or found item.</p>
                                <a href="claimant_my_items.php?action=report" class="inline-block mt-3 rounded-lg bg-teal-600 px-4 py-2 text-xs font-semibold text-white hover:bg-teal-700">Report Item</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">Recent Notifications</h2>
                            <p class="text-xs text-slate-500 mt-1">Latest alerts tied to your account and items.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($recentNotifications)): ?>
                            <div class="space-y-3">
                                <?php foreach ($recentNotifications as $notification): ?>
                                    <?php
                                        $type = strtolower((string)($notification['type'] ?? 'notice'));
                                        $iconBg = 'bg-slate-100 text-slate-600';
                                        if ($type === 'email') {
                                            $iconBg = 'bg-sky-100 text-sky-700';
                                        } elseif ($type === 'sms') {
                                            $iconBg = 'bg-emerald-100 text-emerald-700';
                                        }
                                    ?>
                                    <div class="rounded-xl border border-slate-200 p-3">
                                        <div class="flex items-start gap-3">
                                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold <?php echo $iconBg; ?>">
                                                <?php echo strtoupper(substr($type, 0, 1)); ?>
                                            </span>
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars((string)($notification['message'] ?? 'Notification')); ?></p>
                                                <p class="text-xs text-slate-500 mt-1">Item: <?php echo htmlspecialchars((string)($notification['item_title'] ?? 'Unknown')); ?></p>
                                                <p class="text-xs text-slate-400 mt-1"><?php echo date('M j, g:i A', strtotime((string)($notification['sent_at'] ?? 'now'))); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <h3 class="text-base font-bold text-slate-700">No notifications yet</h3>
                                <p class="mt-1 text-sm">Updates about matches and claims will appear here.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            </section>
        </div>
    </main>

    <script>
        const trendLabels = <?php echo json_encode($trendLabels); ?>;
        const trendLost = <?php echo json_encode($trendLost); ?>;
        const trendFound = <?php echo json_encode($trendFound); ?>;
        const trendClaimed = <?php echo json_encode($trendClaimed); ?>;
        const statusTotals = <?php echo json_encode($statusTotals); ?>;

        const trendCtx = document.getElementById('trendChart');
        if (trendCtx) {
            new Chart(trendCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: trendLabels,
                    datasets: [
                        {
                            label: 'Lost',
                            data: trendLost,
                            backgroundColor: '#ef4444',
                            borderRadius: 6,
                            borderSkipped: false,
                        },
                        {
                            label: 'Found',
                            data: trendFound,
                            backgroundColor: '#0ea5e9',
                            borderRadius: 6,
                            borderSkipped: false,
                        },
                        {
                            label: 'Claimed',
                            data: trendClaimed,
                            backgroundColor: '#10b981',
                            borderRadius: 6,
                            borderSkipped: false,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 },
                            grid: { color: '#273040' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            new Chart(statusCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Lost', 'Found', 'Claimed', 'Matched'],
                    datasets: [{
                        data: [
                            Number(statusTotals.lost || 0),
                            Number(statusTotals.found || 0),
                            Number(statusTotals.claimed || 0),
                            Number(statusTotals.matched || 0)
                        ],
                        backgroundColor: ['#ef4444', '#38bdf8', '#ff7b2c', '#ff5b1f'],
                        borderWidth: 0,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                            }
                        }
                    }
                }
            });
        }

        const itemFilter = document.getElementById('itemFilter');
        const itemRows = Array.from(document.querySelectorAll('#itemsTableBody tr'));

        if (itemFilter && itemRows.length > 0) {
            itemFilter.addEventListener('input', function () {
                const safeValue = this.value.replace(/[^A-Za-z0-9 .,'-]/g, '').slice(0, 50);
                if (safeValue !== this.value) {
                    this.value = safeValue;
                }

                const query = safeValue.trim().toLowerCase();
                itemRows.forEach(function (row) {
                    const haystack = row.getAttribute('data-search') || '';
                    row.style.display = query === '' || haystack.includes(query) ? '' : 'none';
                });
            });
        }
    </script>
</body>
</html>
