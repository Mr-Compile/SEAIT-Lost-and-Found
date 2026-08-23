<?php
declare(strict_types=1);

require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole('claimant');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$itemId = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$matchId = filter_input(INPUT_GET, 'match_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

if ($userId <= 0 || $itemId <= 0 || $matchId <= 0) {
    header('Location: claimant_all_items.php?error=invalid_claim_request');
    exit;
}

$sliderSecurity = $_SESSION['slider_security_verified'] ?? null;
$sliderAllowed = is_array($sliderSecurity)
    && (int)($sliderSecurity['user_id'] ?? 0) === $userId
    && (int)($sliderSecurity['item_id'] ?? 0) === $itemId
    && (int)($sliderSecurity['match_id'] ?? 0) === $matchId
    && (int)($sliderSecurity['expires_at'] ?? 0) >= time();

if (!$sliderAllowed) {
    header('Location: claimant_all_items.php?error=security_slider_required');
    exit;
}

unset($_SESSION['slider_security_verified']);

try {
    $matchStmt = $pdo->prepare('
        SELECT im.match_id, im.lost_item_id, im.found_item_id, li.user_id AS lost_user_id
        FROM itemmatches im
        INNER JOIN items li ON li.item_id = im.lost_item_id
        WHERE im.match_id = ?
          AND im.found_item_id = ?
          AND li.user_id = ?
        LIMIT 1
    ');
    $matchStmt->execute([$matchId, $itemId, $userId]);
    $match = $matchStmt->fetch();

    if (!$match) {
        header('Location: claimant_all_items.php?error=unauthorized_match');
        exit;
    }

    $existingClaimStmt = $pdo->prepare('
        SELECT claim_id
        FROM claim_requests
        WHERE match_id = ? AND claimant_user_id = ?
        LIMIT 1
    ');
    $existingClaimStmt->execute([$matchId, $userId]);
    if ($existingClaimStmt->fetch()) {
        header('Location: claimant_my_claims.php?notice=claim_exists');
        exit;
    }

    $existingContext = $_SESSION['object_verification'] ?? null;
    $reuseContext = is_array($existingContext)
        && (int)($existingContext['user_id'] ?? 0) === $userId
        && (int)($existingContext['item_id'] ?? 0) === $itemId
        && (int)($existingContext['match_id'] ?? 0) === $matchId;

    if ($reuseContext) {
        $_SESSION['object_verification'] = $existingContext;
    } else {
        $_SESSION['object_verification'] = [
            'user_id' => $userId,
            'item_id' => $itemId,
            'match_id' => $matchId,
            'token' => bin2hex(random_bytes(16)),
            'attempts' => 0,
            'max_attempts' => 2,
            'blocked_until' => null,
            'passed' => false,
            'started_at' => time(),
            'passed_at' => null,
            'challenge' => null,
        ];
    }

    header('Location: object-verification.php');
    exit;
} catch (Throwable $e) {
    error_log('claim-item.php error: ' . $e->getMessage());
    header('Location: claimant_all_items.php?error=claim_flow_error');
    exit;
}
