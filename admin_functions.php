<?php
// admin_functions.php

/**
 * Ensures the activity_log table exists.
 */
function ensureActivityLogTable($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type ENUM('user','item','report','memo','other') NOT NULL,
            entity_id INT NOT NULL,
            actor_admin_id INT NULL,
            action VARCHAR(64) NOT NULL,
            details JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at),
            INDEX idx_actor (actor_admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // Silently fail if table creation fails, to avoid breaking the page
        error_log("Activity Log Table Error: " . $e->getMessage());
    }
}

/**
 * Logs an admin activity.
 * 
 * @param PDO $pdo Database connection
 * @param int $adminId ID of the admin performing the action
 * @param string $entityType Entity type ('user', 'item', 'report', 'memo', 'other')
 * @param int $entityId ID of the entity
 * @param string $action Action name (e.g., 'block', 'approve')
 * @param mixed $details Optional array of details to be stored as JSON
 */
function logActivity($pdo, $adminId, $entityType, $entityId, $action, $details = null) {
    try {
        // Ensure table exists (could be optimized to run once per session, but safe here)
        // verify if table exists first to avoid overhead of CREATE TABLE on every log?
        // MySQL IF NOT EXISTS is fast enough.
        
        $sql = "INSERT INTO activity_log (entity_type, entity_id, actor_admin_id, action, details) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        
        $jsonDetails = $details ? json_encode($details) : null;
        
        $stmt->execute([$entityType, $entityId, $adminId, $action, $jsonDetails]);
    } catch (Exception $e) {
        // Critical: Do NOT stop execution if logging fails
        error_log("Activity Log Insert Error: " . $e->getMessage());
    }
}
?>
