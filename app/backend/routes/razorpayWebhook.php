<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Razorpay-Signature");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../services/UpiPaymentService.php';

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        UpiPaymentService::handleRazorpayWebhook();
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}
