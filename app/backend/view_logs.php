<?php
// View WebSocket logs in real-time

$logFile = __DIR__ . '/logs/finnhub.log';

if (!file_exists($logFile)) {
    echo "Log file not found: {$logFile}\n";
    echo "The WebSocket server may not have been started yet.\n";
    exit(1);
}

echo "Viewing Finnhub WebSocket logs: {$logFile}\n";
echo "Press Ctrl+C to exit\n";
echo str_repeat("=", 80) . "\n\n";

// Show last 50 lines first
$lines = file($logFile);
$recentLines = array_slice($lines, -50);
echo implode("", $recentLines);

// Then tail the file for new entries
$lastSize = filesize($logFile);

while (true) {
    clearstatcache(false, $logFile);
    $currentSize = filesize($logFile);
    
    if ($currentSize > $lastSize) {
        // File has grown, read new content
        $handle = fopen($logFile, 'r');
        fseek($handle, $lastSize);
        echo fread($handle, $currentSize - $lastSize);
        fclose($handle);
        
        $lastSize = $currentSize;
    }
    
    usleep(500000); // Sleep for 0.5 seconds
}
