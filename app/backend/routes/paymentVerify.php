<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use Core\SanitizationService;

require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../controllers/PaymentController.php';
require_once __DIR__ . '/../core/SanitizationService.php';

$user = AuthMiddleware::handle(['user']);
$user_id = $user->user_id;
$raw_id = $_GET['id'] ?? '';
$id = strtolower(SanitizationService::sanitizeParam($raw_id));
if(!$id) {
    Response::error('ID is required', 400);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        $rawInput = json_decode(file_get_contents("php://input"), true) ?? [];
        if($id === 'flutterwave') {
            PaymentController::verifyFlutterwavePayment($user_id);
        } else if($id === 'paystack') {
            if (isset($rawInput['tx_ref'])) {
                PaymentController::confirmPaystackPayment($user_id, $rawInput);
            } else {
                PaymentController::updatePaystackPayment($user_id);
            }
        } else if ($id === 'upi') {
            PaymentController::confirmUpiPayment($user_id, $rawInput);
        } else if ($id === 'card') {
            PaymentController::confirmCardPayment($user_id, $rawInput);
        } else if ($id === 'airtm') {
            PaymentController::confirmAirtmPayment($user_id, $rawInput);
        } else if ($id === 'skrill' || $id === 'skrill1tap') {
            PaymentController::confirmSkrillPayment($user_id, $rawInput);
        } else if ($id === 'neteller') {
            PaymentController::confirmNetellerPayment($user_id, $rawInput);
        } else if ($id === 'jeton') {
            PaymentController::confirmJetonPayment($user_id, $rawInput);
        } else if ($id === 'pol') {
            PaymentController::confirmPolPayment($user_id, $rawInput);
        } else if ($id === 'icashone') {
            PaymentController::confirmIcashonePayment($user_id, $rawInput);
        } else if ($id === 'voucherry') {
            PaymentController::confirmVoucherryPayment($user_id, $rawInput);
        } else {
            Response::error('Method not allowed', 405);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}