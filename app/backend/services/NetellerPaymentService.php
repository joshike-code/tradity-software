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

class NetellerPaymentService
{
    public static function createNetellerPayment(int $user_id, array $input)
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

        $tx_ref = uniqid("tx_neteller_");
        $date = gmdate('Y-m-d H:i:s');
        $email = $input['email'] ?? '';

        $keys = require(__DIR__ . '/../config/keys.php');
        $clientId = $keys['neteller']['client_id'] ?? '';
        $clientSecret = $keys['neteller']['client_secret'] ?? '';
        $siteUrl = $keys['platform']['url'] ?? 'http://localhost';

        if (empty($clientId) || empty($clientSecret)) {
            return Response::error('Neteller API credentials are not configured. Please add NETELLER_CLIENT_ID and NETELLER_CLIENT_SECRET to .env', 400);
        }

        // Official Paysafe / Neteller Payments API Call
        $returnUrl = $siteUrl . '/payment/callback?method=neteller&ref=' . $tx_ref;
        $ch = curl_init('https://api.paysafe.com/paymenthub/v1/paymenthandles');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'merchantRefNum' => $tx_ref,
                'transactionType' => 'PAYMENT',
                'paymentType' => 'Neteller',
                'amount' => round($amount * 100),
                'currencyCode' => 'USD',
                'neteller' => [
                    'consumerId' => !empty($email) ? $email : 'trader@traditi.com'
                ],
                'returnLinks' => [
                    [
                        'rel' => 'default',
                        'href' => $returnUrl,
                        'method' => 'GET'
                    ]
                ]
            ]),
            CURLOPT_USERPWD => $clientId . ":" . $clientSecret,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $handleData = $raw ? json_decode($raw, true) : null;
        $paymentHandleToken = $handleData['id'] ?? null;
        $checkoutUrl = null;
        if (!empty($handleData['links'])) {
            foreach ($handleData['links'] as $link) {
                if (($link['rel'] ?? '') === 'redirect_payment' || ($link['rel'] ?? '') === 'default') {
                    $checkoutUrl = $link['href'];
                    break;
                }
            }
        }

        if ($httpCode >= 400 || empty($checkoutUrl)) {
            $msg = $handleData['error']['message'] ?? 'Failed to initialize Neteller payment handle';
            return Response::error('Neteller API error: ' . $msg, 400);
        }

        $payment_type = !empty($email) ? $email : 'Neteller';
        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, payment_type, date)
                                VALUES (?, ?, ?, ?, 'neteller', 'pending', ?, ?)");
        if (!$stmt) {
            return Response::error('Neteller payment initiation failed.', 500);
        }

        $stmt->bind_param("isdsss", $user_id, $account_id, $amount, $tx_ref, $payment_type, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not record Neteller payment.', 500);
        }
        $stmt->close();

        return Response::success([
            'message' => 'Neteller payment initiated successfully',
            'amount' => $amount,
            'method' => 'neteller',
            'tx_ref' => $tx_ref,
            'checkout_url' => $checkoutUrl,
            'payment_handle_token' => $paymentHandleToken
        ]);
    }

    public static function confirmNetellerPayment(int $user_id, array $input)
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
        $clientId = $keys['neteller']['client_id'] ?? '';
        $clientSecret = $keys['neteller']['client_secret'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            return Response::error('Neteller API credentials are not configured in .env', 400);
        }

        // Neteller payments are verified via webhook or gateway redirect callback
        return Response::error('Neteller payment has not yet been confirmed by the gateway.', 400);
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

    public static function handleNetellerWebhook()
    {
        $rawPayload = file_get_contents("php://input");
        $event = json_decode($rawPayload, true);

        if (!$event) {
            Response::error('Invalid webhook payload', 400);
        }

        $tx_ref = $event['merchantRefNum'] ?? ($event['data']['merchantRefNum'] ?? null);
        $status = strtoupper($event['status'] ?? ($event['data']['status'] ?? ''));

        if ($tx_ref && in_array($status, ['COMPLETED', 'PAID', 'SUCCESS', 'PAYABLE'])) {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, user_id, account, amount, status FROM payments WHERE tx_ref = ? LIMIT 1");
            $stmt->bind_param("s", $tx_ref);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($payment && $payment['status'] !== 'success') {
                $amount = isset($event['amount']) ? ($event['amount'] / 100) : $payment['amount'];
                self::fulfillPayment($payment, floatval($amount), $tx_ref);
            }
        }

        Response::success('Neteller webhook processed successfully');
    }
}
