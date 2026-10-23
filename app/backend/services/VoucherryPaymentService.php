<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/TransactionService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ReferralService.php';

class VoucherryPaymentService
{
    public static function createVoucherryPayment(int $user_id, array $input)
    {
        $conn = Database::getConnection();
        $amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        if ($amount <= 0) {
            return Response::error('Invalid amount provided.', 400);
        }

        $account = TradeAccountService::getUserCurrentAccount($user_id);
        if (!$account) {
            return Response::error('User trade account not found.', 404);
        }
        $account_id = $account['id_hash'];

        $tx_ref = uniqid("tx_voucherry_");
        $date = gmdate('Y-m-d H:i:s');
        $voucherCode = $input['voucher_code'] ?? '';

        $keys = require(__DIR__ . '/../config/keys.php');
        $apiKey = $keys['voucherry']['api_key'] ?? '';

        if (empty($apiKey)) {
            return Response::error('Voucherry API credentials are not configured. Please add VOUCHERRY_API_KEY to .env', 400);
        }

        $payment_type = !empty($voucherCode) ? $voucherCode : 'Voucherry';
        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, payment_type, date)
                                VALUES (?, ?, ?, ?, 'voucherry', 'pending', ?, ?)");
        if (!$stmt) {
            return Response::error('Voucherry payment initiation failed.', 500);
        }

        $stmt->bind_param("isdsss", $user_id, $account_id, $amount, $tx_ref, $payment_type, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not create Voucherry payment.', 500);
        }
        $stmt->close();

        return Response::success([
            'message' => 'Voucherry voucher payment initiated successfully',
            'amount' => $amount,
            'method' => 'voucherry',
            'tx_ref' => $tx_ref
        ]);
    }

    public static function confirmVoucherryPayment(int $user_id, array $input)
    {
        $conn = Database::getConnection();
        $tx_ref = $input['tx_ref'] ?? null;
        $voucherCode = trim($input['voucher_code'] ?? '');
        $voucherPin = trim($input['voucher_pin'] ?? '');

        if (!$tx_ref) {
            return Response::error('Missing transaction reference', 400);
        }

        $stmt = $conn->prepare("SELECT id, user_id, account, amount, status, tx_ref FROM payments WHERE tx_ref = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("si", $tx_ref, $user_id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payment) {
            return Response::error('Payment record not found', 404);
        }

        if ($payment['status'] === 'success') {
            return Response::success([
                'message' => 'Payment already completed',
                'tx_ref' => $tx_ref,
                'status' => 'success'
            ]);
        }

        if (empty($voucherCode)) {
            return Response::error('Please enter your Voucherry voucher code.', 400);
        }

        $keys = require(__DIR__ . '/../config/keys.php');
        $merchantId = $keys['voucherry']['merchant_id'] ?? '';
        $apiKey = $keys['voucherry']['api_key'] ?? '';

        if (empty($apiKey)) {
            return Response::error('Voucherry API key is not configured in .env', 400);
        }

        $verified = false;

        // Official Voucherry Redemption API Call
        $ch = curl_init('https://api.voucherry.net/v1/redeem');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'merchant_id' => $merchantId,
                'api_key' => $apiKey,
                'code' => $voucherCode,
                'pin' => $voucherPin,
                'amount' => floatval($payment['amount']),
                'currency' => 'USD',
                'tx_ref' => $tx_ref
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw) {
            $res = json_decode($raw, true);
            if (isset($res['status']) && in_array(strtolower($res['status']), ['success', 'redeemed', 'approved', 'true'])) {
                $verified = true;
            }
        }

        if (!$verified) {
            return Response::error('Invalid or already redeemed Voucherry voucher code.', 400);
        }

        self::fulfillPayment($payment, floatval($payment['amount']), $tx_ref);

        $account = TradeAccountService::getAccountById($payment['user_id'], $payment['account']);
        return Response::success([
            'message' => 'Voucherry voucher redeemed successfully and balance credited',
            'status' => 'success',
            'amount' => $payment['amount'],
            'balance' => $account['balance'],
            'tx_ref' => $tx_ref
        ]);
    }

    public static function fulfillPayment(array $payment, float $amount, string $tx_ref)
    {
        $conn = Database::getConnection();
        $date = gmdate('Y-m-d H:i:s');
        $status = 'success';

        $conn->begin_transaction();
        try {
            $upStmt = $conn->prepare("UPDATE payments SET status = ?, amount = ?, date = ? WHERE id = ?");
            $upStmt->bind_param("sdsi", $status, $amount, $date, $payment['id']);
            $upStmt->execute();
            $upStmt->close();

            $updateBalanceSql = "UPDATE accounts SET balance = balance + ? WHERE id_hash = ?";
            $balanceStmt = $conn->prepare($updateBalanceSql);
            $balanceStmt->bind_param("ds", $amount, $payment['account']);
            $balanceStmt->execute();
            $balanceStmt->close();

            $account = TradeAccountService::getAccountById($payment['user_id'], $payment['account']);
            NotificationService::sendDepositApprovedNotification($payment['user_id'], $amount, 'USD');

            TransactionService::createTransaction(
                $payment['user_id'],
                $payment['account'],
                'wallet',
                'deposit',
                $tx_ref,
                "+" . $amount,
                $account['balance']
            );

            ReferralService::handleReferralBonus($payment['user_id'], $amount, $tx_ref);

            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
