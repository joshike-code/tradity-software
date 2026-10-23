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

class BankAccountsService
{
    /**
     * Retrieve all admin bank accounts that users can pay into for Bank Transfer deposits
     */
    public static function getAllBankAccounts($getResult = false)
    {
        $conn = Database::getConnection();

        $sql = "SELECT id, account_name, bank_name, account_number, ifsc_code, branch, date 
                FROM bank_accounts ORDER BY id DESC";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            Response::error('Failed to get bank accounts', 500);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $bank_accounts = [];
        while ($row = $result->fetch_assoc()) {
            $bank_accounts[] = $row;
        }

        $stmt->close();
        if ($getResult === true) {
            return $bank_accounts;
        }
        Response::success($bank_accounts);
    }

    public static function getBankAccountById($id)
    {
        $conn = Database::getConnection();
        $stmt = $conn->prepare("SELECT id, account_name, bank_name, account_number, ifsc_code, branch, date
            FROM bank_accounts 
            WHERE id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        if (!$stmt) {
            Response::error('Could not get bank account', 500);
        }

        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) {
            Response::error('Bank account not found', 404);
        }

        return $result;
    }

    public static function createBankAccount($input)
    {
        $conn = Database::getConnection();
        $stmt = $conn->prepare("INSERT INTO bank_accounts (account_name, bank_name, account_number, ifsc_code, branch, date) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            Response::error('Failed to create bank account', 500);
        }

        $account_name = $input['account_name'] ?? null;
        $bank_name = $input['bank_name'] ?? null;
        $account_number = $input['account_number'] ?? null;
        $ifsc_code = $input['ifsc_code'] ?? null;
        $branch = $input['branch'] ?? null;
        $date = gmdate('Y-m-d H:i:s');

        $stmt->bind_param("ssssss", $account_name, $bank_name, $account_number, $ifsc_code, $branch, $date);

        if ($stmt->execute()) {
            $stmt->close();
            self::getAllBankAccounts();
        } else {
            $stmt->close();
            Response::error('Failed to create bank account', 500);
        }
    }

    public static function updateBankAccount($id, $input)
    {
        $conn = Database::getConnection();

        $account_name = $input['account_name'] ?? null;
        $bank_name = $input['bank_name'] ?? null;
        $account_number = $input['account_number'] ?? null;
        $ifsc_code = $input['ifsc_code'] ?? null;
        $branch = $input['branch'] ?? null;
        $date = gmdate('Y-m-d H:i:s');

        $stmt = $conn->prepare("UPDATE bank_accounts SET account_name = ?, bank_name = ?, account_number = ?, ifsc_code = ?, branch = ?, date = ? WHERE id = ?");
        $stmt->bind_param("sssssss", $account_name, $bank_name, $account_number, $ifsc_code, $branch, $date, $id);

        if ($stmt->execute()) {
            $stmt->close();
            self::getAllBankAccounts();
        } else {
            $stmt->close();
            Response::error('Failed to update bank account', 500);
        }
    }

    public static function deleteBankAccount($id)
    {
        $conn = Database::getConnection();

        $stmt = $conn->prepare("SELECT id FROM bank_accounts WHERE id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) {
            Response::error('Bank account not found', 404);
        }

        $delStmt = $conn->prepare("DELETE FROM bank_accounts WHERE id = ?");
        $delStmt->bind_param("s", $id);

        if ($delStmt->execute()) {
            $delStmt->close();
            self::getAllBankAccounts();
        } else {
            $delStmt->close();
            Response::error("Failed to delete bank account.", 500);
        }
    }

    /**
     * User initiates a Bank Transfer deposit to company admin bank account
     */
    public static function createBankPayment(int $user_id, array $input)
    {
        $conn = Database::getConnection();

        $amount = isset($input['amount']) ? floatval($input['amount']) : 0;
        $bank_name = $input['bank_name'] ?? null;
        $account_number = $input['account_number'] ?? null;
        $ifsc_code = $input['ifsc_code'] ?? null;
        $branch = $input['branch'] ?? null;
        $payment_ref = $input['payment_ref'] ?? ($input['reference'] ?? null);

        // Validate amount
        if ($amount <= 0) {
            Response::error('Invalid amount provided.', 400);
        }

        // Validate that destination account_number + bank_name matches an allowed admin bank account
        $admin_accounts = self::getAllBankAccounts(true);
        $valid = false;
        $matchedAccount = null;

        if (is_array($admin_accounts) && count($admin_accounts) > 0) {
            // If user supplied specific account details, match them
            if (!empty($bank_name) && !empty($account_number)) {
                $clean_bank_name = strtolower(trim($bank_name));
                $clean_acc_num = trim($account_number);

                foreach ($admin_accounts as $admin_acc) {
                    $admin_bank = strtolower(trim($admin_acc['bank_name'] ?? ''));
                    $admin_acc_num = trim($admin_acc['account_number'] ?? '');
                    if ($admin_bank === $clean_bank_name && $admin_acc_num === $clean_acc_num) {
                        $valid = true;
                        $matchedAccount = $admin_acc;
                        break;
                    }
                }
            } else {
                // If only 1 admin account exists or default selected
                $matchedAccount = $admin_accounts[0];
                $bank_name = $matchedAccount['bank_name'];
                $account_number = $matchedAccount['account_number'];
                $ifsc_code = $matchedAccount['ifsc_code'] ?? null;
                $branch = $matchedAccount['branch'] ?? null;
                $valid = true;
            }
        } else {
            Response::error('No admin bank accounts configured. Please contact support.', 400);
        }

        if (!$valid) {
            Response::error('Invalid bank account selected. Please transfer to an official company bank account.', 400);
        }

        $account = TradeAccountService::getUserCurrentAccount($user_id);
        if (!$account) {
            Response::error('User trading account not found.', 404);
        }
        $account_id = $account['id_hash'];

        $tx_ref = uniqid("tx_");
        $date = gmdate('Y-m-d H:i:s');
        $payment_type = 'Bank Transfer';

        $stmt = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, status, bank_name, account_number, ifsc_code, branch, type, payment_type, date)
                                VALUES (?, ?, ?, ?, 'bank', 'pending', ?, ?, ?, ?, 'credit', ?, ?)");

        if (!$stmt) {
            Response::error('Bank payment initiation failed.', 500);
        }

        $stmt->bind_param("isdsssssss", $user_id, $account_id, $amount, $tx_ref, $bank_name, $account_number, $ifsc_code, $branch, $payment_type, $date);

        if (!$stmt->execute()) {
            $stmt->close();
            Response::error('Could not submit bank payment.', 500);
        }
        $stmt->close();

        return Response::success([
            'message' => 'Bank transfer deposit request submitted for admin approval',
            'amount' => $amount,
            'bank_name' => $bank_name,
            'account_number' => $account_number,
            'ifsc_code' => $ifsc_code,
            'branch' => $branch,
            'tx_ref' => $tx_ref,
            'status' => 'pending'
        ]);
    }

    /**
     * Admin approves or declines a Bank Transfer deposit
     */
    public static function updateBankPaymentStatus($id, $input)
    {
        $conn = Database::getConnection();

        $status = $input['status'] ?? null;
        $amount = isset($input['amount']) ? floatval($input['amount']) : null;

        if ($status !== 'success' && $status !== 'failed') {
            Response::error('Invalid status value. Must be success or failed', 400);
        }

        $paymentStmt = $conn->prepare("SELECT user_id, tx_ref, account, amount FROM payments WHERE id = ? AND method = 'bank'");
        if (!$paymentStmt) {
            Response::error('Invalid payment selection', 400);
        }
        $paymentStmt->bind_param("s", $id);
        $paymentStmt->execute();
        $paymentResult = $paymentStmt->get_result();
        $payment = $paymentResult->fetch_assoc();
        $paymentStmt->close();

        if (!$payment) {
            Response::error('Bank payment record not found', 404);
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

        // If approved, update user trading account balance and notify
        if ($status === 'success') {
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
                $payment['tx_ref'],
                "+$amount",
                $account['balance']
            );

            ReferralService::handleReferralBonus($payment['user_id'], $amount, $payment['tx_ref']);
        }

        Response::success([
            'message' => 'Bank payment status updated successfully',
            'status' => $status,
            'amount' => $amount,
            'id' => $id
        ]);
    }
}