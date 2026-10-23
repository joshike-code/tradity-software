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

class AirtmPaymentService
{
    private static function getBaseUrl(array $keys): string
    {
        $env = strtolower($keys['airtm']['environment'] ?? 'sandbox');
        return $env === 'production'
            ? 'https://api.enterprise.airtm.com'
            : 'https://api.stg.enterprise.airtm.com';
    }

    public static function createAirtmPayment(int $user_id, array $input)
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

        $tx_ref = uniqid("tx_airtm_");
        $date = gmdate('Y-m-d H:i:s');
        $keys = require(__DIR__ . '/../config/keys.php');
        $apiKey = $keys['airtm']['api_key'] ?? '';
        $apiSecret = $keys['airtm']['api_secret'] ?? '';

        if (empty($apiKey) || empty($apiSecret)) {
            return Response::error('AirTM API credentials are not configured. Please add AIRTM_API_KEY and AIRTM_API_SECRET to .env', 400);
        }

        $baseUrl = self::getBaseUrl($keys);
        $siteUrl = $keys['platform']['url'] ?? 'http://localhost';
        $confirmUrl = $siteUrl . '/routes/airtmWebhook.php';
        $redirectUrl = $siteUrl . '/payment/callback?method=airtm&ref=' . $tx_ref;

        $ch = curl_init($baseUrl . '/v1/operations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'type' => 'PURCHASE',
                'amount' => $amount,
                'currency' => 'USD',
                'custom_id' => $tx_ref,
                'confirmation_url' => $confirmUrl,
                'redirect_url' => $redirectUrl,
                'description' => 'Deposit to Trade Account ' . $account_id
            ]),
            CURLOPT_USERPWD => $apiKey . ":" . $apiSecret,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $opData = $raw ? json_decode($raw, true) : null;
        $checkoutUrl = $opData['url'] ?? ($opData['redirect_url'] ?? null);
        $operationId = $opData['id'] ?? null;

        if ($httpCode >= 400 || empty($checkoutUrl)) {
            $msg = $opData['message'] ?? ($opData['error'] ?? 'Failed to initialize AirTM operation');
            return Response::error('AirTM API error: ' . $msg, 400);
        }

        $email = $input['email'] ?? '';
        $payment_type = !empty($email) ? $email : 'AirTM';
        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, payment_type, date)
                                VALUES (?, ?, ?, ?, 'airtm', 'pending', ?, ?)");
        if (!$stmt) {
            return Response::error('AirTM payment initiation failed.', 500);
        }

        $stmt->bind_param("isdsss", $user_id, $account_id, $amount, $tx_ref, $payment_type, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not create AirTM payment.', 500);
        }
        $stmt->close();

        return Response::success([
            'message' => 'AirTM payment initiated successfully',
            'amount' => $amount,
            'method' => 'airtm',
            'tx_ref' => $tx_ref,
            'checkout_url' => $checkoutUrl,
            'operation_id' => $operationId
        ]);
    }

    public static function confirmAirtmPayment(int $user_id, array $input)
    {
        $conn = Database::getConnection();
        $tx_ref = $input['tx_ref'] ?? null;
        $opId = $input['operation_id'] ?? null;

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
        $apiKey = $keys['airtm']['api_key'] ?? '';
        $apiSecret = $keys['airtm']['api_secret'] ?? '';

        if (empty($apiKey) || empty($apiSecret)) {
            return Response::error('AirTM API credentials are not configured in .env', 400);
        }

        if (empty($opId)) {
            return Response::error('Please provide AirTM operation ID to confirm payment.', 400);
        }

        $verified = false;
        $verifiedAmount = $payment['amount'];

        // Official verification via AirTM Operation Details API: GET /v1/operations/{id}
        $baseUrl = self::getBaseUrl($keys);
        $ch = curl_init($baseUrl . "/v1/operations/" . urlencode($opId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $apiKey . ":" . $apiSecret,
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw) {
            $op = json_decode($raw, true);
            if (isset($op['status']) && in_array(strtoupper($op['status']), ['COMPLETED', 'CONFIRMED', 'PAID'])) {
                $verified = true;
                if (isset($op['amount'])) {
                    $verifiedAmount = floatval($op['amount']);
                }
            }
        }

        if (!$verified) {
            return Response::error('AirTM payment verification failed on gateway', 400);
        }

        self::fulfillPayment($payment, $verifiedAmount, $tx_ref);

        $account = TradeAccountService::getAccountById($payment['user_id'], $payment['account']);
        return Response::success([
            'message' => 'AirTM payment confirmed and balance updated',
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

    public static function handleAirtmWebhook()
    {
        $rawPayload = file_get_contents("php://input");
        $event = json_decode($rawPayload, true);

        if (!$event) {
            Response::error('Invalid webhook payload', 400);
        }

        $opStatus = strtoupper($event['status'] ?? '');
        if ($opStatus === 'COMPLETED' || $opStatus === 'CONFIRMED' || $opStatus === 'PAID') {
            $tx_ref = $event['custom_id'] ?? null;
            $amount = isset($event['amount']) ? floatval($event['amount']) : null;

            if ($tx_ref) {
                $conn = Database::getConnection();
                $stmt = $conn->prepare("SELECT id, user_id, account, amount, status FROM payments WHERE tx_ref = ? LIMIT 1");
                $stmt->bind_param("s", $tx_ref);
                $stmt->execute();
                $payment = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($payment && $payment['status'] !== 'success') {
                    $actualAmount = $amount ?? $payment['amount'];
                    self::fulfillPayment($payment, $actualAmount, $tx_ref);
                }
            }
        }

        Response::success('AirTM webhook processed successfully');
    }
}
