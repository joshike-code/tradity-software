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

class PaystackService
{
    public static function createPaystackPayment(int $user_id, array $input)
    {
        $amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        if ($amount <= 0) {
            return Response::error('Invalid amount provided.', 400);
        }

        $keys = require(__DIR__ . '/../config/keys.php');
        $secretKey = $keys['paystack']['secret_key'] ?? '';
        $publicKey = $keys['paystack']['public_key'] ?? '';

        if (empty($secretKey)) {
            return Response::error('Paystack API keys are not configured. Please add PAYSTACK_SECRET_KEY to .env', 400);
        }

        $account = TradeAccountService::getUserCurrentAccount($user_id);
        if (!$account) {
            return Response::error('User trade account not found.', 404);
        }
        $account_id = $account['id_hash'];

        $conn = Database::getConnection();
        $tx_ref = uniqid("tx_paystack_");
        $date = gmdate('Y-m-d H:i:s');

        // Fetch user email
        $userStmt = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
        $userStmt->bind_param("i", $user_id);
        $userStmt->execute();
        $userRow = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();
        $email = $input['email'] ?? ($userRow['email'] ?? 'customer@traditi.com');

        // Official Paystack Transaction Initialization API
        $ch = curl_init('https://api.paystack.co/transaction/initialize');
        $callbackUrl = ($keys['platform']['url'] ?? 'http://localhost') . '/payment/callback?method=paystack';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'email' => $email,
                'amount' => round($amount * 100), // in subunit (cents)
                'reference' => $tx_ref,
                'currency' => 'USD',
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'user_id' => $user_id,
                    'account_id' => $account_id
                ]
            ]),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $secretKey,
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resData = $rawResponse ? json_decode($rawResponse, true) : null;
        if ($httpCode >= 400 || empty($resData['data']['authorization_url'])) {
            $msg = $resData['message'] ?? 'Failed to initialize transaction on Paystack API';
            return Response::error('Paystack API error: ' . $msg, 400);
        }

        $authorizationUrl = $resData['data']['authorization_url'];
        $accessCode = $resData['data']['access_code'] ?? null;

        // Record pending payment in database only after successful API initiation
        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, payment_type, date) VALUES (?, ?, ?, ?, 'paystack', 'pending', 'Paystack', ?)");
        $stmt->bind_param("isdss", $user_id, $account_id, $amount, $tx_ref, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not create payment record', 500);
        }
        $stmt->close();

        return Response::success([
            'message' => 'Paystack payment initiated successfully',
            'tx_ref' => $tx_ref,
            'amount' => $amount,
            'method' => 'paystack',
            'public_key' => $publicKey,
            'checkout_url' => $authorizationUrl,
            'authorization_url' => $authorizationUrl,
            'access_code' => $accessCode
        ]);
    }

    public static function confirmPaystackPayment(int $user_id, array $input)
    {
        $conn = Database::getConnection();
        $tx_ref = $input['tx_ref'] ?? null;

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

        $keys = require(__DIR__ . '/../config/keys.php');
        $secretKey = $keys['paystack']['secret_key'] ?? '';

        if (empty($secretKey)) {
            return Response::error('Paystack secret key is not configured in .env', 400);
        }

        // Official Paystack Verification Endpoint: GET https://api.paystack.co/transaction/verify/:reference
        $ch = curl_init("https://api.paystack.co/transaction/verify/" . urlencode($tx_ref));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $secretKey,
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $verifyResponse = curl_exec($ch);
        curl_close($ch);

        $verified = false;
        $verifiedAmount = $payment['amount'];

        if ($verifyResponse) {
            $vData = json_decode($verifyResponse, true);
            if (isset($vData['status']) && $vData['status'] === true && isset($vData['data']['status']) && $vData['data']['status'] === 'success') {
                $verified = true;
                if (isset($vData['data']['amount'])) {
                    $verifiedAmount = $vData['data']['amount'] / 100;
                }
            }
        }

        if (!$verified) {
            return Response::error('Payment has not been confirmed as paid on Paystack.', 400);
        }

        self::fulfillPayment($payment, $verifiedAmount, $tx_ref);

        $account = TradeAccountService::getAccountById($payment['user_id'], $payment['account']);
        return Response::success([
            'message' => 'Paystack payment confirmed and balance updated',
            'status' => 'success',
            'amount' => $verifiedAmount,
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

    public static function handlePaystackWebhook()
    {
        $input = file_get_contents("php://input");
        $keys = require(__DIR__ . '/../config/keys.php');
        $secretKey = $keys['paystack']['secret_key'] ?? '';
        $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

        if (empty($secretKey)) {
            http_response_code(500);
            echo json_encode(['error' => 'Paystack secret key not configured']);
            exit;
        }

        // Validate HMAC-SHA512 signature
        $computed = hash_hmac('sha512', $input, $secretKey);
        if ($signature !== $computed) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
            exit;
        }

        $payload = json_decode($input, true);
        if (!isset($payload['event']) || $payload['event'] !== 'charge.success') {
            Response::success("Ignored non-success event");
        }

        $data = $payload['data'] ?? null;
        if (!$data || !isset($data['reference'], $data['amount'])) {
            Response::error("Invalid webhook payload", 400);
        }

        $tx_ref = $data['reference'];
        $amount = $data['amount'] / 100;

        $conn = Database::getConnection();
        $stmt = $conn->prepare("SELECT id, user_id, account, amount, status FROM payments WHERE tx_ref = ? LIMIT 1");
        $stmt->bind_param("s", $tx_ref);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            Response::success("Transaction not recognized: $tx_ref");
        }

        $payment = $result->fetch_assoc();
        $stmt->close();

        if ($payment['status'] === 'success') {
            Response::success("Transaction already fulfilled");
        }

        try {
            self::fulfillPayment($payment, $amount, $tx_ref);
            Response::success("Webhook processed: trade account balance credited");
        } catch (Exception $e) {
            Response::error("Webhook error: " . $e->getMessage(), 500);
        }
    }
}