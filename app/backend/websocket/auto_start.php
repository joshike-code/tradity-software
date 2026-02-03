<?php
/**
 * WebSocket Auto-Start
 * 
 * This script automatically starts the WebSocket server if it's not running.
 * Perfect for cPanel cron jobs to ensure the server stays running.
 * 
 * Setup in cPanel Cron Jobs:
 * Add this cron job to run every 5 minutes:
**/

$PID_FILE = __DIR__ . '/server.pid';
$SERVER_SCRIPT = __DIR__ . '/server.php';
$LOG_FILE = __DIR__ . '/server.log';

/**
 * Check if server is running
 */
function isServerRunning($pidFile) {
    if (!file_exists($pidFile)) {
        return false;
    }
    
    $pid = trim(file_get_contents($pidFile));
    
    if (empty($pid)) {
        return false;
    }
    
    // Check if process exists (Windows)
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec("tasklist /FI \"PID eq $pid\" 2>NUL | find \"$pid\" >NUL", $output, $result);
        return $result === 0;
    } else {
        // Unix/Linux
        return file_exists("/proc/$pid");
    }
}

/**
 * Start the server
 */
function startServer($serverScript, $pidFile, $logFile) {
    $phpPath = PHP_BINARY;
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows
        $command = "start /B \"\" \"$phpPath\" \"$serverScript\" > \"$logFile\" 2>&1";
        pclose(popen($command, 'r'));
    } else {
        // Unix/Linux
        $command = "nohup $phpPath $serverScript > $logFile 2>&1 & echo $!";
        $pid = shell_exec($command);
        file_put_contents($pidFile, trim($pid));
    }
    
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] WebSocket server started by auto_start.php\n", 3, $logFile);
}

// Main logic
if (!isServerRunning($PID_FILE)) {
    echo "WebSocket server is not running. Starting...\n";
    startServer($SERVER_SCRIPT, $PID_FILE, $LOG_FILE);
    echo "Server started!\n";
} else {
    echo "WebSocket server is already running.\n";
}
