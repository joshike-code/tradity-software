<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';

class TransactionService
{
    public static function getUserTransactions($user_id) {
        try {
            $conn = Database::getConnection();
            
            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $current_account = $user['current_account'];

            $stmt = $conn->prepare("SELECT id, type, trx, ref, amount, balance, date FROM transactions WHERE userid = ? AND account = ? ORDER BY date DESC");
            $stmt->bind_param("ss", $user_id, $current_account);
            $stmt->execute();
            $result = $stmt->get_result();

            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }

            return $transactions;
        } catch (Exception $e) {
            error_log("TransactionService::getUserTransactions - " . $e->getMessage());
            Response::error('Failed to retrieve transactions', 500);
        }
    }

    public static function createTransaction($user_id, $id_hash, $type, $trx, $ref, $amount, $balance = '0') {
        try {
            $conn = Database::getConnection();

            $date = gmdate('Y-m-d H:i:s');

            $stmt = $conn->prepare("INSERT INTO transactions (userid, account, type, trx, ref, amount, balance, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssds", $user_id, $id_hash, $type, $trx, $ref, $amount, $balance, $date);
            
            if ($stmt->execute()) {
                return true;
            } else {
                Response::error('Failed to create transaction', 500);
            }
        } catch (Exception $e) {
            error_log("TransactionService::createTransaction - " . $e->getMessage());
            Response::error('Failed to create transaction', 500);
        }
    }

    public static function getTransactionById($transaction_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
            $stmt->bind_param("i", $transaction_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Transaction not found', 404);
                return null;
            }

            return $result->fetch_assoc();
        } catch (Exception $e) {
            error_log("TransactionService::getTransactionById - " . $e->getMessage());
            Response::error('Failed to retrieve transaction', 500);
            return null;
        }
    }

    public static function getAllTransactions() {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT t.*, u.email FROM transactions t JOIN users u ON t.userid = u.id ORDER BY t.date DESC");
            $stmt->execute();
            $result = $stmt->get_result();

            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }

            Response::success($transactions);
        } catch (Exception $e) {
            error_log("TransactionService::getAllTransactions - " . $e->getMessage());
            Response::error('Failed to retrieve transactions', 500);
        }
    }

    public static function getUserTransactionsByAccount($user_id, $account_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT * FROM transactions WHERE userid = ? AND account = ? ORDER BY date DESC");
            $stmt->bind_param("ss", $user_id, $account_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }

            Response::success($transactions);
        } catch (Exception $e) {
            error_log("TransactionService::getUserTransactionsByAccount - " . $e->getMessage());
            Response::error('Failed to retrieve transactions', 500);
        }
    }

    public static function searchTransactionsByRef($ref) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT t.*, u.email FROM transactions t JOIN users u ON t.userid = u.id WHERE t.ref LIKE ? ORDER BY t.date DESC");
            $search_term = "%{$ref}%";
            $stmt->bind_param("s", $search_term);
            $stmt->execute();
            $result = $stmt->get_result();

            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }

            Response::success($transactions);
        } catch (Exception $e) {
            error_log("TransactionService::searchTransactionsByRef - " . $e->getMessage());
            Response::error('Failed to search transactions', 500);
        }
    }

    public static function deleteTransaction($transaction_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ?");
            $stmt->bind_param("i", $transaction_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                Response::success(null, 'Transaction deleted successfully');
            } else {
                Response::error('Transaction not found', 404);
            }
        } catch (Exception $e) {
            error_log("TransactionService::deleteTransaction - " . $e->getMessage());
            Response::error('Failed to delete transaction', 500);
        }
    }

    public static function updateTransactionBalance($transaction_id, $new_balance) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("UPDATE transactions SET balance = ? WHERE id = ?");
            $stmt->bind_param("si", $new_balance, $transaction_id);
            
            if ($stmt->execute()) {
                Response::success(null, 'Transaction balance updated successfully');
            } else {
                Response::error('Failed to update transaction balance', 500);
            }
        } catch (Exception $e) {
            error_log("TransactionService::updateTransactionBalance - " . $e->getMessage());
            Response::error('Failed to update transaction balance', 500);
        }
    }

    public static function filterTransactionsByDate($user_id, $input) {
        try {
            // Date in database is stored in this format: 2025-09-03 14:09:04.000000
            $startDate = $input['startDate']; // Date is in this format: 2025-09-15
            $endDate = $input['endDate']; // Date is in this format: 2025-09-15

            // Convert input dates to full datetime range for proper comparison
            $startDateTime = $startDate . ' 00:00:00'; // Start of day
            $endDateTime = $endDate . ' 23:59:59';     // End of day

            $conn = Database::getConnection();
            
            // Get user's current account for consistency with getUserTransactions
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $current_account = $user['current_account'];

            // Filter by date range and current account
            $stmt = $conn->prepare("SELECT id, type, trx, ref, amount, balance, date FROM transactions WHERE userid = ? AND account = ? AND date BETWEEN ? AND ? ORDER BY date DESC");
            $stmt->bind_param("isss", $user_id, $current_account, $startDateTime, $endDateTime);
            $stmt->execute();
            $result = $stmt->get_result();

            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }

            Response::success($transactions);
        } catch (Exception $e) {
            error_log("TransactionService::filterTransactionsByDate - " . $e->getMessage());
            Response::error('Failed to filter transactions', 500);
        }
    }
}
