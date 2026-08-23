<?php
declare(strict_types=1);

require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
requireRole('claimant');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function jsonResponse(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function ensureObjectVerificationLogsTable(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS claim_object_verification_logs (
            log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            match_id INT(11) NOT NULL,
            item_id INT(11) NOT NULL,
            selected_item_id INT(11) NOT NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            attempt_number TINYINT UNSIGNED NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT "unknown",
            user_agent VARCHAR(512) NOT NULL DEFAULT "unknown",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_claim_obj_verify_user_match (user_id, match_id),
            KEY idx_claim_obj_verify_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ');
}

function ensureObjectVerificationBlocksTable(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS claim_object_verification_blocks (
            block_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            match_id INT(11) NOT NULL,
            item_id INT(11) NOT NULL,
            blocked_until DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_claim_obj_block (user_id, match_id, item_id),
            KEY idx_claim_obj_block_until (blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ');
}

function getActiveBlockSeconds(PDO $pdo, int $userId, int $matchId, int $itemId): int
{
    $stmt = $pdo->prepare('
        SELECT TIMESTAMPDIFF(SECOND, NOW(), blocked_until) AS remaining_seconds
        FROM claim_object_verification_blocks
        WHERE user_id = ? AND match_id = ? AND item_id = ? AND blocked_until > NOW()
        LIMIT 1
    ');
    $stmt->execute([$userId, $matchId, $itemId]);
    $row = $stmt->fetch();

    $remaining = (int)($row['remaining_seconds'] ?? 0);
    return max(0, $remaining);
}

function getRecentFailedAttempts(PDO $pdo, int $userId, int $matchId, int $itemId): int
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS failed_attempts
        FROM claim_object_verification_logs
        WHERE user_id = ?
          AND match_id = ?
          AND item_id = ?
          AND is_correct = 0
          AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ');
    $stmt->execute([$userId, $matchId, $itemId]);
    return (int)($stmt->fetch()['failed_attempts'] ?? 0);
}

function lockVerification(PDO $pdo, int $userId, int $matchId, int $itemId, int $seconds): void
{
    $stmt = $pdo->prepare('
        INSERT INTO claim_object_verification_blocks (user_id, match_id, item_id, blocked_until)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
        ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until)
    ');
    $stmt->execute([$userId, $matchId, $itemId, $seconds]);
}

function clearVerificationLock(PDO $pdo, int $userId, int $matchId, int $itemId): void
{
    $stmt = $pdo->prepare('
        DELETE FROM claim_object_verification_blocks
        WHERE user_id = ? AND match_id = ? AND item_id = ?
    ');
    $stmt->execute([$userId, $matchId, $itemId]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$ctx = $_SESSION['object_verification'] ?? null;
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!is_array($ctx) || (int)($ctx['user_id'] ?? 0) !== $userId) {
    jsonResponse(['success' => false, 'message' => 'Verification flow is invalid'], 400);
}

$rawPayload = file_get_contents('php://input');
$payload = json_decode($rawPayload ?: '{}', true);
if (!is_array($payload)) {
    jsonResponse(['success' => false, 'message' => 'Invalid payload'], 422);
}

$selectedItemId = filter_var($payload['selected_item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($selectedItemId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Invalid selection'], 422);
}

$maxAttempts = (int)($ctx['max_attempts'] ?? 2);
$challenge = $ctx['challenge'] ?? null;
$matchId = (int)($ctx['match_id'] ?? 0);
$itemId = (int)($ctx['item_id'] ?? 0);

if (!is_array($challenge)) {
    jsonResponse(['success' => false, 'message' => 'Challenge expired. Please restart claim flow.'], 400);
}

if ($matchId <= 0 || $itemId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Invalid verification context'], 400);
}

ensureObjectVerificationLogsTable($pdo);
ensureObjectVerificationBlocksTable($pdo);

$remainingBlockSeconds = getActiveBlockSeconds($pdo, $userId, $matchId, $itemId);
if ($remainingBlockSeconds > 0) {
    jsonResponse([
        'success' => false,
        'message' => 'Verification is temporarily blocked. Please wait before trying again.',
        'locked' => true,
        'attempts_remaining' => 0,
        'wait_seconds' => $remainingBlockSeconds,
    ], 423);
}

$failedAttempts = getRecentFailedAttempts($pdo, $userId, $matchId, $itemId);
$attemptsRemainingBefore = max(0, $maxAttempts - $failedAttempts);
if ($attemptsRemainingBefore <= 0) {
    lockVerification($pdo, $userId, $matchId, $itemId, 300);
    jsonResponse([
        'success' => false,
        'locked' => true,
        'attempts_remaining' => 0,
        'wait_seconds' => 300,
        'message' => 'Attempt limit reached. Please wait 5 minutes before trying again.',
    ], 423);
}

$validOptionIds = array_map(static fn($v): int => (int)$v, (array)($challenge['option_item_ids'] ?? []));
if (!in_array($selectedItemId, $validOptionIds, true)) {
    jsonResponse(['success' => false, 'message' => 'Selected option is invalid'], 422);
}

$correctItemId = (int)($challenge['correct_item_id'] ?? 0);
$isCorrect = $selectedItemId === $correctItemId;
$nextAttempt = $failedAttempts + 1;

$ctx['attempts'] = $failedAttempts;
$ctx['passed'] = $isCorrect;
$ctx['passed_at'] = $isCorrect ? time() : null;

$ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 512);

try {
    $attemptNumberStmt = $pdo->prepare('
        SELECT COUNT(*) AS total_attempts
        FROM claim_object_verification_logs
        WHERE user_id = ? AND match_id = ? AND item_id = ?
    ');
    $attemptNumberStmt->execute([$userId, $matchId, $itemId]);
    $attemptNumber = ((int)($attemptNumberStmt->fetch()['total_attempts'] ?? 0)) + 1;

    $logStmt = $pdo->prepare('
        INSERT INTO claim_object_verification_logs
        (user_id, match_id, item_id, selected_item_id, is_correct, attempt_number, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $logStmt->execute([
        $userId,
        $matchId,
        $itemId,
        $selectedItemId,
        $isCorrect ? 1 : 0,
        $attemptNumber,
        mb_substr($ipAddress, 0, 45),
        $userAgent,
    ]);
} catch (Throwable $e) {
    error_log('process-verification.php log error: ' . $e->getMessage());
}

if ($isCorrect) {
    clearVerificationLock($pdo, $userId, $matchId, $itemId);

    $claimId = null;
    $existingClaimId = null;

    try {
        $pdo->beginTransaction();

        $matchStmt = $pdo->prepare('
            SELECT im.match_id
            FROM itemmatches im
            INNER JOIN items li ON li.item_id = im.lost_item_id
            WHERE im.match_id = ?
              AND im.found_item_id = ?
              AND li.user_id = ?
            LIMIT 1
        ');
        $matchStmt->execute([$matchId, $itemId, $userId]);
        if (!$matchStmt->fetch()) {
            throw new RuntimeException('Match context is invalid');
        }

        $existingClaimStmt = $pdo->prepare('
            SELECT claim_id
            FROM claim_requests
            WHERE match_id = ? AND claimant_user_id = ?
            LIMIT 1
        ');
        $existingClaimStmt->execute([$matchId, $userId]);
        $existingClaim = $existingClaimStmt->fetch();

        if ($existingClaim) {
            $existingClaimId = (int)($existingClaim['claim_id'] ?? 0);
            $claimId = $existingClaimId > 0 ? $existingClaimId : null;
        } else {
            $requestMessage = 'Object verification passed and claim submitted.';
            $insertStmt = $pdo->prepare('
                INSERT INTO claim_requests
                (match_id, claimant_user_id, item_id, request_message, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, "pending", NOW(), NOW())
            ');
            $insertStmt->execute([$matchId, $userId, $itemId, $requestMessage]);
            $claimId = (int)$pdo->lastInsertId();

            $notifyStmt = $pdo->prepare('
                INSERT INTO notifications (user_id, item_id, type, message, sent_at, is_read)
                SELECT 1, ?, "system", CONCAT("New claim request from ", u.full_name), NOW(), 0
                FROM users u WHERE u.user_id = ?
            ');
            $notifyStmt->execute([$itemId, $userId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('process-verification.php claim submit error: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'Verification passed, but claim submission failed. Please try again.',
        ], 500);
    }

    unset($_SESSION['object_verification']);

    jsonResponse([
        'success' => true,
        'message' => $existingClaimId
            ? 'Match verified. You already have an active claim for this item.'
            : 'Match verified. Claim submitted successfully.',
        'attempts_remaining' => max(0, $maxAttempts - $failedAttempts),
        'claim_id' => $claimId,
        'redirect_url' => 'claimant_matches_chats.php',
    ]);
}

$attemptsRemaining = max(0, $maxAttempts - $nextAttempt);
if ($attemptsRemaining <= 0) {
    $ctx['blocked_until'] = date('Y-m-d H:i:s', time() + 300);
    $_SESSION['object_verification'] = $ctx;
    lockVerification($pdo, $userId, $matchId, $itemId, 300);

    jsonResponse([
        'success' => false,
        'locked' => true,
        'attempts_remaining' => 0,
        'wait_seconds' => 300,
        'message' => 'Verification failed twice. Please wait 5 minutes before trying again.',
    ], 423);
}

$_SESSION['object_verification'] = $ctx;
jsonResponse([
    'success' => false,
    'locked' => false,
    'attempts_remaining' => $attemptsRemaining,
    'message' => 'Incorrect selection. Please try again.',
], 400);

