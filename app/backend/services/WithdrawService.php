<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/TransactionService.php';
require_once __DIR__ . '/../services/NotificationService.php';

class WithdrawService
{

    public static function getPendingWithdrawals() {
        $conn = Database::getConnection();
        $stmt = $conn->prepare("
            SELECT 
                w.id AS withdrawal_id, w.payment_ref, w.amount, w.coin, w.bank_name, w.branch, w.account_number, w.date,
                u.id AS user_id, u.fname, u.lname, u.email
            FROM 
                pending_withdrawals w
            INNER JOIN 
                users u ON w.user_id = u.id
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $pending_withdrawals = [];
        
        while ($row = $result->fetch_assoc()) {
            $pending_withdrawals[] = $row;
        }

        // Total pending withdrawals count
        $stmtTotal = $conn->query("SELECT COUNT(*) AS pending_withdrawals_count FROM pending_withdrawals");
        $pending_withdrawals_count = intval($stmtTotal->fetch_assoc()['pending_withdrawals_count']);

        Response::success([
            'pending_withdrawals'     => $pending_withdrawals,
            'pending_withdrawals_count' => $pending_withdrawals_count
        ]);
    }

    public static function getPendingWithdrawalById($id) {
        $conn = Database::getConnection();
        $stmt = $conn->prepare("
            SELECT 
                w.id AS withdrawal_id, w.payment_ref, w.amount, w.coin, w.network, w.address, 
                w.bank_name, w.account_name, w.account_number, w.ifsc_code, w.branch, w.upi_id, w.date,
                u.id AS user_id, u.fname, u.lname, u.email
            FROM 
                pending_withdrawals w
            INNER JOIN 
                users u ON w.user_id = u.id
        WHERE w.id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        if (!$stmt) {
            Response::error('Could not get pending withdrawals', 500);
        }

        $result = $stmt->get_result()->fetch_assoc();

        if (!$result) {
            Response::error('Pending withdrawals not found', 404);
        }

        return $result;
    }

    public static function createWithdrawal(int $user_id, array $input, string $method) {
        $conn = Database::getConnection();
    
        $amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        $bank_name = $input['bank_name'] ?? null;
        $account_number = $input['account_number'] ?? null;
        $account_name = $input['account_name'] ?? null;
        $ifsc_code = $input['ifsc_code'] ?? null;
        $branch = $input['branch'] ?? null;
        $upi_id = $input['upi_id'] ?? null;
        $coin = $input['coin'] ?? null;
        $network = $input['network'] ?? null;
        $address = $input['address'] ?? null;

        if ($method === 'upi') {
            $keys = require __DIR__ . '/../config/keys.php';
            $keyId = !empty($keys['razorpay']['key_id']) ? trim($keys['razorpay']['key_id']) : '';
            $keySecret = !empty($keys['razorpay']['key_secret']) ? trim($keys['razorpay']['key_secret']) : '';
            $merchantUpi = !empty($keys['razorpay']['merchant_upi']) ? trim($keys['razorpay']['merchant_upi']) : '';
            $upiAvailable = (!empty($keyId) && !empty($keySecret)) || !empty($merchantUpi);
            if (!$upiAvailable) {
                Response::error('UPI withdrawals are currently unavailable. Merchant configuration is missing.', 400);
            }
            $bank_name = 'UPI';
            $account_number = $upi_id;
        }

        if ($method === 'paypal') {
            $keys = require __DIR__ . '/../config/keys.php';
            $paypalClientId = !empty($keys['paypal']['client_id']) ? trim($keys['paypal']['client_id']) : '';
            $paypalClientSecret = !empty($keys['paypal']['client_secret']) ? trim($keys['paypal']['client_secret']) : '';
            $paypalAvailable = (!empty($paypalClientId) && !empty($paypalClientSecret));
            if (!$paypalAvailable) {
                Response::error('PayPal withdrawals are currently unavailable. Merchant credentials are not configured.', 400);
            }
            $paypalEmail = trim($input['account_number'] ?? ($input['paypal_email'] ?? ''));
            if (empty($paypalEmail) || !filter_var($paypalEmail, FILTER_VALIDATE_EMAIL)) {
                Response::error('A valid PayPal email address is required for withdrawal.', 422);
            }
            $bank_name = 'PayPal';
            $branch = 'paypal';
            $account_number = $paypalEmail;
            $account_name = $input['account_name'] ?? 'PayPal User';
        }

        $tx_ref = uniqid("tx_");
        $date = gmdate('Y-m-d H:i:s');
    
        // Validate amount
        if ($amount <= 0) {
          Response::error('Invalid amount provided.', 400);
        }

        // Get user trade account
        $account = TradeAccountService::getUserCurrentAccount($user_id);
        if (!$account) {
            Response::error('Trading account not found.', 404);
        }
        $account_id = $account['id_hash'];

        // Check if pending withdrawals exists for trading account
        $check = $conn->prepare("SELECT account FROM pending_withdrawals WHERE account = ?");
        $check->bind_param("s", $account_id);
        $check->execute();
        $pending_withdrawal = $check->get_result()->fetch_assoc();
        $check->close();
        if($pending_withdrawal) {
            Response::error('Pending withdrawal exists for account', 400);
        };

        // Fetch user balance & Ensure the balance is a float and check if the user has enough balance
        $balance = (float) $account['balance'];
        if ($balance < $amount) {
            Response::error('Insufficient balance', 402);
        }

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("INSERT INTO pending_withdrawals (user_id, account, payment_ref, amount, coin, network, address, bank_name, account_name, account_number, ifsc_code, branch, upi_id, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Prepare failed');
            }
            $stmt->bind_param("sssdssssssssss", $user_id, $account_id, $tx_ref, $amount, $coin, $network, $address, $bank_name, $account_name, $account_number, $ifsc_code, $branch, $upi_id, $date);
            if (!$stmt->execute()) {
                throw new Exception('Failed to create pending withdrawal');
            }
            $stmt->close();

            // Update user balance 
            $update = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id_hash = ?");
            $update->bind_param("ds", $amount, $account['id_hash']);

            if (!$update->execute()) {
                throw new Exception('Failed to update balance');
            }
            $update->close();

            // Fetch updated user balance
            $balanceStmt = $conn->prepare("SELECT balance FROM accounts WHERE id = ?");
            $balanceStmt->bind_param("i", $account['id']);
            $balanceStmt->execute();
            $balanceResult = $balanceStmt->get_result();
            $updatedBalance = $balanceResult->fetch_assoc()['balance'] ?? null;
            $balanceStmt->close();

            // Log to payments table
            $insert = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, coin, address, bank_name, account_number, ifsc_code, branch, upi_id, type, status, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $payMethod = "withdrawal";
            $type = "debit";
            $status = "pending";
            $insert->bind_param("ssdssssssssssss", $user_id, $account_id, $amount, $tx_ref, $payMethod, $coin, $address, $bank_name, $account_number, $ifsc_code, $branch, $upi_id, $type, $status, $date);
            $insert->execute();
            $insert->close();

            // Commit the transaction
            $conn->commit();

            NotificationService::sendWithdrawalPendingNotification($user_id, $amount, $coin ?: ($bank_name ?: 'Bank Transfer'));

            // Fetch and return payment data
            $payments = PaymentService::getUserPayments($user_id);
            return Response::success($payments);
        } catch (Exception $e) {
            // Rollback the transaction in case of an error
            $conn->rollback();
            error_log("ERROR " . $e);
            Response::error('Something went wrong. Please try again', 500);
        }
    }

    public static function approveWithdrawal($id) {
        $conn = Database::getConnection();

        // Find pending payment
        $check = $conn->prepare("SELECT id, user_id, amount, account, payment_ref, bank_name, branch, account_number, address FROM pending_withdrawals WHERE id = ?");
        $check->bind_param("s", $id);
        if (!$check->execute()) {
            Response::error('Failed to fetch pending withdrawal', 500);
        }
        $pending_withdrawal = $check->get_result()->fetch_assoc();
        if(!$pending_withdrawal) {
            Response::error('Pending withdrawal does not exist', 400);
        };

        // Automated PayPal Payout API (Method 2)
        if (($pending_withdrawal['branch'] ?? '') === 'paypal' || ($pending_withdrawal['bank_name'] ?? '') === 'PayPal') {
            require_once __DIR__ . '/PayPalService.php';
            $payoutResult = PayPalService::initiatePayout($pending_withdrawal);
            if (!$payoutResult['success']) {
                Response::error('PayPal payout failed: ' . ($payoutResult['message'] ?? 'Unknown error'), 400);
            }
        }

        $amount = $pending_withdrawal['amount'];
        $user_id = $pending_withdrawal['user_id'];
        $account_id = $pending_withdrawal['account'];
        $payment_ref = $pending_withdrawal['payment_ref'];

        $conn->begin_transaction();

        try {
            //Delete pending withdrawal
            $stmt = $conn->prepare("DELETE FROM pending_withdrawals WHERE id = ?");
            $stmt->bind_param("s", $id);

            if (!$stmt->execute()) {
                throw new Exception('Failed to delete pending withdrawal');
            };

            // Update payment status
            $status = 'success';
            $stmt = $conn->prepare("UPDATE payments SET status = ? WHERE tx_ref = ?");
            $stmt->bind_param("ss", $status, $payment_ref);

            if(!$stmt->execute()) {
                throw new Exception('Status update failed');
            }

            $account = TradeAccountService::getUserCurrentAccount($user_id);

            TransactionService::createTransaction(
                $user_id,
                $account_id,
                'wallet',
                'withdraw',
                $payment_ref,
                "-$amount",
                $account['balance']
            );

            // Commit the transaction
            $conn->commit();
            $methodName = !empty($pending_withdrawal['coin']) ? $pending_withdrawal['coin'] : (!empty($pending_withdrawal['bank_name']) ? $pending_withdrawal['bank_name'] : 'Withdrawal');
            NotificationService::sendWithdrawalApprovedNotification($user_id, $amount, $methodName);

            Response::success('Withdrawal approved');

        } catch (Exception $e) {
            // Rollback the transaction in case of an error
            $conn->rollback();
            error_log("ERROR " . $e);
            Response::error("An error occured: $e", 500);
        }
    }

    public static function declineWithdrawal($id) {
        $conn = Database::getConnection();

        // Find pending payment
        $check = $conn->prepare("SELECT user_id, account, amount, payment_ref FROM pending_withdrawals WHERE id = ?");
        $check->bind_param("s", $id);
        if (!$check->execute()) {
            Response::error('Failed to fetch pending withdrawal', 500);
        }
        $pending_withdrawal = $check->get_result()->fetch_assoc();
        if(!$pending_withdrawal) {
            Response::error('Pending withdrawal does not exist', 400);
        };

        $amount = $pending_withdrawal['amount'];
        $user_id = $pending_withdrawal['user_id'];
        $payment_ref = $pending_withdrawal['payment_ref'];
        $account_id = $pending_withdrawal['account'];

        $conn->begin_transaction();

        try {
            // Update user balance 
            $update = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id_hash = ?");
            $update->bind_param("ds", $amount, $account_id);

            if (!$update->execute()) {
                throw new Exception('Failed to update balance');
            }

            //Delete pending withdrawal
            $stmt = $conn->prepare("DELETE FROM pending_withdrawals WHERE id = ?");
            $stmt->bind_param("s", $id);

            if (!$stmt->execute()) {
                throw new Exception('Failed to delete pending withdrawal');
            };

            // Update payment status
            $status = 'cancelled';
            $stmt = $conn->prepare("UPDATE payments SET status = ? WHERE tx_ref = ?");
            $stmt->bind_param("ss", $status, $payment_ref);

            if(!$stmt->execute()) {
                throw new Exception('Status update failed');
            }

            // Commit the transaction
            $conn->commit();
            NotificationService::sendWithdrawalRejectedNotification($user_id, $amount);

            Response::success('Withdrawal declined');

        } catch (Exception $e) {
            // Rollback the transaction in case of an error
            $conn->rollback();
            error_log("ERROR " . $e);
            Response::error("An error occured: $e", 500);
        }
    }
}



?>