<?php
/**
 * WebSocket Server Restart & Cache Cleanup Script - Cron Version
 * 
 * This is a simpler version for automated cron job execution.
 * Outputs plain text suitable for cron email logs.
 * 
 * Setup in cPanel cron:
 * */30 * * * * /usr/local/bin/php /path/to/websocket/restart_and_clean_cron.php >> /path/to/cleanup.log 2>&1
 */

$websocketDir = __DIR__;
$cacheFile = dirname(__DIR__) . '/cache/websocket_live_prices.json';
$pidFile = $websocketDir . '/server.pid';
$stopScript = $websocketDir . '/stop_websocket.sh';
$startScript = $websocketDir . '/start_websocket.sh';

echo "=== WebSocket Cleanup - " . date('Y-m-d H:i:s') . " ===\n";

// 1. Stop server
echo "Stopping server...\n";
if (file_exists($pidFile)) {
    $pidData = json_decode(file_get_contents($pidFile), true);
    $pid = $pidData['pid'] ?? null;
    
    if ($pid && function_exists('posix_kill')) {
        posix_kill($pid, 15);
        echo "Sent stop signal to PID $pid\n";
        sleep(2);
    }
    unlink($pidFile);
}

// 2. Clear cache
echo "Clearing cache...\n";
if (file_exists($cacheFile)) {
    unlink($cacheFile);
    echo "Deleted: $cacheFile\n";
}

// 3. Clear old cache files
$cacheDir = dirname(__DIR__) . '/cache/';
$oldFiles = glob($cacheDir . '*.json');
$deleted = 0;
foreach ($oldFiles as $file) {
    if (time() - filemtime($file) > 3600) { // Older than 1 hour
        unlink($file);
        $deleted++;
    }
}
if ($deleted > 0) {
    echo "Deleted $deleted old cache files\n";
}

// 4. Start server
echo "Starting server...\n";
if (file_exists($startScript)) {
    exec("bash {$startScript} 2>&1", $output, $code);
    echo "Start script executed (code: $code)\n";
    
    sleep(3);
    
    if (file_exists($pidFile)) {
        $pidData = json_decode(file_get_contents($pidFile), true);
        echo "Server started with PID: " . ($pidData['pid'] ?? 'unknown') . "\n";
    } else {
        echo "WARNING: PID file not created\n";
    }
}

echo "=== Cleanup completed ===\n\n";
