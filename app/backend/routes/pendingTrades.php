<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../controllers/PendingTradeController.php';

$user = AuthMiddleware::handle(['user']);
$user_id = $user->user_id;

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        PendingTradeController::getPendingTrades($user_id);
        break;

    case 'POST':
        PendingTradeController::submitPendingTrade($user_id);
        break;

    case 'PUT':
        if(isset($_GET['id'])) {
            $id = $_GET['id'];
            PendingTradeController::cancelPendingTrade($user_id, $id);
        } else {
            Response::error('id parameter required', 400);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}
