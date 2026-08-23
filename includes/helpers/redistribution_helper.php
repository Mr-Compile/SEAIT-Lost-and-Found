<?php
/**
 * Redistribution Helper Functions
 * Provides utility functions for item redistribution management
 */

/**
 * Get redistribution statistics
 */
function getRedistributionStats($pdo) {
    try {
        $stats = [
            'total_donations' => 0,
            'total_repurposed' => 0,
            'total_awarded' => 0,
            'items_in_holding' => 0,
            'ready_for_redistribution' => 0
        ];
        
        // Get donation count
        $stmt = $pdo->query("SELECT COUNT(*) FROM redistribution_records WHERE redistribution_method = 'donation' AND status = 'completed'");
        $stats['total_donations'] = $stmt->fetchColumn();
        
        // Get repurposed count
        $stmt = $pdo->query("SELECT COUNT(*) FROM redistribution_records WHERE redistribution_method = 'repurposing' AND status = 'completed'");
        $stats['total_repurposed'] = $stmt->fetchColumn();
        
        // Get awarded to finder count
        $stmt = $pdo->query("SELECT COUNT(*) FROM redistribution_records WHERE redistribution_method = 'give_to_finder' AND status = 'completed'");
        $stats['total_awarded'] = $stmt->fetchColumn();
        
        // Get items currently in holding (found items within 30 days)
        $stmt = $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'found' AND hold_expiry > CURDATE()");
        $stats['items_in_holding'] = $stmt->fetchColumn();
        
        // Get items ready for redistribution (expired hold period)
        $stmt = $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'found' AND hold_expiry <= CURDATE()");
        $stats['ready_for_redistribution'] = $stmt->fetchColumn();
        
        return $stats;
    } catch (Exception $e) {
        error_log("Error getting redistribution stats: " . $e->getMessage());
        return [
            'total_donations' => 0,
            'total_repurposed' => 0,
            'total_awarded' => 0,
            'items_in_holding' => 0,
            'ready_for_redistribution' => 0
        ];
    }
}

/**
 * Get redistribution setting value
 */
function getRedistributionSetting($pdo, $setting_key) {
    $defaults = [
        'default_holding_period_days' => 30,
        'auto_redistribution_enabled' => 1,
        'donation_organizations' => 'Local Charities, School Programs, Community Centers'
    ];
    
    return $defaults[$setting_key] ?? 'N/A';
}

/**
 * Check for unclaimed items past hold period
 */
function checkForUnclaimedItems() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'found' AND hold_expiry <= CURDATE()");
        $count = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'message' => "Found {$count} items ready for redistribution"
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error checking unclaimed items: ' . $e->getMessage()
        ];
    }
}

/**
 * Check expired hold items
 */
function checkExpiredHoldItems() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM items 
            WHERE status = 'found' 
            AND hold_expiry IS NOT NULL 
            AND hold_expiry <= CURDATE()
        ");
        $count = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'message' => "Found {$count} expired items that need redistribution"
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

/**
 * Process automatic redistribution
 */
function processAutomaticRedistribution($pdo, $admin_user_id) {
    try {
        $pdo->beginTransaction();
        
        // Get expired items with finder information
        $stmt = $pdo->prepare("
            SELECT item_id, title, finder_name 
            FROM items 
            WHERE status = 'found' 
            AND hold_expiry <= CURDATE()
            LIMIT 10
        ");
        $stmt->execute();
        $expired_items = $stmt->fetchAll();
        
        $count = 0;
        foreach ($expired_items as $item) {
            // Create redistribution record with 'pending' status
            $insert_stmt = $pdo->prepare("
                INSERT INTO redistribution_records 
                (item_id, redistribution_method, admin_user_id, notes, status)
                VALUES (?, 'repurposing', ?, 'Auto-processed - Hold period expired', 'pending')
            ");
            $insert_stmt->execute([$item['item_id'], $admin_user_id]);
            $count++;
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => "Processed {$count} items for redistribution"
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

/**
 * Award bonus points to finder when item is given to them
 * Called when redistribution method is 'give_to_finder'
 */
function awardFinderBonusPoints($pdo, $finder_name, $bonus_points = 5) {
    try {
        if (empty($finder_name)) {
            return false;
        }
        
        // Check if finder exists in leaderboard
        $check_stmt = $pdo->prepare("SELECT id, total_points FROM finder_leaderboard WHERE finder_name = ?");
        $check_stmt->execute([$finder_name]);
        $finder = $check_stmt->fetch();
        
        if ($finder) {
            // Add bonus points to existing finder
            $new_points = $finder['total_points'] + $bonus_points;
            $update_stmt = $pdo->prepare("UPDATE finder_leaderboard SET total_points = ?, updated_at = NOW() WHERE id = ?");
            return $update_stmt->execute([$new_points, $finder['id']]);
        } else {
            // Create new finder entry with bonus points
            $insert_stmt = $pdo->prepare("INSERT INTO finder_leaderboard (finder_name, total_points) VALUES (?, ?)");
            return $insert_stmt->execute([$finder_name, $bonus_points]);
        }
    } catch (Exception $e) {
        error_log("Error awarding finder bonus points: " . $e->getMessage());
        return false;
    }
}

/**
 * Process daily redistribution check
 */
function processDailyRedistribution() {
    global $pdo;
    
    try {
        // Check for items that expired today
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM items 
            WHERE status = 'found' 
            AND DATE(hold_expiry) = CURDATE()
        ");
        $count = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'message' => "Daily check completed. Found {$count} items expiring today"
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}
