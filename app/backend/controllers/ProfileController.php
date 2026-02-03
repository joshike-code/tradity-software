<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/ProfileService.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/UserService.php';
require_once __DIR__ . '/../services/KycService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class ProfileController {

    public static function getUserProfile($user_id) {
        $response = ProfileService::getUserProfile($user_id);
        Response::success($response);
    }

    public static function updateUserAccountProfile($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        $rules = [
            'email'  => 'null|email',
            'fname'  => 'null|string',
            'lname'  => 'null|string',
            'country'  => 'null|string',
            'phone'  => 'null|stringOrNumeric',
            'promotional_email'  => 'null|boolean',
            'trading_experience'  => 'null|stringOrNumeric',
            'trading_duration'  => 'null|stringOrNumeric',
            'trading_instrument'  => 'null|stringOrNumeric',
            'trading_frequency'  => 'null|stringOrNumeric',
            'trading_objective'  => 'null|stringOrNumeric',
            'trading_risk'  => 'null|stringOrNumeric',
            'employment_status'  => 'null|stringOrNumeric',
            'annual_income'  => 'null|stringOrNumeric',
            'income_source'  => 'null|stringOrNumeric',
            'net_worth'  => 'null|stringOrNumeric',
            'invest_amount'  => 'null|stringOrNumeric',
            'debt'  => 'null|stringOrNumeric',
            'pep'  => 'null|stringOrNumeric',
            'pep_relationship'  => 'null|stringOrNumeric',
            'pep_role'  => 'null|stringOrNumeric',
            'dob'  => 'null|stringOrNumeric',
            'gender'  => 'null|stringOrNumeric',
            'doc_identity_type'  => 'null|stringOrNumeric',
            'doc_identity_id'  => 'null|stringOrNumeric',
            'doc_identity_country'  => 'null|stringOrNumeric',
            'street'  => 'null|stringOrNumeric',
            'city'  => 'null|stringOrNumeric',
            'state'  => 'null|stringOrNumeric',
            'postal'  => 'null|stringOrNumeric',
            'doc_address_type'  => 'null|stringOrNumeric',
            'doc_address_date'  => 'null|stringOrNumeric',
            'employer_name'  => 'null|stringOrNumeric',
            'business_name'  => 'null|stringOrNumeric',
            'doc_income_type'  => 'null|stringOrNumeric',
            'dob_place'  => 'null|stringOrNumeric',
            'tax_country'  => 'null|stringOrNumeric',
            'tax_id'  => 'null|stringOrNumeric',
            'us_citizen'  => 'null|stringOrNumeric',
            'filled_group'  => 'required|string',
        ];
        
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $response = ProfileService::updateUserProfile($user_id, $input);
        if($response) {
            $account = ProfileService::getUserProfile($user_id);
            $kycData = KycService::getKycCompletionData($user_id);
            $category = '';
            if(!$kycData['is_complete']) {
                $category = array_values($kycData['incomplete_categories'])[0];
            }
            if($category === '') {
                NotificationService::sendKYCCompleteNotification($user_id);
            }

            Response::success([
                'account' => $account,
                'nextCategory' => $category
            ]);
        }
    }

    public static function updateUserKycProfile($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        $rules = [
            'fname'  => 'required|string',
            'lname'  => 'required|string',
            'country' => 'required|string',
            'phone' => 'required|stringOrNumeric',
            'dob_place'  => 'required|string',
            'tax_country' => 'required|string',
            'tax_id' => 'null|stringOrNumeric',
            'us_citizen' => 'required|boolean',
            'trading_objective' => 'required|string',
            'employment_status'  => 'required|string',
            'pep' => 'required|string',
            'dob' => 'required|stringOrNumeric',
            'street' => 'required|stringOrNumeric',
            'city' => 'required|string',
            'state' => 'required|string',
            'postal' => 'required|stringOrNumeric',
            'account_currency' => 'required|string',
            'filled_group'  => 'required|string',
        ];
        
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $response = ProfileService::updateUserProfile($user_id, $input);
        if($response) {
            // account/profile, trade_account, user, trade_accounts
            $trade_account = TradeAccountService::createAccount($user_id, 'real'); //create real account
            TradeAccountService::switchCurrentAccount($user_id, $trade_account['id_hash']); //switch to real account

            $account = ProfileService::getUserProfile($user_id); //fetch
            $trade_accounts = TradeAccountService::getUserAccounts($user_id); //fetch
            $userData = UserService::getUserById($user_id); //fetch

            Response::success(['account' => $account, 'balance' => $trade_account['balance'], 'trade_account' => $trade_account, 'trade_accounts' => $trade_accounts, 'user' => $userData]);
        }
    }

    public static function uploadDocument($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        $rules = [
            'filetype'  => 'required|string',
            'file'      => 'required|string'
        ];
        
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        ProfileService::uploadDocument($user_id, $input);
    }

    public static function getUserProfileById($profile_user_id) {
        $profile = ProfileService::getUserProfile($profile_user_id);
        Response::success($profile);
    }

    public static function getAllUserProfiles() {
        ProfileService::getAllUserProfiles();
    }

    public static function searchUsersByName($name) {
        ProfileService::searchUsersByName($name);
    }

    public static function updateKYCStatus($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'kyc_status'  => 'required|string',
            'verification_notes'  => 'string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        ProfileService::updateKYCStatus($user_id, $input);
    }
}
