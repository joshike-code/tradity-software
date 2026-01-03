<?php
/**
 * WebSocket Server Restart & Cache Cleanup Script
 * 
 * This script stops the server, clears cache, and restarts the server.
 * Can be called via browser or cron job.
 * 
 * Usage:
 * 1. Browser: https://yourdomain.com/app/backend/websocket/restart_and_clean.php?key=YOUR_SECRET_KEY
 * 2. Cron: wget -q -O- "https://yourdomain.com/app/backend/websocket/restart_and_clean.php?key=YOUR_SECRET_KEY"
 */

// Security: Change this secret key!
// define('SECRET_KEY', 'CHANGE_ME_' . md5('your-random-string-here'));

// // Check authentication
// $providedKey = $_GET['key'] ?? '';
// if ($providedKey !== SECRET_KEY) {
//     http_response_code(403);
//     die("❌ Access denied. Invalid key.");
// }

// Set execution time
set_time_limit(60);

$websocketDir = __DIR__;
$cacheFile = dirname(__DIR__) . '/cache/websocket_live_prices.json';
$stopScript = $websocketDir . '/stop_websocket.sh';
$startScript = $websocketDir . '/start_websocket.sh';
$pidFile = $websocketDir . '/server.pid';
$logFile = $websocketDir . '/server.log';

$output = [];
$output[] = "🔄 Starting WebSocket Server Restart & Cleanup";
$output[] = "Time: " . date('Y-m-d H:i:s');
$output[] = str_repeat('=', 50);

// Step 1: Clear cache FIRST (before killing server)
$output[] = "\n🗑️ Step 1: Clearing ALL cache files (before restart)...";

$deletedCount = 0;
$cacheDir = dirname(__DIR__) . '/cache/';

// First, clear the main WebSocket cache
if (file_exists($cacheFile)) {
    $fileSize = filesize($cacheFile);
    $fileTime = date('Y-m-d H:i:s', filemtime($cacheFile));
    $output[] = "Main cache: " . basename($cacheFile);
    $output[] = "Size: " . number_format($fileSize) . " bytes";
    $output[] = "Last modified: $fileTime";
    
    if (@unlink($cacheFile)) {
        $output[] = "✅ Main cache deleted (server will load fresh data on restart)";
        $deletedCount++;
    } else {
        $output[] = "❌ Failed to delete main cache (check permissions)";
    }
}

// Clear ALL JSON cache files (not just old ones)
$cacheFiles = glob($cacheDir . '*.json');

foreach ($cacheFiles as $file) {
    // Delete ALL cache files to ensure fresh start
    if (@unlink($file)) {
        $deletedCount++;
    }
}

$output[] = "✅ Deleted $deletedCount cache file(s) total";

// Also clear any PHP opcache if available
if (function_exists('opcache_reset')) {
    if (@opcache_reset()) {
        $output[] = "✅ PHP opcache cleared";
    }
}

// Step 2: Stop the server (kill ALL related processes)
$output[] = "\n📛 Step 2: Stopping server (killing all PHP WebSocket processes)...";

$killedCount = 0;

// Method 1: Kill the main PID from file
if (file_exists($pidFile)) {
    $pidData = json_decode(file_get_contents($pidFile), true);
    $pid = $pidData['pid'] ?? null;
    
    if ($pid) {
        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 15)) { // SIGTERM
                $output[] = "✅ Main server stopped (PID: $pid)";
                $killedCount++;
                sleep(1);
            }
        } elseif (function_exists('exec')) {
            @exec("kill -15 $pid 2>&1", $execOutput);
            $output[] = "✅ Stop signal sent to main PID $pid";
            $killedCount++;
            sleep(1);
        }
    }
    
    // Remove PID file
    @unlink($pidFile);
}

// Method 2: Kill ALL PHP processes running websocket_server.php (like pkill -f)
if (function_exists('shell_exec')) {
    // Find all PHP processes with websocket_server.php in command
    $psOutput = @shell_exec("ps aux | grep '[w]ebsocket_server.php' | awk '{print $2}'");
    
    if (!empty($psOutput)) {
        $pids = array_filter(explode("\n", trim($psOutput)));
        
        foreach ($pids as $pid) {
            $pid = trim($pid);
            if (is_numeric($pid)) {
                if (function_exists('posix_kill')) {
                    if (@posix_kill((int)$pid, 15)) {
                        $killedCount++;
                    }
                } elseif (function_exists('exec')) {
                    @exec("kill -15 $pid 2>&1");
                    $killedCount++;
                }
            }
        }
        
        if ($killedCount > 0) {
            $output[] = "✅ Killed $killedCount WebSocket process(es)";
            sleep(2); // Wait for all processes to die
        }
    }
} elseif (function_exists('exec')) {
    // Fallback: Use pkill command
    @exec("pkill -f 'websocket_server.php' 2>&1", $killOutput, $killReturn);
    if ($killReturn === 0) {
        $output[] = "✅ All WebSocket processes killed via pkill";
        $killedCount++;
        sleep(2);
    }
}

if ($killedCount === 0) {
    $output[] = "ℹ️ No running WebSocket processes found";
}

// Double-check: Force kill any remaining processes (SIGKILL)
sleep(1);
if (function_exists('shell_exec')) {
    $remainingProcs = @shell_exec("ps aux | grep '[w]ebsocket_server.php' | awk '{print $2}'");
    
    if (!empty(trim($remainingProcs))) {
        $output[] = "⚠️ Some processes still running - forcing kill...";
        $pids = array_filter(explode("\n", trim($remainingProcs)));
        
        foreach ($pids as $pid) {
            $pid = trim($pid);
            if (is_numeric($pid)) {
                if (function_exists('posix_kill')) {
                    @posix_kill((int)$pid, 9); // SIGKILL - force kill
                } elseif (function_exists('exec')) {
                    @exec("kill -9 $pid 2>&1");
                }
            }
        }
        
        $output[] = "✅ Force-killed remaining processes";
        sleep(1);
    }
} elseif (function_exists('exec')) {
    @exec("pkill -9 -f 'websocket_server.php' 2>&1");
}

// Remove PID file if it still exists
if (file_exists($pidFile)) {
    @unlink($pidFile);
    $output[] = "✅ Removed PID file";
}

// Step 3: Start the server
$output[] = "\n▶️ Step 3: Starting server...";

if (file_exists($startScript)) {
    if (function_exists('exec')) {
        // Execute start script
        $startOutput = [];
        $returnCode = null;
        @exec("bash {$startScript} 2>&1", $startOutput, $returnCode);
        
        if ($returnCode === 0 || $returnCode === null) {
            $output[] = "✅ Start command executed";
            
            // Wait a moment for server to start
            sleep(3);
            
            // Check if server actually started
            if (file_exists($pidFile)) {
                $pidData = json_decode(file_get_contents($pidFile), true);
                $newPid = $pidData['pid'] ?? null;
                $output[] = "✅ Server started successfully! (PID: $newPid)";
            } else {
                $output[] = "⚠️ Start command executed but PID file not created";
                $output[] = "Check server.log for errors";
            }
            
            if (!empty($startOutput)) {
                $output[] = "Output: " . implode("\n", $startOutput);
            }
        } else {
            $output[] = "❌ Start command failed (code: $returnCode)";
            $output[] = "Output: " . implode("\n", $startOutput);
        }
    } else {
        $output[] = "❌ exec() function disabled - cannot start server";
        $output[] = "💡 You'll need to start manually or setup cron job";
    }
} else {
    $output[] = "❌ start_websocket.sh not found";
}

// Step 4: Show server status
$output[] = "\n📊 Step 4: Current Status";

if (file_exists($pidFile)) {
    $pidData = json_decode(file_get_contents($pidFile), true);
    $output[] = "PID: " . ($pidData['pid'] ?? 'Unknown');
    $output[] = "Started at: " . date('Y-m-d H:i:s', $pidData['started_at'] ?? time());
}

if (file_exists($logFile)) {
    $logSize = filesize($logFile);
    $output[] = "Log file size: " . number_format($logSize) . " bytes";
    
    // Show last 5 log lines
    $logLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($logLines) > 0) {
        $lastLines = array_slice($logLines, -5);
        $output[] = "\nLast 5 log entries:";
        foreach ($lastLines as $line) {
            $output[] = "  " . htmlspecialchars($line);
        }
    }
}

// Display output
$output[] = "\n" . str_repeat('=', 50);
$output[] = "✅ Cleanup and restart completed!";
$output[] = "Time: " . date('Y-m-d H:i:s');

// Format output
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebSocket Server Restart</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a2e;
            color: #00ff00;
            padding: 20px;
            max-width: 900px;
            margin: 0 auto;
        }
        .output {
            background: #0f0f23;
            padding: 20px;
            border-radius: 8px;
            border: 2px solid #00ff00;
            white-space: pre-wrap;
            line-height: 1.6;
        }
        .header {
            color: #ffff00;
            font-size: 1.2em;
            margin-bottom: 20px;
            text-align: center;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #00ffff;
            text-decoration: none;
            padding: 10px 20px;
            border: 1px solid #00ffff;
            border-radius: 4px;
        }
        .back-link:hover {
            background: #00ffff;
            color: #1a1a2e;
        }
    </style>
</head>
<body>
    <div class="header">🔄 WebSocket Server Restart & Cleanup</div>
    <div class="output"><?php echo implode("\n", $output); ?></div>
    <a href="setup.php" class="back-link">← Back to Setup</a>
</body>
</html>
