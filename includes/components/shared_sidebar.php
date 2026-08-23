<?php
require_once dirname(__DIR__) . '/anti_inspect.php';
if (!function_exists('getSetting')) {
    require_once dirname(__DIR__) . '/helpers/settings_helper.php';
}

if (!isset($current_page)) {
    $current_page = basename($_SERVER['PHP_SELF']);
}

if (!isset($user) || !is_array($user)) {
    if (!function_exists('getUserInfo')) {
        require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
    }
    $user = getUserInfo();
    if (!is_array($user)) {
        $user = [
            'user_id' => $_SESSION['user_id'] ?? 0,
            'full_name' => $_SESSION['full_name'] ?? 'User',
            'role' => $_SESSION['role'] ?? 'claimant',
            'image_path' => $_SESSION['image_path'] ?? '',
        ];
    }
}

$role = (string)($user['role'] ?? $_SESSION['role'] ?? 'claimant');
$isAdmin = $role === 'admin';
$profileIsActive = in_array($current_page, ['profile.php', 'shared_profile.php'], true);

$scriptDir = trim(str_replace('\\', '/', dirname((string)($_SERVER['PHP_SELF'] ?? ''))), '/');
$dirName = basename($scriptDir);
$isRoleFolderContext = in_array($dirName, ['admin', 'claimant'], true);
$isComponentContext = (substr($scriptDir, -19) === 'includes/components');
$isRootContext = !$isRoleFolderContext && !$isComponentContext;

$relativeToRoot = '';
if ($isRoleFolderContext) {
    $relativeToRoot = '../';
} elseif ($isComponentContext) {
    $relativeToRoot = '../../';
}

$assetPrefix = $relativeToRoot;
$sectionPrefix = $isRoleFolderContext ? '' : $relativeToRoot . ($isAdmin ? 'admin/' : 'claimant/');
$profileHref = $relativeToRoot . 'profile.php';
$logoutHref = $relativeToRoot . 'logout.php';
$leaderboardHref = $isComponentContext ? 'shared_leaderboard.php' : $relativeToRoot . 'includes/components/shared_leaderboard.php';
$leaderboardIsActive = in_array($current_page, ['shared_leaderboard.php'], true);
$sessionTimeoutAssetHref = $relativeToRoot . 'assets/js/session-timeout.js';
$unifiedLoaderAssetHref = $relativeToRoot . 'assets/js/unified-loader.js';
$unifiedLoaderStyleHref = $relativeToRoot . 'assets/css/unified-loader.css';
$sessionTimeoutPingHref = $relativeToRoot . 'includes/api/session-activity.php';
$sessionTimeoutSeconds = function_exists('getSessionTimeoutSeconds') ? getSessionTimeoutSeconds() : 180;
$sessionWarningSeconds = function_exists('getSessionWarningSeconds') ? getSessionWarningSeconds() : 60;
$sidebarLogoSetting = (string)getSetting('site_logo', '');
$sidebarLogoSrc = '';
if ($sidebarLogoSetting !== '' && file_exists($assetPrefix . $sidebarLogoSetting)) {
    $sidebarLogoSrc = $assetPrefix . $sidebarLogoSetting;
}

function sidebarIcon(string $name): string
{
    $icons = [
        'dashboard' => '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>',
        'items' => '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V7a2 2 0 00-2-2h-4V3H10v2H6a2 2 0 00-2 2v6m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4"/></svg>',
        'claims' => '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>',
        'chat' => '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8m-8 4h5m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        'redistribution' => '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v6h6M20 20v-6h-6M5.5 14A7 7 0 0018 9m.5 1A7 7 0 016 15"/></svg>',
        'leaderboard' => '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6m6 13V10m6 9V4M3 19V8"/></svg>',
        'profile' => '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A10.96 10.96 0 0112 15c2.625 0 5.04.922 6.879 2.46M15 9a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
        'settings' => '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8a4 4 0 100 8 4 4 0 000-8zm8.94 4a7.96 7.96 0 00-.34-2l2.03-1.58-2-3.46-2.44.8a8.2 8.2 0 00-1.73-1L14.9 2h-3.8l-.51 2.76a8.2 8.2 0 00-1.73 1l-2.44-.8-2 3.46 2.03 1.58a7.96 7.96 0 000 4l-2.03 1.58 2 3.46 2.44-.8a8.2 8.2 0 001.73 1L11.1 22h3.8l.51-2.76a8.2 8.2 0 001.73-1l2.44.8 2-3.46-2.03-1.58c.22-.65.34-1.31.34-2z"/></svg>',
        'matches' => '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10l-4 4m0-4l4 4m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        
    ];

    return $icons[$name] ?? $icons['dashboard'];
}
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($unifiedLoaderStyleHref, ENT_QUOTES, 'UTF-8'); ?>">
<script>
window.__FINDIT_LOADING__ = Object.assign({}, window.__FINDIT_LOADING__ || {}, {
    logoSrc: <?php echo json_encode($sidebarLogoSrc !== '' ? $sidebarLogoSrc : null, JSON_UNESCAPED_SLASHES); ?>,
    autoNavigationDelayMs: 120
});
</script>
<script src="<?php echo htmlspecialchars($unifiedLoaderAssetHref, ENT_QUOTES, 'UTF-8'); ?>"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap');

:is(body.claimant-theme, body.admin-theme) {
    --c-bg: #080b0f;
    --c-bg-soft: #0d1117;
    --c-panel: #131922;
    --c-panel-2: #1a212d;
    --c-line: #273040;
    --c-text: #ecf2ff;
    --c-text-soft: #bac4d5;
    --c-accent: #ff5b1f;
    --c-accent-2: #ff7b2c;
    --c-accent-soft: rgba(255, 112, 58, 0.2);
    margin: 0;
    color: var(--c-text);
    background:
        radial-gradient(circle at 8% 8%, rgba(255, 122, 28, 0.16), transparent 38%),
        radial-gradient(circle at 92% 4%, rgba(34, 211, 238, 0.12), transparent 42%),
        linear-gradient(180deg, #080b0f 0%, #0a0f14 46%, #0d1117 100%);
    font-family: 'Manrope', 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
}

:is(body.claimant-theme, body.admin-theme) .main-content,
:is(body.claimant-theme, body.admin-theme) .ml-sidebar,
:is(body.claimant-theme, body.admin-theme) .main-shell {
    background: transparent;
}

:is(body.claimant-theme, body.admin-theme) h1,
:is(body.claimant-theme, body.admin-theme) h2,
:is(body.claimant-theme, body.admin-theme) h3,
:is(body.claimant-theme, body.admin-theme) h4 {
    font-family: 'Plus Jakarta Sans', 'Manrope', 'Segoe UI', sans-serif;
    letter-spacing: -0.02em;
}

:is(body.claimant-theme, body.admin-theme) .admin-shell {
    padding: 1.25rem;
}

:is(body.claimant-theme, body.admin-theme) .card-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 30px rgba(2, 8, 20, 0.42) !important;
}

:is(body.claimant-theme, body.admin-theme) .icon-badge {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 123, 44, 0.34);
    background: linear-gradient(135deg, rgba(255, 123, 44, 0.26), rgba(255, 91, 31, 0.2));
    color: #ffe7d7;
}

:is(body.claimant-theme, body.admin-theme) .table-wrap,
:is(body.claimant-theme, body.admin-theme) .overflow-x-auto {
    scrollbar-color: #364152 #11161f;
}

:is(body.claimant-theme, body.admin-theme) .main-content table,
:is(body.claimant-theme, body.admin-theme) .ml-sidebar table,
:is(body.claimant-theme, body.admin-theme) .main-shell table {
    width: 100%;
    border-collapse: collapse;
}

:is(body.claimant-theme, body.admin-theme) .main-content table thead,
:is(body.claimant-theme, body.admin-theme) .ml-sidebar table thead,
:is(body.claimant-theme, body.admin-theme) .main-shell table thead {
    background: #0f151e !important;
}

:is(body.claimant-theme, body.admin-theme) .main-content table th,
:is(body.claimant-theme, body.admin-theme) .ml-sidebar table th,
:is(body.claimant-theme, body.admin-theme) .main-shell table th {
    color: #9fb0c7 !important;
    border-bottom: 1px solid var(--c-line) !important;
    font-size: 0.72rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    font-weight: 700;
}

:is(body.claimant-theme, body.admin-theme) .main-content table td,
:is(body.claimant-theme, body.admin-theme) .ml-sidebar table td,
:is(body.claimant-theme, body.admin-theme) .main-shell table td {
    border-top: 1px solid var(--c-line) !important;
}

:is(body.claimant-theme, body.admin-theme) .main-content table tbody tr:hover,
:is(body.claimant-theme, body.admin-theme) .ml-sidebar table tbody tr:hover,
:is(body.claimant-theme, body.admin-theme) .main-shell table tbody tr:hover {
    background: #1a212d !important;
}

:is(body.claimant-theme, body.admin-theme) .theme-surface {
    border: 1px solid var(--c-line);
    border-radius: 16px;
    background: var(--c-panel);
    box-shadow: 0 14px 32px rgba(2, 8, 20, 0.42);
}

:is(body.claimant-theme, body.admin-theme) .theme-gradient {
    background: linear-gradient(132deg, #fd7f33 0%, #ff5b1f 42%, #f23c00 100%);
}

:is(body.claimant-theme, body.admin-theme) .theme-btn {
    background: linear-gradient(132deg, var(--c-accent), var(--c-accent-2));
    color: #ffffff;
    border: 0;
    border-radius: 10px;
    font-weight: 700;
    transition: all 0.2s ease;
}

:is(body.claimant-theme, body.admin-theme) .theme-btn:hover {
    filter: brightness(1.08);
}

:is(body.claimant-theme, body.admin-theme) .theme-btn-soft {
    background: rgba(56, 189, 248, 0.1);
    color: #8be9ff;
    border: 1px solid rgba(56, 189, 248, 0.35);
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.2s ease;
}

:is(body.claimant-theme, body.admin-theme) .theme-btn-soft:hover {
    background: rgba(56, 189, 248, 0.18);
}

:is(body.claimant-theme, body.admin-theme) .theme-btn-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #ffffff;
    border: 0;
    border-radius: 10px;
    font-weight: 700;
    transition: all 0.2s ease;
}

:is(body.claimant-theme, body.admin-theme) .theme-btn-danger:hover {
    filter: brightness(1.08);
}

:is(body.claimant-theme, body.admin-theme) .theme-input,
:is(body.claimant-theme, body.admin-theme) input[type='text'],
:is(body.claimant-theme, body.admin-theme) input[type='email'],
:is(body.claimant-theme, body.admin-theme) input[type='password'],
:is(body.claimant-theme, body.admin-theme) input[type='search'],
:is(body.claimant-theme, body.admin-theme) input[type='file'],
:is(body.claimant-theme, body.admin-theme) textarea,
:is(body.claimant-theme, body.admin-theme) select {
    width: 100%;
    border: 1px solid var(--c-line);
    border-radius: 10px;
    padding: 0.72rem 0.9rem;
    outline: none;
    transition: all 0.2s ease;
    background: #0f151e;
    color: var(--c-text);
}

:is(body.claimant-theme, body.admin-theme) .theme-input:focus,
:is(body.claimant-theme, body.admin-theme) input:focus,
:is(body.claimant-theme, body.admin-theme) textarea:focus,
:is(body.claimant-theme, body.admin-theme) select:focus {
    border-color: var(--c-accent-2);
    box-shadow: 0 0 0 3px var(--c-accent-soft);
}

:is(body.claimant-theme, body.admin-theme) ::placeholder {
    color: #8591a6;
}

:is(body.claimant-theme, body.admin-theme) .theme-modal-backdrop,
:is(body.claimant-theme, body.admin-theme) .fixed.inset-0.bg-black.bg-opacity-50,
:is(body.claimant-theme, body.admin-theme) .fixed.inset-0.bg-black\/50 {
    background: rgba(4, 8, 17, 0.78) !important;
}

:is(body.claimant-theme, body.admin-theme) .theme-modal-panel {
    border: 1px solid var(--c-line);
    border-radius: 16px;
    background: var(--c-panel);
    box-shadow: 0 22px 40px rgba(0, 0, 0, 0.45);
}

:is(body.claimant-theme, body.admin-theme) .bg-white,
:is(body.claimant-theme, body.admin-theme) .bg-gray-50,
:is(body.claimant-theme, body.admin-theme) .bg-gray-100,
:is(body.claimant-theme, body.admin-theme) .bg-slate-50,
:is(body.claimant-theme, body.admin-theme) .bg-slate-100,
:is(body.claimant-theme, body.admin-theme) .bg-neutral-50 {
    background-color: var(--c-panel) !important;
    color: var(--c-text) !important;
}

:is(body.claimant-theme, body.admin-theme) .from-gray-50,
:is(body.claimant-theme, body.admin-theme) .from-gray-100,
:is(body.claimant-theme, body.admin-theme) .to-gray-100,
:is(body.claimant-theme, body.admin-theme) .to-gray-200 {
    --tw-gradient-from: #0f151e var(--tw-gradient-from-position) !important;
    --tw-gradient-to: #11182700 var(--tw-gradient-to-position) !important;
    --tw-gradient-stops: var(--tw-gradient-from), #1a212d var(--tw-gradient-via-position), var(--tw-gradient-to) !important;
}

:is(body.claimant-theme, body.admin-theme) .border,
:is(body.claimant-theme, body.admin-theme) .border-gray-100,
:is(body.claimant-theme, body.admin-theme) .border-gray-200,
:is(body.claimant-theme, body.admin-theme) .border-gray-300,
:is(body.claimant-theme, body.admin-theme) .border-slate-100,
:is(body.claimant-theme, body.admin-theme) .border-slate-200,
:is(body.claimant-theme, body.admin-theme) .border-slate-300 {
    border-color: var(--c-line) !important;
}

:is(body.claimant-theme, body.admin-theme) .text-gray-900,
:is(body.claimant-theme, body.admin-theme) .text-slate-900,
:is(body.claimant-theme, body.admin-theme) .text-gray-800,
:is(body.claimant-theme, body.admin-theme) .text-slate-800,
:is(body.claimant-theme, body.admin-theme) .text-gray-700,
:is(body.claimant-theme, body.admin-theme) .text-slate-700,
:is(body.claimant-theme, body.admin-theme) .text-gray-600,
:is(body.claimant-theme, body.admin-theme) .text-slate-600,
:is(body.claimant-theme, body.admin-theme) .text-gray-500,
:is(body.claimant-theme, body.admin-theme) .text-slate-500,
:is(body.claimant-theme, body.admin-theme) .text-gray-400,
:is(body.claimant-theme, body.admin-theme) .text-slate-400 {
    color: var(--c-text-soft) !important;
}

:is(body.claimant-theme, body.admin-theme) .text-gray-900,
:is(body.claimant-theme, body.admin-theme) .text-slate-900 {
    color: var(--c-text) !important;
}

:is(body.claimant-theme, body.admin-theme) .shadow,
:is(body.claimant-theme, body.admin-theme) .shadow-sm,
:is(body.claimant-theme, body.admin-theme) .shadow-md,
:is(body.claimant-theme, body.admin-theme) .shadow-lg,
:is(body.claimant-theme, body.admin-theme) .shadow-xl,
:is(body.claimant-theme, body.admin-theme) .shadow-2xl {
    box-shadow: 0 14px 28px rgba(0, 0, 0, 0.42) !important;
}

:is(body.claimant-theme, body.admin-theme) .hover\:bg-gray-50:hover,
:is(body.claimant-theme, body.admin-theme) .hover\:bg-gray-100:hover,
:is(body.claimant-theme, body.admin-theme) .hover\:bg-slate-50:hover,
:is(body.claimant-theme, body.admin-theme) .hover\:bg-slate-100:hover {
    background-color: var(--c-panel-2) !important;
}

:is(body.claimant-theme, body.admin-theme) .ring-gray-200,
:is(body.claimant-theme, body.admin-theme) .ring-slate-200 {
    --tw-ring-color: rgba(255, 123, 44, 0.35) !important;
}

body.admin-theme .gradient-bg,
body.admin-theme .gradient-button {
    background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 42%, #f23c00 100%) !important;
}

body.admin-theme .gradient-button:hover {
    background: linear-gradient(132deg, #ff8f4a 0%, #ff6a2b 42%, #ff4b1f 100%) !important;
    box-shadow: 0 10px 20px rgba(255, 91, 31, 0.26) !important;
}

body.admin-theme .status-pending {
    background: rgba(245, 158, 11, 0.2) !important;
    color: #fcdba6 !important;
}

body.admin-theme .status-approved,
body.admin-theme .status-ready_for_pickup {
    background: rgba(34, 211, 238, 0.2) !important;
    color: #c8f7ff !important;
}

body.admin-theme .status-claimed {
    background: rgba(16, 185, 129, 0.2) !important;
    color: #ccfbeb !important;
}

body.admin-theme .status-rejected {
    background: rgba(239, 68, 68, 0.2) !important;
    color: #ffd2d2 !important;
}

.sidebar {
    width: 280px;
    height: 100vh;
    background: linear-gradient(180deg, #0d1218 0%, #0a0f14 100%);
    border-right: 1px solid #1f2733;
    box-shadow: 0 14px 32px rgba(0, 0, 0, 0.32);
    display: flex;
    flex-direction: column;
    position: fixed;
    left: 0;
    top: 0;
    z-index: 1000;
}

.sidebar-header {
    background: linear-gradient(132deg, #ff7b2c 0%, #ff5b1f 42%, #f23c00 100%);
    padding: 22px 18px 20px;
    color: white;
    text-align: left;
    border-bottom-left-radius: 18px;
    border-bottom-right-radius: 18px;
    position: relative;
    overflow: hidden;
}

.sidebar-header::after {
    content: '';
    position: absolute;
    right: -40px;
    top: -40px;
    width: 132px;
    height: 132px;
    border-radius: 999px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.35), rgba(255, 255, 255, 0));
}

.sidebar-header h1 {
    margin: 0;
    font-size: 1.06rem;
    line-height: 1.25;
    font-weight: 800;
    letter-spacing: -0.01em;
}

.sidebar-header p {
    margin: 4px 0 0;
    font-size: 0.75rem;
    opacity: 0.95;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.user-profile {
    margin: 12px 14px 0;
    padding: 14px;
    border: 1px solid #273040;
    border-radius: 14px;
    background: #121922;
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar {
    width: 46px;
    height: 46px;
    background: linear-gradient(135deg, #ff7b2c, #ff5b1f);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 15px;
    border: 2px solid rgba(255, 123, 44, 0.35);
}

.user-info {
    min-width: 0;
}

.user-info h3 {
    margin: 0;
    font-size: 0.86rem;
    font-weight: 700;
    color: #f2f6ff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px;
}

.user-info p {
    margin: 4px 0 0;
    font-size: 0.69rem;
    color: #ffd3bf;
    background: rgba(255, 123, 44, 0.16);
    border: 1px solid rgba(255, 123, 44, 0.3);
    border-radius: 999px;
    padding: 0.18rem 0.5rem;
    display: inline-flex;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 700;
}

.sidebar-header-brand {
    display: flex;
    align-items: center;
    gap: 10px;
}

.sidebar-site-logo {
    width: 3rem;
    height: 3rem;
    border-radius: 999px;
    object-fit: cover;
    background: #ffffff;
    border: 2px solid rgba(255, 255, 255, 0.98);
    padding: 2px;
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.28);
    flex-shrink: 0;
}

.sidebar-site-logo-placeholder {
    width: 3rem;
    height: 3rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: #111827;
    border: 2px solid rgba(255, 255, 255, 0.98);
    background: #ffffff;
    flex-shrink: 0;
}

.sidebar-header-meta {
    min-width: 0;
}

.sidebar-header-meta h1 {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.nav-menu {
    flex: 1;
    padding: 12px 12px 10px;
    overflow-y: auto;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 12px;
    color: #bcc7d8;
    text-decoration: none;
    font-size: 0.84rem;
    font-weight: 600;
    border: 1px solid transparent;
    border-radius: 12px;
    margin: 3px 0;
    transition: all 0.22s ease;
}

.nav-item:hover {
    background: linear-gradient(140deg, #242e3a 0%, #1a222c 52%, #121922 100%);
    color: #e4ebf5;
    border-color: #3a4658;
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.26);
}

.nav-item.active {
    background: linear-gradient(130deg, #2a3441 0%, #1b232d 42%, #111821 100%);
    color: #f5f7fb;
    border-color: #7f8da0;
    box-shadow: 0 10px 18px rgba(0, 0, 0, 0.34);
}

.nav-icon {
    width: 19px;
    height: 19px;
    flex-shrink: 0;
}

.logout-section {
    padding: 14px;
    border-top: 1px solid #273040;
    background: #10161e;
}

.logout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 11px 14px;
    background: linear-gradient(135deg, #ff5b1f, #ef4444);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 0.84rem;
    font-weight: 700;
    cursor: pointer;
    letter-spacing: 0.02em;
    transition: all 0.2s ease;
}

.logout-btn:hover {
    filter: brightness(1.08);
    transform: translateY(-1px);
}

.logout-icon {
    width: 16px;
    height: 16px;
    margin-right: 8px;
}

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(4, 8, 17, 0.78);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1300;
    visibility: hidden;
    opacity: 0;
    transition: visibility 0s linear 0.25s, opacity 0.25s;
}

.modal-overlay.active {
    visibility: visible;
    opacity: 1;
    transition-delay: 0s;
}

.modal-content {
    background: #121922;
    width: 92%;
    max-width: 430px;
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid #2a3444;
    box-shadow: 0 22px 36px rgba(0, 0, 0, 0.5);
}

.modal-header {
    padding: 18px 20px;
    background: linear-gradient(132deg, #ff7b2c, #ff5b1f, #ef4444);
    color: white;
}

.modal-header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.modal-body {
    padding: 20px;
    text-align: center;
    color: #d5def0;
}

.modal-body p {
    margin: 0;
}

.modal-note {
    margin-top: 10px;
    font-size: 0.76rem;
    color: #93a2ba;
}

.modal-buttons {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-top: 16px;
}

.modal-btn-cancel,
.modal-btn-confirm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding: 10px 16px;
    border: none;
    border-radius: 9px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
}

.modal-btn-cancel {
    background: #1f2937;
    color: #d1d9e6;
    border: 1px solid #334155;
}

.modal-btn-confirm {
    background: linear-gradient(135deg, #ff5b1f, #ef4444);
    color: white;
}

.main-content {
    margin-left: 280px;
    min-height: 100vh;
}

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); transition: transform 0.3s ease; }
    .sidebar.mobile-open { transform: translateX(0); }
    .main-content { margin-left: 0; }
}
</style>

<script>
if (document.body) {
    document.body.classList.add('<?php echo $isAdmin ? 'admin-theme' : 'claimant-theme'; ?>');
} else {
    document.addEventListener('DOMContentLoaded', function () {
        if (document.body) {
            document.body.classList.add('<?php echo $isAdmin ? 'admin-theme' : 'claimant-theme'; ?>');
        }
    });
}
</script>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-header-brand">
            <?php if ($sidebarLogoSrc !== ''): ?>
                <img src="<?php echo htmlspecialchars($sidebarLogoSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="Site Logo" class="sidebar-site-logo">
            <?php else: ?>
                <span class="sidebar-site-logo-placeholder">LOGO</span>
            <?php endif; ?>
            <div class="sidebar-header-meta">
                <h1><?php echo $isAdmin ? 'Admin Dashboard' : 'FindIt Dashboard'; ?></h1>
                <p>Lost & Found System</p>
            </div>
        </div>
    </div>

    <div class="user-profile">
        <?php if (!empty($user['image_path']) && file_exists($assetPrefix . $user['image_path'])): ?>
            <img src="<?php echo htmlspecialchars($assetPrefix . $user['image_path']); ?>" alt="Profile Picture" class="user-avatar" style="object-fit: cover;">
        <?php else: ?>
            <div class="user-avatar"><?php echo strtoupper(substr((string)$user['full_name'], 0, 2)); ?></div>
        <?php endif; ?>
        <div class="user-info">
            <h3><?php echo htmlspecialchars((string)$user['full_name']); ?></h3>
            <p><?php echo ucfirst((string)$role); ?></p>
        </div>
    </div>

    <nav class="nav-menu">
        <?php if ($isAdmin): ?>
            <a href="<?php echo $sectionPrefix; ?>admin_dashboard.php" class="nav-item <?php echo ($current_page==='admin_dashboard.php')?'active':''; ?>"><?php echo sidebarIcon('dashboard'); ?><span>Dashboard Overview</span></a>
            <a href="<?php echo $sectionPrefix; ?>admin_items.php" class="nav-item <?php echo ($current_page==='admin_items.php')?'active':''; ?>"><?php echo sidebarIcon('items'); ?><span>Manage Items</span></a>
            <a href="<?php echo $sectionPrefix; ?>admin_claims.php" class="nav-item <?php echo ($current_page==='admin_claims.php')?'active':''; ?>"><?php echo sidebarIcon('claims'); ?><span>Claim Requests</span></a>
            <a href="<?php echo $sectionPrefix; ?>admin_chat_messages.php" class="nav-item <?php echo ($current_page==='admin_chat_messages.php')?'active':''; ?>"><?php echo sidebarIcon('chat'); ?><span>Chat Messages</span></a>
            <a href="<?php echo $sectionPrefix; ?>admin_redistribution.php" class="nav-item <?php echo ($current_page==='admin_redistribution.php')?'active':''; ?>"><?php echo sidebarIcon('redistribution'); ?><span>Redistribution</span></a>
            <a href="<?php echo $leaderboardHref; ?>" class="nav-item <?php echo $leaderboardIsActive ? 'active' : ''; ?>"><?php echo sidebarIcon('leaderboard'); ?><span>Leaderboard</span></a>
            <a href="<?php echo $profileHref; ?>" class="nav-item <?php echo $profileIsActive ? 'active' : ''; ?>"><?php echo sidebarIcon('profile'); ?><span>My Profile</span></a>
            <a href="<?php echo $sectionPrefix; ?>admin_settings.php" class="nav-item <?php echo ($current_page==='admin_settings.php')?'active':''; ?>"><?php echo sidebarIcon('settings'); ?><span>Settings</span></a>
        <?php else: ?>
            <a href="<?php echo $sectionPrefix; ?>claimant_dashboard.php" class="nav-item <?php echo ($current_page==='claimant_dashboard.php')?'active':''; ?>"><?php echo sidebarIcon('dashboard'); ?><span>Dashboard Overview</span></a>
            <a href="<?php echo $sectionPrefix; ?>claimant_all_items.php" class="nav-item <?php echo ($current_page==='claimant_all_items.php')?'active':''; ?>"><?php echo sidebarIcon('items'); ?><span>Lost and Found Items</span></a>
            <a href="<?php echo $sectionPrefix; ?>claimant_my_items.php" class="nav-item <?php echo ($current_page==='claimant_my_items.php' || $current_page==='get_item_details.php')?'active':''; ?>"><?php echo sidebarIcon('items'); ?><span>My Lost Items</span></a>
             <a href="<?php echo $sectionPrefix; ?>claimant_my_claims.php" class="nav-item <?php echo ($current_page==='claimant_my_claims.php')?'active':''; ?>"><?php echo sidebarIcon('claims'); ?><span>My Claim Requests</span></a>
            <a href="<?php echo $sectionPrefix; ?>claimant_matches_chats.php" class="nav-item <?php echo ($current_page==='claimant_matches_chats.php')?'active':''; ?>"><?php echo sidebarIcon('matches'); ?><span>Matches & Chats</span></a>
            <a href="<?php echo $leaderboardHref; ?>" class="nav-item <?php echo $leaderboardIsActive ? 'active' : ''; ?>"><?php echo sidebarIcon('leaderboard'); ?><span>Leaderboard</span></a>
            <a href="<?php echo $profileHref; ?>" class="nav-item <?php echo $profileIsActive ? 'active' : ''; ?>"><?php echo sidebarIcon('profile'); ?><span>My Profile</span></a>
            
        <?php endif; ?>
    </nav>

    <div class="logout-section">
        <button class="logout-btn" onclick="showLogoutModal()">
            <svg class="logout-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/></svg>
            Logout
        </button>
    </div>
</div>

<div class="modal-overlay" id="logoutModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M12 9V13M12 17H12.01M10.29 3.86L1.82 18A2 2 0 0 0 3.53 21H20.47A2 2 0 0 0 22.18 18L13.71 3.86A2 2 0 0 0 10.29 3.86Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Confirm Logout
            </h3>
        </div>
        <div class="modal-body">
            <p>You are about to end this secure session.</p>
            <p class="modal-note">Any unsaved progress may be lost after logout.</p>
            <div class="modal-buttons">
                <button class="modal-btn-cancel" onclick="hideLogoutModal()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Stay Signed In
                </button>
                <button class="modal-btn-confirm" onclick="logoutWithLoader('<?php echo htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8'); ?>')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M16 17L21 12L16 7M21 12H9M12 17V18A2 2 0 0 1 10 20H5A2 2 0 0 1 3 18V6A2 2 0 0 1 5 4H10A2 2 0 0 1 12 6V7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Logout Now
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function showLogoutModal() {
    document.getElementById('logoutModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function hideLogoutModal() {
    document.getElementById('logoutModal').classList.remove('active');
    document.body.style.overflow = '';
}
function logoutWithLoader(targetUrl) {
    hideLogoutModal();

    if (typeof navigateWithLoading === 'function') {
        navigateWithLoading(targetUrl, 'Signing out...', 3000);
        return;
    }

    window.location.href = targetUrl;
}
document.addEventListener('click', function(event) {
    const modal = document.getElementById('logoutModal');
    const modalContent = modal ? modal.querySelector('.modal-content') : null;
    const logoutBtn = document.querySelector('.logout-btn');
    if (modal && modal.classList.contains('active') && modalContent && !modalContent.contains(event.target) && logoutBtn && !logoutBtn.contains(event.target)) {
        hideLogoutModal();
    }
});
document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('logoutModal');
    if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
        hideLogoutModal();
    }
});
</script>
<script>
window.__SESSION_TIMEOUT_CONFIG__ = {
    timeoutSeconds: <?php echo (int)$sessionTimeoutSeconds; ?>,
    warningSeconds: <?php echo (int)$sessionWarningSeconds; ?>,
    pingUrl: <?php echo json_encode($sessionTimeoutPingHref, JSON_UNESCAPED_SLASHES); ?>,
    logoutUrl: <?php echo json_encode($logoutHref, JSON_UNESCAPED_SLASHES); ?>
};
</script>
<script src="<?php echo htmlspecialchars($sessionTimeoutAssetHref, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php renderAntiInspectScript(); ?>
