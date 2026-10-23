<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


use Core\SanitizationService;
use Core\InputData;

require_once __DIR__ . '/../services/BankAccountsService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class BankAccountsController {

    public static function getBankAccounts() {
        $accounts = BankAccountsService::getAllBankAccounts(true);
        $keys = require __DIR__ . '/../config/keys.php';
        $keyId = !empty($keys['razorpay']['key_id']) ? trim($keys['razorpay']['key_id']) : '';
        $keySecret = !empty($keys['razorpay']['key_secret']) ? trim($keys['razorpay']['key_secret']) : '';
        $merchantUpi = !empty($keys['razorpay']['merchant_upi']) ? trim($keys['razorpay']['merchant_upi']) : '';
        $upiAvailable = (!empty($keyId) && !empty($keySecret)) || !empty($merchantUpi);

        $paypalClientId = !empty($keys['paypal']['client_id']) ? trim($keys['paypal']['client_id']) : '';
        $paypalClientSecret = !empty($keys['paypal']['client_secret']) ? trim($keys['paypal']['client_secret']) : '';
        $paypalAvailable = (!empty($paypalClientId) && !empty($paypalClientSecret));

        Response::success([
            'accounts' => $accounts,
            'upi_available' => $upiAvailable,
            'paypal_available' => $paypalAvailable
        ]);
    }

    public static function getSelectBankAccount($id) {
        $wallet = BankAccountsService::getBankAccountById($id);
        Response::success($wallet);
    }

    public static function deleteBankAccount($id) {
        BankAccountsService::deleteBankAccount($id);
    }

    public static function createBankAccount() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'account_name'  => 'required|string',
            'bank_name'     => 'required|string',
            'account_number' => 'required|stringOrNumeric'
        ];
        $validator = new Validator();
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        BankAccountsService::createBankAccount($input);
    }

    public static function updateBankAccount($id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'account_name'  => 'required|string',
            'bank_name'     => 'required|string',
            'account_number' => 'required|stringOrNumeric'
        ];
        $validator = new Validator();
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        BankAccountsService::updateBankAccount($id, $input);
    }

}

