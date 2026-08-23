<?php

require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole('claimant');

$sessionTimeoutSeconds = function_exists('getSessionTimeoutSeconds') ? getSessionTimeoutSeconds() : 180;
$sessionWarningSeconds = function_exists('getSessionWarningSeconds') ? getSessionWarningSeconds() : 60;
$sessionTimeoutPingHref = '../includes/api/session-activity.php';
$sessionTimeoutLogoutHref = '../logout.php';
$sessionTimeoutAssetHref = '../assets/js/session-timeout.js';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$ctx = $_SESSION['object_verification'] ?? null;
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if (!is_array($ctx) || (int)($ctx['user_id'] ?? 0) !== $currentUserId) {
    header('Location: claimant_all_items.php?error=verification_flow_required');
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

$itemId = (int)($ctx['item_id'] ?? 0);
$matchId = (int)($ctx['match_id'] ?? 0);
$maxAttempts = (int)($ctx['max_attempts'] ?? 2);

ensureObjectVerificationLogsTable($pdo);
ensureObjectVerificationBlocksTable($pdo);

$failedStmt = $pdo->prepare('
        SELECT COUNT(*) AS failed_attempts
        FROM claim_object_verification_logs
        WHERE user_id = ?
            AND match_id = ?
            AND item_id = ?
            AND is_correct = 0
            AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
');
$failedStmt->execute([$currentUserId, $matchId, $itemId]);
$failedAttempts = (int)($failedStmt->fetch()['failed_attempts'] ?? 0);
$attemptsRemaining = max(0, $maxAttempts - $failedAttempts);

$blockStmt = $pdo->prepare('
        SELECT TIMESTAMPDIFF(SECOND, NOW(), blocked_until) AS remaining_seconds
        FROM claim_object_verification_blocks
        WHERE user_id = ?
            AND match_id = ?
            AND item_id = ?
            AND blocked_until > NOW()
        LIMIT 1
');
$blockStmt->execute([$currentUserId, $matchId, $itemId]);
$activeBlock = $blockStmt->fetch();
$blockedSeconds = max(0, (int)($activeBlock['remaining_seconds'] ?? 0));
$isBlocked = $blockedSeconds > 0;

if ($itemId <= 0) {
    header('Location: claimant_all_items.php?error=invalid_verification_context');
    exit;
}

$itemStmt = $pdo->prepare('SELECT item_id, title, category, description, item_image FROM items WHERE item_id = ? LIMIT 1');
$itemStmt->execute([$itemId]);
$correctItem = $itemStmt->fetch();

if (!$correctItem) {
    header('Location: claimant_all_items.php?error=item_not_found');
    exit;
}

function buildObjectVerificationImageSrc(?string $rawImage): ?string
{
    $imageValue = trim((string)$rawImage);
    if ($imageValue === '' || strtolower($imageValue) === 'null') {
        return null;
    }

    $normalized = str_replace('\\', '/', $imageValue);
    if (preg_match('#^(https?:)?//#i', $normalized) === 1 || strpos($normalized, 'data:') === 0) {
        return $normalized;
    }

    $projectRoot = dirname(__DIR__);
    $uploadsRoot = $projectRoot . '/uploads';

    $trimmed = ltrim($normalized, '/');
    $uploadsMarkerPos = stripos($trimmed, 'uploads/');
    if ($uploadsMarkerPos !== false) {
        $trimmed = substr($trimmed, $uploadsMarkerPos + strlen('uploads/'));
    }

    if (strpos($trimmed, '../uploads/') === 0) {
        $trimmed = substr($trimmed, 11);
    }
    if (strpos($trimmed, 'uploads/') === 0) {
        $trimmed = substr($trimmed, 8);
    }

    $trimmed = ltrim($trimmed, '/');
    if ($trimmed === '') {
        return null;
    }

    $candidates = [];
    $candidates[] = $trimmed;

    $fileName = basename($trimmed);
    if ($fileName !== '' && $fileName !== '.') {
        $candidates[] = $fileName;
        $candidates[] = 'backup_img/' . $fileName;
    }

    foreach (array_values(array_unique($candidates)) as $relativePath) {
        $relativePath = ltrim(str_replace('\\', '/', (string)$relativePath), '/');
        if ($relativePath === '') {
            continue;
        }

        $absolutePath = $uploadsRoot . '/' . $relativePath;
        if (!is_file($absolutePath)) {
            continue;
        }

        $encodedRelative = implode('/', array_map('rawurlencode', explode('/', trim($relativePath, '/'))));
        return '../uploads/' . $encodedRelative;
    }

    return null;
}

$category = (string)($correctItem['category'] ?? '');

$distractorStmt = $pdo->prepare('
    SELECT item_id, title, category, description, item_image
    FROM items
    WHERE item_id != ?
      AND category = ?
      AND item_image IS NOT NULL
      AND TRIM(item_image) != ""
      AND LOWER(TRIM(item_image)) != "null"
    ORDER BY RAND()
    LIMIT 3
');
$distractorStmt->execute([$itemId, $category]);
$distractors = $distractorStmt->fetchAll();

if (count($distractors) < 3) {
    $excludeIds = array_map(static fn(array $row): int => (int)$row['item_id'], $distractors);
    $excludeIds[] = $itemId;

    $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
    $fallbackSql = '
        SELECT item_id, title, category, description, item_image
        FROM items
        WHERE item_id NOT IN (' . $placeholders . ')
          AND item_image IS NOT NULL
          AND TRIM(item_image) != ""
          AND LOWER(TRIM(item_image)) != "null"
        ORDER BY RAND()
        LIMIT ' . (3 - count($distractors));

    $fallbackStmt = $pdo->prepare($fallbackSql);
    $fallbackStmt->execute($excludeIds);
    $fallbackRows = $fallbackStmt->fetchAll();
    $distractors = array_merge($distractors, $fallbackRows);
}

if (count($distractors) < 3) {
    $excludeIds = array_map(static fn(array $row): int => (int)$row['item_id'], $distractors);
    $excludeIds[] = $itemId;

    $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
    $lastFallbackSql = '
        SELECT item_id, title, category, description, item_image
        FROM items
        WHERE item_id NOT IN (' . $placeholders . ')
        ORDER BY RAND()
        LIMIT ' . (3 - count($distractors));

    $lastFallbackStmt = $pdo->prepare($lastFallbackSql);
    $lastFallbackStmt->execute($excludeIds);
    $lastFallbackRows = $lastFallbackStmt->fetchAll();
    $distractors = array_merge($distractors, $lastFallbackRows);
}

$options = array_merge([$correctItem], array_slice($distractors, 0, 3));
shuffle($options);
$itemPlaceholderSrc = finditAssetUrl('images/placeholders/item-default.svg');

$normalizedOptions = [];
foreach ($options as $option) {
    $imagePath = buildObjectVerificationImageSrc($option['item_image'] ?? null);

    $normalizedOptions[] = [
        'item_id' => (int)$option['item_id'],
        'title' => (string)$option['title'],
        'category' => (string)($option['category'] ?? 'Other'),
        'description' => (string)($option['description'] ?? ''),
        'image' => $imagePath,
    ];
}

$_SESSION['object_verification']['challenge'] = [
    'correct_item_id' => (int)$correctItem['item_id'],
    'option_item_ids' => array_map(static fn(array $o): int => (int)$o['item_id'], $normalizedOptions),
    'generated_at' => time(),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Object Verification - FindIt</title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        .verify-shell {
            background:
                radial-gradient(circle at 8% 8%, rgba(255, 122, 28, 0.16), transparent 38%),
                radial-gradient(circle at 92% 4%, rgba(34, 211, 238, 0.12), transparent 42%),
                #0a0f14;
            color: #e8eefb;
        }

        .verify-card {
            background: #131922;
            border: 1px solid #273040;
            box-shadow: 0 24px 40px rgba(0, 0, 0, 0.45);
        }

        .verify-option {
            background: #1a212d;
            border-color: #2d384b;
        }

        .verify-option:hover {
            border-color: #ff7b2c;
            box-shadow: 0 8px 20px rgba(255, 91, 31, 0.18);
        }

        .verify-submit {
            background: linear-gradient(135deg, #ff5b1f 0%, #ff7b2c 100%);
            color: #ffffff;
            border: 1px solid rgba(255, 174, 126, 0.5);
            box-shadow: 0 10px 22px rgba(255, 91, 31, 0.25);
        }

        .verify-submit:hover {
            background: linear-gradient(135deg, #ff4f12 0%, #ff6f1f 100%);
        }

        .verify-submit:disabled {
            background: #374151;
            border-color: #4b5563;
            box-shadow: none;
        }

        .verify-nav-btn {
            border: 1px solid #2d384b;
            background: #1a212d;
            color: #d9e4f7;
        }

        .verify-nav-btn:hover {
            border-color: #3e4f67;
            background: #222c3a;
            color: #ffffff;
        }

        .verify-nav-btn-back {
            border-color: rgba(255, 174, 126, 0.5);
            background: rgba(255, 122, 28, 0.18);
            color: #ffd9c0;
        }

        .verify-nav-btn-back:hover {
            border-color: rgba(255, 174, 126, 0.8);
            background: rgba(255, 122, 28, 0.28);
            color: #fff1e8;
        }
    </style>
</head>
<body class="claimant-theme verify-shell min-h-screen p-4 md:p-8">
    <div class="max-w-4xl mx-auto">
        <div class="verify-card rounded-2xl p-6 md:p-8">
            <div class="mb-6 text-center">
                <h1 class="text-2xl md:text-3xl font-bold text-slate-100">Object Matching Verification</h1>
                <p class="text-slate-300 mt-2">Select the item that matches yours to continue claim submission.</p>
            </div>

            <div class="mb-4 rounded-lg bg-slate-900 border border-slate-700 p-4">
                <p class="text-sm text-slate-200"><strong>Attempts remaining:</strong> <span id="attemptsRemaining"><?php echo (int)$attemptsRemaining; ?></span> / <?php echo (int)$maxAttempts; ?></p>
                <?php if ($isBlocked): ?>
                    <p class="text-sm text-red-300 mt-1">Verification is temporarily blocked. Wait <span id="waitTimeLabel"></span> before trying again.</p>
                <?php endif; ?>
            </div>

            <p class="text-sm font-semibold text-slate-100 mb-3">Question: Select the item that matches yours</p>

            <div id="optionsGrid" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                <?php foreach ($normalizedOptions as $index => $option): ?>
                    <button
                        type="button"
                        class="verify-option group text-left rounded-xl border p-4 transition-all disabled:opacity-50"
                        data-item-id="<?php echo (int)$option['item_id']; ?>"
                        <?php echo $isBlocked ? 'disabled' : ''; ?>
                    >
                        <div class="flex gap-3">
                            <div class="w-16 h-16 rounded-lg bg-slate-100 overflow-hidden flex-shrink-0">
                                <?php if (!empty($option['image'])): ?>
                                    <img src="<?php echo htmlspecialchars((string)$option['image'], ENT_QUOTES, 'UTF-8'); ?>" alt="Option image" class="w-full h-full object-cover" onerror="this.onerror=null; this.src='<?php echo htmlspecialchars($itemPlaceholderSrc, ENT_QUOTES, 'UTF-8'); ?>';">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center text-slate-400">
                                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14"/></svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-100 truncate"><?php echo htmlspecialchars((string)$option['title'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="text-xs text-slate-300 mt-1"><?php echo htmlspecialchars((string)$option['category'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="text-xs text-slate-400 mt-1 line-clamp-2"><?php echo htmlspecialchars((string)$option['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>

            <div id="feedbackBox" class="hidden rounded-lg border p-3 text-sm mb-5"></div>
            <div id="toastBox" class="hidden fixed top-4 right-4 z-50 rounded-lg border px-4 py-3 text-sm shadow-lg"></div>

            <div class="flex items-center justify-between">
                <div class="inline-flex items-center gap-2">
                    <button
                        type="button"
                        class="verify-nav-btn verify-nav-btn-back inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
                        onclick="window.history.length > 1 ? window.history.back() : (window.location.href = 'claimant_all_items.php');"
                    >
                        Back
                    </button>
                    <a href="claimant_all_items.php" class="verify-nav-btn inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium transition-colors">Cancel and return</a>
                </div>
                <button id="submitBtn" type="button" class="verify-submit inline-flex items-center rounded-lg px-5 py-2.5 text-sm font-semibold" disabled>
                    Submit Selection
                </button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const options = Array.from(document.querySelectorAll('.verify-option'));
            const submitBtn = document.getElementById('submitBtn');
            const feedbackBox = document.getElementById('feedbackBox');
            const toastBox = document.getElementById('toastBox');
            const attemptsRemainingEl = document.getElementById('attemptsRemaining');
            const isBlocked = <?php echo $isBlocked ? 'true' : 'false'; ?>;
            let waitSeconds = <?php echo (int)$blockedSeconds; ?>;

            function formatSeconds(totalSeconds) {
                const mins = Math.floor(totalSeconds / 60);
                const secs = totalSeconds % 60;
                return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
            }

            function showToast(message, isError = true) {
                toastBox.classList.remove('hidden', 'bg-emerald-950', 'text-emerald-200', 'border-emerald-700', 'bg-rose-950', 'text-rose-200', 'border-rose-700');
                if (isError) {
                    toastBox.classList.add('bg-rose-950', 'text-rose-200', 'border-rose-700');
                } else {
                    toastBox.classList.add('bg-emerald-950', 'text-emerald-200', 'border-emerald-700');
                }
                toastBox.textContent = message;
                setTimeout(() => {
                    toastBox.classList.add('hidden');
                }, 3500);
            }

            function runWaitCountdown(seconds) {
                const waitLabel = document.getElementById('waitTimeLabel');
                if (!waitLabel) return;

                let remaining = Math.max(0, Number(seconds || 0));
                waitLabel.textContent = formatSeconds(remaining);

                const timer = setInterval(() => {
                    remaining -= 1;
                    waitLabel.textContent = formatSeconds(Math.max(0, remaining));
                    if (remaining <= 0) {
                        clearInterval(timer);
                    }
                }, 1000);
            }

            let selectedItemId = null;

            if (isBlocked) {
                submitBtn.disabled = true;
                showToast(`Attempt limit reached. Please wait ${formatSeconds(waitSeconds)}.`, true);
                runWaitCountdown(waitSeconds);
                return;
            }

            options.forEach((button) => {
                button.addEventListener('click', function () {
                    options.forEach((opt) => {
                        opt.classList.remove('border-orange-500', 'ring-2', 'ring-orange-300', 'bg-orange-500/20');
                    });

                    this.classList.add('border-orange-500', 'ring-2', 'ring-orange-300', 'bg-orange-500/20');
                    selectedItemId = Number(this.getAttribute('data-item-id') || 0);
                    submitBtn.disabled = !selectedItemId;
                });
            });

            submitBtn.addEventListener('click', async function () {
                if (!selectedItemId) {
                    return;
                }

                submitBtn.disabled = true;
                options.forEach((opt) => { opt.disabled = true; });
                submitBtn.textContent = 'Checking...';

                try {
                    const response = await fetch('api/process-verification.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ selected_item_id: selectedItemId })
                    });

                    const data = await response.json();

                    if (typeof data.attempts_remaining !== 'undefined') {
                        attemptsRemainingEl.textContent = String(data.attempts_remaining);
                    }

                    feedbackBox.classList.remove('hidden');

                    if (data.success) {
                        feedbackBox.className = 'rounded-lg border border-green-200 bg-green-50 text-green-800 p-3 text-sm mb-5';
                        feedbackBox.textContent = data.message || 'Verification successful. Claim submitted. Redirecting...';
                        window.location.href = data.redirect_url || 'claimant_matches_chats.php';
                        return;
                    }

                    feedbackBox.className = 'rounded-lg border border-red-200 bg-red-50 text-red-700 p-3 text-sm mb-5';
                    feedbackBox.textContent = data.message || 'Incorrect selection. Try again.';

                    const shouldLock = Boolean(data.locked);
                    if (shouldLock) {
                        if (typeof data.wait_seconds !== 'undefined') {
                            waitSeconds = Number(data.wait_seconds || 0);
                        }
                        const waitText = waitSeconds > 0 ? formatSeconds(waitSeconds) : '05:00';
                        showToast(`Attempt limit reached. Please wait ${waitText}.`, true);
                        submitBtn.disabled = true;
                        options.forEach((opt) => { opt.disabled = true; });
                        runWaitCountdown(waitSeconds || 300);
                        return;
                    }

                    submitBtn.disabled = false;
                    options.forEach((opt) => { opt.disabled = false; });
                    submitBtn.textContent = 'Try Again';
                } catch (error) {
                    feedbackBox.classList.remove('hidden');
                    feedbackBox.className = 'rounded-lg border border-red-200 bg-red-50 text-red-700 p-3 text-sm mb-5';
                    feedbackBox.textContent = 'Network error while validating your answer.';
                    submitBtn.disabled = false;
                    options.forEach((opt) => { opt.disabled = false; });
                    submitBtn.textContent = 'Submit Selection';
                }
            });
        })();
    </script>
    <script>
        window.__SESSION_TIMEOUT_CONFIG__ = {
            timeoutSeconds: <?php echo (int)$sessionTimeoutSeconds; ?>,
            warningSeconds: <?php echo (int)$sessionWarningSeconds; ?>,
            pingUrl: <?php echo json_encode($sessionTimeoutPingHref, JSON_UNESCAPED_SLASHES); ?>,
            logoutUrl: <?php echo json_encode($sessionTimeoutLogoutHref, JSON_UNESCAPED_SLASHES); ?>
        };
    </script>
    <script src="<?php echo htmlspecialchars($sessionTimeoutAssetHref, ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>

