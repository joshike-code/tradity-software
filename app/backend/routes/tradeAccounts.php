<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../controllers/TradeAccountController.php';

$user = AuthMiddleware::handle(['user']);
$user_id = $user->user_id;

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        TradeAccountController::getUserAccounts($user_id);
        break;

    case 'POST':
        if(isset($_GET['action'])) {
            $action = $_GET['action'];
            if($action === 'switch') {
                TradeAccountController::switchCurrentAccount($user_id);
            } elseif($action === 'reset') {
                TradeAccountController::resetAccountBalance($user_id);
            } elseif($action === 'settings') {
                TradeAccountController::updateAccountLeverage($user_id);
            } else {
                Response::error('Invalid action', 400);
            }
        } else {
            Response::error('Action parameter required', 400);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}
