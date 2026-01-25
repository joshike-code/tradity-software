<?php
/**
 * Cron Command Generator
 * 
 * This script displays the exact cron commands to use in cPanel
 * Access via: https://yourdomain.com/app/backend/websocket/get_cron_commands.php
 */

header('Content-Type: text/html; charset=UTF-8');

$websocketDir = __DIR__;
$backendDir = dirname($websocketDir);
$cronDir = $backendDir . '/cron';

// Script paths
$startWebsocketScript = $websocketDir . '/start_websocket.sh';
$cronUpdateScript = $cronDir . '/cron_update.php';

// Detect PHP CLI binary
$phpBinaries = [
    '/usr/local/bin/php',
    '/usr/bin/php',
    '/opt/cpanel/ea-php82/root/usr/bin/php',
    '/opt/cpanel/ea-php81/root/usr/bin/php',
    '/opt/cpanel/ea-php80/root/usr/bin/php',
    '/opt/cpanel/ea-php74/root/usr/bin/php',
];

// Try which command
$whichPhp = @trim(shell_exec('which php 2>/dev/null'));
if ($whichPhp && is_executable($whichPhp)) {
    array_unshift($phpBinaries, $whichPhp);
}

// Find first available PHP binary
$detectedPhp = null;
$phpVersion = null;
foreach (array_unique($phpBinaries) as $phpPath) {
    if (@is_executable($phpPath)) {
        $detectedPhp = $phpPath;
        $phpVersion = @trim(shell_exec("{$phpPath} -v 2>/dev/null | head -n 1"));
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>cPanel Cron Commands</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Courier New', monospace;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
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
        .header p { opacity: 0.9; font-size: 14px; }
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
        .code-block {
            background: #1f2937;
            color: #10b981;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 10px 0;
            position: relative;
        }
        .code-block.command {
            color: #fbbf24;
        }
        .code-block.schedule {
            color: #60a5fa;
        }
        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #374151;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            transition: background 0.2s;
        }
        .copy-btn:hover {
            background: #4b5563;
        }
        .copy-btn.copied {
            background: #10b981;
        }
        .info-box {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            font-size: 14px;
        }
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            font-size: 14px;
        }
        .success-box {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            font-size: 14px;
        }
        .label {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .steps {
            margin-top: 15px;
        }
        .step {
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .step:last-child {
            border-bottom: none;
        }
        .step strong {
            color: #667eea;
        }
        table {
            width: 100%;
            margin-top: 15px;
            border-collapse: collapse;
        }
        table td {
            padding: 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
        }
        table td:first-child {
            font-weight: 600;
            color: #6b7280;
            width: 30%;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⏰ cPanel Cron Commands</h1>
            <p>Copy and paste these exact commands into cPanel Cron Jobs</p>
        </div>
        
        <div class="content">
            
            <!-- PHP Detection -->
            <div class="section">
                <h2>🔍 Detected Configuration</h2>
                
                <table>
                    <tr>
                        <td>PHP CLI Binary:</td>
                        <td>
                            <?php if ($detectedPhp): ?>
                                <span style="color: #10b981; font-weight: bold;">✓ Found</span>
                                <code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px;"><?= htmlspecialchars($detectedPhp) ?></code>
                            <?php else: ?>
                                <span style="color: #ef4444; font-weight: bold;">✗ Not Found</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($phpVersion): ?>
                    <tr>
                        <td>PHP Version:</td>
                        <td><code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px;"><?= htmlspecialchars($phpVersion) ?></code></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>WebSocket Script:</td>
                        <td>
                            <?php if (file_exists($startWebsocketScript)): ?>
                                <span style="color: #10b981;">✓</span>
                            <?php else: ?>
                                <span style="color: #ef4444;">✗</span>
                            <?php endif; ?>
                            <code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-size: 11px;"><?= htmlspecialchars($startWebsocketScript) ?></code>
                        </td>
                    </tr>
                    <tr>
                        <td>Email Queue Script:</td>
                        <td>
                            <?php if (file_exists($cronUpdateScript)): ?>
                                <span style="color: #10b981;">✓</span>
                            <?php else: ?>
                                <span style="color: #ef4444;">✗</span>
                            <?php endif; ?>
                            <code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-size: 11px;"><?= htmlspecialchars($cronUpdateScript) ?></code>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Cron Job 1: WebSocket Server -->
            <div class="section">
                <h2>🚀 Cron Job #1: WebSocket Server (Keep Alive)</h2>
                
                <div class="info-box">
                    <strong>Purpose:</strong> Ensures the WebSocket server stays running. cPanel may kill long-running processes, so this cron restarts it if stopped.
                </div>
                
                <span class="label">SCHEDULE (Every 5 minutes)</span>
                <div class="code-block schedule">
                    <button class="copy-btn" onclick="copyToClipboard(this, '*/5 * * * *')">Copy</button>
                    */5 * * * *
                </div>
                
                <span class="label">COMMAND</span>
                <div class="code-block command">
                    <button class="copy-btn" onclick="copyToClipboard(this, '/bin/bash <?= $startWebsocketScript ?> >> <?= $websocketDir ?>/cron.log 2>&1')">Copy</button>
                    /bin/bash <?= $startWebsocketScript ?> >> <?= $websocketDir ?>/cron.log 2>&1
                </div>
                
                <div class="steps">
                    <div class="step"><strong>Step 1:</strong> Go to cPanel → Advanced → Cron Jobs</div>
                    <div class="step"><strong>Step 2:</strong> Under "Add New Cron Job", select "Common Settings" → Custom</div>
                    <div class="step"><strong>Step 3:</strong> Enter schedule: <code>*/5 * * * *</code></div>
                    <div class="step"><strong>Step 4:</strong> Paste the command above (click Copy button)</div>
                    <div class="step"><strong>Step 5:</strong> Click "Add New Cron Job"</div>
                </div>
            </div>
            
            <!-- Cron Job 2: Email Queue -->
            <div class="section">
                <h2>📧 Cron Job #2: Email Queue Processor</h2>
                
                <div class="info-box">
                    <strong>Purpose:</strong> Processes queued emails (account notifications, alerts, etc.) every 5 minutes.
                </div>
                
                <?php if ($detectedPhp): ?>
                    <span class="label">SCHEDULE (Every 5 minutes)</span>
                    <div class="code-block schedule">
                        <button class="copy-btn" onclick="copyToClipboard(this, '*/5 * * * *')">Copy</button>
                        */5 * * * *
                    </div>
                    
                    <span class="label">COMMAND</span>
                    <div class="code-block command">
                        <button class="copy-btn" onclick="copyToClipboard(this, '<?= $detectedPhp ?> <?= $cronUpdateScript ?> >> <?= $cronDir ?>/cron.log 2>&1')">Copy</button>
                        <?= $detectedPhp ?> <?= $cronUpdateScript ?> >> <?= $cronDir ?>/cron.log 2>&1
                    </div>
                <?php else: ?>
                    <div class="warning-box">
                        <strong>⚠️ Warning:</strong> PHP CLI binary not detected. Use one of these common paths:
                    </div>
                    
                    <span class="label">SCHEDULE (Every 5 minutes)</span>
                    <div class="code-block schedule">
                        <button class="copy-btn" onclick="copyToClipboard(this, '*/5 * * * *')">Copy</button>
                        */5 * * * *
                    </div>
                    
                    <span class="label">COMMON PHP PATHS (Try in order)</span>
                    
                    <?php foreach ($phpBinaries as $phpPath): ?>
                        <div class="code-block command" style="margin-bottom: 5px;">
                            <button class="copy-btn" onclick="copyToClipboard(this, '<?= $phpPath ?> <?= $cronUpdateScript ?> >> <?= $cronDir ?>/cron.log 2>&1')">Copy</button>
                            <?= $phpPath ?> <?= $cronUpdateScript ?> >> <?= $cronDir ?>/cron.log 2>&1
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="steps">
                    <div class="step"><strong>Step 1:</strong> Go to cPanel → Advanced → Cron Jobs</div>
                    <div class="step"><strong>Step 2:</strong> Under "Add New Cron Job", select "Common Settings" → Custom</div>
                    <div class="step"><strong>Step 3:</strong> Enter schedule: <code>*/5 * * * *</code></div>
                    <div class="step"><strong>Step 4:</strong> Paste the command above (click Copy button)</div>
                    <div class="step"><strong>Step 5:</strong> Click "Add New Cron Job"</div>
                </div>
            </div>
            
            <!-- Verification -->
            <div class="section">
                <h2>✅ Verify Cron Jobs Are Working</h2>
                
                <div class="success-box">
                    <strong>After setting up both cron jobs, wait 5 minutes and check:</strong>
                </div>
                
                <div class="steps">
                    <div class="step">
                        <strong>WebSocket Cron Log:</strong><br>
                        <code style="background: #f3f4f6; padding: 4px 8px; border-radius: 3px; font-size: 12px; display: inline-block; margin-top: 5px;">
                            <?= $websocketDir ?>/cron.log
                        </code><br>
                        <span style="color: #6b7280; font-size: 12px;">Should show: "✓ WebSocket server started successfully" or "already running"</span>
                    </div>
                    
                    <div class="step">
                        <strong>Email Queue Cron Log:</strong><br>
                        <code style="background: #f3f4f6; padding: 4px 8px; border-radius: 3px; font-size: 12px; display: inline-block; margin-top: 5px;">
                            <?= $cronDir ?>/cron.log
                        </code><br>
                        <span style="color: #6b7280; font-size: 12px;">Should show: "Email Queue Processor started" and "Processing complete"</span>
                    </div>
                    
                    <div class="step">
                        <strong>Check via Admin Dashboard:</strong><br>
                        <span style="color: #6b7280; font-size: 12px;">Go to Admin → Server Management to see WebSocket status</span>
                    </div>
                </div>
            </div>
            
            <!-- Troubleshooting -->
            <div class="section">
                <h2>🔧 Troubleshooting</h2>
                
                <div class="steps">
                    <div class="step">
                        <strong>Problem: Cron emails me script code</strong><br>
                        <span style="color: #6b7280; font-size: 12px;">→ You forgot <code>/bin/bash</code> for the WebSocket script. Use the EXACT command above.</span>
                    </div>
                    
                    <div class="step">
                        <strong>Problem: <code>$'\r': command not found</code></strong><br>
                        <span style="color: #6b7280; font-size: 12px;">→ Windows line endings issue. Visit: <a href="fix_line_endings.php" style="color: #667eea;">fix_line_endings.php</a></span>
                    </div>
                    
                    <div class="step">
                        <strong>Problem: "PHP not found"</strong><br>
                        <span style="color: #6b7280; font-size: 12px;">→ Try different PHP paths listed above, or check cPanel → Select PHP Version</span>
                    </div>
                    
                    <div class="step">
                        <strong>Problem: Cron runs but server doesn't start</strong><br>
                        <span style="color: #6b7280; font-size: 12px;">→ Check <code>cron.log</code> and <code>server.log</code> for error messages</span>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    
    <script>
        function copyToClipboard(button, text) {
            navigator.clipboard.writeText(text).then(function() {
                const originalText = button.textContent;
                button.textContent = 'Copied!';
                button.classList.add('copied');
                
                setTimeout(function() {
                    button.textContent = originalText;
                    button.classList.remove('copied');
                }, 2000);
            }).catch(function(err) {
                alert('Failed to copy: ' + err);
            });
        }
    </script>
</body>
</html>
