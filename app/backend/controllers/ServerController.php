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
        $startTriggerFile = __DIR__ . '/../websocket/server.start';
        $startScript = __DIR__ . '/../websocket/start_websocket.sh';
        
        // Check if already running
        if (file_exists($statusFile)) {
            $pidData = json_decode(file_get_contents($statusFile), true);
            $pid = $pidData['pid'] ?? null;
            
            if ($pid && self::isProcessRunning($pid)) {
                Response::success([
                    'message' => 'WebSocket server is already running',
                    'pid' => $pid,
                    'running' => true
                ]);
                return;
            }
            
            // Clean up stale PID file
            @unlink($statusFile);
        }
        
        // Check if start script exists (Linux/cPanel)
        if (file_exists($startScript)) {
            // Try to execute the script directly
            if (function_exists('exec')) {
                $output = [];
                $returnCode = null;
                @exec("bash {$startScript} 2>&1", $output, $returnCode);
                
                sleep(3); // Wait for startup
                
                // Check if server started
                if (file_exists($statusFile)) {
                    $pidData = json_decode(file_get_contents($statusFile), true);
                    $pid = $pidData['pid'] ?? null;
                    
                    if ($pid && self::isProcessRunning($pid)) {
                        Response::success([
                            'message' => 'WebSocket server started successfully via shell script',
                            'pid' => $pid,
                            'running' => true,
                            'method' => 'shell_script'
                        ]);
                        return;
                    }
                }
            }
            
            // Fallback: Create trigger file for cron to pick up
            file_put_contents($startTriggerFile, json_encode([
                'requested_at' => time(),
                'requested_by' => 'admin_dashboard'
            ]));
            
            Response::success([
                'message' => 'Start command issued. Server will start within 5 minutes via cron job. Check status to confirm.',
                'running' => false,
                'method' => 'cron_trigger',
                'note' => 'If server doesn\'t start, ensure cron job is configured: bash ' . $startScript
            ]);
            return;
        }
        
        // Windows or development environment
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            // Try Windows batch file if it exists
            $batchScript = __DIR__ . '/../websocket/start_websocket.bat';
            if (file_exists($batchScript)) {
                @exec("start /B cmd /c \"{$batchScript}\"", $output, $returnCode);
                
                sleep(3);
                
                if (file_exists($statusFile)) {
                    $pidData = json_decode(file_get_contents($statusFile), true);
                    $pid = $pidData['pid'] ?? null;
                    
                    if ($pid && self::isProcessRunning($pid)) {
                        Response::success([
                            'message' => 'WebSocket server started successfully',
                            'pid' => $pid,
                            'running' => true,
                            'method' => 'windows_batch'
                        ]);
                        return;
                    }
                }
            }
        }
        
        // Manual instruction
        $serverScript = __DIR__ . '/../websocket/server.php';
        $phpBinary = PHP_BINARY ?: 'php';
        
        Response::error([
            'message' => 'Cannot start server automatically from web interface.',
            'instructions' => [
                'cPanel' => [
                    'step1' => 'SSH into server',
                    'step2' => 'chmod +x ' . $startScript,
                    'step3' => 'bash ' . $startScript,
                    'step4' => 'Setup cron job: */5 * * * * bash ' . $startScript
                ],
                'manual' => $phpBinary . ' ' . $serverScript . ' &',
                'note' => 'For production, use cron job to keep server running automatically'
            ]
        ], 500);
    }
    
    public static function stopServer() {
        $statusFile = __DIR__ . '/../websocket/server.pid';
        $stopScript = __DIR__ . '/../websocket/stop_websocket.sh';
        
        if (!file_exists($statusFile)) {
            Response::error('WebSocket server is not running (PID file not found)', 400);
            return;
        }
        
        $pidData = json_decode(file_get_contents($statusFile), true);
        $pid = $pidData['pid'] ?? null;
        
        if (!$pid) {
            @unlink($statusFile);
            Response::error('Invalid PID file (no PID found)', 400);
            return;
        }
        
        if (!self::isProcessRunning($pid)) {
            @unlink($statusFile);
            Response::error('Process is not running', 400);
            return;
        }
        
        // Create a stop signal file (the server checks for this)
        $stopFile = __DIR__ . '/../websocket/server.stop';
        file_put_contents($stopFile, time());
        
        // Try using stop script if it exists (Linux/cPanel)
        if (file_exists($stopScript) && function_exists('exec')) {
            @exec("bash {$stopScript} 2>&1", $output, $returnCode);
            
            sleep(2);
            
            if (!self::isProcessRunning($pid)) {
                Response::success([
                    'message' => 'WebSocket server stopped successfully',
                    'pid' => $pid,
                    'running' => false,
                    'method' => 'shell_script'
                ]);
                return;
            }
        }
        
        // Wait for graceful shutdown via signal file
        sleep(3);
        
        if (!self::isProcessRunning($pid)) {
            @unlink($statusFile);
            @unlink($stopFile);
            Response::success([
                'message' => 'WebSocket server stopped gracefully',
                'pid' => $pid,
                'running' => false,
                'method' => 'signal_file'
            ]);
            return;
        }
        
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        // Try posix_kill (Linux)
        if (!$isWindows && function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
            sleep(2);
            
            if (!self::isProcessRunning($pid)) {
                @unlink($statusFile);
                @unlink($stopFile);
                Response::success([
                    'message' => 'WebSocket server stopped',
                    'pid' => $pid,
                    'running' => false,
                    'method' => 'posix_kill'
                ]);
                return;
            }
            
            // Force kill
            @posix_kill($pid, SIGKILL);
            sleep(1);
        }
        
        // Try exec (may be restricted)
        if (function_exists('exec')) {
            if ($isWindows) {
                @exec("taskkill /F /PID {$pid} 2>&1");
            } else {
                @exec("kill {$pid} 2>&1");
                sleep(1);
                if (self::isProcessRunning($pid)) {
                    @exec("kill -9 {$pid} 2>&1");
                }
            }
            
            sleep(1);
        }
        
        // Clean up
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
            Response::error([
                'message' => 'Could not stop server automatically.',
                'instructions' => 'Use cPanel Process Manager to kill PID: ' . $pid . ', or run: bash ' . $stopScript,
                'pid' => $pid
            ], 500);
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
