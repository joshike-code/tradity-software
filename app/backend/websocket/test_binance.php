<?php
/**
 * WebSocket Server Test - Binance Connection
 * 
 * Tests if your server can connect to Binance API
 * Access: https://yourdomain.com/app/backend/websocket/test_binance.php
 */

header('Content-Type: text/html; charset=UTF-8');

// Load composer autoloader first
$autoloadFile = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
}

$tests = [];

// Test 1: Binance REST API
$ch = curl_init('https://api.binance.com/api/v3/ping');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

$tests[] = [
    'name' => 'Binance REST API',
    'status' => $httpCode === 200 ? 'success' : 'error',
    'message' => $httpCode === 200 
        ? "✅ Connected successfully (HTTP $httpCode)" 
        : "❌ Failed to connect (HTTP $httpCode)" . ($error ? ": $error" : ""),
    'details' => "Endpoint: https://api.binance.com/api/v3/ping"
];

// Test 2: Binance WebSocket (check if reachable)
$wsUrl = 'stream.binance.com';
$wsPort = 9443;
$tests[] = [
    'name' => 'Binance WebSocket Port',
    'status' => 'info',
    'message' => "Testing connection to $wsUrl:$wsPort...",
    'details' => "WebSocket URL: wss://stream.binance.com:9443/ws"
];

// Test 3: Get server time
$ch = curl_init('https://api.binance.com/api/v3/time');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $serverTime = isset($data['serverTime']) ? date('Y-m-d H:i:s', $data['serverTime'] / 1000) : 'Unknown';
    $tests[] = [
        'name' => 'Binance Server Time',
        'status' => 'success',
        'message' => "✅ Server time: $serverTime UTC",
        'details' => "Your server can communicate with Binance"
    ];
} else {
    $tests[] = [
        'name' => 'Binance Server Time',
        'status' => 'error',
        'message' => "❌ Could not retrieve server time",
        'details' => "HTTP Code: $httpCode"
    ];
}

// Test 4: Check if WebSocket extension is available
$tests[] = [
    'name' => 'PHP WebSocket Support',
    'status' => class_exists('Ratchet\Server\IoServer') ? 'success' : 'error',
    'message' => class_exists('Ratchet\Server\IoServer') 
        ? "✅ Ratchet library is installed" 
        : "❌ Ratchet library not found (run: composer install)",
    'details' => "Required for WebSocket server functionality"
];

// Test 5: Check server resources
$tests[] = [
    'name' => 'Server Resources',
    'status' => 'info',
    'message' => sprintf(
        "Memory: %s / %s | CPU Load: %s",
        round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        ini_get('memory_limit'),
        function_exists('sys_getloadavg') ? implode(', ', sys_getloadavg()) : 'N/A'
    ),
    'details' => "Current resource usage"
];

// Test 6: Check if ports are available
$pidFile = __DIR__ . '/server.pid';
$serverRunning = file_exists($pidFile);

$tests[] = [
    'name' => 'WebSocket Server Status',
    'status' => $serverRunning ? 'success' : 'warning',
    'message' => $serverRunning 
        ? "✅ Server is running (PID file exists)" 
        : "⚠️ Server not running (no PID file)",
    'details' => $serverRunning 
        ? "PID file: $pidFile" 
        : "Start the server using setup.php"
];

// Test 7: Test sample Binance ticker
$ch = curl_init('https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $btcPrice = isset($data['price']) ? number_format($data['price'], 2) : 'Unknown';
    $tests[] = [
        'name' => 'Binance Market Data',
        'status' => 'success',
        'message' => "✅ BTC/USDT Price: $$btcPrice",
        'details' => "Live market data is accessible"
    ];
} else {
    $tests[] = [
        'name' => 'Binance Market Data',
        'status' => 'error',
        'message' => "❌ Could not fetch market data",
        'details' => "HTTP Code: $httpCode"
    ];
}

$passedTests = count(array_filter($tests, fn($t) => $t['status'] === 'success'));
$totalTests = count($tests);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Binance Connection Test</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
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
        .score {
            font-size: 48px;
            font-weight: bold;
            margin: 20px 0;
        }
        .content { padding: 30px; }
        .test-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #ccc;
        }
        .test-item.success { border-color: #10b981; }
        .test-item.error { border-color: #ef4444; }
        .test-item.warning { border-color: #f59e0b; }
        .test-item.info { border-color: #3b82f6; }
        .test-name {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 8px;
            color: #111;
        }
        .test-message {
            color: #444;
            margin-bottom: 5px;
        }
        .test-details {
            color: #666;
            font-size: 13px;
            font-style: italic;
        }
        .actions {
            margin-top: 30px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
        }
        .btn:hover { background: #5568d3; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔌 Binance Connection Test</h1>
            <div class="score"><?= $passedTests ?> / <?= $totalTests ?></div>
            <p>Tests Passed</p>
        </div>
        
        <div class="content">
            <?php foreach ($tests as $test): ?>
                <div class="test-item <?= $test['status'] ?>">
                    <div class="test-name"><?= htmlspecialchars($test['name']) ?></div>
                    <div class="test-message"><?= $test['message'] ?></div>
                    <div class="test-details"><?= htmlspecialchars($test['details']) ?></div>
                </div>
            <?php endforeach; ?>
            
            <div class="actions">
                <a href="view_logs.php" class="btn">📊 View Server Logs</a>
                <a href="setup.php" class="btn">🔧 Back to Setup</a>
                <a href="?refresh=1" class="btn">🔄 Re-run Tests</a>
            </div>
        </div>
    </div>
</body>
</html>
