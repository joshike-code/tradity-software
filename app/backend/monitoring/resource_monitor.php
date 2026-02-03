<?php
/**
 * Simple Resource Monitor for VPS
 * Access: https://yourdomain.com/app/backend/monitoring/resource_monitor.php?key=YOUR_SECRET_KEY
 * 
 * Usage: Check server health before demos or during testing
 */

// Security: Change this to a random string
define('MONITOR_KEY', 'change_this_secret_key_123');

if (!isset($_GET['key']) || $_GET['key'] !== MONITOR_KEY) {
    http_response_code(403);
    die('Access denied');
}

// Get system stats
function getSystemStats() {
    $stats = [];
    
    // Memory usage
    if (function_exists('shell_exec')) {
        // Linux systems
        $free = shell_exec('free -m');
        preg_match_all('/\d+/', $free, $matches);
        if (isset($matches[0][0])) {
            $stats['total_ram_mb'] = intval($matches[0][0] ?? 0);
            $stats['used_ram_mb'] = intval($matches[0][1] ?? 0);
            $stats['free_ram_mb'] = intval($matches[0][2] ?? 0);
            $stats['ram_usage_percent'] = round(($stats['used_ram_mb'] / $stats['total_ram_mb']) * 100, 2);
        }
        
        // CPU load
        $load = sys_getloadavg();
        $stats['cpu_load_1min'] = $load[0] ?? 0;
        $stats['cpu_load_5min'] = $load[1] ?? 0;
        $stats['cpu_load_15min'] = $load[2] ?? 0;
        
        // Disk usage
        $stats['disk_total_gb'] = round(disk_total_space('/') / 1024 / 1024 / 1024, 2);
        $stats['disk_free_gb'] = round(disk_free_space('/') / 1024 / 1024 / 1024, 2);
        $stats['disk_used_gb'] = $stats['disk_total_gb'] - $stats['disk_free_gb'];
        $stats['disk_usage_percent'] = round(($stats['disk_used_gb'] / $stats['disk_total_gb']) * 100, 2);
    }
    
    // PHP memory
    $stats['php_memory_limit'] = ini_get('memory_limit');
    $stats['php_memory_used_mb'] = round(memory_get_usage(true) / 1024 / 1024, 2);
    
    return $stats;
}

// Get MySQL stats
function getMySQLStats() {
    try {
        require_once __DIR__ . '/../config/db.php';
        global $pdo;
        
        $stats = [];
        
        // Connection count
        $stmt = $pdo->query("SHOW STATUS LIKE 'Threads_connected'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['mysql_connections'] = intval($result['Value'] ?? 0);
        
        // Max connections
        $stmt = $pdo->query("SHOW VARIABLES LIKE 'max_connections'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['mysql_max_connections'] = intval($result['Value'] ?? 0);
        
        // Uptime
        $stmt = $pdo->query("SHOW STATUS LIKE 'Uptime'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $uptime = intval($result['Value'] ?? 0);
        $stats['mysql_uptime_hours'] = round($uptime / 3600, 2);
        
        return $stats;
    } catch (Exception $e) {
        return ['error' => 'MySQL connection failed: ' . $e->getMessage()];
    }
}

// Get WebSocket stats
function getWebSocketStats() {
    $wsPath = __DIR__ . '/../websocket';
    $stats = [];
    
    // Check if running
    $pidFile = $wsPath . '/server.pid';
    if (file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        $stats['websocket_running'] = true;
        $stats['websocket_pid'] = $pid;
        
        // Check if process actually exists (Linux)
        if (function_exists('posix_getpgid')) {
            $stats['websocket_process_exists'] = posix_getpgid($pid) !== false;
        }
    } else {
        $stats['websocket_running'] = false;
    }
    
    // Log file size
    $logFile = $wsPath . '/server.log';
    if (file_exists($logFile)) {
        $stats['websocket_log_size_mb'] = round(filesize($logFile) / 1024 / 1024, 2);
        
        // Last 5 lines
        $lines = file($logFile);
        $stats['websocket_last_log_lines'] = array_slice($lines, -5);
    }
    
    return $stats;
}

// Get health status
function getHealthStatus($systemStats) {
    $health = [
        'status' => 'healthy',
        'warnings' => [],
        'critical' => []
    ];
    
    // Check RAM
    if (isset($systemStats['ram_usage_percent'])) {
        if ($systemStats['ram_usage_percent'] > 90) {
            $health['critical'][] = 'RAM usage critical: ' . $systemStats['ram_usage_percent'] . '%';
            $health['status'] = 'critical';
        } elseif ($systemStats['ram_usage_percent'] > 75) {
            $health['warnings'][] = 'RAM usage high: ' . $systemStats['ram_usage_percent'] . '%';
            if ($health['status'] === 'healthy') $health['status'] = 'warning';
        }
    }
    
    // Check CPU
    if (isset($systemStats['cpu_load_1min'])) {
        if ($systemStats['cpu_load_1min'] > 2.0) {
            $health['warnings'][] = 'CPU load high: ' . $systemStats['cpu_load_1min'];
            if ($health['status'] === 'healthy') $health['status'] = 'warning';
        }
    }
    
    // Check Disk
    if (isset($systemStats['disk_usage_percent'])) {
        if ($systemStats['disk_usage_percent'] > 90) {
            $health['critical'][] = 'Disk usage critical: ' . $systemStats['disk_usage_percent'] . '%';
            $health['status'] = 'critical';
        } elseif ($systemStats['disk_usage_percent'] > 80) {
            $health['warnings'][] = 'Disk usage high: ' . $systemStats['disk_usage_percent'] . '%';
            if ($health['status'] === 'healthy') $health['status'] = 'warning';
        }
    }
    
    return $health;
}

// Collect all stats
$data = [
    'timestamp' => date('Y-m-d H:i:s'),
    'server' => $_SERVER['SERVER_NAME'] ?? 'unknown',
    'system' => getSystemStats(),
    'mysql' => getMySQLStats(),
    'websocket' => getWebSocketStats(),
];

$data['health'] = getHealthStatus($data['system']);

// Output as JSON or HTML
$format = $_GET['format'] ?? 'html';

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// HTML Output
?>
<!DOCTYPE html>
<html>
<head>
    <title>Server Monitor - Tradity</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header h1 {
            color: #667eea;
            margin-bottom: 5px;
        }
        .header p {
            color: #666;
            font-size: 14px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
            margin-left: 10px;
        }
        .status-healthy { background: #10b981; color: white; }
        .status-warning { background: #f59e0b; color: white; }
        .status-critical { background: #ef4444; color: white; }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .card h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .stat-row:last-child {
            border-bottom: none;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        .stat-value {
            color: #333;
            font-weight: bold;
            font-size: 14px;
        }
        .progress-bar {
            width: 100%;
            height: 20px;
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 5px;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        .progress-fill.warning { background: linear-gradient(90deg, #f59e0b, #ef4444); }
        .progress-fill.critical { background: #ef4444; }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .alert-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        .alert-critical {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        .refresh-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
        }
        .refresh-btn:hover {
            background: #5568d3;
        }
        .log-line {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            padding: 5px;
            background: #f9fafb;
            margin: 3px 0;
            border-radius: 3px;
            overflow-x: auto;
        }
    </style>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => location.reload(), 30000);
    </script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                Tradity Server Monitor
                <span class="status-badge status-<?php echo $data['health']['status']; ?>">
                    <?php echo strtoupper($data['health']['status']); ?>
                </span>
            </h1>
            <p>Last updated: <?php echo $data['timestamp']; ?> | Auto-refresh: 30s</p>
            <button class="refresh-btn" onclick="location.reload()">🔄 Refresh Now</button>
        </div>
        
        <?php if (!empty($data['health']['critical'])): ?>
            <?php foreach ($data['health']['critical'] as $msg): ?>
                <div class="alert alert-critical">
                    <strong>⚠️ CRITICAL:</strong> <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (!empty($data['health']['warnings'])): ?>
            <?php foreach ($data['health']['warnings'] as $msg): ?>
                <div class="alert alert-warning">
                    <strong>⚠️ WARNING:</strong> <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="grid">
            <!-- System Resources -->
            <div class="card">
                <h2>💻 System Resources</h2>
                <?php if (isset($data['system']['total_ram_mb'])): ?>
                    <div class="stat-row">
                        <span class="stat-label">RAM Usage</span>
                        <span class="stat-value">
                            <?php echo $data['system']['used_ram_mb']; ?> / 
                            <?php echo $data['system']['total_ram_mb']; ?> MB
                        </span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill <?php 
                            if ($data['system']['ram_usage_percent'] > 90) echo 'critical';
                            elseif ($data['system']['ram_usage_percent'] > 75) echo 'warning';
                        ?>" style="width: <?php echo $data['system']['ram_usage_percent']; ?>%">
                            <?php echo $data['system']['ram_usage_percent']; ?>%
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($data['system']['cpu_load_1min'])): ?>
                    <div class="stat-row" style="margin-top: 15px;">
                        <span class="stat-label">CPU Load (1/5/15 min)</span>
                        <span class="stat-value">
                            <?php echo $data['system']['cpu_load_1min']; ?> / 
                            <?php echo $data['system']['cpu_load_5min']; ?> / 
                            <?php echo $data['system']['cpu_load_15min']; ?>
                        </span>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($data['system']['disk_total_gb'])): ?>
                    <div class="stat-row" style="margin-top: 15px;">
                        <span class="stat-label">Disk Usage</span>
                        <span class="stat-value">
                            <?php echo $data['system']['disk_used_gb']; ?> / 
                            <?php echo $data['system']['disk_total_gb']; ?> GB
                        </span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill <?php 
                            if ($data['system']['disk_usage_percent'] > 90) echo 'critical';
                            elseif ($data['system']['disk_usage_percent'] > 80) echo 'warning';
                        ?>" style="width: <?php echo $data['system']['disk_usage_percent']; ?>%">
                            <?php echo $data['system']['disk_usage_percent']; ?>%
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- MySQL -->
            <div class="card">
                <h2>🗄️ MySQL Database</h2>
                <?php if (isset($data['mysql']['error'])): ?>
                    <div class="stat-row">
                        <span class="stat-value" style="color: #ef4444;">
                            <?php echo htmlspecialchars($data['mysql']['error']); ?>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="stat-row">
                        <span class="stat-label">Connections</span>
                        <span class="stat-value">
                            <?php echo $data['mysql']['mysql_connections']; ?> / 
                            <?php echo $data['mysql']['mysql_max_connections']; ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Uptime</span>
                        <span class="stat-value">
                            <?php echo $data['mysql']['mysql_uptime_hours']; ?> hours
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- WebSocket -->
            <div class="card">
                <h2>🔌 WebSocket Server</h2>
                <div class="stat-row">
                    <span class="stat-label">Status</span>
                    <span class="stat-value" style="color: <?php echo $data['websocket']['websocket_running'] ? '#10b981' : '#ef4444'; ?>">
                        <?php echo $data['websocket']['websocket_running'] ? '✅ Running' : '❌ Stopped'; ?>
                    </span>
                </div>
                <?php if (isset($data['websocket']['websocket_pid'])): ?>
                    <div class="stat-row">
                        <span class="stat-label">Process ID</span>
                        <span class="stat-value"><?php echo $data['websocket']['websocket_pid']; ?></span>
                    </div>
                <?php endif; ?>
                <?php if (isset($data['websocket']['websocket_log_size_mb'])): ?>
                    <div class="stat-row">
                        <span class="stat-label">Log Size</span>
                        <span class="stat-value"><?php echo $data['websocket']['websocket_log_size_mb']; ?> MB</span>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($data['websocket']['websocket_last_log_lines'])): ?>
                    <h3 style="margin-top: 15px; font-size: 14px; color: #666;">Recent Logs:</h3>
                    <?php foreach (array_slice($data['websocket']['websocket_last_log_lines'], -3) as $line): ?>
                        <div class="log-line"><?php echo htmlspecialchars($line); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
