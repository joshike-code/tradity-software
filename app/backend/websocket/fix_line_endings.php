<?php
/**
 * Fix Line Endings Script
 * 
 * Converts Windows line endings (CRLF) to Unix line endings (LF)
 * This fixes the "$'\r': command not found" error in bash scripts
 * 
 * Run via browser: https://yourdomain.com/app/backend/websocket/fix_line_endings.php
 */

header('Content-Type: text/plain');

echo "=== Line Endings Fix Tool ===\n\n";

$websocketDir = __DIR__;
$filesToFix = [
    'start_websocket.sh',
    'stop_websocket.sh',
];

$fixed = 0;
$errors = 0;

foreach ($filesToFix as $filename) {
    $filepath = $websocketDir . '/' . $filename;
    
    echo "Processing: {$filename}\n";
    
    if (!file_exists($filepath)) {
        echo "  ✗ File not found\n\n";
        $errors++;
        continue;
    }
    
    // Read file
    $content = file_get_contents($filepath);
    
    if ($content === false) {
        echo "  ✗ Could not read file\n\n";
        $errors++;
        continue;
    }
    
    // Check if file has Windows line endings
    $hasCRLF = strpos($content, "\r\n") !== false;
    $hasCR = strpos($content, "\r") !== false;
    
    if ($hasCRLF) {
        echo "  → Found Windows line endings (CRLF)\n";
        // Convert CRLF to LF
        $content = str_replace("\r\n", "\n", $content);
        echo "  → Converted CRLF to LF\n";
    } elseif ($hasCR) {
        echo "  → Found old Mac line endings (CR)\n";
        // Convert CR to LF
        $content = str_replace("\r", "\n", $content);
        echo "  → Converted CR to LF\n";
    } else {
        echo "  ✓ Already using Unix line endings (LF)\n\n";
        continue;
    }
    
    // Write back to file
    $result = file_put_contents($filepath, $content);
    
    if ($result !== false) {
        echo "  ✓ File fixed successfully\n";
        
        // Make executable
        @chmod($filepath, 0755);
        echo "  ✓ Made executable (chmod 755)\n\n";
        
        $fixed++;
    } else {
        echo "  ✗ Could not write to file\n\n";
        $errors++;
    }
}

echo "=== SUMMARY ===\n";
echo "Fixed: {$fixed} file(s)\n";
echo "Errors: {$errors} file(s)\n\n";

if ($fixed > 0) {
    echo "✓ SUCCESS! Line endings have been fixed.\n";
    echo "  You can now run the start_websocket.sh script.\n";
    echo "  Try: bash start_websocket.sh\n\n";
}

if ($errors > 0) {
    echo "⚠ Some files could not be fixed.\n";
    echo "  Check file permissions and try again.\n\n";
}

echo "=== Alternative Fix (via SSH) ===\n";
echo "If you have SSH access, you can also fix this with:\n";
echo "  dos2unix {$websocketDir}/start_websocket.sh\n";
echo "  dos2unix {$websocketDir}/stop_websocket.sh\n\n";
echo "Or using sed:\n";
echo "  sed -i 's/\\r$//' {$websocketDir}/start_websocket.sh\n";
echo "  sed -i 's/\\r$//' {$websocketDir}/stop_websocket.sh\n\n";

echo "=== END ===\n";
