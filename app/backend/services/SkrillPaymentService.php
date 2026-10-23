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

class SkrillPaymentService
{
    public static function createSkrillPayment(int $user_id, array $input)
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

        $method = strtolower($input['method'] ?? 'skrill');
        $isOneTap = ($method === 'skrill1tap');
        $tx_ref = uniqid($isOneTap ? "tx_skrill1tap_" : "tx_skrill_");
        $date = gmdate('Y-m-d H:i:s');
        $paymentType = $isOneTap ? 'Skrill 1-Tap' : 'Skrill';

        $keys = require(__DIR__ . '/../config/keys.php');
        $merchantEmail = $keys['skrill']['merchant_email'] ?? '';

        if (empty($merchantEmail)) {
            return Response::error('Skrill API credentials are not configured. Please add SKRILL_MERCHANT_EMAIL to .env', 400);
        }

        $siteUrl = $keys['platform']['url'] ?? 'http://localhost';
        $statusUrl = $siteUrl . '/routes/skrillWebhook.php';
        $returnUrl = $siteUrl . '/payment/callback?method=' . $method . '&ref=' . $tx_ref;
        $cancelUrl = $siteUrl . '/deposit-usd';

        // Official Skrill Quick Checkout parameters
        $formFields = [
            'pay_to_email' => $merchantEmail,
            'recipient_description' => $keys['platform']['name'] ?? 'Traditi',
            'transaction_id' => $tx_ref,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'status_url' => $statusUrl,
            'language' => 'EN',
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'USD',
            'detail1_description' => 'Account ID:',
            'detail1_text' => $account_id,
            'confirmation_note' => 'Deposit to your trading balance.'
        ];

        if ($isOneTap) {
            $formFields['rec_payment_type'] = 'ondemand';
            $formFields['rec_status_url'] = $statusUrl;
            $formFields['ondemand_max_amount'] = 10000;
        }

        $checkoutUrl = 'https://pay.skrill.com/?' . http_build_query($formFields);

        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, payment_type, date)
                                VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)");
        if (!$stmt) {
            return Response::error('Skrill payment initiation failed.', 500);
        }

        $stmt->bind_param("isdssss", $user_id, $account_id, $amount, $tx_ref, $method, $paymentType, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not create Skrill payment record.', 500);
        }
        $stmt->close();

        return Response::success([
            'message' => ($isOneTap ? 'Skrill 1-Tap' : 'Skrill') . ' payment initiated successfully',
            'amount' => $amount,
            'method' => $method,
            'tx_ref' => $tx_ref,
            'checkout_url' => $checkoutUrl,
            'form_fields' => $formFields
        ]);
    }

    public static function confirmSkrillPayment(int $user_id, array $input)
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
        $merchantEmail = $keys['skrill']['merchant_email'] ?? '';

        if (empty($merchantEmail)) {
            return Response::error('Skrill API credentials are not configured in .env', 400);
        }

        // Skrill payments are securely verified and updated via the Skrill Webhook (status_url)
        return Response::error('Skrill payment has not yet been confirmed by the gateway.', 400);
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

    public static function handleSkrillWebhook()
    {
        $keys = require(__DIR__ . '/../config/keys.php');
        $secretWord = $keys['skrill']['secret_word'] ?? '';

        $merchant_id = $_POST['merchant_id'] ?? '';
        $transaction_id = $_POST['transaction_id'] ?? '';
        $mb_amount = $_POST['mb_amount'] ?? '';
        $mb_currency = $_POST['mb_currency'] ?? '';
        $status = $_POST['status'] ?? '';
        $md5sig = $_POST['md5sig'] ?? '';

        // Official Skrill MD5 Signature verification:
        // strtoupper(md5($merchant_id . $transaction_id . strtoupper(md5($secret_word)) . $mb_amount . $mb_currency . $status))
        if (!empty($secretWord) && !empty($md5sig)) {
            $computed = strtoupper(md5($merchant_id . $transaction_id . strtoupper(md5($secretWord)) . $mb_amount . $mb_currency . $status));
            if ($computed !== strtoupper($md5sig)) {
                http_response_code(400);
                echo "INVALID SIGNATURE";
                exit;
            }
        }

        // Status 2 is "Processed / Successful"
        if ($status == '2') {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, user_id, account, amount, status FROM payments WHERE tx_ref = ? LIMIT 1");
            $stmt->bind_param("s", $transaction_id);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($payment && $payment['status'] !== 'success') {
                $actualAmount = !empty($mb_amount) ? floatval($mb_amount) : $payment['amount'];
                self::fulfillPayment($payment, $actualAmount, $transaction_id);
            }
        }

        http_response_code(200);
        echo "OK";
        exit;
    }
}
