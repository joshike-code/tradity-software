<?php
/**
 * WebSocket Server Starter
 * 
 * This script starts the WebSocket server as a background process.
 * Can be triggered via HTTP request or cron job.
 * 
 * Usage:
 * 1. Via browser: https://yourdomain.com/websocket/start_server.php?action=start&key=YOUR_SECRET_KEY
 * 2. Via cron: php websocket/start_server.php start
 */

require_once __DIR__ . '/../config/keys.php';

// Configuration
$PID_FILE = __DIR__ . '/server.pid';
$LOG_FILE = __DIR__ . '/server.log';
$SERVER_SCRIPT = __DIR__ . '/server.php';

// Security key for web requests (set this in your keys.php)
$keys = require __DIR__ . '/../config/keys.php';
$SECRET_KEY = $keys['websocket_control_key'] ?? 'change_this_secret_key';

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
    
    // Check if process exists
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows - check for php.exe running server.php
        exec('tasklist /FI "IMAGENAME eq php.exe" /FO CSV /NH', $output);
        foreach ($output as $line) {
            if (strpos($line, 'php.exe') !== false) {
                // At least one PHP process is running
                // Additional check: see if server.log is being written to recently
                $logFile = dirname($pidFile) . '/server.log';
                if (file_exists($logFile) && (time() - filemtime($logFile)) < 60) {
                    return true;
                }
            }
        }
        return false;
    } else {
        // Unix/Linux - check /proc
        return file_exists("/proc/$pid");
    }
}

/**
 * Start the WebSocket server
 */
function startServer($serverScript, $pidFile, $logFile) {
    if (isServerRunning($pidFile)) {
        return ['status' => 'already_running', 'message' => 'WebSocket server is already running'];
    }
    
    // Get PHP executable path
    $phpPath = PHP_BINARY;
    
    // Start server as background process
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows - use START command
        $command = "start /B \"\" \"$phpPath\" \"$serverScript\" > \"$logFile\" 2>&1";
        pclose(popen($command, 'r'));
        
        // Wait a moment for process to start
        sleep(2);
        
        // Write a temporary PID file (Windows makes it hard to get real PID)
        // We'll verify by checking the log file for startup messages
        file_put_contents($pidFile, time());
        
        // Verify server started by checking log
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            if (strpos($logContent, 'Server running') !== false || 
                strpos($logContent, 'WebSocket Server initialized') !== false) {
                return ['status' => 'started', 'message' => 'WebSocket server started successfully'];
            }
        }
    } else {
        // Unix/Linux - use nohup
        $command = "nohup $phpPath $serverScript > $logFile 2>&1 & echo $!";
        $pid = shell_exec($command);
        file_put_contents($pidFile, trim($pid));
        
        sleep(1);
        
        if (isServerRunning($pidFile)) {
            return ['status' => 'started', 'message' => 'WebSocket server started successfully', 'pid' => $pid ?? 'unknown'];
        }
    }
    
    return ['status' => 'error', 'message' => 'Failed to start server. Check logs.'];
}

/**
 * Stop the WebSocket server
 */
function stopServer($pidFile) {
    if (!isServerRunning($pidFile)) {
        @unlink($pidFile);
        return ['status' => 'not_running', 'message' => 'WebSocket server is not running'];
    }
    
    $pid = trim(file_get_contents($pidFile));
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows
        exec("taskkill /PID $pid /F 2>NUL", $output, $result);
    } else {
        // Unix/Linux
        posix_kill($pid, SIGTERM);
    }
    
    sleep(1);
    
    if (!isServerRunning($pidFile)) {
        @unlink($pidFile);
        return ['status' => 'stopped', 'message' => 'WebSocket server stopped successfully'];
    } else {
        return ['status' => 'error', 'message' => 'Failed to stop server'];
    }
}

/**
 * Get server status
 */
function getServerStatus($pidFile) {
    if (isServerRunning($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        return ['status' => 'running', 'message' => 'WebSocket server is running', 'pid' => $pid];
    } else {
        return ['status' => 'stopped', 'message' => 'WebSocket server is not running'];
    }
}

/**
 * Restart the server
 */
function restartServer($serverScript, $pidFile, $logFile) {
    stopServer($pidFile);
    sleep(2);
    return startServer($serverScript, $pidFile, $logFile);
}

// Handle CLI usage
if (php_sapi_name() === 'cli') {
    $action = $argv[1] ?? 'status';
    
    switch ($action) {
        case 'start':
            $result = startServer($SERVER_SCRIPT, $PID_FILE, $LOG_FILE);
            break;
        case 'stop':
            $result = stopServer($PID_FILE);
            break;
        case 'restart':
            $result = restartServer($SERVER_SCRIPT, $PID_FILE, $LOG_FILE);
            break;
        case 'status':
        default:
            $result = getServerStatus($PID_FILE);
            break;
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit($result['status'] === 'error' ? 1 : 0);
}

// Handle HTTP requests
header('Content-Type: application/json');

// Security check
$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
if ($providedKey !== $SECRET_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security key']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

switch ($action) {
    case 'start':
        $result = startServer($SERVER_SCRIPT, $PID_FILE, $LOG_FILE);
        break;
    case 'stop':
        $result = stopServer($PID_FILE);
        break;
    case 'restart':
        $result = restartServer($SERVER_SCRIPT, $PID_FILE, $LOG_FILE);
        break;
    case 'status':
    default:
        $result = getServerStatus($PID_FILE);
        break;
}

echo json_encode($result, JSON_PRETTY_PRINT);
