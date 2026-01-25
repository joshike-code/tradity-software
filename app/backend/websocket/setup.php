<?php
/**
 * WebSocket Server Setup Script
 * 
 * This script helps set up the WebSocket server on cPanel without SSH access.
 * Access via: https://yourdomain.com/app/backend/websocket/setup.php
 * 
 * IMPORTANT: Delete this file after setup is complete for security!
 */

header('Content-Type: text/html; charset=UTF-8');

$websocketDir = __DIR__;
$startScript = $websocketDir . '/start_websocket.sh';
$stopScript = $websocketDir . '/stop_websocket.sh';
$serverScript = $websocketDir . '/server.php';

$messages = [];
$errors = [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'make_executable') {
        // Make scripts executable
        $files = [$startScript, $stopScript];
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                if (chmod($file, 0755)) {
                    $messages[] = "✅ Made executable: " . basename($file);
                } else {
                    $errors[] = "❌ Failed to make executable: " . basename($file);
                }
            } else {
                $errors[] = "❌ File not found: " . basename($file);
            }
        }
    }
    
    if ($action === 'test_start') {
        // Try to start the server
        if (file_exists($startScript)) {
            $output = [];
            $returnCode = null;
            @exec("bash {$startScript} 2>&1", $output, $returnCode);
            
            if ($returnCode === 0 || $returnCode === null) {
                $messages[] = "✅ Start command executed. Check status below.";
                $messages[] = "Output: " . implode("<br>", $output);
            } else {
                $errors[] = "❌ Start command failed with code: {$returnCode}";
                $errors[] = "Output: " . implode("<br>", $output);
            }
        } else {
            $errors[] = "❌ start_websocket.sh not found";
        }
    }
    
    if ($action === 'test_stop') {
        // Try to stop the server
        if (file_exists($stopScript)) {
            $output = [];
            $returnCode = null;
            @exec("bash {$stopScript} 2>&1", $output, $returnCode);
            
            if ($returnCode === 0 || $returnCode === null) {
                $messages[] = "✅ Stop command executed. Server should stop shortly.";
                $messages[] = "Output: " . implode("<br>", $output);
            } else {
                $errors[] = "❌ Stop command failed with code: {$returnCode}";
                $errors[] = "Output: " . implode("<br>", $output);
            }
        } else {
            $errors[] = "❌ stop_websocket.sh not found";
        }
    }
    
    if ($action === 'create_cron_helper') {
        // Create a PHP script that can be called via HTTP (for cPanel URL cron)
        $cronHelperContent = <<<'PHP'
<?php
/**
 * Cron Helper Script
 * 
 * This script can be called via cPanel's HTTP cron job feature
 * URL: https://yourdomain.com/app/backend/websocket/cron_start.php?key=YOUR_SECRET_KEY
 * 
 * Security: Change the secret key below!
 */

$secretKey = 'CHANGE_THIS_SECRET_KEY_' . bin2hex(random_bytes(16));
$providedKey = $_GET['key'] ?? '';

if ($providedKey !== $secretKey) {
    http_response_code(403);
    die("Access denied. Invalid key.");
}

// Start the WebSocket server
$startScript = __DIR__ . '/start_websocket.sh';

if (!file_exists($startScript)) {
    http_response_code(500);
    die("Start script not found");
}

$output = [];
$returnCode = null;
exec("bash {$startScript} 2>&1", $output, $returnCode);

echo "Cron executed at " . date('Y-m-d H:i:s') . "\n";
echo "Return code: {$returnCode}\n";
echo "Output:\n" . implode("\n", $output);

// Also check status
$pidFile = __DIR__ . '/server.pid';
if (file_exists($pidFile)) {
    $pidData = json_decode(file_get_contents($pidFile), true);
    echo "\nServer PID: " . ($pidData['pid'] ?? 'Unknown');
}
PHP;

        $cronHelperPath = $websocketDir . '/cron_start.php';
        if (file_put_contents($cronHelperPath, $cronHelperContent)) {
            $messages[] = "✅ Created cron_start.php";
            $messages[] = "⚠️ IMPORTANT: Edit cron_start.php and change the secret key!";
        } else {
            $errors[] = "❌ Failed to create cron_start.php";
        }
    }
}

// Check current status
$statusInfo = [];
$pidFile = $websocketDir . '/server.pid';
$logFile = $websocketDir . '/server.log';

if (file_exists($pidFile)) {
    $pidData = json_decode(file_get_contents($pidFile), true);
    $statusInfo['pid'] = $pidData['pid'] ?? 'Unknown';
    $statusInfo['started_at'] = isset($pidData['started_at']) ? date('Y-m-d H:i:s', $pidData['started_at']) : 'Unknown';
} else {
    $statusInfo['status'] = 'Not running (no PID file)';
}

if (file_exists($logFile)) {
    $logLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $statusInfo['last_log'] = end($logLines);
    $statusInfo['log_file_size'] = filesize($logFile) . ' bytes';
}

// Get server resource usage
$resourceStats = [];

// Memory Usage
$memoryUsage = memory_get_usage(true);
$memoryLimit = ini_get('memory_limit');
$resourceStats['memory_used'] = round($memoryUsage / 1024 / 1024, 2) . ' MB';
$resourceStats['memory_limit'] = $memoryLimit;

// CPU Load Average (Unix-like systems)
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    $resourceStats['cpu_load'] = round($load[0], 2) . ' (1m), ' . round($load[1], 2) . ' (5m), ' . round($load[2], 2) . ' (15m)';
}

// Disk Space
$diskFree = @disk_free_space('/');
$diskTotal = @disk_total_space('/');
if ($diskFree !== false && $diskTotal !== false) {
    $diskUsed = $diskTotal - $diskFree;
    $diskPercent = round(($diskUsed / $diskTotal) * 100, 1);
    $resourceStats['disk_usage'] = round($diskUsed / 1024 / 1024 / 1024, 2) . ' GB / ' . round($diskTotal / 1024 / 1024 / 1024, 2) . ' GB (' . $diskPercent . '%)';
}

// Server Uptime (if available)
if (function_exists('shell_exec') && @shell_exec('uptime') !== null) {
    $uptime = @shell_exec('uptime -p');
    if ($uptime) {
        $resourceStats['server_uptime'] = trim($uptime);
    }
}

// Check script permissions
$permissions = [];
if (file_exists($startScript)) {
    $perms = fileperms($startScript);
    $permissions['start_websocket.sh'] = substr(sprintf('%o', $perms), -4) . (is_executable($startScript) ? ' ✅ Executable' : ' ❌ Not executable');
}
if (file_exists($stopScript)) {
    $perms = fileperms($stopScript);
    $permissions['stop_websocket.sh'] = substr(sprintf('%o', $perms), -4) . (is_executable($stopScript) ? ' ✅ Executable' : ' ❌ Not executable');
}

// Get PHP CLI path
$phpCliPath = PHP_BINARY;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebSocket Server Setup - cPanel Edition</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .header p { opacity: 0.9; }
        .content { padding: 30px; }
        .section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .section h2 {
            color: #667eea;
            font-size: 20px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn:hover { background: #5568d3; transform: translateY(-2px); }
        .btn-success { background: #10b981; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
        .message {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .message.success { background: #d1fae5; color: #065f46; }
        .message.error { background: #fee2e2; color: #991b1b; }
        .info-grid {
            display: grid;
            gap: 10px;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            background: white;
            border-radius: 6px;
        }
        .info-label { font-weight: 600; color: #6b7280; }
        .info-value { color: #111827; font-family: monospace; }
        .code-block {
            background: #1f2937;
            color: #f3f4f6;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 10px 0;
        }
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
        }
        .step {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .step-number {
            background: #667eea;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
        }
        .step-content { flex: 1; }
        form { margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 WebSocket Server Setup</h1>
            <p>No SSH Required - cPanel Web Interface Edition</p>
        </div>
        
        <div class="content">
            <?php if (!empty($messages)): ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="message success"><?= htmlspecialchars($msg) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $err): ?>
                    <div class="message error"><?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <div class="warning">
                <strong>⚠️ IMPORTANT: Fix Line Endings First!</strong><br>
                If you uploaded these files from Windows, they may have Windows line endings that cause errors like:<br>
                <code style="background: #fff; padding: 2px 6px; border-radius: 3px; color: #dc2626;">$'\r': command not found</code><br><br>
                <a href="fix_line_endings.php" class="btn btn-danger" target="_blank" style="margin-top: 10px;">🔧 Fix Line Endings Now</a>
                <p style="margin-top: 10px; font-size: 13px;">Run this BEFORE starting the server. It converts Windows (CRLF) to Unix (LF) line endings.</p>
            </div>
            
            <div class="section">
                <h2>📊 Current Status</h2>
                <div class="info-grid">
                    <?php foreach ($statusInfo as $key => $value): ?>
                        <div class="info-item">
                            <span class="info-label"><?= ucfirst(str_replace('_', ' ', $key)) ?>:</span>
                            <span class="info-value"><?= htmlspecialchars($value) ?></span>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php foreach ($permissions as $file => $perm): ?>
                        <div class="info-item">
                            <span class="info-label"><?= $file ?>:</span>
                            <span class="info-value"><?= $perm ?></span>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="info-item">
                        <span class="info-label">PHP CLI Path:</span>
                        <span class="info-value"><?= htmlspecialchars($phpCliPath) ?></span>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2>� Server Resource Usage</h2>
                <div class="info-grid">
                    <?php foreach ($resourceStats as $key => $value): ?>
                        <div class="info-item">
                            <span class="info-label"><?= ucfirst(str_replace('_', ' ', $key)) ?>:</span>
                            <span class="info-value"><?= htmlspecialchars($value) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($resourceStats)): ?>
                    <p style="color: #6b7280; margin-top: 10px;">Resource information not available on this system.</p>
                <?php endif; ?>
                <p style="margin-top: 15px; font-size: 13px; color: #6b7280;">
                    <strong>Tip:</strong> For detailed VPS monitoring, use the "VPS Resources" link in Diagnostic Tools below.
                </p>
            </div>
            
            <div class="section">
                <h2>�🔧 Step 1: Make Scripts Executable</h2>
                <p style="margin-bottom: 15px;">First, we need to make the shell scripts executable:</p>
                <form method="POST">
                    <input type="hidden" name="action" value="make_executable">
                    <button type="submit" class="btn btn-success">Make Scripts Executable</button>
                </form>
            </div>
            
            <div class="section">
                <h2>🧪 Step 2: Test Server Control</h2>
                <p style="margin-bottom: 15px;">Start or stop the server manually:</p>
                <form method="POST" style="display: inline-block; margin-right: 10px;">
                    <input type="hidden" name="action" value="test_start">
                    <button type="submit" class="btn btn-success">▶️ Start Server</button>
                </form>
                <form method="POST" style="display: inline-block;">
                    <input type="hidden" name="action" value="test_stop">
                    <button type="submit" class="btn btn-danger">⏹️ Stop Server</button>
                </form>
            </div>
            
            <div class="section">
                <h2>🔍 Diagnostic Tools</h2>
                <p style="margin-bottom: 15px;">Quick access to server monitoring and testing tools:</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                    <a href="fix_line_endings.php" class="btn btn-danger" target="_blank" style="text-align: center;">🔧 Fix Line Endings</a>
                    <a href="test_cron.php" class="btn btn-success" target="_blank" style="text-align: center;">🧪 Test Cron Setup</a>
                    <a href="view_logs.php" class="btn" target="_blank" style="text-align: center;">📊 View Server Logs</a>
                    <a href="test_binance.php" class="btn" target="_blank" style="text-align: center;">🔌 Test Binance Connection</a>
                    <a href="check_vendor.php" class="btn" target="_blank" style="text-align: center;">📦 Check Dependencies</a>
                    <a href="../monitoring/resource_monitor.php?key=your-random-secret-key-here" class="btn" target="_blank" style="text-align: center;">💻 VPS Resources</a>
                    <a href="restart_and_clean.php?key=CHANGE_ME_<?= md5('your-random-string-here') ?>" class="btn btn-danger" target="_blank" style="text-align: center;">🔄 Restart & Clean Cache</a>
                </div>
                <p style="margin-top: 15px; font-size: 13px; color: #6b7280;">
                    <strong>Tip:</strong> Run "Fix Line Endings" first if you see <code>$'\r'</code> errors
                </p>
            </div>
            
            <div class="section">
                <h2>⏰ Step 3: Setup Cron Job</h2>
                
                <div class="success-box" style="background: #d1fae5; padding: 15px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #10b981;">
                    <strong>✨ Easy Mode:</strong> Click the button below to get the EXACT cron commands for your server!<br>
                    <a href="get_cron_commands.php" class="btn btn-success" target="_blank" style="margin-top: 10px; text-decoration: none;">📋 Get My Cron Commands</a>
                    <p style="margin-top: 10px; font-size: 13px; color: #065f46;">
                        This tool auto-detects your PHP path and shows copy-paste ready commands for BOTH cron jobs (WebSocket + Email Queue).
                    </p>
                </div>
                
                <div class="warning">
                    <strong>⚠️ Choose ONE method below based on your cPanel capabilities:</strong>
                </div>
                
                <div class="step">
                    <div class="step-number">A</div>
                    <div class="step-content">
                        <h3>Method A: Shell Command Cron (Recommended)</h3>
                        <p>If your cPanel allows shell commands in cron jobs:</p>
                        <ol style="margin-left: 20px; margin-top: 10px;">
                            <li>Go to cPanel → <strong>Advanced → Cron Jobs</strong></li>
                            <li>Set interval to <strong>Every 5 minutes</strong>: <code>*/5 * * * *</code></li>
                            <li>In the "Command" field, paste this <strong>exact command</strong>:</li>
                        </ol>
                        <div class="code-block">/bin/bash <?= $startScript ?> >> <?= $websocketDir ?>/cron.log 2>&1</div>
                        <p style="margin-top: 10px; font-size: 13px;">
                            <strong>⚠️ Important:</strong> Copy the FULL path including <code>/bin/bash</code> at the start. 
                            This ensures the script runs properly. The output will be saved to <code>cron.log</code>.
                        </p>
                        <p style="margin-top: 10px; font-size: 13px; color: #059669;">
                            <strong>✓ Tip:</strong> After saving, wait 5 minutes then check <code>cron.log</code> to verify it's working.
                        </p>
                    </div>
                </div>
                
                <div class="step">
                    <div class="step-number">B</div>
                    <div class="step-content">
                        <h3>Method B: HTTP URL Cron (If shell commands restricted)</h3>
                        <p>If shell commands don't work, use HTTP URL method:</p>
                        <form method="POST" style="margin: 10px 0;">
                            <input type="hidden" name="action" value="create_cron_helper">
                            <button type="submit" class="btn">Create HTTP Cron Script</button>
                        </form>
                        <p style="margin-top: 10px;">After creating, setup cron job with this URL:</p>
                        <div class="code-block">wget -q -O- "<?= 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) ?>/cron_start.php?key=YOUR_SECRET_KEY"</div>
                        <p style="margin-top: 10px;"><strong>Important:</strong> Edit cron_start.php and change the secret key first!</p>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2>✅ Step 4: Verify Setup</h2>
                <p>After setting up the cron job:</p>
                <ol style="margin-left: 20px; margin-top: 10px;">
                    <li>Wait 5 minutes for the first cron run</li>
                    <li>Refresh this page to check if PID file exists</li>
                    <li>Check <code>cron.log</code> in the websocket folder to see cron output</li>
                    <li>Check <code>server.log</code> file for server startup messages</li>
                    <li>Access your admin dashboard to verify status</li>
                </ol>
                
                <div class="warning" style="margin-top: 15px;">
                    <strong>🔍 Troubleshooting:</strong><br><br>
                    
                    <strong>Problem: <code>$'\r': command not found</code> or <code>syntax error near unexpected token</code></strong><br>
                    → Windows line endings detected! This is the most common issue.<br>
                    → Fix: Click "Fix Line Endings" button above or in Diagnostic Tools<br>
                    → After fixing, make scripts executable again (Step 1)<br><br>
                    
                    <strong>Problem: Cron emails me the script code instead of running it</strong><br>
                    → You forgot <code>/bin/bash</code> at the start of the cron command<br>
                    → Fix: Use <code>/bin/bash /full/path/to/start_websocket.sh</code><br><br>
                    
                    <strong>Problem: "PHP not found" error</strong><br>
                    → The script will auto-detect PHP. Check <code>cron.log</code> for the detected path<br>
                    → If detection fails, manually edit start_websocket.sh and set PHP_BIN<br><br>
                    
                    <strong>Problem: Server starts but stops immediately</strong><br>
                    → Check <code>server.log</code> for errors (missing dependencies, DB connection, etc.)<br>
                    → Run manually: <code>bash start_websocket.sh</code> to see errors<br><br>
                    
                    <strong>Problem: Permission denied</strong><br>
                    → Run Step 1 again to make scripts executable<br>
                    → Or via SSH: <code>chmod +x start_websocket.sh stop_websocket.sh</code>
                </div>
            </div>
            
            <div class="section">
                <h2>🗑️ Step 5: Clean Up</h2>
                <div class="warning">
                    <strong>🔒 Security Warning:</strong><br>
                    After successful setup, <strong>DELETE this setup.php file</strong> for security!
                </div>
                <p style="margin-top: 15px;">File location:</p>
                <div class="code-block"><?= __FILE__ ?></div>
            </div>
            
            <div class="section">
                <h2>📖 Need Help?</h2>
                <p>If you encounter issues:</p>
                <ul style="margin-left: 20px; margin-top: 10px;">
                    <li>Check server.log file for error messages</li>
                    <li>Verify PHP CLI path is correct</li>
                    <li>Ensure file permissions are set (755 for scripts)</li>
                    <li>Contact support with error logs</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
