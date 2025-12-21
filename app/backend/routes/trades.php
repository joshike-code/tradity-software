<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../controllers/TradeController.php';

$user = AuthMiddleware::handle(['user']);
$user_id = $user->user_id;

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if(isset($_GET['filter'])) {
            $filter = $_GET['filter'];
            if($filter === 'open') {
                TradeController::getOpenTrades($user_id);
            } elseif($filter === 'closed') {
                TradeController::getClosedTrades($user_id);
            } else {
                TradeController::getUserTrades($user_id);
            }
        } else {
            TradeController::getUserTrades($user_id);
        }
        break;

    case 'POST':
        if(isset($_GET['action'])) {
            $action = $_GET['action'];
            if($action === 'open') {
                TradeController::openTrade($user_id);
            } elseif($action === 'close') {
                TradeController::closeTrade($user_id);
            } elseif($action === 'edit') {
                TradeController::editTrade($user_id);
            } elseif($action === 'date_filter' && isset($_GET['type']) && isset($_GET['fetchAll'])) {
                // Filter trades by date range
                $type = $_GET['type'];
                $fetchAll = $_GET['fetchAll'];
                if($type === 'open') {
                    if($fetchAll === 'true') {
                        TradeController::getOpenTrades($user_id);
                    }
                    TradeController::filterOpenTradesByDate($user_id);
                } elseif($type === 'close') {
                    if($fetchAll === 'true') {
                        TradeController::getClosedTrades($user_id);
                    }
                    TradeController::filterClosedTradesByDate($user_id);
                } else {
                    Response::error('Invalid filter type', 400);
                }
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
