<?php
// SoftWare repo: https://github.com/joshike-code/tradity-software
// Author: Joshike-code


// CORS headers - allow cross-origin requests
header("Access-Control-Allow-Origin:*");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

// Cache-Control headers - prevent browser caching of API responses
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error/server_errors.log');
error_reporting(E_ALL);

require_once __DIR__ . '/core/response.php';

// Global error handler
set_error_handler(function($severity, $message, $file, $line) {
    error_log("ERROR [$severity]: $message in $file on line $line");
    Response::error("A server error occurred.", 500);
});

// Global exception handler
set_exception_handler(function($exception) {
    error_log("UNCAUGHT EXCEPTION: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    Response::error("A server exception occurred.", 500);
});

$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
if($request === '/app/backend/api/install') {
    require 'routes/install.php';
    exit;
}
if($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$keys = require __DIR__ . '/config/keys.php';
$host = $keys['system']['host_link'];


switch($request) {

    case $host.'api/login':
        require 'routes/login.php';
        break;

    case $host.'api/platform':
        require 'routes/platform.php';
        break;

    case $host.'api/register':
        require 'routes/register.php';
        break;
        
    case $host.'api/user':
        require 'routes/user.php';
        break;

    case $host.'api/profile':
        require 'routes/profile.php';
        break;

    case $host.'api/credentials':
        require 'routes/credentials.php';
        break;

    case $host.'api/change_credential':
        require 'routes/credentialChange.php';
        break;

    case $host.'api/password':
        require 'routes/password.php';
        break;

    case $host.'api/online_activity':
        require 'routes/onlineActivity.php';
        break;

    case $host.'api/forgot_password':
        require 'routes/forgotPassword.php';
        break;

    case $host.'api/trade_accounts':
        require 'routes/tradeAccounts.php';
        break;

    case $host.'api/trades':
        require 'routes/trades.php';
        break;

    case $host.'api/bot_trades':
        require 'routes/botTrades.php';
        break;

    case $host.'api/transactions':
        require 'routes/transactions.php';
        break;

    case $host.'api/activity':
        require 'routes/activity.php';
        break;

    case $host.'api/notifications':
        require 'routes/notifications.php';
        break;

    case $host.'api/pairs':
        require 'routes/pairs.php';
        break;

    case $host.'api/chart_data':
        require 'routes/chartData.php';
        break;

    case $host.'api/usd-exchange':
        require 'routes/exchange.php';
        break;

    case $host.'api/payment_wallets':
        require 'routes/paymentWallets.php';
        break;

    case $host.'api/bank_accounts':
        require 'routes/bankAccounts.php';
        break;

    case $host.'api/withdraw':
        require 'routes/withdraw.php';
        break;

    case $host.'api/payment':
        require 'routes/payment.php';
        break;

    case $host.'api/payment-verify':
        require 'routes/paymentVerify.php';
        break;

    case $host.'api/flutterwavewebhook':
        require 'routes/flutterwaveWebhook.php';
        break;

    case $host.'api/paystackwebhook':
        require 'routes/paystackWebhook.php';
        break;

    case $host.'api/opaywebhook':
        require 'routes/opayWebhook.php';
        break;

    case $host.'api/safehavenwebhook':
        require 'routes/safehavenWebhook.php';
        break;

    case $host.'api/config':
        require 'routes/config.php';
        break;

    case $host.'api/update':
        require 'routes/update.php';
        break;

    case $host.'api/websocket':
        require 'routes/websocket.php';
        break;

    case $host.'api/admin/manage_server':
        require 'routes/admin/manageServer.php';
        break;

    case $host.'api/admin/user':
        require 'routes/admin/user.php';
        break;

    case $host.'api/admin/trade_accounts':
        require 'routes/admin/tradeAccounts.php';
        break;

    case $host.'api/admin/profile':
        require 'routes/admin/profile.php';
        break;

    case $host.'api/admin/balance':
        require 'routes/admin/balance.php';
        break;

    case $host.'api/admin/pairs':
        require 'routes/admin/pairs.php';
        break;

    case $host.'api/admin/trades':
        require 'routes/admin/trades.php';
        break;

    case $host.'api/admin/bot_trades':
        require 'routes/admin/botTrades.php';
        break;

    case $host.'api/admin/trade_alter':
        require 'routes/admin/tradeAlter.php';
        break;

    case $host.'api/admin/payments':
        require 'routes/admin/payments.php';
        break;

    case $host.'api/admin/withdraw':
        require 'routes/admin/withdraw.php';
        break;

    case $host.'api/admin/deposit':
        require 'routes/admin/deposit.php';
        break;

    case $host.'api/admin/online_activity':
        require 'routes/admin/onlineActivity.php';
        break;

    case $host.'api/admin/payment_wallets':
        require 'routes/admin/paymentWallets.php';
        break;

    case $host.'api/admin/bank_accounts':
        require 'routes/admin/bankAccounts.php';
        break;

    case $host.'api/admin/platform':
        require 'routes/admin/platform.php';
        break;

    case $host.'api/admin/kyc_config':
        require 'routes/admin/kycConfig.php';
        break;

    case $host.'api/admin/mail-manager':
        require 'routes/admin/mailManager.php';
        break;

    case $host.'api/admin/account_trade_stats':
        require 'routes/admin/accountTradeStats.php';
        break;

    case $host.'api/admin/user_stats':
        require 'routes/admin/userStats.php';
        break;

    case $host.'api/admin/trade_stats':
        require 'routes/admin/tradeStats.php';
        break;

    case $host.'api/admin/admin':
        require 'routes/admin/admin.php';
        break;

    case $host.'api/admin/permission':
        require 'routes/admin/permission.php';
        break;

    case $host.'api/contact_us':
        require 'routes/contactUs.php';
        break;

    case $host.'api/log-client-error':
        require 'routes/logClientError.php';
        break;

    default:
        Response::error('Route not found', 404);
        break;
}

?>