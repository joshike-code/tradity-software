<?php
use Core\SanitizationService;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../controllers/OnlineActivityController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

// Authenticate user and get user data
$user = AuthMiddleware::handle(['user']);
$user_id = $user->user_id;

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        // Update online status
        if (isset($_GET['action']) && $_GET['action'] === 'update') {
            OnlineActivityController::updateOnlineStatus($user_id);
        }
        // Send heartbeat
        elseif (isset($_GET['action']) && $_GET['action'] === 'heartbeat') {
            OnlineActivityController::sendHeartbeat($user_id);
        }
        // Mark offline
        elseif (isset($_GET['action']) && $_GET['action'] === 'offline') {
            OnlineActivityController::markOffline($user_id);
        }
        else {
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}