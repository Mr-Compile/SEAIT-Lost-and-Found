<?php

declare(strict_types=1);

require_once '../../../includes/auth_check.php';
require_once '../../../config/db.php';
requireRole('claimant');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function sliderChallengeResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function validateMatchOwnership(PDO $pdo, int $userId, int $itemId, int $matchId): bool
{
    $stmt = $pdo->prepare('
        SELECT im.match_id
        FROM itemmatches im
        INNER JOIN items li ON li.item_id = im.lost_item_id
        WHERE im.match_id = ?
          AND im.found_item_id = ?
          AND li.user_id = ?
        LIMIT 1
    ');
    $stmt->execute([$matchId, $itemId, $userId]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function verifyTrail(array $trail): bool
{
    if (count($trail) < 8) {
        return false;
    }

    $normalized = [];
    foreach ($trail as $value) {
        $numericValue = (float)$value;
        if (!is_finite($numericValue)) {
            return false;
        }

        if ($numericValue < 0 || $numericValue > 100) {
            return false;
        }

        $normalized[] = $numericValue;
    }

    if (count(array_unique(array_map(static fn(float $v): string => sprintf('%.2f', $v), $normalized))) < 5) {
        return false;
    }

    $backwards = 0;
    for ($i = 1, $len = count($normalized); $i < $len; $i++) {
        if ($normalized[$i] + 0.2 < $normalized[$i - 1]) {
            $backwards++;
        }
    }

    return $backwards <= 2;
}

function verifyDiagonalTrail(array $xTrail, array $yTrail): bool
{
    if (count($xTrail) < 10 || count($xTrail) !== count($yTrail)) {
        return false;
    }

    $maxJump = 0.0;
    $backwardCount = 0;
    $movingSamples = 0;

    $yMin = 9999.0;
    $yMax = -9999.0;

    $normalizedX = [];
    $normalizedY = [];

    foreach ($xTrail as $index => $xValue) {
        $x = (float)$xValue;
        $y = (float)$yTrail[$index];

        if (!is_finite($x) || !is_finite($y)) {
            return false;
        }

        $normalizedX[] = $x;
        $normalizedY[] = $y;

        $yMin = min($yMin, $y);
        $yMax = max($yMax, $y);

        if ($index === 0) {
            continue;
        }

        $dx = $x - $normalizedX[$index - 1];
        $dy = $y - $normalizedY[$index - 1];
        $jump = sqrt(($dx * $dx) + ($dy * $dy));
        $maxJump = max($maxJump, $jump);

        if ($dx < -2.5) {
            $backwardCount++;
        }

        if (abs($dx) > 0.2 || abs($dy) > 0.2) {
            $movingSamples++;
        }
    }

    if ($maxJump > 90) {
        return false;
    }

    if ($backwardCount > 4) {
        return false;
    }

    if ($movingSamples < 8) {
        return false;
    }

    if (($normalizedX[count($normalizedX) - 1] - $normalizedX[0]) < 20) {
        return false;
    }

    $yVariance = $yMax - $yMin;
    if ($yVariance < 3 || $yVariance > 90) {
        return false;
    }

    return true;
}

function buildSliderBackgroundImageUrl(PDO $pdo, int $itemId): ?string
{
    $stmt = $pdo->prepare('SELECT item_image FROM items WHERE item_id = ? LIMIT 1');
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $rawImage = trim((string)($row['item_image'] ?? ''));
    if ($rawImage === '' || strtolower($rawImage) === 'null') {
        return null;
    }

    $normalized = str_replace('\\', '/', $rawImage);
    if (preg_match('#^(https?:)?//#i', $normalized) === 1 || strpos($normalized, 'data:') === 0) {
        return $normalized;
    }

    $projectRoot = dirname(__DIR__, 3);
    $basePath = function_exists('getAppBasePath') ? rtrim(getAppBasePath(), '/') : '';

    $candidates = [];
    $trimmed = ltrim($normalized, '/');

    if (strpos($trimmed, 'uploads/') === 0) {
        $candidates[] = $trimmed;
    } else {
        $fileName = basename($trimmed);
        $candidates[] = 'uploads/' . $trimmed;
        $candidates[] = 'uploads/' . $fileName;
        $candidates[] = 'uploads/backup_img/' . $fileName;
    }

    foreach (array_values(array_unique($candidates)) as $relativePath) {
        $absolutePath = $projectRoot . '/' . ltrim($relativePath, '/');
        if (!is_file($absolutePath)) {
            continue;
        }

        $encodedRelative = implode('/', array_map('rawurlencode', explode('/', trim($relativePath, '/'))));
        return ($basePath !== '' ? $basePath : '') . '/' . $encodedRelative;
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sliderChallengeResponse([
        'success' => false,
        'message' => 'Method not allowed.'
    ], 405);
}

$action = (string)($_POST['action'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$itemId = (int)($_POST['item_id'] ?? 0);
$matchId = (int)($_POST['match_id'] ?? 0);

if ($userId <= 0) {
    sliderChallengeResponse([
        'success' => false,
        'message' => 'Authentication required.'
    ], 401);
}

if ($itemId <= 0 || $matchId <= 0) {
    sliderChallengeResponse([
        'success' => false,
        'message' => 'Invalid item or match ID.'
    ], 400);
}

if (!validateMatchOwnership($pdo, $userId, $itemId, $matchId)) {
    sliderChallengeResponse([
        'success' => false,
        'message' => 'This claim request is not authorized.'
    ], 403);
}

if ($action === 'issue') {
    $canvasWidth = 320;
    $canvasHeight = 170;
    $pieceSize = 52;
    $startX = 14;
    $targetX = random_int(110, $canvasWidth - $pieceSize - 20);
    $targetY = random_int(24, $canvasHeight - $pieceSize - 18);

    $startYMin = max(10, $targetY - 24);
    $startYMax = min($canvasHeight - $pieceSize - 10, $targetY + 24);
    $startY = random_int($startYMin, $startYMax);

    $toleranceX = 7;
    $toleranceY = 10;
    $token = bin2hex(random_bytes(12));
    $backgroundImageUrl = buildSliderBackgroundImageUrl($pdo, $itemId);

    $_SESSION['slider_security_challenge'] = [
        'user_id' => $userId,
        'item_id' => $itemId,
        'match_id' => $matchId,
        'token' => $token,
        'canvas_width' => $canvasWidth,
        'canvas_height' => $canvasHeight,
        'piece_size' => $pieceSize,
        'start_x' => $startX,
        'start_y' => $startY,
        'target_x' => $targetX,
        'target_y' => $targetY,
        'tolerance_x' => $toleranceX,
        'tolerance_y' => $toleranceY,
        'attempts' => 0,
        'max_attempts' => 5,
        'issued_at' => time(),
        'expires_at' => time() + 300,
    ];

    sliderChallengeResponse([
        'success' => true,
        'token' => $token,
        'canvas_width' => $canvasWidth,
        'canvas_height' => $canvasHeight,
        'piece_size' => $pieceSize,
        'start_x' => $startX,
        'start_y' => $startY,
        'target_x' => $targetX,
        'target_y' => $targetY,
        'background_image_url' => $backgroundImageUrl,
        'max_attempts' => 5,
        'message' => 'Drag the puzzle piece to fit the slot.'
    ]);
}

if ($action === 'verify') {
    $challenge = $_SESSION['slider_security_challenge'] ?? null;
    if (!is_array($challenge)) {
        sliderChallengeResponse([
            'success' => false,
            'message' => 'Challenge expired. Please retry.'
        ], 410);
    }

    if ((int)($challenge['user_id'] ?? 0) !== $userId
        || (int)($challenge['item_id'] ?? 0) !== $itemId
        || (int)($challenge['match_id'] ?? 0) !== $matchId) {
        unset($_SESSION['slider_security_challenge']);
        sliderChallengeResponse([
            'success' => false,
            'message' => 'Challenge context mismatch. Please retry.'
        ], 400);
    }

    if ((int)($challenge['expires_at'] ?? 0) < time()) {
        unset($_SESSION['slider_security_challenge']);
        sliderChallengeResponse([
            'success' => false,
            'message' => 'Challenge timed out. Please retry.'
        ], 410);
    }

    $requestToken = (string)($_POST['token'] ?? '');
    if ($requestToken === '' || !hash_equals((string)$challenge['token'], $requestToken)) {
        sliderChallengeResponse([
            'success' => false,
            'message' => 'Invalid challenge token.'
        ], 400);
    }

    $positionX = (float)($_POST['position_x'] ?? -1);
    $positionY = (float)($_POST['position_y'] ?? -1);

    $trailXRaw = (string)($_POST['trail_x'] ?? '[]');
    $trailYRaw = (string)($_POST['trail_y'] ?? '[]');

    $trailX = json_decode($trailXRaw, true);
    $trailY = json_decode($trailYRaw, true);

    if (!is_array($trailX)) {
        $trailX = [];
    }

    if (!is_array($trailY)) {
        $trailY = [];
    }

    $attempts = (int)($challenge['attempts'] ?? 0) + 1;
    $maxAttempts = (int)($challenge['max_attempts'] ?? 5);
    $challenge['attempts'] = $attempts;
    $_SESSION['slider_security_challenge'] = $challenge;

    if ($attempts > $maxAttempts) {
        unset($_SESSION['slider_security_challenge']);
        sliderChallengeResponse([
            'success' => false,
            'message' => 'Too many failed attempts. Please request a new challenge.'
        ], 429);
    }

    $targetX = (float)($challenge['target_x'] ?? 0);
    $targetY = (float)($challenge['target_y'] ?? 0);
    $toleranceX = (float)($challenge['tolerance_x'] ?? 7);
    $toleranceY = (float)($challenge['tolerance_y'] ?? 10);

    $isPositionValid = abs($positionX - $targetX) <= $toleranceX && abs($positionY - $targetY) <= $toleranceY;
    $isTrailValid = verifyDiagonalTrail($trailX, $trailY);

    if (!$isPositionValid || !$isTrailValid) {
        sliderChallengeResponse([
            'success' => false,
            'message' => 'Verification failed. Move the slider smoothly and align the piece.',
            'attempts_left' => max(0, $maxAttempts - $attempts),
        ], 400);
    }

    $_SESSION['slider_security_verified'] = [
        'user_id' => $userId,
        'item_id' => $itemId,
        'match_id' => $matchId,
        'verified_at' => time(),
        'expires_at' => time() + 180,
    ];
    unset($_SESSION['slider_security_challenge']);

    sliderChallengeResponse([
        'success' => true,
        'message' => 'Security check passed.'
    ]);
}

sliderChallengeResponse([
    'success' => false,
    'message' => 'Invalid challenge action.'
], 400);
