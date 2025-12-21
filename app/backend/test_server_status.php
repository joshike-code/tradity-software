<?php
// Test script to get server status

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/controllers/ServerController.php';

echo "Testing ServerController::getServerStatus()\n";
echo "============================================\n\n";

try {
    ServerController::getServerStatus();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
