<?php
/**
 * AutoMatch API Endpoint
 * Handles automatic matching of lost and found items
 * Can be called from admin or claimant interfaces
 */

require_once '../../includes/auth_check.php';
require_once '../../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = getUserInfo();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'run_automatch':
            $beforeStmt = $pdo->query("SELECT COUNT(*) as count FROM itemmatches");
            $beforeCount = $beforeStmt->fetch()['count'];

            $stmt = $pdo->prepare("CALL AutoMatchItems()");
            $stmt->execute();
            $result = $stmt->fetch();

            $afterStmt = $pdo->query("SELECT COUNT(*) as count FROM itemmatches");
            $afterCount = $afterStmt->fetch()['count'];

            $newMatches = $afterCount - $beforeCount;

            $detailsStmt = $pdo->prepare("
                SELECT
                    im.match_id,
                    im.similarity_score,
                    l.title as lost_title,
                    f.title as found_title
                FROM itemmatches im
                JOIN items l ON im.lost_item_id = l.item_id
                JOIN items f ON im.found_item_id = f.item_id
                ORDER BY im.matched_at DESC
                LIMIT ?
            ");
            $detailsStmt->execute([$newMatches > 0 ? $newMatches : 10]);
            $matchDetails = $detailsStmt->fetchAll();

            echo json_encode([
                'success' => true,
                'new_matches' => $newMatches,
                'total_matches' => $afterCount,
                'matches' => $matchDetails,
                'message' => $newMatches > 0
                    ? "Successfully created $newMatches new match(es)!"
                    : 'No new matches found. All possible matches may already exist.'
            ]);
            break;

        case 'get_match_stats':
            $stats = [];

            $stmt = $pdo->query("SELECT COUNT(*) as count FROM itemmatches");
            $stats['total_matches'] = $stmt->fetch()['count'];

            $stmt = $pdo->query("
                SELECT status, COUNT(*) as count
                FROM itemmatches
                GROUP BY status
            ");
            $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $stmt = $pdo->query("
                SELECT COUNT(*) as count
                FROM items i
                WHERE i.status = 'lost'
                AND NOT EXISTS (
                    SELECT 1 FROM itemmatches im
                    WHERE im.lost_item_id = i.item_id
                )
            ");
            $stats['unmatched_lost'] = $stmt->fetch()['count'];

            $stmt = $pdo->query("
                SELECT COUNT(*) as count
                FROM items i
                WHERE i.status = 'found'
                AND NOT EXISTS (
                    SELECT 1 FROM itemmatches im
                    WHERE im.found_item_id = i.item_id
                )
            ");
            $stats['unmatched_found'] = $stmt->fetch()['count'];

            $stmt = $pdo->query("
                SELECT AVG(similarity_score) as avg_score
                FROM itemmatches
            ");
            $stats['avg_similarity'] = round($stmt->fetch()['avg_score'] ?? 0, 2);

            $stmt = $pdo->query("
                SELECT
                    im.match_id,
                    im.similarity_score,
                    im.matched_at,
                    l.title as lost_title,
                    f.title as found_title
                FROM itemmatches im
                JOIN items l ON im.lost_item_id = l.item_id
                JOIN items f ON im.found_item_id = f.item_id
                ORDER BY im.matched_at DESC
                LIMIT 10
            ");
            $stats['recent_matches'] = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;

        case 'check_matchable_items':
            $stmt = $pdo->query("
                SELECT
                    (SELECT COUNT(*) FROM items WHERE status = 'lost') as lost_count,
                    (SELECT COUNT(*) FROM items WHERE status = 'found') as found_count
            ");
            $counts = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'can_match' => $counts['lost_count'] > 0 && $counts['found_count'] > 0,
                'lost_items' => (int)$counts['lost_count'],
                'found_items' => (int)$counts['found_count']
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log('AutoMatch API Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
