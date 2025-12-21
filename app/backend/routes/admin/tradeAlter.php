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
require_once __DIR__ . '/../../controllers/TradeAlterController.php';

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $user = AuthMiddleware::requirePermission('view_alters');
        $user_id = $user->user_id;
        if(isset($_GET['mode'])) {
            $mode = $_GET['mode'];
            if($mode === 'trade') {
                if(isset($_GET['ref'])) {
                    TradeAlterController::getAlterTrade($_GET['ref']);
                } else {
                    Response::error('ref parameter required', 400);
                }
            } else if($mode === 'pair') {
                if(isset($_GET['pair']) && isset($_GET['acc_type'])) {
                    TradeAlterController::getAlterPair($_GET['pair'], $_GET['acc_type']);
                } else {
                    Response::error('pair and acc_type parameters required', 400);
                }
            } else if($mode === 'account_pair') {
                if(isset($_GET['account'])) {
                    TradeAlterController::getAlterAccountPair($_GET['account'] );
                } else {
                    Response::error('pair & account parameter required', 400);
                }
            } else {
                Response::error('Invalid mode', 400);
            }
        } else {
            TradeAlterController::getAllAlters();
        }
        break;
        
    case 'POST':
        $user = AuthMiddleware::requirePermission('manage_alters');
        $user_id = $user->user_id;
        if(isset($_GET['mode'])) {
            $mode = $_GET['mode'];
            if($mode === 'trade') {
                TradeAlterController::createAlterTrade();
            } else if($mode === 'pair') {
                TradeAlterController::createAlterPair();
            } else if($mode === 'account_pair') {
                TradeAlterController::createAlterAccountPair();
            } else {
                Response::error('Invalid mode', 400);
            }
        } else {
            Response::error('Mode parameter required', 400);
        }
        break;

    case 'DELETE':
        $user = AuthMiddleware::requirePermission('manage_alters');
        $user_id = $user->user_id;
        if(isset($_GET['id']) && isset($_GET['mode'])) {
            $id = $_GET['id'];
            $mode = $_GET['mode'];
            TradeAlterController::deleteAlterTrade($id, $mode);
        } else {
            Response::error('Id and Mode is required', 400);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}