<?php
/**
 * System Settings Helper
 * Provides functions to retrieve and update system settings from the database
 */

require_once __DIR__ . '/../../config/db.php';

/**
 * Get all system settings as an associative array
 * @return array Array of settings with setting_key as key and setting_value as value
 */
function getSystemSettings() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value, setting_type FROM system_settings");
        $settings = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['setting_key'];
            $value = $row['setting_value'];
            
            // Convert boolean string values to actual booleans
            if ($row['setting_type'] === 'boolean') {
                $value = (int)$value;
            }
            
            $settings[$key] = $value;
        }
        
        // Return settings with default values if table is empty
        if (empty($settings)) {
            return getDefaultSettings();
        }
        
        return $settings;
    } catch (PDOException $e) {
        // If table doesn't exist or error occurs, return default settings
        error_log("Settings Helper Error: " . $e->getMessage());
        return getDefaultSettings();
    }
}

/**
 * Get a specific system setting value
 * @param string $key The setting key to retrieve
 * @param mixed $default Default value if setting not found
 * @return mixed The setting value or default
 */
function getSetting($key, $default = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $value = $row['setting_value'];
            
            // Convert boolean string values to actual booleans
            if ($row['setting_type'] === 'boolean') {
                return (int)$value;
            }
            
            return $value;
        }
        
        return $default;
    } catch (PDOException $e) {
        error_log("Settings Helper Error: " . $e->getMessage());
        return $default;
    }
}

/**
 * Update a system setting
 * @param string $key The setting key
 * @param mixed $value The new value
 * @return bool Success status
 */
function updateSetting($key, $value) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = NOW()
        ");
        $stmt->execute([$key, $value]);
        return true;
    } catch (PDOException $e) {
        error_log("Settings Helper Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update multiple system settings at once
 * @param array $settings Associative array of setting_key => setting_value
 * @return bool Success status
 */
function updateSettings($settings) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = NOW()
        ");
        
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Settings Helper Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get default system settings (fallback)
 * @return array Default settings
 */
function getDefaultSettings() {
    return [
        'site_name' => 'FindIt - Lost & Found System',
        'site_description' => 'A comprehensive lost and found management system',
        'contact_email' => 'admin@findit.com',
        'site_tagline' => "Find What's Lost. Return What's Found.",
        'hero_image' => '',
        'login_image' => '',
        'register_image' => '',
        'site_logo' => ''
    ];
}

/**
 * Initialize system settings table if it doesn't exist
 * @return bool Success status
 */
function initializeSettingsTable() {
    global $pdo;
    
    try {
        // Check if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'system_settings'");
        if ($stmt->rowCount() == 0) {
            // Table doesn't exist, create it
            $sql = file_get_contents(__DIR__ . '/../database_updates.sql');
            $pdo->exec($sql);
            return true;
        }
        return true;
    } catch (PDOException $e) {
        error_log("Settings Helper Error: " . $e->getMessage());
        return false;
    }
}
