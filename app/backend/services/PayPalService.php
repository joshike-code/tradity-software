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

class PayPalService
{
    private static function getBaseUrl(array $keys): string
    {
        $env = strtolower($keys['paypal']['environment'] ?? 'sandbox');
        return ($env === 'live' || $env === 'production')
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public static function getAccessToken(): ?string
    {
        $keys = require(__DIR__ . '/../config/keys.php');
        $clientId = !empty($keys['paypal']['client_id']) ? trim($keys['paypal']['client_id']) : '';
        $clientSecret = !empty($keys['paypal']['client_secret']) ? trim($keys['paypal']['client_secret']) : '';

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        $baseUrl = self::getBaseUrl($keys);
        $ch = curl_init($baseUrl . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_USERPWD => $clientId . ':' . $clientSecret,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: en_US',
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 20
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || !$raw) {
            return null;
        }

        $data = json_decode($raw, true);
        return $data['access_token'] ?? null;
    }

    public static function createPayPalPayment(int $user_id, array $input)
    {
        $amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        if ($amount <= 0) {
            return Response::error('Invalid amount provided.', 400);
        }

        $keys = require(__DIR__ . '/../config/keys.php');
        $platformName = $keys['platform']['name'] ?? 'Traditi';
        $platformUrl = $keys['platform']['url'] ?? 'http://localhost';

        $token = self::getAccessToken();
        if (!$token) {
            return Response::error('PayPal API keys are not configured or invalid in .env', 400);
        }

        $account = TradeAccountService::getUserCurrentAccount($user_id);
        if (!$account) {
            return Response::error('User trade account not found.', 404);
        }
        $account_id = $account['id_hash'];

        $conn = Database::getConnection();
        $tx_ref = uniqid("tx_paypal_");
        $date = gmdate('Y-m-d H:i:s');
        $baseUrl = self::getBaseUrl($keys);

        $returnUrl = $platformUrl . '/payment/callback?method=paypal&ref=' . urlencode($tx_ref);
        $cancelUrl = $platformUrl . '/payment/callback?method=paypal&ref=' . urlencode($tx_ref) . '&status=cancel';

        $orderPayload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $tx_ref,
                    'custom_id' => $tx_ref,
                    'description' => 'Deposit to Trade Account ' . $account_id,
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($amount, 2, '.', '')
                    ]
                ]
            ],
            'application_context' => [
                'brand_name' => $platformName,
                'locale' => 'en-US',
                'landing_page' => 'BILLING',
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl
            ]
        ];

        $ch = curl_init($baseUrl . '/v2/checkout/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($orderPayload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'PayPal-Request-Id: ' . $tx_ref
            ],
            CURLOPT_TIMEOUT => 25
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $orderData = $raw ? json_decode($raw, true) : null;
        if ($httpCode >= 400 || empty($orderData['id'])) {
            $msg = $orderData['message'] ?? ($orderData['details'][0]['description'] ?? 'Failed to create PayPal order');
            return Response::error('PayPal API error: ' . $msg, 400);
        }

        $orderId = $orderData['id'];
        $checkoutUrl = null;

        if (!empty($orderData['links'])) {
            foreach ($orderData['links'] as $link) {
                if ($link['rel'] === 'approve' || $link['rel'] === 'payer-action') {
                    $checkoutUrl = $link['href'];
                    break;
                }
            }
        }

        if (empty($checkoutUrl)) {
            return Response::error('PayPal approval URL not found in API response', 500);
        }

        // Record pending payment in database
        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, payment_id, method, status, payment_type, date)
                                VALUES (?, ?, ?, ?, ?, 'paypal', 'pending', 'PayPal', ?)");
        if (!$stmt) {
            return Response::error('Failed to create payment record', 500);
        }
        $stmt->bind_param("isdsss", $user_id, $account_id, $amount, $tx_ref, $orderId, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not save payment to database', 500);
        }
        $stmt->close();

        NotificationService::sendDepositPendingNotification($user_id, $amount, 'PayPal');

        return Response::success([
            'message' => 'PayPal payment initialized successfully',
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'paypal',
            'tx_ref' => $tx_ref,
            'order_id' => $orderId,
            'checkout_url' => $checkoutUrl,
            'authorization_url' => $checkoutUrl
        ]);
    }

    public static function confirmPayPalPayment(int $user_id, array $input)
    {
        $conn = Database::getConnection();
        $tx_ref = $input['tx_ref'] ?? null;
        $orderId = $input['order_id'] ?? null;

        if (!$tx_ref && !$orderId) {
            return Response::error('Missing transaction reference or PayPal order ID', 400);
        }

        $stmt = $conn->prepare("SELECT id, user_id, account, amount, status, tx_ref, payment_id FROM payments WHERE (tx_ref = ? OR payment_id = ?) AND user_id = ? LIMIT 1");
        $stmt->bind_param("ssi", $tx_ref, $orderId, $user_id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payment) {
            return Response::error('Payment record not found', 404);
        }

        if ($payment['status'] === 'success') {
            return Response::success([
                'message' => 'Payment already completed',
                'tx_ref' => $payment['tx_ref'],
                'status' => 'success'
            ]);
        }

        $token = self::getAccessToken();
        if (!$token) {
            return Response::error('PayPal credentials invalid or missing', 400);
        }

        $keys = require(__DIR__ . '/../config/keys.php');
        $baseUrl = self::getBaseUrl($keys);
        $paypalOrderId = !empty($payment['payment_id']) ? $payment['payment_id'] : $orderId;

        // Capture PayPal Order: POST /v2/checkout/orders/{id}/capture
        $ch = curl_init($baseUrl . '/v2/checkout/orders/' . urlencode($paypalOrderId) . '/capture');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $captureData = $raw ? json_decode($raw, true) : null;
        $status = $captureData['status'] ?? '';

        // If order already captured, check order details
        if ($httpCode >= 400 && strpos($raw, 'ORDER_ALREADY_CAPTURED') !== false) {
            $status = 'COMPLETED';
        }

        if ($status !== 'COMPLETED') {
            $msg = $captureData['message'] ?? ($captureData['details'][0]['description'] ?? 'PayPal payment has not been captured yet.');
            return Response::error('PayPal verification error: ' . $msg, 400);
        }

        $verifiedAmount = floatval($payment['amount']);
        if (!empty($captureData['purchase_units'][0]['payments']['captures'][0]['amount']['value'])) {
            $verifiedAmount = floatval($captureData['purchase_units'][0]['payments']['captures'][0]['amount']['value']);
        }

        self::fulfillPayment($payment, $verifiedAmount, $payment['tx_ref']);

        $account = TradeAccountService::getAccountById($payment['user_id'], $payment['account']);
        return Response::success([
            'message' => 'PayPal payment captured successfully and balance credited',
            'status' => 'success',
            'amount' => $verifiedAmount,
            'balance' => $account['balance'],
            'tx_ref' => $payment['tx_ref']
        ]);
    }

    /**
     * Automated PayPal Payout API (Method 2)
     * Triggered when an admin approves a PayPal withdrawal
     */
    public static function initiatePayout(array $pending_withdrawal): array
    {
        $token = self::getAccessToken();
        if (!$token) {
            return [
                'success' => false,
                'message' => 'PayPal API keys are not configured or invalid in .env'
            ];
        }

        $keys = require(__DIR__ . '/../config/keys.php');
        $platformName = $keys['platform']['name'] ?? 'Traditi';
        $baseUrl = self::getBaseUrl($keys);

        $receiverEmail = trim($pending_withdrawal['account_number'] ?? ($pending_withdrawal['address'] ?? ''));
        if (empty($receiverEmail) || !filter_var($receiverEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Invalid or missing PayPal email address: ' . $receiverEmail
            ];
        }

        $amount = floatval($pending_withdrawal['amount']);
        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid withdrawal amount'
            ];
        }

        $tx_ref = $pending_withdrawal['payment_ref'] ?? uniqid("tx_payout_");

        $payoutPayload = [
            'sender_batch_header' => [
                'sender_batch_id' => 'batch_' . $tx_ref,
                'email_subject' => 'Payout from ' . $platformName,
                'email_message' => 'You have received a payout from your ' . $platformName . ' trading account.'
            ],
            'items' => [
                [
                    'recipient_type' => 'EMAIL',
                    'amount' => [
                        'value' => number_format($amount, 2, '.', ''),
                        'currency' => 'USD'
                    ],
                    'receiver' => $receiverEmail,
                    'note' => 'Trading Account Withdrawal #' . $tx_ref,
                    'sender_item_id' => $tx_ref
                ]
            ]
        ];

        $ch = curl_init($baseUrl . '/v1/payments/payouts');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payoutPayload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resData = $raw ? json_decode($raw, true) : null;
        if ($httpCode >= 400 || empty($resData['batch_header'])) {
            $err = $resData['message'] ?? ($resData['details'][0]['issue'] ?? 'Failed to execute PayPal payout');
            return [
                'success' => false,
                'message' => $err
            ];
        }

        $batchStatus = strtoupper($resData['batch_header']['batch_status'] ?? '');
        $batchId = $resData['batch_header']['payout_batch_id'] ?? null;

        if (in_array($batchStatus, ['PENDING', 'SUCCESS', 'PROCESSING'])) {
            return [
                'success' => true,
                'message' => 'PayPal payout initiated successfully (Status: ' . $batchStatus . ')',
                'batch_id' => $batchId,
                'batch_status' => $batchStatus
            ];
        }

        return [
            'success' => false,
            'message' => 'PayPal payout batch status: ' . $batchStatus
        ];
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

    public static function handlePayPalWebhook()
    {
        $rawPayload = file_get_contents("php://input");
        $event = json_decode($rawPayload, true);

        if (!$event || !isset($event['event_type'])) {
            Response::error('Invalid PayPal webhook payload', 400);
        }

        $eventType = $event['event_type'];
        $resource = $event['resource'] ?? [];

        // Handle Deposit Capture: PAYMENT.CAPTURE.COMPLETED
        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            $tx_ref = $resource['custom_id'] ?? ($resource['invoice_id'] ?? null);
            $amount = isset($resource['amount']['value']) ? floatval($resource['amount']['value']) : null;

            if ($tx_ref) {
                $conn = Database::getConnection();
                $stmt = $conn->prepare("SELECT id, user_id, account, amount, status FROM payments WHERE tx_ref = ? LIMIT 1");
                $stmt->bind_param("s", $tx_ref);
                $stmt->execute();
                $payment = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($payment && $payment['status'] !== 'success') {
                    $actualAmount = $amount ?? floatval($payment['amount']);
                    self::fulfillPayment($payment, $actualAmount, $tx_ref);
                }
            }
        }

        Response::success('PayPal webhook processed successfully');
    }
}
