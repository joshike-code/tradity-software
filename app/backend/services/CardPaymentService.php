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

class CardPaymentService
{
    public static function createCardPayment(int $user_id, array $input)
    {
        $amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        if ($amount <= 0) {
            return Response::error('Invalid amount provided.', 400);
        }

        $keys = require(__DIR__ . '/../config/keys.php');
        $stripeSecret = $keys['stripe']['secret_key'] ?? '';
        $stripePublic = $keys['stripe']['public_key'] ?? '';

        if (empty($stripeSecret)) {
            return Response::error('Credit/Debit Card (Stripe) API keys are not configured. Please add STRIPE_SECRET_KEY to .env', 400);
        }

        $account = TradeAccountService::getUserCurrentAccount($user_id);
        if (!$account) {
            return Response::error('User trade account not found.', 404);
        }
        $account_id = $account['id_hash'];

        $conn = Database::getConnection();
        $tx_ref = uniqid("tx_card_");
        $date = gmdate('Y-m-d H:i:s');

        // Fetch user email
        $userStmt = $conn->prepare("SELECT email, fname, lname FROM users WHERE id = ? LIMIT 1");
        $userStmt->bind_param("i", $user_id);
        $userStmt->execute();
        $userRow = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();
        $customerEmail = $input['email'] ?? ($userRow['email'] ?? 'trader@traditi.com');

        $siteUrl = $keys['platform']['url'] ?? 'http://localhost';
        $successUrl = $siteUrl . "/payment/callback?method=card&session_id={CHECKOUT_SESSION_ID}&ref=" . $tx_ref;
        $cancelUrl = $siteUrl . "/deposit-usd";

        $params = [
            'payment_method_types[]' => 'card',
            'customer_email' => $customerEmail,
            'client_reference_id' => $tx_ref,
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => round($amount * 100),
            'line_items[0][price_data][product_data][name]' => 'Trading Account Deposit ($' . $amount . ' USD)',
            'line_items[0][quantity]' => 1,
            'metadata[user_id]' => $user_id,
            'metadata[account_id]' => $account_id,
            'metadata[tx_ref]' => $tx_ref
        ];

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $stripeSecret,
                "Content-Type: application/x-www-form-urlencoded"
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $stripeRes = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $sessionData = $stripeRes ? json_decode($stripeRes, true) : null;
        if ($httpCode >= 400 || empty($sessionData['url'])) {
            $msg = $sessionData['error']['message'] ?? 'Failed to initialize Stripe checkout session';
            return Response::error('Stripe API error: ' . $msg, 400);
        }

        $checkoutUrl = $sessionData['url'];
        $sessionId = $sessionData['id'] ?? null;

        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, payment_type, date)
                                VALUES (?, ?, ?, ?, 'card', 'pending', 'Credit / Debit Card', ?)");
        if (!$stmt) {
            return Response::error('Card payment initiation failed.', 500);
        }

        $stmt->bind_param("isdss", $user_id, $account_id, $amount, $tx_ref, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not record card payment.', 500);
        }
        $stmt->close();

        return Response::success([
            'message' => 'Card payment initiated successfully',
            'amount' => $amount,
            'method' => 'card',
            'tx_ref' => $tx_ref,
            'public_key' => $stripePublic,
            'checkout_url' => $checkoutUrl,
            'session_id' => $sessionId
        ]);
    }

    public static function confirmCardPayment(int $user_id, array $input)
    {
        $conn = Database::getConnection();
        $tx_ref = $input['tx_ref'] ?? null;
        $sessionId = $input['session_id'] ?? null;

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
        $stripeSecret = $keys['stripe']['secret_key'] ?? '';

        if (empty($stripeSecret)) {
            return Response::error('Stripe secret key not configured in .env', 400);
        }

        $verified = false;
        $verifiedAmount = $payment['amount'];

        if (!empty($sessionId)) {
            $ch = curl_init("https://api.stripe.com/v1/checkout/sessions/" . urlencode($sessionId));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $stripeSecret
                ],
                CURLOPT_TIMEOUT => 30
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);

            if ($raw) {
                $session = json_decode($raw, true);
                if (isset($session['payment_status']) && $session['payment_status'] === 'paid') {
                    $verified = true;
                    if (isset($session['amount_total'])) {
                        $verifiedAmount = $session['amount_total'] / 100;
                    }
                }
            }
        }

        if (!$verified) {
            return Response::error('Card payment has not been confirmed as paid on Stripe.', 400);
        }

        self::fulfillPayment($payment, $verifiedAmount, $tx_ref);

        $account = TradeAccountService::getAccountById($payment['user_id'], $payment['account']);
        return Response::success([
            'message' => 'Card payment confirmed and balance updated',
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

    public static function handleStripeWebhook()
    {
        $rawPayload = file_get_contents("php://input");
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $keys = require(__DIR__ . '/../config/keys.php');
        $webhookSecret = $keys['stripe']['webhook_secret'] ?? '';

        if (empty($webhookSecret)) {
            http_response_code(500);
            echo json_encode(['error' => 'Stripe webhook secret not configured']);
            exit;
        }

        // Official Stripe-Signature verification
        if (!empty($sigHeader)) {
            $items = explode(',', $sigHeader);
            $timestamp = null;
            $signatures = [];
            foreach ($items as $item) {
                $parts = explode('=', trim($item), 2);
                if (count($parts) === 2) {
                    if ($parts[0] === 't') $timestamp = $parts[1];
                    if ($parts[0] === 'v1') $signatures[] = $parts[1];
                }
            }

            if (!$timestamp || empty($signatures)) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid signature format']);
                exit;
            }

            $signedPayload = $timestamp . '.' . $rawPayload;
            $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

            $valid = false;
            foreach ($signatures as $sig) {
                if (hash_equals($expectedSignature, $sig)) {
                    $valid = true;
                    break;
                }
            }

            if (!$valid) {
                http_response_code(401);
                echo json_encode(['error' => 'Signature mismatch']);
                exit;
            }
        }

        $event = json_decode($rawPayload, true);
        if (!$event || !isset($event['type'])) {
            Response::error('Invalid event payload', 400);
        }

        // Process successful checkout session or payment intent
        if ($event['type'] === 'checkout.session.completed') {
            $session = $event['data']['object'] ?? [];
            $tx_ref = $session['client_reference_id'] ?? ($session['metadata']['tx_ref'] ?? null);
            $amount = isset($session['amount_total']) ? ($session['amount_total'] / 100) : null;

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

        Response::success('Stripe webhook processed successfully');
    }
}
