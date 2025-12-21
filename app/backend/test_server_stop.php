<?php
// Test script to stop the server

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/controllers/ServerController.php';

echo "Testing ServerController::stopServer()\n";
echo "==========================================\n\n";

try {
    ServerController::stopServer();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
