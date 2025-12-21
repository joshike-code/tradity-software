<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/TransactionService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class TransactionController {

    public static function getUserTransactions($user_id) {
        $transactions = TransactionService::getUserTransactions($user_id);
        Response::success($transactions);
    }

    public static function getTransactionById($transaction_id) {
        $transaction = TransactionService::getTransactionById($transaction_id);
        Response::success($transaction);
    }

    public static function getAllTransactions() {
        TransactionService::getAllTransactions();
    }

    public static function getUserTransactionsByAccount($user_id, $account_id) {
        TransactionService::getUserTransactionsByAccount($user_id, $account_id);
    }

    public static function searchTransactionsByRef($ref) {
        TransactionService::searchTransactionsByRef($ref);
    }

    public static function deleteTransaction($transaction_id) {
        TransactionService::deleteTransaction($transaction_id);
    }

    public static function filterTransactionsByDate($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'startDate'  => 'string',
            'endDate'  => 'string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        TransactionService::filterTransactionsByDate($user_id, $input);
    }
}
