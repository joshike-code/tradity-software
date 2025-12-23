<?php
/**
 * WebSocket Server Log Viewer
 * 
 * View recent log entries to diagnose issues
 * Access: https://yourdomain.com/app/backend/websocket/view_logs.php
 * 
 * Security: Add authentication or delete after debugging
 */

$logFile = __DIR__ . '/server.log';
$lines = $_GET['lines'] ?? 100; // Default: last 100 lines
$lines = min(max(10, intval($lines)), 1000); // Between 10-1000

if (!file_exists($logFile)) {
    die("Log file not found");
}

// Get last N lines efficiently
$file = new SplFileObject($logFile);
$file->seek(PHP_INT_MAX);
$totalLines = $file->key();
$startLine = max(0, $totalLines - $lines);

$file->seek($startLine);
$logContent = [];

while (!$file->eof()) {
    $line = $file->current();
    if (trim($line) !== '') {
        $logContent[] = $line;
    }
    $file->next();
}

$fileSize = filesize($logFile);
$fileSizeMB = round($fileSize / 1024 / 1024, 2);

// Check PID status
$pidFile = __DIR__ . '/server.pid';
$serverStatus = 'Unknown';
if (file_exists($pidFile)) {
    $pidData = json_decode(file_get_contents($pidFile), true);
    $pid = $pidData['pid'] ?? 'Unknown';
    $serverStatus = "Running (PID: $pid)";
} else {
    $serverStatus = "Not running";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebSocket Server Logs</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #f0f0f0;
            padding: 20px;
        }
        .header {
            background: #2d2d2d;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .header h1 {
            color: #4ade80;
            font-size: 20px;
        }
        .info {
            display: flex;
            gap: 20px;
            font-size: 14px;
        }
        .info span {
            background: #1a1a1a;
            padding: 5px 10px;
            border-radius: 4px;
        }
        .controls {
            background: #2d2d2d;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .controls a, .controls button {
            background: #4ade80;
            color: #1a1a1a;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: 'Courier New', monospace;
            font-weight: bold;
        }
        .controls a:hover, .controls button:hover {
            background: #22c55e;
        }
        .controls .danger {
            background: #ef4444;
            color: white;
        }
        .controls .danger:hover {
            background: #dc2626;
        }
        .log-container {
            background: #0a0a0a;
            border: 2px solid #2d2d2d;
            border-radius: 8px;
            padding: 20px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .log-line {
            padding: 4px 0;
            font-size: 13px;
            line-height: 1.6;
            border-bottom: 1px solid #1a1a1a;
        }
        .log-line:hover {
            background: #1a1a1a;
        }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .success { color: #4ade80; }
        .info { color: #3b82f6; }
        .timestamp { color: #6b7280; }
        
        .status-running { color: #4ade80; }
        .status-stopped { color: #ef4444; }
        
        .auto-refresh {
            background: #2d2d2d;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .auto-refresh label {
            margin-right: 10px;
        }
    </style>
    <script>
        let autoRefresh = false;
        let refreshInterval = null;
        
        function toggleAutoRefresh() {
            autoRefresh = !autoRefresh;
            const btn = document.getElementById('autoRefreshBtn');
            
            if (autoRefresh) {
                btn.textContent = '⏸️ Pause Auto-Refresh';
                btn.style.background = '#f59e0b';
                refreshInterval = setInterval(() => {
                    location.reload();
                }, 5000);
            } else {
                btn.textContent = '▶️ Start Auto-Refresh';
                btn.style.background = '#4ade80';
                clearInterval(refreshInterval);
            }
        }
        
        // Scroll to bottom on load
        window.addEventListener('load', () => {
            const container = document.querySelector('.log-container');
            container.scrollTop = container.scrollHeight;
        });
    </script>
</head>
<body>
    <div class="header">
        <h1>📊 WebSocket Server Logs</h1>
        <div class="info">
            <span>Status: <strong class="<?= strpos($serverStatus, 'Running') !== false ? 'status-running' : 'status-stopped' ?>"><?= htmlspecialchars($serverStatus) ?></strong></span>
            <span>Size: <strong><?= $fileSizeMB ?> MB</strong></span>
            <span>Lines: <strong><?= $totalLines ?></strong></span>
        </div>
    </div>
    
    <div class="auto-refresh">
        <button id="autoRefreshBtn" onclick="toggleAutoRefresh()">▶️ Start Auto-Refresh (5s)</button>
        <span style="color: #6b7280; margin-left: 10px;">Updates every 5 seconds when enabled</span>
    </div>
    
    <div class="controls">
        <a href="?lines=50">Last 50 lines</a>
        <a href="?lines=100">Last 100 lines</a>
        <a href="?lines=500">Last 500 lines</a>
        <a href="?lines=1000">Last 1000 lines</a>
        <a href="?lines=<?= $lines ?>">🔄 Refresh</a>
        <form method="POST" action="clear_logs.php" style="display: inline;" onsubmit="return confirm('Clear all logs? This cannot be undone!');">
            <button type="submit" class="danger">🗑️ Clear Logs</button>
        </form>
    </div>
    
    <div class="log-container">
        <?php if (empty($logContent)): ?>
            <div class="log-line info">No log entries found</div>
        <?php else: ?>
            <?php foreach ($logContent as $line): ?>
                <?php
                $class = '';
                if (stripos($line, 'error') !== false) $class = 'error';
                elseif (stripos($line, 'warning') !== false) $class = 'warning';
                elseif (stripos($line, 'success') !== false || stripos($line, 'started') !== false) $class = 'success';
                elseif (stripos($line, 'retrying') !== false) $class = 'warning';
                ?>
                <div class="log-line <?= $class ?>"><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
