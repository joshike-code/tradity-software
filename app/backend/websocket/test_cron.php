<?php
/**
 * Cron Test & Diagnostic Script
 * 
 * This script tests if your cron job will work properly
 * Run via browser: https://yourdomain.com/app/backend/websocket/test_cron.php
 */

header('Content-Type: text/plain');

echo "=== WebSocket Cron Job Diagnostic Tool ===\n\n";

$websocketDir = __DIR__;
$startScript = $websocketDir . '/start_websocket.sh';
$serverScript = $websocketDir . '/server.php';
$pidFile = $websocketDir . '/server.pid';
$logFile = $websocketDir . '/server.log';
$cronLog = $websocketDir . '/cron.log';

// Test 1: Check if scripts exist
echo "1. Checking files...\n";
$files = [
    'start_websocket.sh' => $startScript,
    'server.php' => $serverScript,
];

foreach ($files as $name => $path) {
    if (file_exists($path)) {
        $perms = substr(sprintf('%o', fileperms($path)), -4);
        $executable = is_executable($path) ? 'YES' : 'NO';
        echo "   ✓ {$name}: EXISTS (perms: {$perms}, executable: {$executable})\n";
    } else {
        echo "   ✗ {$name}: NOT FOUND at {$path}\n";
    }
}

// Test 2: Find PHP CLI binary
echo "\n2. Detecting PHP CLI binary...\n";
$phpPaths = [
    '/usr/local/bin/php',
    '/usr/bin/php',
    '/opt/cpanel/ea-php82/root/usr/bin/php',
    '/opt/cpanel/ea-php81/root/usr/bin/php',
    '/opt/cpanel/ea-php80/root/usr/bin/php',
];

// Try which command
$whichPhp = trim(shell_exec('which php 2>/dev/null'));
if ($whichPhp) {
    $phpPaths[] = $whichPhp;
}

$foundPhp = null;
foreach (array_unique($phpPaths) as $path) {
    if (@is_executable($path)) {
        $version = trim(shell_exec("{$path} -v 2>/dev/null | head -n 1"));
        echo "   ✓ Found: {$path}\n";
        echo "     Version: {$version}\n";
        if (!$foundPhp) {
            $foundPhp = $path;
        }
    }
}

if (!$foundPhp) {
    echo "   ✗ No PHP CLI binary found!\n";
    echo "     This will prevent the server from starting.\n";
} else {
    echo "   → Will use: {$foundPhp}\n";
}

// Test 3: Check shell execution
echo "\n3. Testing shell command execution...\n";
if (function_exists('shell_exec')) {
    $testCmd = shell_exec('echo "Shell execution works"');
    if ($testCmd) {
        echo "   ✓ shell_exec() is enabled\n";
        echo "   Output: " . trim($testCmd) . "\n";
    } else {
        echo "   ⚠ shell_exec() returned empty (might be disabled)\n";
    }
} else {
    echo "   ✗ shell_exec() is disabled\n";
    echo "   This may cause issues with the startup script.\n";
}

// Test 4: Try running the start script
echo "\n4. Testing start script execution...\n";
if (file_exists($startScript) && $foundPhp) {
    echo "   Running: bash {$startScript}\n";
    $output = [];
    $returnCode = null;
    exec("bash {$startScript} 2>&1", $output, $returnCode);
    
    echo "   Return code: {$returnCode}\n";
    echo "   Output:\n";
    foreach ($output as $line) {
        echo "     {$line}\n";
    }
    
    // Check if server started
    sleep(2);
    if (file_exists($pidFile)) {
        $pidData = json_decode(file_get_contents($pidFile), true);
        $pid = $pidData['pid'] ?? null;
        echo "\n   ✓ Server started! PID: {$pid}\n";
    } else {
        echo "\n   ⚠ Server did not create PID file. Check server.log for errors.\n";
    }
} else {
    echo "   ✗ Cannot test - script or PHP not found\n";
}

// Test 5: Check logs
echo "\n5. Recent log entries...\n";

if (file_exists($logFile)) {
    $logSize = filesize($logFile);
    echo "   server.log size: " . number_format($logSize) . " bytes\n";
    
    if ($logSize > 0) {
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recentLines = array_slice($lines, -10);
        echo "   Last 10 lines:\n";
        foreach ($recentLines as $line) {
            echo "     {$line}\n";
        }
    } else {
        echo "   (empty)\n";
    }
} else {
    echo "   server.log does not exist yet\n";
}

if (file_exists($cronLog)) {
    echo "\n   cron.log exists\n";
    $lines = @file($cronLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recentLines = array_slice($lines, -5);
    echo "   Last 5 lines:\n";
    foreach ($recentLines as $line) {
        echo "     {$line}\n";
    }
}

// Test 6: Current status
echo "\n6. Current server status...\n";
if (file_exists($pidFile)) {
    $pidData = json_decode(file_get_contents($pidFile), true);
    $pid = $pidData['pid'] ?? null;
    $startTime = $pidData['started_at'] ?? null;
    
    echo "   PID: {$pid}\n";
    if ($startTime) {
        $uptime = time() - $startTime;
        echo "   Started: " . date('Y-m-d H:i:s', $startTime) . "\n";
        echo "   Uptime: {$uptime} seconds\n";
    }
    
    // Check if process is running
    $psOutput = shell_exec("ps -p {$pid} 2>&1");
    if (strpos($psOutput, (string)$pid) !== false) {
        echo "   ✓ Process is running\n";
    } else {
        echo "   ✗ Process not found (server may have crashed)\n";
    }
} else {
    echo "   ✗ Server is not running (no PID file)\n";
}

// Recommendations
echo "\n=== RECOMMENDATIONS ===\n\n";

if (!$foundPhp) {
    echo "❌ CRITICAL: PHP CLI binary not found\n";
    echo "   Action: Contact your hosting provider to get the PHP CLI path\n\n";
}

if (!is_executable($startScript)) {
    echo "⚠ WARNING: start_websocket.sh is not executable\n";
    echo "   Action: Run 'chmod +x {$startScript}'\n";
    echo "   Or use the setup.php tool to make it executable\n\n";
}

echo "✓ Cron command to use:\n";
echo "   /bin/bash {$startScript} >> {$cronLog} 2>&1\n\n";

echo "✓ Cron schedule (every 5 minutes):\n";
echo "   */5 * * * *\n\n";

echo "=== END OF DIAGNOSTIC ===\n";
