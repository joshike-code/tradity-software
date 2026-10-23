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

class UpiPaymentService
{
    public static function createUpiPayment(int $user_id, array $input)
    {
        $amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        if ($amount <= 0) {
            return Response::error('Invalid amount provided.', 400);
        }

        $account = TradeAccountService::getUserCurrentAccount($user_id);
        if (!$account) {
            return Response::error('User trade account not found.', 404);
        }
        $account_id = $account['id_hash'];

        $keys = require(__DIR__ . '/../config/keys.php');
        $platformName = $keys['platform']['name'] ?? '';
        $keyId = !empty($keys['razorpay']['key_id']) ? trim($keys['razorpay']['key_id']) : '';
        $keySecret = !empty($keys['razorpay']['key_secret']) ? trim($keys['razorpay']['key_secret']) : '';
        $merchantUpi = !empty($keys['razorpay']['merchant_upi']) ? trim($keys['razorpay']['merchant_upi']) : '';

        $conn = Database::getConnection();
        $tx_ref = uniqid("tx_");
        $date = gmdate('Y-m-d H:i:s');
        $upi_id = $input['upi_id'] ?? '';

        // Standard USD to INR rate for UPI payments
        $inrAmount = round($amount * 85.0, 2);

        $orderId = null;
        $checkoutUrl = null;
        $upiIntent = null;

        // MODE 1: Gateway Mode (Razorpay API + Webhooks)
        // If RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET are provided, it MUST use the Gateway.
        // If it fails, it fails (no fallback to manual QR).
        if (!empty($keyId) && !empty($keySecret)) {
            $ch = curl_init('https://api.razorpay.com/v1/payment_links');
            $callbackUrl = ($keys['platform']['url'] ?? 'http://localhost') . '/payment/callback?method=upi&ref=' . $tx_ref;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'amount' => round($inrAmount * 100),
                    'currency' => 'INR',
                    'description' => 'Deposit to Trade Account ' . $account_id,
                    'reference_id' => $tx_ref,
                    'notes' => [
                        'user_id' => $user_id,
                        'account_id' => $account_id,
                        'upi_id' => $upi_id,
                        'tx_ref' => $tx_ref
                    ],
                    'callback_url' => $callbackUrl,
                    'callback_method' => 'get'
                ]),
                CURLOPT_USERPWD => $keyId . ":" . $keySecret,
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json"
                ],
                CURLOPT_TIMEOUT => 15
            ]);
            $raw = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!empty($curlErr)) {
                return Response::error('Razorpay Gateway connection error: ' . $curlErr, 502);
            }

            $orderData = $raw ? json_decode($raw, true) : null;
            if ($httpCode >= 400 || empty($orderData['short_url'])) {
                $errorDesc = $orderData['error']['description'] ?? ($orderData['error']['message'] ?? 'Failed to create payment link on Razorpay Gateway.');
                return Response::error('Razorpay Gateway error: ' . $errorDesc, 400);
            }

            $orderId = $orderData['id'] ?? null;
            $checkoutUrl = $orderData['short_url'] ?? null;
        }
        // MODE 2: Manual Direct UPI QR Mode
        // If only RAZORPAY_MERCHANT_UPI is filled (no gateway keys provided), it generates the QR portal.
        else if (!empty($merchantUpi)) {
            $upiIntent = "upi://pay?pa=" . urlencode($merchantUpi) . "&pn=" . urlencode($platformName) . "&tr=" . urlencode($tx_ref) . "&am=" . $inrAmount . "&cu=INR&tn=" . urlencode("Deposit Account " . $account_id);

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $backendHost = $keys['system']['host_link'] ?? '/app/backend/';
            $backendHost = '/' . trim($backendHost, '/') . '/';
            $checkoutUrl = "{$scheme}://{$host}{$backendHost}api/upi-checkout?ref=" . urlencode($tx_ref);
        }
        // MODE 3: Neither configured
        else {
            return Response::error('UPI payments are currently unavailable. Gateway credentials or RAZORPAY_MERCHANT_UPI must be configured in .env', 400);
        }

        $payment_type = !empty($upi_id) ? $upi_id : 'UPI';
        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, payment_type, upi_id, date)
                                VALUES (?, ?, ?, ?, 'upi', 'pending', ?, ?, ?)");
        if (!$stmt) {
            return Response::error('UPI payment initiation failed.', 500);
        }

        $stmt->bind_param("isdssss", $user_id, $account_id, $amount, $tx_ref, $payment_type, $upi_id, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not create UPI payment.', 500);
        }
        $stmt->close();

        NotificationService::sendDepositPendingNotification($user_id, $amount, 'UPI');

        return Response::success([
            'message' => 'UPI payment initiated successfully',
            'amount' => $amount,
            'inr_amount' => $inrAmount,
            'method' => 'upi',
            'tx_ref' => $tx_ref,
            'order_id' => $orderId,
            'upi_id' => $upi_id,
            'merchant_upi' => $merchantUpi,
            'checkout_url' => $checkoutUrl,
            'upi_intent' => $upiIntent
        ]);
    }

    /**
     * Admin approves or declines a UPI deposit
     */
    public static function updateUpiPaymentStatus($id, array $input)
    {
        $conn = Database::getConnection();

        $status = $input['status'] ?? null;
        $amount = isset($input['amount']) ? floatval($input['amount']) : null;

        if ($status !== 'success' && $status !== 'failed') {
            Response::error('Invalid status value. Must be success or failed', 400);
        }

        $stmt = $conn->prepare("SELECT id, user_id, tx_ref, account, amount, status FROM payments WHERE id = ? AND method = 'upi'");
        if (!$stmt) {
            Response::error('Invalid payment selection', 400);
        }
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payment) {
            Response::error('UPI payment record not found', 404);
        }

        if ($amount === null || $amount <= 0) {
            $amount = floatval($payment['amount']);
        }

        $stmt = $conn->prepare("UPDATE payments SET status = ?, amount = ? WHERE id = ?");
        $stmt->bind_param("sds", $status, $amount, $id);
        if (!$stmt->execute()) {
            $stmt->close();
            Response::error('Status update failed', 500);
        }
        $stmt->close();

        if ($status === 'success') {
            self::fulfillPayment($payment, $amount, $payment['tx_ref']);
        } else {
            NotificationService::sendDepositRejectedNotification($payment['user_id'], $amount, 'Rejected by admin');
        }

        Response::success([
            'message' => 'UPI payment status updated successfully',
            'status' => $status,
            'amount' => $amount,
            'id' => $id
        ]);
    }

    public static function confirmUpiPayment(int $user_id, array $input)
    {
        $conn = Database::getConnection();
        $tx_ref = $input['tx_ref'] ?? null;
        $paymentId = $input['razorpay_payment_id'] ?? ($input['payment_id'] ?? null);

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
        $keyId = $keys['razorpay']['key_id'] ?? '';
        $keySecret = $keys['razorpay']['key_secret'] ?? '';

        if (empty($keyId) || empty($keySecret)) {
            return Response::error('Razorpay API keys are not configured in .env', 400);
        }

        if (empty($paymentId)) {
            return Response::error('Please provide razorpay_payment_id to verify UPI payment.', 400);
        }

        $verified = false;
        $verifiedAmount = $payment['amount'];

        // Official verification via Razorpay Payment Fetch API: GET https://api.razorpay.com/v1/payments/{payment_id}
        $ch = curl_init("https://api.razorpay.com/v1/payments/" . urlencode($paymentId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $keyId . ":" . $keySecret,
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw) {
            $payData = json_decode($raw, true);
            if (isset($payData['status']) && in_array($payData['status'], ['captured', 'authorized'])) {
                $verified = true;
                if (isset($payData['amount'])) {
                    $verifiedAmount = $payData['amount'] / 100;
                }
            }
        }

        if (!$verified) {
            return Response::error('UPI payment verification failed on Razorpay gateway', 400);
        }

        self::fulfillPayment($payment, $verifiedAmount, $tx_ref);

        $account = TradeAccountService::getAccountById($payment['user_id'], $payment['account']);
        return Response::success([
            'message' => 'UPI payment confirmed and balance updated',
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

            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public static function handleRazorpayWebhook()
    {
        $rawPayload = file_get_contents("php://input");
        $signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
        $keys = require(__DIR__ . '/../config/keys.php');
        $secret = $keys['razorpay']['webhook_secret'] ?? '';

        // Validate HMAC-SHA256 signature
        if (!empty($secret) && !empty($signature)) {
            $expectedSignature = hash_hmac('sha256', $rawPayload, $secret);
            if (!hash_equals($expectedSignature, $signature)) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid signature']);
                exit;
            }
        }

        $event = json_decode($rawPayload, true);
        if (!$event || !isset($event['event'])) {
            Response::error('Invalid event payload', 400);
        }

        if ($event['event'] === 'order.paid' || $event['event'] === 'payment.captured') {
            $entity = $event['payload']['payment']['entity'] ?? ($event['payload']['order']['entity'] ?? []);
            $tx_ref = $entity['notes']['tx_ref'] ?? ($entity['receipt'] ?? null);
            $amount = isset($entity['amount']) ? ($entity['amount'] / 100) : null;

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

        Response::success('Razorpay UPI webhook processed successfully');
    }
}
