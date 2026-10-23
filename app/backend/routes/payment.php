<?php
use Core\SanitizationService;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../controllers/PaymentController.php';

$user = AuthMiddleware::handle(['user']);
$user_id = $user->user_id;

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if(isset($_GET['search'])) {
            $search = SanitizationService::sanitizeParam($_GET['search']);
            PaymentController::searchUserPayments($user_id, $search);
        }
        PaymentController::getPayments($user_id);
        break;

    case 'POST':
        $input = json_decode(file_get_contents("php://input"), true) ?? [];
        $method = strtolower($input['method'] ?? '');
        $action = strtolower($input['action'] ?? '');

        // Check if action is confirmation
        if ($action === 'confirm') {
            switch ($method) {
                case 'upi':
                    PaymentController::confirmUpiPayment($user_id, $input);
                    break;
                case 'card':
                    PaymentController::confirmCardPayment($user_id, $input);
                    break;
                case 'airtm':
                    PaymentController::confirmAirtmPayment($user_id, $input);
                    break;
                case 'skrill':
                case 'skrill1tap':
                    PaymentController::confirmSkrillPayment($user_id, $input);
                    break;
                case 'neteller':
                    PaymentController::confirmNetellerPayment($user_id, $input);
                    break;
                case 'jeton':
                    PaymentController::confirmJetonPayment($user_id, $input);
                    break;
                case 'pol':
                    PaymentController::confirmPolPayment($user_id, $input);
                    break;
                case 'icashone':
                    PaymentController::confirmIcashonePayment($user_id, $input);
                    break;
                case 'voucherry':
                    PaymentController::confirmVoucherryPayment($user_id, $input);
                    break;
                case 'paystack':
                    PaymentController::confirmPaystackPayment($user_id, $input);
                    break;
                default:
                    Response::error('Unsupported payment method for confirmation', 400);
                    break;
            }
            break;
        }

        // Initiation flow
        switch ($method) {
            case 'crypto':
                PaymentController::handleCryptoPayment($user_id, $input);
                break;
            case 'flutterwave':
                PaymentController::initiateFlutterwavePayment($user_id, $input);
                break;
            case 'paystack':
                PaymentController::initiatePaystackPayment($user_id, $input);
                break;
            case 'opay':
                PaymentController::initiateOpayPayment($user_id, $input);
                break;
            case 'safehaven':
                PaymentController::initiateSafehavenPayment($user_id, $input);
                break;
            case 'bank':
                PaymentController::handleBankPayment($user_id, $input);
                break;
            case 'upi':
                PaymentController::initiateUpiPayment($user_id, $input);
                break;
            case 'card':
                PaymentController::initiateCardPayment($user_id, $input);
                break;
            case 'airtm':
                PaymentController::initiateAirtmPayment($user_id, $input);
                break;
            case 'skrill':
            case 'skrill1tap':
                PaymentController::initiateSkrillPayment($user_id, $input);
                break;
            case 'neteller':
                PaymentController::initiateNetellerPayment($user_id, $input);
                break;
            case 'jeton':
                PaymentController::initiateJetonPayment($user_id, $input);
                break;
            case 'pol':
                PaymentController::initiatePolPayment($user_id, $input);
                break;
            case 'icashone':
                PaymentController::initiateIcashonePayment($user_id, $input);
                break;
            case 'voucherry':
                PaymentController::initiateVoucherryPayment($user_id, $input);
                break;
            default:
                Response::error('Invalid payment method', 400);
                break;
        }
        break; 

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}