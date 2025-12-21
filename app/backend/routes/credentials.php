<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../controllers/CredentialController.php';
require_once __DIR__ . '/../core/response.php';

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if(isset($_GET['action'])) {
            $action = $_GET['action'];
            if($action === 'verify') {
                CredentialController::verifyCredentialToken();
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
            if($action === 'request') {
                $user = AuthMiddleware::handle(['user']);
                $user_id = $user->user_id;
                CredentialController::requestCredentialChange($user_id);
            } elseif($action === 'update') {
                CredentialController::updateCredentials();
            } else {
                Response::error('Invalid action', 400);
            }
        } else {
            Response::error('Action parameter required', 400);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
