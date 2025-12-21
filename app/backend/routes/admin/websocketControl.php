<?php
/**
 * WebSocket Server Control API
 * 
 * Admin endpoint to manage the WebSocket server
 * 
 * Endpoints:
 * POST /routes/admin/websocketControl.php?action=start
 * POST /routes/admin/websocketControl.php?action=stop
 * POST /routes/admin/websocketControl.php?action=restart
 * GET  /routes/admin/websocketControl.php?action=status
 */

require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../core/response.php';

// Verify admin authentication
AuthMiddleware::authenticate();

$user_id = $_SESSION['user_id'];

// Check if user is admin (assuming you have an is_admin column or role check)
$conn = Database::getConnection();
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user || $user['role'] !== 'admin') {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Include the server control functions
$PID_FILE = __DIR__ . '/../../websocket/server.pid';
$LOG_FILE = __DIR__ . '/../../websocket/server.log';
$SERVER_SCRIPT = __DIR__ . '/../../websocket/server.php';

function isServerRunning($pidFile) {
    if (!file_exists($pidFile)) {
        return false;
    }
    
    $pid = trim(file_get_contents($pidFile));
    
    if (empty($pid)) {
        return false;
    }
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec("tasklist /FI \"PID eq $pid\" 2>NUL | find \"$pid\" >NUL", $output, $result);
        return $result === 0;
    } else {
        return file_exists("/proc/$pid");
    }
}

function startServer($serverScript, $pidFile, $logFile) {
    if (isServerRunning($pidFile)) {
        return ['status' => 'already_running', 'message' => 'WebSocket server is already running'];
    }
    
    $phpPath = PHP_BINARY;
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = "start /B \"\" \"$phpPath\" \"$serverScript\" > \"$logFile\" 2>&1";
        pclose(popen($command, 'r'));
        sleep(2);
        exec("wmic process where \"commandline like '%$serverScript%' and name='php.exe'\" get processid", $output);
        if (isset($output[1])) {
            $pid = trim($output[1]);
            file_put_contents($pidFile, $pid);
        }
    } else {
        $command = "nohup $phpPath $serverScript > $logFile 2>&1 & echo $!";
        $pid = shell_exec($command);
        file_put_contents($pidFile, trim($pid));
    }
    
    sleep(1);
    
    if (isServerRunning($pidFile)) {
        return ['status' => 'started', 'message' => 'WebSocket server started successfully'];
    } else {
        return ['status' => 'error', 'message' => 'Failed to start server. Check logs.'];
    }
}

function stopServer($pidFile) {
    if (!isServerRunning($pidFile)) {
        @unlink($pidFile);
        return ['status' => 'not_running', 'message' => 'WebSocket server is not running'];
    }
    
    $pid = trim(file_get_contents($pidFile));
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec("taskkill /PID $pid /F 2>NUL");
    } else {
        shell_exec("kill -15 $pid");
    }
    
    sleep(1);
    
    if (!isServerRunning($pidFile)) {
        @unlink($pidFile);
        return ['status' => 'stopped', 'message' => 'WebSocket server stopped successfully'];
    } else {
        return ['status' => 'error', 'message' => 'Failed to stop server'];
    }
}

function getServerStatus($pidFile, $logFile) {
    if (isServerRunning($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        
        // Get last 10 lines of log
        $logLines = [];
        if (file_exists($logFile)) {
            $logContent = file($logFile);
            $logLines = array_slice($logContent, -10);
        }
        
        return [
            'status' => 'running',
            'message' => 'WebSocket server is running',
            'pid' => $pid,
            'logs' => $logLines
        ];
    } else {
        return ['status' => 'stopped', 'message' => 'WebSocket server is not running'];
    }
}

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

switch ($action) {
    case 'start':
        $result = startServer($SERVER_SCRIPT, $PID_FILE, $LOG_FILE);
        Response::success($result['message'], $result);
        break;
        
    case 'stop':
        $result = stopServer($PID_FILE);
        Response::success($result['message'], $result);
        break;
        
    case 'restart':
        $stopResult = stopServer($PID_FILE);
        sleep(2);
        $startResult = startServer($SERVER_SCRIPT, $PID_FILE, $LOG_FILE);
        Response::success('Server restarted', $startResult);
        break;
        
    case 'status':
    default:
        $result = getServerStatus($PID_FILE, $LOG_FILE);
        Response::success($result['message'], $result);
        break;
}
