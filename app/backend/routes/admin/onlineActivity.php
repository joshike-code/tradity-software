<?php
use Core\SanitizationService;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../core/SanitizationService.php';
require_once __DIR__ . '/../../controllers/OnlineActivityController.php';

$raw_id = $_GET['id'] ?? '';
$id = SanitizationService::sanitizeParam($raw_id);
if(!$id) {
    Response::error('ID is required', 400);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // Get all online accounts
        if (isset($_GET['action'])) {
            $action = $_GET['action'];
            if($action === 'user') {
                $user = AuthMiddleware::requirePermission('manage_users');
                $user_id = $user->user_id;
                OnlineActivityController::getUserOnlineStatus($id);
            } elseif($action === 'account') {
                $user = AuthMiddleware::requirePermission('manage_accounts');
                $user_id = $user->user_id;
                OnlineActivityController::getAccountOnlineStatus($id);
            } else {
                Response::error('Invalid action', 400);
            }
        } else {
            Response::error('Action required', 400);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}