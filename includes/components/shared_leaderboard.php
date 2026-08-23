<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$user = getUserInfo();
$current_page = basename($_SERVER['PHP_SELF']);
$role = strtolower((string)($user['role'] ?? $_SESSION['role'] ?? ''));

if (!in_array($role, ['admin', 'claimant'], true)) {
    redirectToApp('login.php?reason=unauthorized');
}

$leaderboardRows = [];
$topThree = [];
$stats = [
    'total_finders' => 0,
    'avg_points' => 0,
    'highest_score' => 0,
    'total_items_found' => 0,
];

try {
    $leaderboardStmt = $pdo->prepare(
        'SELECT
            fl.id,
            fl.finder_name,
            fl.total_points,
            fl.created_at,
            fl.updated_at,
            COUNT(DISTINCT i.item_id) AS items_found_count,
            ROW_NUMBER() OVER (ORDER BY fl.total_points DESC, fl.finder_name ASC) AS rank
        FROM finder_leaderboard fl
        LEFT JOIN items i ON fl.finder_name = i.finder_name
        GROUP BY fl.id, fl.finder_name, fl.total_points, fl.created_at, fl.updated_at
        ORDER BY fl.total_points DESC, fl.finder_name ASC
        LIMIT 20'
    );
    $leaderboardStmt->execute();
    $leaderboardRows = $leaderboardStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $topThree = array_slice($leaderboardRows, 0, 3);

    $statsStmt = $pdo->prepare(
        'SELECT
            COUNT(DISTINCT fl.id) AS total_finders,
            COALESCE(ROUND(AVG(fl.total_points)), 0) AS avg_points,
            COALESCE(MAX(fl.total_points), 0) AS highest_score,
            COUNT(DISTINCT i.item_id) AS total_items_found
        FROM finder_leaderboard fl
        LEFT JOIN items i ON fl.finder_name = i.finder_name'
    );
    $statsStmt->execute();

    $statsResult = $statsStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($statsResult)) {
        $stats = [
            'total_finders' => (int)($statsResult['total_finders'] ?? 0),
            'avg_points' => (int)($statsResult['avg_points'] ?? 0),
            'highest_score' => (int)($statsResult['highest_score'] ?? 0),
            'total_items_found' => (int)($statsResult['total_items_found'] ?? 0),
        ];
    }
} catch (PDOException $e) {
    error_log('Shared leaderboard load error: ' . $e->getMessage());
}

function leaderboardInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : '--';
}

function leaderboardAvatarStyle(int $rank): string
{
    switch ($rank) {
        case 1:
            return 'background: linear-gradient(135deg, #f59e0b, #b45309);';
        case 2:
            return 'background: linear-gradient(135deg, #94a3b8, #64748b);';
        case 3:
            return 'background: linear-gradient(135deg, #d97706, #92400e);';
        default:
            return 'background: linear-gradient(135deg, #14b8a6, #0f766e);';
    }
}

function leaderboardRankClass(int $rank): string
{
    if ($rank === 1) {
        return 'rank rank-gold';
    }
    if ($rank === 2) {
        return 'rank rank-silver';
    }
    if ($rank === 3) {
        return 'rank rank-bronze';
    }

    return 'rank rank-default';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shared Leaderboard</title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0f14;
            --panel: #131922;
            --text: #ecf2ff;
            --muted: #95a4bc;
            --line: #273040;
            --accent: #ff5b1f;
            --accent-soft: rgba(255, 123, 44, 0.22);
            --status-text: #ffcdb2;
            --table-head-bg: #1a212d;
            --table-head-text: #a7b8d3;
            --table-row-line: #273040;
            --table-hover: #171e29;
        }

        body {
            margin: 0;
            background:
                radial-gradient(circle at 8% 8%, rgba(255, 122, 28, 0.16), transparent 38%),
                radial-gradient(circle at 92% 4%, rgba(34, 211, 238, 0.12), transparent 42%),
                var(--bg);
            color: var(--text);
            font-family: 'Manrope', 'Segoe UI', sans-serif;
        }

        h1, h2, h3 {
            font-family: 'Plus Jakarta Sans', 'Manrope', sans-serif;
            letter-spacing: -0.02em;
        }

        .main-shell {
            margin-left: 280px;
            min-height: 100vh;
            padding: 26px;
        }

        .hero {
            background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 36%, #ef4444 72%, #22d3ee 100%);
            border-radius: 22px;
            padding: 28px;
            color: #ecfeff;
            box-shadow: 0 18px 42px rgba(0, 0, 0, 0.34);
            border: 1px solid rgba(255, 255, 255, 0.15);
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            right: -80px;
            top: -90px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.34), rgba(255, 255, 255, 0));
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 20px;
            align-items: center;
        }

        .hero-kicker {
            font-size: 0.74rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            opacity: 0.98;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 999px;
            padding: 0.3rem 0.6rem;
        }

        .hero-tags {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .hero-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            background: rgba(255, 255, 255, 0.15);
            padding: 0.32rem 0.58rem;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .viewer-card {
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 16px;
            padding: 16px;
            backdrop-filter: blur(6px);
            position: relative;
            z-index: 1;
        }

        .viewer-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            opacity: 0.9;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .stat-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
            border-top: 3px solid var(--accent);
        }

        .stat-value {
            font-size: 1.65rem;
            font-weight: 800;
            line-height: 1;
            margin-top: 0.4rem;
        }

        .rank {
            width: 38px;
            height: 38px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 0.9rem;
            font-weight: 800;
        }

        .rank-gold { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .rank-silver { background: linear-gradient(135deg, #94a3b8, #64748b); }
        .rank-bronze { background: linear-gradient(135deg, #b45309, #92400e); }
        .rank-default { background: linear-gradient(135deg, #ff7b2c, #ff5b1f); }

        .podium-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-top: 22px;
        }

        .podium-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
        }

        .podium-card.top {
            border-color: #fcd34d;
            box-shadow: 0 16px 32px rgba(217, 119, 6, 0.15);
            transform: translateY(-6px);
        }

        .avatar {
            width: 52px;
            height: 52px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 800;
            letter-spacing: 0.03em;
            margin: 0.75rem 0;
        }

        .board-panel {
            margin-top: 22px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        }

        .board-toolbar {
            padding: 14px 18px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filter-input {
            width: 320px;
            max-width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 0.6rem 0.8rem 0.6rem 2.35rem;
            font-size: 0.92rem;
            background: var(--panel);
            color: var(--text);
        }

        .filter-wrap {
            position: relative;
            width: 320px;
            max-width: 100%;
        }

        .filter-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            width: 1rem;
            height: 1rem;
            color: var(--muted);
            pointer-events: none;
        }

        .filter-input::placeholder {
            color: var(--muted);
        }

        .filter-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255, 123, 44, 0.2);
            outline: none;
        }

        .leaderboard-table {
            width: 100%;
            border-collapse: collapse;
        }

        .leaderboard-table th,
        .leaderboard-table td {
            padding: 0.88rem 1rem;
            text-align: left;
            vertical-align: middle;
        }

        .leaderboard-table th {
            background: var(--table-head-bg);
            color: var(--table-head-text);
            font-size: 0.73rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
        }

        .leaderboard-table tbody tr {
            border-bottom: 1px solid var(--table-row-line);
        }

        .leaderboard-table tbody tr:hover {
            background: var(--table-hover);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 9999px;
            background: var(--accent-soft);
            color: var(--status-text);
            padding: 0.28rem 0.55rem;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .claimant-mode .text-slate-900,
        .admin-mode .text-slate-900 {
            color: var(--text) !important;
        }

        .claimant-mode .text-slate-700,
        .claimant-mode .text-slate-600,
        .claimant-mode .text-slate-500,
        .claimant-mode .text-slate-400,
        .admin-mode .text-slate-700,
        .admin-mode .text-slate-600,
        .admin-mode .text-slate-500,
        .admin-mode .text-slate-400 {
            color: var(--muted) !important;
        }

        .claimant-mode .text-teal-700,
        .admin-mode .text-teal-700 {
            color: #ffd9c6 !important;
        }

        .empty-state {
            padding: 48px 20px;
            text-align: center;
            color: var(--muted);
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .main-shell {
                margin-left: 0;
                padding: 16px;
            }

            .hero-grid,
            .podium-grid {
                grid-template-columns: 1fr;
            }

            .podium-card.top {
                transform: none;
            }

            .hero {
                padding: 20px;
            }
        }
    </style>
</head>
<body class="<?php echo $role === 'claimant' ? 'claimant-mode' : 'admin-mode'; ?>">
    <?php include __DIR__ . '/shared_sidebar.php'; ?>

    <main class="main-shell">
        <div class="max-w-7xl mx-auto">
            <section class="hero">
                <div class="hero-grid">
                    <div>
                        <p class="hero-kicker"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l2.034 6.26a1 1 0 00.95.69h6.584c.969 0 1.371 1.24.588 1.81l-5.326 3.87a1 1 0 00-.364 1.118l2.034 6.26c.3.921-.755 1.688-1.54 1.118l-5.326-3.87a1 1 0 00-1.176 0l-5.326 3.87c-.784.57-1.838-.197-1.539-1.118l2.034-6.26a1 1 0 00-.364-1.118l-5.326-3.87c-.784-.57-.38-1.81.588-1.81h6.584a1 1 0 00.95-.69l2.034-6.26z"/></svg>Unified Leaderboard</p>
                        <h1 class="text-3xl md:text-4xl font-extrabold mt-2 leading-tight">Finder Impact Rankings</h1>
                        <p class="mt-3 text-sm md:text-base text-teal-50 max-w-2xl">
                            One shared leaderboard for admin and claimant accounts. Data is loaded from the same live database and restricted to authenticated roles.
                        </p>
                        <div class="hero-tags">
                            <span class="hero-tag">Live Rankings</span>
                            <span class="hero-tag">Role-Based Access</span>
                            <span class="hero-tag">Top 20 Visibility</span>
                        </div>
                    </div>
                    <div class="viewer-card">
                        <p class="viewer-label">Current Session</p>
                        <h2 class="text-xl font-bold mt-2"><?php echo htmlspecialchars((string)($user['full_name'] ?? 'User')); ?></h2>
                        <p class="text-teal-50 text-sm mt-1"><?php echo htmlspecialchars((string)($user['email'] ?? 'No email')); ?></p>
                        <div class="mt-3 inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-semibold uppercase tracking-wide">
                            <span>Role</span>
                            <span><?php echo htmlspecialchars($role); ?></span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="stats-grid">
                <article class="stat-card">
                    <p class="text-sm text-slate-500">Total Finders</p>
                    <p class="stat-value"><?php echo number_format($stats['total_finders']); ?></p>
                </article>
                <article class="stat-card">
                    <p class="text-sm text-slate-500">Average Points</p>
                    <p class="stat-value"><?php echo number_format($stats['avg_points']); ?></p>
                </article>
                <article class="stat-card">
                    <p class="text-sm text-slate-500">Highest Score</p>
                    <p class="stat-value"><?php echo number_format($stats['highest_score']); ?></p>
                </article>
                <article class="stat-card">
                    <p class="text-sm text-slate-500">Items Found</p>
                    <p class="stat-value"><?php echo number_format($stats['total_items_found']); ?></p>
                </article>
            </section>

            <section class="podium-grid">
                <?php foreach ([2, 1, 3] as $slotRank): ?>
                    <?php $entry = $topThree[$slotRank - 1] ?? null; ?>
                    <article class="podium-card <?php echo $slotRank === 1 ? 'top' : ''; ?>">
                        <span class="<?php echo leaderboardRankClass($slotRank); ?>"><?php echo $slotRank; ?></span>
                        <?php if ($entry): ?>
                            <div class="avatar" style="<?php echo leaderboardAvatarStyle($slotRank); ?>">
                                <?php echo htmlspecialchars(leaderboardInitials((string)$entry['finder_name'])); ?>
                            </div>
                            <h3 class="text-lg font-extrabold text-slate-900"><?php echo htmlspecialchars((string)$entry['finder_name']); ?></h3>
                            <p class="text-sm text-slate-500 mt-1"><?php echo $slotRank === 1 ? 'Top Finder' : 'Community Finder'; ?></p>
                            <p class="text-3xl font-extrabold text-teal-700 mt-3"><?php echo number_format((int)$entry['total_points']); ?></p>
                            <p class="text-xs text-slate-500">points</p>
                            <p class="text-xs text-slate-600 mt-2">Items: <?php echo number_format((int)($entry['items_found_count'] ?? 0)); ?></p>
                        <?php else: ?>
                            <div class="avatar" style="background: linear-gradient(135deg, #cbd5e1, #94a3b8);">--</div>
                            <h3 class="text-lg font-extrabold text-slate-400">No Data</h3>
                            <p class="text-sm text-slate-400 mt-1">No finder yet</p>
                            <p class="text-3xl font-extrabold text-slate-300 mt-3">0</p>
                            <p class="text-xs text-slate-400">points</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="board-panel">
                <div class="board-toolbar">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900 inline-flex items-center gap-2"><svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>All Finder Rankings</h2>
                        <p class="text-sm text-slate-500 mt-1">Restricted view: only top 20 verified finder records are displayed.</p>
                    </div>
                    <label class="filter-wrap">
                        <span class="sr-only">Filter leaderboard</span>
                        <svg class="filter-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input
                            id="finderFilter"
                            type="text"
                            class="filter-input"
                            maxlength="40"
                            pattern="[A-Za-z0-9 .'-]*"
                            placeholder="Filter by finder name"
                            autocomplete="off"
                        >
                    </label>
                </div>

                <?php if (!empty($leaderboardRows)): ?>
                    <div class="overflow-x-auto">
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Finder</th>
                                    <th>Status</th>
                                    <th>Points</th>
                                    <th>Items Found</th>
                                    <th>Member Since</th>
                                </tr>
                            </thead>
                            <tbody id="leaderboardTableBody">
                                <?php foreach ($leaderboardRows as $row): ?>
                                    <?php
                                        $rank = (int)($row['rank'] ?? 0);
                                        $finderName = (string)($row['finder_name'] ?? 'Unknown Finder');
                                        $foundCount = (int)($row['items_found_count'] ?? 0);
                                    ?>
                                    <tr data-name="<?php echo htmlspecialchars(strtolower($finderName)); ?>">
                                        <td><span class="<?php echo leaderboardRankClass($rank); ?>"><?php echo $rank; ?></span></td>
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <div class="avatar" style="<?php echo leaderboardAvatarStyle($rank); ?> width:42px; height:42px; margin:0; font-size:0.8rem;">
                                                    <?php echo htmlspecialchars(leaderboardInitials($finderName)); ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($finderName); ?></p>
                                                    <p class="text-xs text-slate-500">Community Finder</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-pill">
                                                <svg width="11" height="11" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                                Verified
                                            </span>
                                        </td>
                                        <td class="font-bold text-slate-900"><?php echo number_format((int)$row['total_points']); ?></td>
                                        <td class="text-slate-700"><?php echo number_format($foundCount); ?></td>
                                        <td class="text-slate-600"><?php echo date('M Y', strtotime((string)$row['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <h3 class="text-lg font-bold text-slate-700">No leaderboard data yet</h3>
                        <p class="mt-2 text-sm">Finder records will appear here once points are awarded.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        const finderFilter = document.getElementById('finderFilter');
        const rows = Array.from(document.querySelectorAll('#leaderboardTableBody tr'));

        if (finderFilter && rows.length > 0) {
            finderFilter.addEventListener('input', function () {
                const safeValue = this.value.replace(/[^A-Za-z0-9 .'-]/g, '').slice(0, 40);
                if (safeValue !== this.value) {
                    this.value = safeValue;
                }

                const query = safeValue.trim().toLowerCase();
                rows.forEach(function (row) {
                    const name = row.getAttribute('data-name') || '';
                    row.style.display = query === '' || name.includes(query) ? '' : 'none';
                });
            });
        }
    </script>
</body>
</html>
