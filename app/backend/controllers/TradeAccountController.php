<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/TradeService.php';
require_once __DIR__ . '/../controllers/UserController.php';
require_once __DIR__ . '/../services/BotTradeService.php';
require_once __DIR__ . '/../services/TransactionService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class TradeAccountController {

    public static function getUserAccounts($user_id) {
        $response = TradeAccountService::getUserAccounts($user_id);
        Response::success($response);
    }

    public static function getAccountById($user_id, $account_id) {
        $account = TradeAccountService::getAccountById($user_id, $account_id);
        Response::success($account);
    }

    public static function getAccountByUniqueId($id) {
        $account = TradeAccountService::getAccountByUniqueId($id);
        Response::success($account);
    }

    public static function getAllAccounts($filter) {
        TradeAccountService::getAllAccounts($filter);
    }

    public static function resetAccountBalance($user_id) {
        TradeAccountService::resetAccountBalance($user_id);
    }

    public static function updateAccountStatus($id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'status'  => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $response = TradeAccountService::updateAccountStatus($id, $input);
        if($response) {
            self::getAccountByUniqueId($id);
        };
    }

    public static function updateAccountLeverage($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'leverage'  => 'required|integer'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        TradeAccountService::updateAccountLeverage($user_id, $input);
    }

    public static function deleteAccount($account_id) {
        TradeAccountService::deleteAccount($account_id);
    }

    public static function switchCurrentAccount($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'id_hash'  => 'required|float'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        TradeAccountService::switchCurrentAccount($user_id, $input['id_hash']);
        // UserController::getUser($user_id);

        $account = TradeAccountService::getAccountById($user_id, $input['id_hash']);
        $openTrades = TradeService::getOpenTrades($user_id);
        $closedTrades = TradeService::getClosedTrades($user_id);
        $transactions = TransactionService::getUserTransactions($user_id);
        $openBotTrade = BotTradeService::getUserTrade($user_id);
        if(!$openBotTrade) $openBotTrade = [];
        Response::success(['trade_account' => $account, 'openTrades' => $openTrades, 'closedTrades' => $closedTrades, 'openBotTrade' => $openBotTrade, 'transactions' => $transactions, 'balance' => $account['balance']]);
    }

    public static function updateBalance($type) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'amount'  => 'required|float',
            'account_id'  => 'required|integer',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        if($type === 'topup') {
            TradeAccountService::topUpBalance($input);
        } elseif($type === 'deduct') {
            TradeAccountService::deductBalance($input);
        } else {
            Response::error('Method not allowed', 405);
        };
    }

    public static function createRealAccount() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'balance'  => 'required|float',
            'user_id'  => 'required|integer',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $type = 'real';
        $user_id = $input['user_id'];
        $balance = $input['balance'] ?? '0';

        TradeAccountService::createAccount($user_id, $type, $balance);
        $accounts = TradeAccountService::getUserAccounts($user_id);
        Response::success($accounts);
    }
}
