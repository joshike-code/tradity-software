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
require_once __DIR__ . '/../../controllers/BotTradeController.php';

$raw_id = $_GET['id'] ?? '';
$id = SanitizationService::sanitizeParam($raw_id);
if(!$id) {
    Response::error('ID is required', 400);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $user = AuthMiddleware::requirePermission('view_bot_trades');
        $user_id = $user->user_id;

        if(isset($_GET['search']) && isset($_GET['trade_acc'])) {
            $search = SanitizationService::sanitizeParam($_GET['search']);
            $trade_acc = SanitizationService::sanitizeParam($_GET['trade_acc']);
            BotTradeController::searchAllTrades($search, $trade_acc);
        }
        if($id === 'all') {
            if(isset($_GET['trade_acc'])) {
                $trade_acc = $_GET['trade_acc'];
                BotTradeController::getAllTrades($trade_acc);
            }
        } else  {
            BotTradeController::getTradeById($id);
        };
        break;

    case 'DELETE':
        $user = AuthMiddleware::requirePermission('manage_bot_trades');
        $user_id = $user->user_id;

        BotTradeController::deleteTrade($id);
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}