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
require_once __DIR__ . '/../../controllers/UserController.php';
require_once __DIR__ . '/../../controllers/MailerController.php';

$raw_id = $_GET['id'] ?? '';
$id = SanitizationService::sanitizeParam($raw_id);
if(!$id) {
    Response::error('ID is required', 400);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $user = AuthMiddleware::requirePermission('view_users');
        $user_id = $user->user_id;

        if(isset($_GET['search'])) {
            $search = SanitizationService::sanitizeParam($_GET['search']);
            UserController::searchUsersByEmail($search);
        }
        if($id === 'all') {
            UserController::getUsers();
        } else  {
            UserController::getUserToAdmin($id);
        };
        break;

    case 'PUT':
        $user = AuthMiddleware::requirePermission('manage_users');
        $user_id = $user->user_id;
        if(isset($_GET['action'])) {
            $action = $_GET['action'];
            if($action === 'profile') {
                UserController::updateProfile($id);
            } elseif($action === 'status') {
                UserController::updateUserStatus($id);
            } elseif($action === 'kyc') {
                UserController::updateKycConfig($id);
            } elseif($action === 'kyc-status') {
                UserController::updateKycStatus($id);
            } else {
                Response::error('Invalid action', 400);
            }
        } else {
            Response::error('Action parameter required', 400);
        }
        break;

    case 'POST':
        if(isset($_GET['action'])) {
            $action = $_GET['action'];
            if($action === 'mail') {
                $user = AuthMiddleware::requirePermission('mail_users');
                $user_id = $user->user_id;
                MailerController::mailUser($id);
            } else {
                Response::error('Invalid action', 400);
            }
        } else {
            Response::error('Action parameter required', 400);
        }
        break;

    case 'DELETE':
        $user = AuthMiddleware::requirePermission('manage_users');
        $user_id = $user->user_id;

        UserController::deleteUser($id);
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}