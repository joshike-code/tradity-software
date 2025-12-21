<?php
/**
 * Cron job to cleanup stale online activity statuses
 * 
 * This script should be run every 5-10 minutes via cron
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

try {
    $conn = Database::getConnection();
    
    // Mark as offline if no heartbeat in last 5 minutes
    $five_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-5 minutes'));
    
    $status = 'offline';
    $stmt = $conn->prepare("
        UPDATE accounts 
        SET online_status = ? 
        WHERE online_status != 'offline' 
        AND (last_heartbeat < ? OR last_heartbeat IS NULL)
    ");
    $stmt->bind_param("ss", $status, $five_minutes_ago);
    
    if ($stmt->execute()) {
        $affected_rows = $stmt->affected_rows;
        echo "[" . date('Y-m-d H:i:s') . "] Successfully cleaned up $affected_rows stale account statuses\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Failed to cleanup stale statuses\n";
    }
} catch (Exception $e) {
    error_log("Cleanup cron job error: " . $e->getMessage());
    echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
}
