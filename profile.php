<?php
require_once __DIR__ . '/includes/auth_check.php';

$user = getUserInfo();
$profile_role = strtolower((string)($user['role'] ?? ($_SESSION['role'] ?? 'claimant')));
$current_page = basename($_SERVER['PHP_SELF']);

if (!in_array($profile_role, ['admin', 'claimant'], true)) {
    redirectToApp('login.php?reason=unauthorized');
}

include __DIR__ . '/includes/components/shared_profile.php';
