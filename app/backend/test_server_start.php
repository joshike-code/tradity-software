<?php
// Test script to start the WebSocket server via ServerController

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/controllers/ServerController.php';

echo "Testing ServerController::startServer()\n";
echo "==========================================\n\n";

try {
    ServerController::startServer();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
