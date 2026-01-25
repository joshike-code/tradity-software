<?php

class ServerController {
    
    public static function getServerStatus() {
        $statusFile = __DIR__ . '/../websocket/server.pid';
        $logFile = __DIR__ . '/../websocket/server.log';
        $restartFile = __DIR__ . '/../restartserver.txt';
        
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
        
        // Get last log entry (use memory-efficient method to avoid loading entire file)
        if (file_exists($logFile)) {
            $lastLog = self::getLastLogLine($logFile);
        }
        
        // Check for restart notification
        $restartRequired = file_exists($restartFile);
        $restartMessage = null;
        
        if ($restartRequired) {
            $fileContent = @file_get_contents($restartFile);
            if ($fileContent && trim($fileContent)) {
                $restartMessage = trim($fileContent);
            } else {
                $restartMessage = 'A software update requires the WebSocket server to be restarted';
            }
        }
        
        Response::success([
            'running' => $isRunning,
            'pid' => $pid,
            'uptime_seconds' => $uptime,
            'uptime_formatted' => $uptime ? self::formatUptime($uptime) : null,
            'last_log' => $lastLog,
            'status_message' => $isRunning ? 'WebSocket server is running' : 'WebSocket server is stopped',
            'restart_required' => $restartRequired,
            'restart_message' => $restartMessage
        ]);
    }
    
    public static function startServer() {
        $statusFile = __DIR__ . '/../websocket/server.pid';
        $startTriggerFile = __DIR__ . '/../websocket/server.start';
        $startScript = __DIR__ . '/../websocket/start_websocket.sh';
        $logFile = __DIR__ . '/../websocket/server.log';
        
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
        
        // Determine environment
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $serverScript = __DIR__ . '/../websocket/server.php';
        $phpBinary = PHP_BINARY ?: 'php';
        
        // Ensure log file directory exists
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Method 1: Try proc_open (works on both Windows and Linux)
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
                        // Delete restart notification file if it exists
                        $restartFile = __DIR__ . '/../restartserver.txt';
                        if (file_exists($restartFile)) {
                            @unlink($restartFile);
                        }
                        
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
                                'Server started but stopped immediately. Check logs: ' . $logFile,
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
                
                sleep(3);
                
                // Verify by checking if log file is being written
                if (file_exists($logFile) && (time() - filemtime($logFile)) < 5) {
                    $logContent = file_get_contents($logFile);
                    if (stripos($logContent, 'Server running') !== false || 
                        stripos($logContent, 'WebSocket Server initialized') !== false) {
                        
                        // Server started, wait for PID file
                        if (file_exists($statusFile)) {
                            $pidData = json_decode(file_get_contents($statusFile), true);
                            
                            // Delete restart notification file if it exists
                            $restartFile = __DIR__ . '/../restartserver.txt';
                            if (file_exists($restartFile)) {
                                @unlink($restartFile);
                            }
                            
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
                    // Delete restart notification file if it exists
                    $restartFile = __DIR__ . '/../restartserver.txt';
                    if (file_exists($restartFile)) {
                        @unlink($restartFile);
                    }
                    
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
        
        // Method 4: Check if start script exists (Linux/cPanel)
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
                        // Delete restart notification file if it exists
                        $restartFile = __DIR__ . '/../restartserver.txt';
                        if (file_exists($restartFile)) {
                            @unlink($restartFile);
                        }
                        
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
        
        // If all methods failed, provide helpful error message
        $errorDetails = [
            'proc_open' => function_exists('proc_open') ? 'available' : 'disabled',
            'popen' => function_exists('popen') ? 'available' : 'disabled',
            'shell_exec' => function_exists('shell_exec') ? 'available' : 'disabled',
            'exec' => function_exists('exec') ? 'available' : 'disabled',
            'os' => PHP_OS,
            'php_binary' => $phpBinary,
            'is_windows' => $isWindows
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
        
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        // Method 1: Graceful shutdown via signal file
        // The server's main loop checks for this file and exits gracefully
        sleep(3); // Wait for graceful shutdown
        
        if (!self::isProcessRunning($pid)) {
            // Server stopped gracefully (may have deleted its own PID file)
            if (file_exists($statusFile)) {
                @unlink($statusFile);
            }
            if (file_exists($stopFile)) {
                @unlink($stopFile);
            }
            Response::success([
                'message' => 'WebSocket server stopped successfully (graceful shutdown)',
                'pid' => $pid,
                'running' => false,
                'method' => 'signal_file'
            ]);
            return;
        }
        
        // Method 2: Try using stop script if it exists (Linux/cPanel)
        if (file_exists($stopScript) && function_exists('exec')) {
            @exec("bash {$stopScript} 2>&1", $output, $returnCode);
            
            sleep(2);
            
            if (!self::isProcessRunning($pid)) {
                if (file_exists($statusFile)) {
                    @unlink($statusFile);
                }
                if (file_exists($stopFile)) {
                    @unlink($stopFile);
                }
                Response::success([
                    'message' => 'WebSocket server stopped successfully',
                    'pid' => $pid,
                    'running' => false,
                    'method' => 'shell_script'
                ]);
                return;
            }
        }
        
        // Method 3: Try posix_kill (Linux only)
        if (!$isWindows && function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
            sleep(2);
            
            if (!self::isProcessRunning($pid)) {
                if (file_exists($statusFile)) {
                    @unlink($statusFile);
                }
                if (file_exists($stopFile)) {
                    @unlink($stopFile);
                }
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
        
        // Method 4: Try exec (may be restricted)
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
        
        // Clean up (check if files exist first)
        if (file_exists($statusFile)) {
            @unlink($statusFile);
        }
        if (file_exists($stopFile)) {
            @unlink($stopFile);
        }
        
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
    
    /**
     * Get last line from log file without loading entire file into memory
     * Prevents memory exhaustion on large log files
     */
    private static function getLastLogLine($filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }
        
        $fileSize = @filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            return null;
        }
        
        // Try to open file
        $fp = @fopen($filePath, 'r');
        if (!$fp) {
            return null;
        }
        
        // For small files (< 1MB), just read normally
        if ($fileSize < 1048576) {
            $content = @file_get_contents($filePath);
            fclose($fp);
            if ($content !== false) {
                $lines = explode("\n", trim($content));
                return end($lines) ?: null;
            }
            return null;
        }
        
        // For large files, read from the end
        $line = '';
        $buffer = '';
        $chunkSize = 4096;
        
        // Start reading from end in chunks
        for ($pos = -$chunkSize; abs($pos) <= $fileSize; $pos -= $chunkSize) {
            $seekPos = max($pos, -$fileSize);
            
            if (@fseek($fp, $seekPos, SEEK_END) !== 0) {
                break;
            }
            
            $chunk = fread($fp, $chunkSize);
            if ($chunk === false) {
                break;
            }
            
            $buffer = $chunk . $buffer;
            
            // Look for last newline
            $lastNewline = strrpos($buffer, "\n");
            if ($lastNewline !== false && $lastNewline < strlen($buffer) - 1) {
                // Found the last line
                $line = substr($buffer, $lastNewline + 1);
                break;
            } elseif ($lastNewline !== false && abs($pos) < $fileSize) {
                // Found newline but it's at the end, keep looking
                $buffer = rtrim($buffer, "\n\r");
                $lastNewline = strrpos($buffer, "\n");
                if ($lastNewline !== false) {
                    $line = substr($buffer, $lastNewline + 1);
                    break;
                }
            }
            
            // If we've read the entire file
            if (abs($seekPos) >= $fileSize) {
                $line = $buffer;
                break;
            }
        }
        
        fclose($fp);
        return trim($line) ?: null;
    }
}
