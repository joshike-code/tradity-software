<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use Core\SanitizationService;

require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/../services/FlutterwaveService.php';
require_once __DIR__ . '/../services/PaystackService.php';
require_once __DIR__ . '/../services/OpayService.php';
require_once __DIR__ . '/../services/SafehavenService.php';
require_once __DIR__ . '/../services/CryptoPaymentService.php';
require_once __DIR__ . '/../services/BankAccountsService.php';
require_once __DIR__ . '/../services/KycService.php';
require_once __DIR__ . '/../services/UpiPaymentService.php';
require_once __DIR__ . '/../services/CardPaymentService.php';
require_once __DIR__ . '/../services/AirtmPaymentService.php';
require_once __DIR__ . '/../services/SkrillPaymentService.php';
require_once __DIR__ . '/../services/NetellerPaymentService.php';
require_once __DIR__ . '/../services/JetonPaymentService.php';
require_once __DIR__ . '/../services/PolPaymentService.php';
require_once __DIR__ . '/../services/IcashonePaymentService.php';
require_once __DIR__ . '/../services/VoucherryPaymentService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class PaymentController {

    public static function getPayments($user_id) {
        $payments = PaymentService::getUserPayments($user_id);
        Response::success($payments);
    }

    public static function searchUserPayments($user_id, $term) {
        PaymentService::searchUserPayments($user_id, $term);
    }

    public static function getAllPayments() {
        PaymentService::getAllPayments();
    }

    public static function getSelectPayment($id) {
        $paymentData = PaymentService::getPaymentByID($id);
        Response::success($paymentData);
    }

    public static function getPendingDeposits() {
       PaymentService::getPendingDeposits();
    }

    public static function searchPaymentsByRef($ref) {
        PaymentService::searchPaymentsByRef($ref);
    }

    public static function deletePayment($id) {
        PaymentService::deletePayment($id);
    }

    public static function editPaymentRecord($id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        $rules = [
            'amount' => 'required|float',
            'date' => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        PaymentService::editPaymentRecord($id, $input);
    }

    public static function handleCryptoPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        
        $rules = [
            'amount' => 'required|float',
            'coin' => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        CryptoPaymentService::createCryptoPayment($user_id, $input);
    }

    public static function handleBankPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        
        $rules = [
            'amount' => 'required|float',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        BankAccountsService::createBankPayment($user_id, $input);
    }

    public static function updateCryptoPaymentStatus($id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        $rules = [
            'amount' => 'required|float',
            'status' => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        CryptoPaymentService::updateCryptoPaymentStatus($id, $input);
    }

    public static function updateBankPaymentStatus($id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        $rules = [
            'amount' => 'required|float',
            'status' => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        BankAccountsService::updateBankPaymentStatus($id, $input);
    }

    public static function initiateFlutterwavePayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        FlutterwaveService::createFlutterwavePayment($user_id, $input);
    }

    public static function verifyFlutterwavePayment($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        $transaction_id = $input['transaction_id'] ?? null;
        if(!$transaction_id) {
            Response::error('Missing transaction ID', 400);
        }
        FlutterwaveService::verifyFlutterwaveTransaction($transaction_id);
    }

    public static function updatePaystackPayment($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        $transaction_id = $input['transaction_id'] ?? null;
        if(!$transaction_id) {
            Response::error('Missing transaction ID', 400);
        }
        PaystackService::updatePaystackTransaction($transaction_id);
    }

    public static function initiatePaystackPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        PaystackService::createPaystackPayment($user_id, $input);
    }

    public static function confirmPaystackPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        PaystackService::confirmPaystackPayment($user_id, $input);
    }

    public static function initiateOpayPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        OpayService::createOpayPayment($user_id, $input);
    }

    public static function initiateSafehavenPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        SafehavenService::createVirtualAccount($user_id, $input);
    }

    // --- UPI Payment ---
    public static function initiateUpiPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        UpiPaymentService::createUpiPayment($user_id, $input);
    }

    public static function confirmUpiPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        UpiPaymentService::confirmUpiPayment($user_id, $input);
    }

    public static function updateUpiPaymentStatus($id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        $rules = [
            'amount' => 'required|float',
            'status' => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        UpiPaymentService::updateUpiPaymentStatus($id, $input);
    }

    // --- Card Payment ---
    public static function initiateCardPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        CardPaymentService::createCardPayment($user_id, $input);
    }

    public static function confirmCardPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        CardPaymentService::confirmCardPayment($user_id, $input);
    }

    // --- AirTM Payment ---
    public static function initiateAirtmPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        AirtmPaymentService::createAirtmPayment($user_id, $input);
    }

    public static function confirmAirtmPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        AirtmPaymentService::confirmAirtmPayment($user_id, $input);
    }

    // --- Skrill / Skrill 1-Tap ---
    public static function initiateSkrillPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        SkrillPaymentService::createSkrillPayment($user_id, $input);
    }

    public static function confirmSkrillPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        SkrillPaymentService::confirmSkrillPayment($user_id, $input);
    }

    // --- Neteller ---
    public static function initiateNetellerPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        NetellerPaymentService::createNetellerPayment($user_id, $input);
    }

    public static function confirmNetellerPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        NetellerPaymentService::confirmNetellerPayment($user_id, $input);
    }

    // --- JetonBank ---
    public static function initiateJetonPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        JetonPaymentService::createJetonPayment($user_id, $input);
    }

    public static function confirmJetonPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        JetonPaymentService::confirmJetonPayment($user_id, $input);
    }

    // --- Polygon POL ---
    public static function initiatePolPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        PolPaymentService::createPolPayment($user_id, $input);
    }

    public static function confirmPolPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        PolPaymentService::confirmPolPayment($user_id, $input);
    }

    // --- iCash.One ---
    public static function initiateIcashonePayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        IcashonePaymentService::createIcashonePayment($user_id, $input);
    }

    public static function confirmIcashonePayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        IcashonePaymentService::confirmIcashonePayment($user_id, $input);
    }

    // --- Voucherry ---
    public static function initiateVoucherryPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['amount' => 'required|float'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $kycStatus = KycService::checkUserKycPermission('deposit', $user_id);
        if(!$kycStatus['has_access']) {
            Response::error($kycStatus['error_message'] ?? 'KYC verification required', 403);
        }

        VoucherryPaymentService::createVoucherryPayment($user_id, $input);
    }

    public static function confirmVoucherryPayment($user_id, $rawInput) {
        $input = SanitizationService::sanitize($rawInput);
        $rules = ['tx_ref' => 'required|string'];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        VoucherryPaymentService::confirmVoucherryPayment($user_id, $input);
    }
}
