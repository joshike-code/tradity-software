<?php
/**
 * Clear WebSocket Server Logs
 * 
 * Clears the server.log file to save space
 * Access: POST to https://yourdomain.com/app/backend/websocket/clear_logs.php
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Only POST requests allowed");
}

$logFile = __DIR__ . '/server.log';

if (file_exists($logFile)) {
    // Backup last 100 lines before clearing
    $lines = file($logFile);
    $lastLines = array_slice($lines, -100);
    $backupContent = "=== Log cleared on " . date('Y-m-d H:i:s') . " ===\n";
    $backupContent .= "=== Last 100 lines before clearing ===\n\n";
    $backupContent .= implode('', $lastLines);
    
    // Clear the log file
    file_put_contents($logFile, $backupContent);
    
    header('Location: view_logs.php?cleared=1');
} else {
    die("Log file not found");
}
?>
