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

class PolPaymentService
{
    public static function createPolPayment(int $user_id, array $input)
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

        $tx_ref = uniqid("tx_pol_");
        $date = gmdate('Y-m-d H:i:s');

        $keys = require(__DIR__ . '/../config/keys.php');
        $depositAddress = $keys['polygon']['deposit_address'] ?? '';

        if (empty($depositAddress)) {
            return Response::error('Polygon (POL) deposit address is not configured. Please add POLYGON_DEPOSIT_ADDRESS to .env', 400);
        }

        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, address, coin, date)
                                VALUES (?, ?, ?, ?, 'pol', 'pending', ?, 'POL', ?)");
        if (!$stmt) {
            return Response::error('POL payment initiation failed.', 500);
        }

        $stmt->bind_param("isdsss", $user_id, $account_id, $amount, $tx_ref, $depositAddress, $date);
        if (!$stmt->execute()) {
            return Response::error('Could not create POL payment.', 500);
        }
        $stmt->close();

        return Response::success([
            'message' => 'POL payment initiated successfully',
            'amount' => $amount,
            'method' => 'pol',
            'tx_ref' => $tx_ref,
            'deposit_address' => $depositAddress,
            'network' => 'Polygon Mainnet (Chain ID 137)'
        ]);
    }

    public static function confirmPolPayment(int $user_id, array $input)
    {
        $conn = Database::getConnection();
        $tx_ref = $input['tx_ref'] ?? null;
        $tx_hash = trim($input['tx_hash'] ?? ($input['hash'] ?? ''));

        if (!$tx_ref) {
            return Response::error('Missing transaction reference', 400);
        }

        if (empty($tx_hash)) {
            return Response::error('Please provide the transaction hash to verify your POL deposit.', 400);
        }

        $stmt = $conn->prepare("SELECT id, user_id, account, amount, status, tx_ref, address FROM payments WHERE tx_ref = ? AND user_id = ? LIMIT 1");
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
        $rpcUrl = $keys['polygon']['rpc_url'] ?? 'https://polygon-rpc.com';
        $depositAddress = strtolower($keys['polygon']['deposit_address'] ?? '');

        $verified = false;

        // On-Chain Verification via Polygon JSON-RPC
        // Check for replay attacks: tx_hash must not be used on any other completed payment
        $replayStmt = $conn->prepare("SELECT id FROM payments WHERE address = ? AND status = 'success' LIMIT 1");
        $replayStmt->bind_param("s", $tx_hash);
        $replayStmt->execute();
        if ($replayStmt->get_result()->num_rows > 0) {
            $replayStmt->close();
            return Response::error('This transaction hash has already been credited.', 400);
        }
        $replayStmt->close();

        // Official JSON-RPC call: eth_getTransactionReceipt
        $ch = curl_init($rpcUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'jsonrpc' => '2.0',
                'method' => 'eth_getTransactionReceipt',
                'params' => [$tx_hash],
                'id' => 1
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw) {
            $rpcRes = json_decode($raw, true);
            $receipt = $rpcRes['result'] ?? null;
            if ($receipt) {
                $status = $receipt['status'] ?? '';
                $to = strtolower($receipt['to'] ?? '');
                // Verify status is 0x1 (success) and recipient is our deposit address
                if ($status === '0x1' && ($to === $depositAddress || empty($depositAddress))) {
                    $verified = true;
                }
            }
        }

        if (!$verified) {
            return Response::error('Blockchain transaction could not be verified on Polygon network.', 400);
        }

        self::fulfillPayment($payment, floatval($payment['amount']), $tx_ref, $tx_hash);

        $account = TradeAccountService::getAccountById($payment['user_id'], $payment['account']);
        return Response::success([
            'message' => 'POL payment confirmed and balance updated',
            'status' => 'success',
            'amount' => $payment['amount'],
            'balance' => $account['balance'],
            'tx_ref' => $tx_ref
        ]);
    }

    public static function fulfillPayment(array $payment, float $amount, string $tx_ref, string $tx_hash = '')
    {
        $conn = Database::getConnection();
        $date = gmdate('Y-m-d H:i:s');
        $status = 'success';
        $storedHash = !empty($tx_hash) ? $tx_hash : $payment['address'];

        $conn->begin_transaction();
        try {
            $upStmt = $conn->prepare("UPDATE payments SET status = ?, amount = ?, address = ?, date = ? WHERE id = ?");
            $upStmt->bind_param("sdssi", $status, $amount, $storedHash, $date, $payment['id']);
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
