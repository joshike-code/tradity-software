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
require_once __DIR__ . '/../../controllers/TradeAccountController.php';

$raw_id = $_GET['id'] ?? '';
$id = SanitizationService::sanitizeParam($raw_id);

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET':
        $user = AuthMiddleware::requirePermission('view_accounts');
        $user_id = $user->user_id;

        if(isset($_GET['search'])) {
            $search = SanitizationService::sanitizeParam($_GET['search']);
            // TradeAccountController::searchAllAccounts($search);
        }
        if($id === 'all') {
            if(isset($_GET['filter'])) {
                $filter = $_GET['filter'];
                if($filter === 'real' || $filter === 'demo') {
                    TradeAccountController::getAllAccounts($filter);
                } else {
                    Response::error('Invalid filter', 400);
                }
            } else {
                Response::error('Filter parameter required', 400);
            }
        } else  {
            TradeAccountController::getAccountByUniqueId($id);
        };
        break;

    case 'POST':
        $user = AuthMiddleware::requirePermission('manage_users');
        $user_id = $user->user_id;
        TradeAccountController::createRealAccount();
        break;

    case 'PUT':
        $user = AuthMiddleware::requirePermission('manage_accounts');
        $user_id = $user->user_id;
        TradeAccountController::updateAccountStatus($id);
        break;

    case 'DELETE':
        $user = AuthMiddleware::requirePermission('manage_accounts');
        $user_id = $user->user_id;

        TradeAccountController::deleteAccount($id);
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}