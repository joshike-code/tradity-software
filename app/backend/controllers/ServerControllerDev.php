<?php

class ServerController {
    
    public static function getServerStatus() {
        $statusFile = __DIR__ . '/../websocket/server.pid';
        $logFile = __DIR__ . '/../websocket/server.log';
        
        $isRunning = false;
        $pid = null;
        $uptime = null;
        $lastLog = null;
        
        // Check if PID file exists
        if (file_exists($statusFile)) {
            $pidData = json_decode(file_get_contents($statusFile), true);
            $pid = $pidData['pid'] ?? null;
            $startTime = $pidData['started_at'] ?? null;
            
            if ($pid && self::isProcessRunning($pid)) {
                $isRunning = true;
                if ($startTime) {
                    $uptime = time() - $startTime;
                }
            } else {
                // PID file exists but process is not running - clean up
                @unlink($statusFile);
            }
        }
        
        // Get last log entry
        if (file_exists($logFile)) {
            $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lastLog = end($logs) ?: null;
        }
        
        Response::success([
            'running' => $isRunning,
            'pid' => $pid,
            'uptime_seconds' => $uptime,
            'uptime_formatted' => $uptime ? self::formatUptime($uptime) : null,
            'last_log' => $lastLog,
            'status_message' => $isRunning ? 'WebSocket server is running' : 'WebSocket server is stopped'
        ]);
    }
    
    public static function startServer() {
        $statusFile = __DIR__ . '/../websocket/server.pid';
        $logFile = __DIR__ . '/../websocket/server.log';
        
        // Check if already running
        if (file_exists($statusFile)) {
            $pidData = json_decode(file_get_contents($statusFile), true);
            $pid = $pidData['pid'] ?? null;
            
            if ($pid && self::isProcessRunning($pid)) {
                Response::error('WebSocket server is already running (PID: ' . $pid . ')', 400);
                return;
            }
            
            // Clean up stale PID file
            @unlink($statusFile);
        }
        
        // Determine environment
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $serverScript = __DIR__ . '/../websocket/server.php';
        $phpBinary = PHP_BINARY ?: 'php';
        
        // Ensure log file directory exists
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Method 1: Try proc_open (most reliable, works on both Windows and Linux)
        if (function_exists('proc_open')) {
            $descriptorspec = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['file', $logFile, 'w'],  // stdout to log file (WRITE mode to clear old logs)
                2 => ['file', $logFile, 'a']   // stderr to log file (append)
            ];
            
            $cmd = $isWindows 
                ? "\"{$phpBinary}\" \"{$serverScript}\""
                : "{$phpBinary} {$serverScript}";
            
            $cwd = dirname($serverScript);
            
            // On Windows, don't bypass shell to allow proper detachment
            $options = $isWindows ? [] : ['bypass_shell' => true];
            $process = proc_open($cmd, $descriptorspec, $pipes, $cwd, null, $options);
            
            if (is_resource($process)) {
                // Close stdin pipe immediately
                fclose($pipes[0]);
                
                // On Linux, we can safely close the process handle
                if (!$isWindows) {
                    proc_close($process);
                }
                // On Windows, DON'T call proc_close - keep the handle open to prevent process termination
                
                // Wait longer for server to fully start and create its own PID file
                sleep(5);
                
                // The server.php creates its own PID file with getmypid()
                // Check if it was created successfully
                if (file_exists($statusFile)) {
                    $pidData = json_decode(file_get_contents($statusFile), true);
                    $serverPid = $pidData['pid'] ?? null;
                    
                    // Verify the server process is running
                    if ($serverPid && self::isProcessRunning($serverPid)) {
                        Response::success([
                            'message' => 'WebSocket server started successfully',
                            'pid' => $serverPid,
                            'running' => true,
                            'method' => 'proc_open'
                        ]);
                        return;
                    }
                }
                
                // Fallback: Check log file for startup confirmation
                if (file_exists($logFile)) {
                    $logContent = file_get_contents($logFile);
                    $lastModified = filemtime($logFile);
                    
                    // Check if server started in last 10 seconds
                    if ((time() - $lastModified) < 10) {
                        // Check for startup messages
                        if (stripos($logContent, 'Server running') !== false && 
                            stripos($logContent, 'Stop signal received') === false) {
                            
                            Response::error(
                                'Server started but PID file missing. Check logs: ' . $logFile . 
                                '. Try starting manually: ' . $phpBinary . ' ' . $serverScript,
                                500
                            );
                            return;
                        }
                        
                        // Check if server is stopping immediately
                        if (stripos($logContent, 'Stop signal received') !== false) {
                            Response::error(
                                'Server started but shut down immediately. Check for stale stop signal files in websocket directory.',
                                500
                            );
                            return;
                        }
                    }
                }
            }
        }
        
        // Method 2: Windows-specific - use popen with START command
        if ($isWindows && function_exists('popen')) {
            $command = "start /B \"WebSocketServer\" \"{$phpBinary}\" \"{$serverScript}\"";
            $handle = popen($command, 'r');
            
            if ($handle) {
                pclose($handle);
                
                // On Windows, we can't get the PID easily, so use timestamp
                $pidData = [
                    'pid' => time(), // Use timestamp as pseudo-PID
                    'started_at' => time()
                ];
                file_put_contents($statusFile, json_encode($pidData));
                
                sleep(3);
                
                // Verify by checking if log file is being written
                if (file_exists($logFile) && (time() - filemtime($logFile)) < 5) {
                    $logContent = file_get_contents($logFile);
                    if (stripos($logContent, 'Server running') !== false || 
                        stripos($logContent, 'WebSocket Server initialized') !== false) {
                        Response::success([
                            'message' => 'WebSocket server started successfully',
                            'pid' => $pidData['pid'],
                            'running' => true,
                            'method' => 'popen (Windows)'
                        ]);
                        return;
                    }
                }
            }
        }
        
        // Method 3: Unix/Linux - shell_exec with nohup
        if (!$isWindows && function_exists('shell_exec')) {
            $command = "nohup {$phpBinary} {$serverScript} > {$logFile} 2>&1 & echo $!";
            $pid = trim(shell_exec($command));
            
            if ($pid && is_numeric($pid)) {
                $pidData = [
                    'pid' => (int)$pid,
                    'started_at' => time()
                ];
                file_put_contents($statusFile, json_encode($pidData));
                
                sleep(2);
                
                if (self::isProcessRunning($pid)) {
                    Response::success([
                        'message' => 'WebSocket server started successfully',
                        'pid' => $pid,
                        'running' => true,
                        'method' => 'shell_exec (Linux)'
                    ]);
                    return;
                }
            }
        }
        
        // If all methods failed, provide helpful error message
        $errorDetails = [
            'proc_open' => function_exists('proc_open') ? 'available' : 'disabled',
            'popen' => function_exists('popen') ? 'available' : 'disabled',
            'shell_exec' => function_exists('shell_exec') ? 'available' : 'disabled',
            'os' => PHP_OS,
            'php_binary' => $phpBinary
        ];
        
        Response::error(
            'Failed to start WebSocket server. ' .
            'Try running manually: ' . $phpBinary . ' ' . $serverScript . 
            ' or set up a cron job. Debug info: ' . json_encode($errorDetails),
            500
        );
    }
    
    public static function stopServer() {
        $statusFile = __DIR__ . '/../websocket/server.pid';
        
        if (!file_exists($statusFile)) {
            // Add debug info
            $debugInfo = [
                'pid_file_path' => $statusFile,
                'pid_file_exists' => false,
                'server_directory' => __DIR__ . '/../websocket'
            ];
            Response::error('WebSocket server is not running (PID file not found). Debug: ' . json_encode($debugInfo), 400);
            return;
        }
        
        $pidData = json_decode(file_get_contents($statusFile), true);
        $pid = $pidData['pid'] ?? null;
        
        if (!$pid) {
            @unlink($statusFile);
            Response::error('Invalid PID file (no PID found in file)', 400);
            return;
        }
        
        if (!self::isProcessRunning($pid)) {
            @unlink($statusFile);
            $debugInfo = [
                'pid' => $pid,
                'running' => false,
                'reason' => 'Process check failed'
            ];
            Response::error('Process is not running. Debug: ' . json_encode($debugInfo), 400);
            return;
        }
        
        // Create a stop signal file (the server checks for this)
        $stopFile = __DIR__ . '/../websocket/server.stop';
        file_put_contents($stopFile, time());
        
        // Attempt to stop the process using available methods
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $stopped = false;
        
        // Method 1: Graceful shutdown via signal file (most cPanel-friendly)
        // The server's main loop checks for this file and exits gracefully
        sleep(3); // Wait for graceful shutdown
        
        if (!self::isProcessRunning($pid)) {
            @unlink($statusFile);
            @unlink($stopFile);
            Response::success([
                'message' => 'WebSocket server stopped successfully (graceful shutdown)',
                'pid' => $pid,
                'running' => false,
                'method' => 'signal_file'
            ]);
            return;
        }
        
        // Method 2: Try posix_kill (if available on Linux)
        if (!$isWindows && function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM); // Graceful termination
            sleep(2);
            
            if (!self::isProcessRunning($pid)) {
                @unlink($statusFile);
                @unlink($stopFile);
                Response::success([
                    'message' => 'WebSocket server stopped successfully',
                    'pid' => $pid,
                    'running' => false,
                    'method' => 'posix_kill'
                ]);
                return;
            }
            
            // Force kill if graceful didn't work
            posix_kill($pid, SIGKILL);
            sleep(1);
        }
        
        // Method 3: Try exec/shell_exec (may be restricted on cPanel)
        if (function_exists('exec')) {
            if ($isWindows) {
                @exec("taskkill /F /PID {$pid} 2>&1", $output, $returnCode);
            } else {
                @exec("kill {$pid} 2>&1", $output, $returnCode);
                sleep(1);
                if (self::isProcessRunning($pid)) {
                    @exec("kill -9 {$pid} 2>&1");
                }
            }
            
            sleep(1);
        }
        
        // Clean up files
        @unlink($statusFile);
        @unlink($stopFile);
        
        // Final verification
        if (!self::isProcessRunning($pid)) {
            Response::success([
                'message' => 'WebSocket server stopped successfully',
                'pid' => $pid,
                'running' => false
            ]);
        } else {
            Response::error(
                'Could not stop WebSocket server automatically. ' .
                'Please use cPanel Process Manager to kill PID: ' . $pid . 
                ', or wait for it to detect the stop signal.',
                500
            );
        }
    }
    
    private static function isProcessRunning($pid) {
        if (!$pid) {
            return false;
        }
        
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            // For Windows, if PID is a timestamp (our pseudo-PID), check log file activity
            if ($pid > 1000000000) { // Looks like a timestamp
                $logFile = __DIR__ . '/../websocket/server.log';
                if (file_exists($logFile)) {
                    // Check if log file was modified in last 30 seconds
                    return (time() - filemtime($logFile)) < 30;
                }
                return false;
            }
            
            // Windows: Use tasklist with proper PID check
            $output = @shell_exec("tasklist /FI \"PID eq {$pid}\" /NH 2>&1");
            if ($output) {
                // Check if the output contains the PID and php.exe
                $lines = explode("\n", $output);
                foreach ($lines as $line) {
                    if (stripos($line, 'php.exe') !== false && stripos($line, (string)$pid) !== false) {
                        return true;
                    }
                }
            }
            return false;
        } else {
            // Linux/Unix: Check /proc or use ps
            if (file_exists("/proc/{$pid}")) {
                return true;
            }
            
            $result = @exec("ps -p {$pid} 2>&1", $output, $returnCode);
            return $returnCode === 0;
        }
    }
    
    private static function formatUptime($seconds) {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";
        if ($secs > 0 || empty($parts)) $parts[] = "{$secs}s";
        
        return implode(' ', $parts);
    }
}
