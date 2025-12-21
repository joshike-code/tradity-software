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
require_once __DIR__ . '/../../controllers/TradeController.php';

$raw_id = $_GET['id'] ?? '';
$id = SanitizationService::sanitizeParam($raw_id);
if(!$id) {
    Response::error('ID is required', 400);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $user = AuthMiddleware::requirePermission('view_trades');
        $user_id = $user->user_id;

        if(isset($_GET['search']) && isset($_GET['trade_acc'])) {
            $search = SanitizationService::sanitizeParam($_GET['search']);
            $trade_acc = SanitizationService::sanitizeParam($_GET['trade_acc']);
            TradeController::searchAllTrades($search, $trade_acc);
        }
        if($id === 'all') {
            if(isset($_GET['trade_acc']) && isset($_GET['pair']) && isset($_GET['status']) && isset($_GET['type']) && isset($_GET['account'])) {
                $trade_acc = $_GET['trade_acc'] !== 'null' ? $_GET['trade_acc'] : null;
                $pair = $_GET['pair'] !== 'null' ? $_GET['pair'] : null;
                $status = $_GET['status'] !== 'null' ? $_GET['status'] : null;
                $type = $_GET['type'] !== 'null' ? $_GET['type'] : null;
                $account = $_GET['account'] !== 'null' ? $_GET['account'] : null;
                TradeController::getAllTrades($trade_acc, $pair, $status, $type, $account);  
            }
        } else  {
            TradeController::getTradeById($id);
        };
        break;

    case 'POST':
        $user = AuthMiddleware::requirePermission('manage_trades');
        $user_id = $user->user_id;
        if(isset($_GET['action'])) {
            $action = $_GET['action'];
            if($action === 'close') {
                TradeController::closeTradeFromAdmin('expire');
            } else {
                Response::error('Invalid action', 400);
            }
        } else {
            Response::error('Action parameter required', 400);
        }
        break;

    case 'DELETE':
        $user = AuthMiddleware::requirePermission('manage_trades');
        $user_id = $user->user_id;

        TradeController::deleteTrade($id);
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}