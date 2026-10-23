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

class JetonPaymentService
{
    public static function createJetonPayment(int $user_id, array $input)
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

        $tx_ref = uniqid("tx_jeton_");
        $date = gmdate('Y-m-d H:i:s');
        $email = $input['email'] ?? '';

        $keys = require(__DIR__ . '/../config/keys.php');
        $apiKey = $keys['jeton']['api_key'] ?? '';
        $siteUrl = $keys['platform']['url'] ?? 'http://localhost';

        if (empty($apiKey)) {
            return Response::error('Jeton API key is not configured. Please add JETON_API_KEY to .env', 400);
        }

        $returnUrl = $siteUrl . '/payment/callback?method=jeton&ref=' . $tx_ref;
        $notifyUrl = $siteUrl . '/routes/jetonWebhook.php';

        // Official Jeton Checkout API Call
        $ch = curl_init('https://api.jeton.com/payment/create');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'orderNumber' => $tx_ref,
                'amount' => $amount,
                'currency' => 'USD',
                'returnUrl' => $returnUrl,
                'notifyUrl' => $notifyUrl,
                'customer' => [
                    'email' => !empty($email) ? $email : 'trader@traditi.com'
                ]
            ]),
            CURLOPT_HTTPHEADER => [
                "apiKey: " . $apiKey,
                "Authorization: Bearer " . $apiKey,
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $jData = $raw ? json_decode($raw, true) : null;
        $checkoutUrl = $jData['paymentUrl'] ?? ($jData['redirectUrl'] ?? null);

        if ($httpCode >= 400 || empty($checkoutUrl)) {
            $msg = $jData['message'] ?? 'Failed to initialize Jeton checkout';
            return Response::error('Jeton API error: ' . $msg, 400);
        }

        $payment_type = !empty($email) ? $email : 'Jeton';
        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, payment_type, date)
                                VALUES (?, ?, ?, ?, 'jeton', 'pending', ?, ?)");
        if (!$stmt) {
            return Response::error('Jeton payment initiation failed.', 500);
        }

        $stmt->bind_param("isdsss", $user_id, $account_id, $amount, $tx_ref, $payment_type, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not record Jeton payment.', 500);
        }
        $stmt->close();

        return Response::success([
            'message' => 'Jeton payment initiated successfully',
            'amount' => $amount,
            'method' => 'jeton',
            'tx_ref' => $tx_ref,
            'checkout_url' => $checkoutUrl,
            'order_number' => $tx_ref
        ]);
    }

    public static function confirmJetonPayment(int $user_id, array $input)
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
        $apiKey = $keys['jeton']['api_key'] ?? '';

        if (empty($apiKey)) {
            return Response::error('Jeton API key is not configured in .env', 400);
        }

        // Jeton payments are verified via webhook or gateway redirect callback
        return Response::error('Jeton payment has not yet been confirmed by the gateway.', 400);
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

    public static function handleJetonWebhook()
    {
        $rawPayload = file_get_contents("php://input");
        $event = json_decode($rawPayload, true);

        if (!$event) {
            Response::error('Invalid webhook payload', 400);
        }

        $tx_ref = $event['orderNumber'] ?? ($event['order_id'] ?? null);
        $status = strtoupper($event['status'] ?? '');

        if ($tx_ref && in_array($status, ['PAID', 'COMPLETED', 'SUCCESS'])) {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, user_id, account, amount, status FROM payments WHERE tx_ref = ? LIMIT 1");
            $stmt->bind_param("s", $tx_ref);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($payment && $payment['status'] !== 'success') {
                $amount = isset($event['amount']) ? floatval($event['amount']) : $payment['amount'];
                self::fulfillPayment($payment, $amount, $tx_ref);
            }
        }

        Response::success('Jeton webhook processed successfully');
    }
}
