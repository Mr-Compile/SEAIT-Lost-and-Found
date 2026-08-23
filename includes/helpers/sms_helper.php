<?php
// SMS Helper Functions for FindIt System

require_once __DIR__ . '/../../sms/vendor/autoload.php';

use AndroidSmsGateway\Client;
use AndroidSmsGateway\Domain\Message;

/**
 * Send SMS notification to a phone number
 * @param string $phone_number Phone number (should be 10 digits starting with 9)
 * @param string $message Message to send
 * @return array Result array with success status and message
 */
function sendSMSNotification($phone_number, $message) {
    // SMS Gateway credentials
    $username = "ORFAAP";
    $password = "coffeecrumble06";
    
    error_log("SMS Attempt - Phone: {$phone_number}, Message: " . substr($message, 0, 50) . "...");
    
    // Validate phone number format
    if (!preg_match('/^9\d{9}$/', $phone_number)) {
        error_log("SMS Failed - Invalid phone format: {$phone_number}");
        return [
            'success' => false,
            'message' => 'Invalid phone number format. Must be 10 digits starting with 9.'
        ];
    }
    
    // Format phone number for Philippines (+63)
    $formatted_number = "+63" . $phone_number;
    error_log("SMS Sending to: {$formatted_number}");
    
    try {
        $client = new Client($username, $password);
        $sms_message = new Message($message, [$formatted_number]);
        
        $messageState = $client->Send($sms_message);
        
        error_log("SMS Success - Message ID: " . $messageState->ID());
        return [
            'success' => true,
            'message' => 'SMS sent successfully with ID: ' . $messageState->ID(),
            'message_id' => $messageState->ID()
        ];
        
    } catch (Exception $e) {
        error_log("SMS Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to send SMS: ' . $e->getMessage()
        ];
    }
}

/**
 * Send match status notification to both users
 * @param PDO $pdo Database connection
 * @param int $match_id Match ID
 * @param string $status New status (processing, claimed, rejected)
 * @return array Result array with success status and details
 */
function sendMatchStatusNotification($pdo, $match_id, $status) {
    try {
        // Get match details with user phone numbers
        $stmt = $pdo->prepare("
            SELECT 
                im.match_id,
                im.similarity_score,
                li.title as lost_title,
                li.user_id as lost_user_id,
                lu.full_name as lost_user_name,
                lu.phone as lost_user_phone,
                fi.title as found_title,
                fi.user_id as found_user_id,
                fu.full_name as found_user_name,
                fu.phone as found_user_phone
            FROM itemmatches im
            JOIN items li ON im.lost_item_id = li.item_id
            JOIN items fi ON im.found_item_id = fi.item_id
            JOIN users lu ON li.user_id = lu.user_id
            JOIN users fu ON fi.user_id = fu.user_id
            WHERE im.match_id = ?
        ");
        $stmt->execute([$match_id]);
        $match = $stmt->fetch();
        
        if (!$match) {
            return [
                'success' => false,
                'message' => 'Match not found'
            ];
        }
        
        $results = [];
        
        // Create status-specific messages
        $status_messages = [
            'processing' => [
                'lost' => "🔍 Your lost item '{$match['lost_title']}' is being processed for a potential match. We'll notify you once the review is complete.",
                'found' => "🔍 Your found item '{$match['found_title']}' is being processed for a potential match. We'll notify you once the review is complete."
            ],
            'ready_to_claim' => [
                'lost' => "🎉 Good news! Your lost item '{$match['lost_title']}' has been found and is ready to claim! Please contact the finder to arrange pickup. Thank you for using FindIt!",
                'found' => "🎉 Great job! The owner of the item you found '{$match['found_title']}' has been notified and is ready to claim it. Please coordinate with them for the return. Thank you for helping!"
            ],
            'claimed' => [
                'lost' => "✅ Great news! Your lost item '{$match['lost_title']}' has been successfully claimed. Thank you for using FindIt!",
                'found' => "✅ Great news! Your found item '{$match['found_title']}' has been successfully claimed. Thank you for helping someone find their lost item!"
            ],
            'rejected' => [
                'lost' => "❌ Unfortunately, the match for your lost item '{$match['lost_title']}' was not approved. We'll continue looking for matches.",
                'found' => "❌ Unfortunately, the match for your found item '{$match['found_title']}' was not approved. We'll continue looking for matches."
            ]
        ];
        
        // Debug: Log match details
        error_log("SMS Debug - Match ID: {$match['match_id']}, Lost User: {$match['lost_user_name']} ({$match['lost_user_phone']}), Found User: {$match['found_user_name']} ({$match['found_user_phone']})");
        
        // Send SMS to lost item owner (claimant)
        if (!empty($match['lost_user_phone'])) {
            $lost_message = $status_messages[$status]['lost'];
            $result = sendSMSNotification($match['lost_user_phone'], $lost_message);
            $results['lost_user'] = [
                'name' => $match['lost_user_name'],
                'phone' => $match['lost_user_phone'],
                'result' => $result
            ];
            error_log("SMS sent to lost item owner: {$match['lost_user_phone']} - " . ($result['success'] ? 'Success' : 'Failed: ' . $result['message']));
        } else {
            error_log("No phone number for lost item owner: {$match['lost_user_name']}");
        }
        
        // Send SMS to found item owner (finder)
        if (!empty($match['found_user_phone'])) {
            $found_message = $status_messages[$status]['found'];
            $result = sendSMSNotification($match['found_user_phone'], $found_message);
            $results['found_user'] = [
                'name' => $match['found_user_name'],
                'phone' => $match['found_user_phone'],
                'result' => $result
            ];
            error_log("SMS sent to found item owner: {$match['found_user_phone']} - " . ($result['success'] ? 'Success' : 'Failed: ' . $result['message']));
        } else {
            error_log("No phone number for found item owner: {$match['found_user_name']}");
        }
        
        return [
            'success' => true,
            'message' => 'SMS notifications sent',
            'details' => $results
        ];
        
    } catch (Exception $e) {
        error_log("Match SMS Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to send match notifications: ' . $e->getMessage()
        ];
    }
}

/**
 * Log SMS activity for tracking
 * @param PDO $pdo Database connection
 * @param string $user_name User name (instead of user_id to avoid foreign key issues)
 * @param string $phone_number Phone number
 * @param string $message Message sent
 * @param string $status Success/failure status
 * @param string $details Additional details
 */
function logSMSActivity($pdo, $user_name, $phone_number, $message, $status, $details = '') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sms_logs (user_id, phone_number, message, status, details, sent_at) 
            VALUES (NULL, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$phone_number, $message, $status, $details]);
    } catch (Exception $e) {
        error_log("SMS Log Error: " . $e->getMessage());
    }
}
?>
