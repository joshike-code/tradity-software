<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../services/PlatformService.php';
require_once __DIR__ . '/../services/NotificationService.php';

class TradeAccountService
{
    public static function getUserAccounts($user_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT * FROM accounts WHERE user_id = ? ORDER BY date DESC");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $accounts = [];
            while ($row = $result->fetch_assoc()) {
                $accounts[] = $row;
            }

            return $accounts;
        } catch (Exception $e) {
            error_log("TradeAccountService::getUserAccounts - " . $e->getMessage());
            Response::error('Failed to retrieve accounts', 500);
        }
    }

    public static function createAccount($user_id, $type, $balance = '0') {
        try {
            $conn = Database::getConnection();
            
            // Generate unique account hash
            $id_hash = rand(1000000000, 9999999999);
            $leverage = PlatformService::getSetting('default_leverage', 1000);
            
            $date = gmdate('Y-m-d H:i:s');

            $stmt = $conn->prepare("INSERT INTO accounts (user_id, id_hash, type, leverage, balance, date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssds", $user_id, $id_hash, $type, $leverage, $balance, $date);
            
            if ($stmt->execute()) {
                $account_id = $conn->insert_id;
                
                // Get the created account
                $stmt = $conn->prepare("SELECT * FROM accounts WHERE id = ?");
                $stmt->bind_param("i", $account_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $account = $result->fetch_assoc();

                return $account;
            } else {
                Response::error('Failed to create account', 500);
            }
        } catch (Exception $e) {
            error_log("TradeAccountService::createAccount - " . $e->getMessage());
            Response::error('Failed to create account', 500);
        }
    }

    public static function getAccountById($user_id, $id_hash) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id_hash, type, balance, leverage, first_deposit, status, date FROM accounts WHERE id_hash = ? AND user_id = ?");
            $stmt->bind_param("si", $id_hash, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Account not found', 404);
                return null;
            }

            return $result->fetch_assoc();
        } catch (Exception $e) {
            error_log("TradeAccountService::getAccountById - " . $e->getMessage());
            Response::error('Failed to retrieve account', 500);
            return null;
        }
    }

    public static function getUserCurrentAccount($user_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return null;
            }

            $user = $result->fetch_assoc();
            $id_hash = $user['current_account'];
            return self::getAccountById($user_id, $id_hash);
        } catch (Exception $e) {
            error_log("TradeAccountService::getUserCurrentAccount - " . $e->getMessage());
            Response::error('Failed to retrieve account', 500);
            return null;
        }
    }

    public static function updateAccountLeverage($user_id, $input)
    {
        $account = self::getUserCurrentAccount($user_id);
        $id_hash = $account['id_hash'];

        $conn = Database::getConnection();
        $leverage = $input['leverage'];

        $stmt = $conn->prepare("UPDATE accounts SET leverage = ? WHERE id_hash = ?");
        $stmt->bind_param("si", $leverage, $id_hash);

        if (!$stmt->execute()) {
            Response::error('Leverage update failed', 500);
        };

        $updated_account = self::getAccountById($user_id, $id_hash);
        Response::success($updated_account);
    }

    public static function updateAccountBalance($user_id, $amount, $checks = true) {
        try {
            $user_account = self::getUserCurrentAccount($user_id);
            return self::updateSpecificAccountBalance($user_id, $user_account['id_hash'], $amount, $checks);
            
        } catch (Exception $e) {
            error_log("TradeAccountService::updateAccountBalance - " . $e->getMessage());
            Response::error('Failed to update account balance', 500);
        }
    }

    public static function updateSpecificAccountBalance($user_id, $id_hash, $amount, $checks = true) {
        try {
            $user_account = self::getAccountById($user_id, $id_hash);
            $amount_value = floatval($amount);

            if($checks === true) {
                $current_balance = floatval($user_account['balance']);

                // Check if it's a withdrawal and if there are sufficient funds
                if($amount_value < 0 && abs($amount_value) > $current_balance) {
                    Response::error('Insufficient funds', 400);
                    return;
                }
            }

            $conn = Database::getConnection();

            $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id_hash = ?");
            $stmt->bind_param("ds", $amount_value, $user_account['id_hash']);
            
            if ($stmt->execute()) {
                // Get the updated account data
                return self::getAccountById($user_id, $user_account['id_hash']);
            } else {
                Response::error('Failed to update account balance', 500);
            }
        } catch (Exception $e) {
            error_log("TradeAccountService::updateAccountBalance - " . $e->getMessage());
            Response::error('Failed to update account balance', 500);
        }
    }

    public static function resetAccountBalance($user_id) {
        try {
            $account = self::getUserCurrentAccount($user_id);
            if($account['type'] === 'real') {
                Response::error('Real account balance cannot be reset', 400);
            }

            $default_balance = PlatformService::getSetting('demo_account_balance', 10000);

            if($account['balance'] == $default_balance) {
                Response::error('Account balance is default balance', 400);
            }

            $conn = Database::getConnection();

            $stmt = $conn->prepare("UPDATE accounts SET balance = ? WHERE id_hash = ?");
            $stmt->bind_param("ds", $default_balance, $account['id_hash']);

            if ($stmt->execute()) {
                // Get the updated account data
                $updated_account = self::getAccountById($user_id, $account['id_hash']);
                Response::success($updated_account);
            } else {
                Response::error('Failed to update account balance', 500);
            }
        } catch (Exception $e) {
            error_log("TradeAccountService::resetAccountBalance - " . $e->getMessage());
            Response::error('Failed to update account balance', 500);
        }
    }

    public static function getAccountByUniqueId($id) {
        try {
            $conn = Database::getConnection();
            
            // Consider accounts offline if no heartbeat in last 2 minutes
            $two_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-2 minutes'));
            
            $stmt = $conn->prepare("
                SELECT 
                    a.*,
                    u.id AS user_id, 
                    u.fname, 
                    u.lname, 
                    u.email,
                    CASE 
                        WHEN a.online_status IN ('online', 'away') 
                        AND a.last_heartbeat IS NOT NULL
                        AND a.last_heartbeat > ? 
                        THEN a.online_status
                        ELSE 'offline'
                    END as current_online_status
                FROM accounts a
                INNER JOIN users u ON a.user_id = u.id
                WHERE a.id = ?
            ");
            $stmt->bind_param("si", $two_minutes_ago, $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Account not found', 404);
                return null;
            }

            return $result->fetch_assoc();
        } catch (Exception $e) {
            error_log("TradeAccountService::getAccountByUniqueId - " . $e->getMessage());
            Response::error('Failed to retrieve account', 500);
            return null;
        }
    }

    public static function getAllAccounts($filter) {
        try {
            $conn = Database::getConnection();
            
            // Consider accounts offline if no heartbeat in last 2 minutes
            $two_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-2 minutes'));
            
            $stmt = $conn->prepare("
                SELECT 
                    a.*, 
                    u.email,
                    u.fname,
                    u.lname,
                    CASE 
                        WHEN a.online_status IN ('online', 'away') 
                        AND a.last_heartbeat IS NOT NULL
                        AND a.last_heartbeat > ? 
                        THEN a.online_status
                        ELSE 'offline'
                    END as current_online_status
                FROM accounts a 
                JOIN users u ON a.user_id = u.id 
                WHERE a.type = ?
                ORDER BY a.last_heartbeat DESC, a.date DESC
            ");
            $stmt->bind_param("ss", $two_minutes_ago, $filter);
            $stmt->execute();
            $result = $stmt->get_result();

            $accounts = [];
            while ($row = $result->fetch_assoc()) {
                $accounts[] = $row;
            }

            Response::success($accounts);
        } catch (Exception $e) {
            error_log("TradeAccountService::getAllAccounts - " . $e->getMessage());
            Response::error('Failed to retrieve accounts', 500);
        }
    }

    public static function updateAccountStatus($id, $input)
    {
        $conn = Database::getConnection();
        $stmt = $conn->prepare("SELECT user_id, id_hash, type, balance, leverage, first_deposit, status, date FROM accounts WHERE id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            Response::error('Account not found', 404);
        }

        $account = $result->fetch_assoc();

        $status = $input['status'];
        if($status !== 'active' && $status !== 'suspended') {
            Response::error('Invalid input', 422);
        };

        if($account['type'] === 'demo' && $status === 'suspended') {
            Response::error('Demo accounts cannot be suspended', 400);
        }

        $stmt = $conn->prepare("UPDATE accounts SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);

        if (!$stmt->execute()) {
            Response::error('Status update failed', 500);
        };

        if($status === 'active') {
            NotificationService::sendTradeAccountReactivatedNotification($account['user_id'], $account['type'], $account['id_hash']);
        }
        if($status === 'suspended') {
            NotificationService::sendTradeAccountSuspendedNotification($account['user_id'], $account['type'], $account['id_hash']);
        }

        return true;
    }

    public static function deleteAccount($account_id) {
        try {
            $conn = Database::getConnection();

            // get account info
            $check = $conn->prepare("SELECT * FROM accounts WHERE id = ?");
            $check->bind_param("s", $account_id);
            $check->execute();
            $account = $check->get_result()->fetch_assoc();

            if(!$account) {
                Response::error('Account not found', 404);
            }

            if($account['type'] === 'demo') {
                Response::error('Demo accounts cannot be deleted', 400);
            }

            $stmt = $conn->prepare("DELETE FROM accounts WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                Response::success('Account deleted successfully');
            } else {
                Response::error('Account not found', 404);
            }
        } catch (Exception $e) {
            error_log("TradeAccountService::deleteAccount - " . $e->getMessage());
            Response::error('Failed to delete account', 500);
        }
    }

    public static function switchCurrentAccount($user_id, $id_hash) {
        try {
            $conn = Database::getConnection();
            
            // Verify account belongs to user
            $stmt = $conn->prepare("SELECT id_hash, status FROM accounts WHERE id_hash = ? AND user_id = ?");
            $stmt->bind_param("si", $id_hash, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Account not found or unauthorized', 404);
                return;
            }

            $account = $result->fetch_assoc();
            $id_hash = $account['id_hash'];

            // Check it is not suspended
            if($account['status'] === 'suspended') {
                Response::error('Trade account suspended', 400);
            }

            // Update user's current account
            $stmt = $conn->prepare("UPDATE users SET current_account = ? WHERE id = ?");
            $stmt->bind_param("si", $id_hash, $user_id);
            
            if ($stmt->execute()) {
                return $id_hash;
            } else {
                Response::error('Failed to update current account', 500);
            }
        } catch (Exception $e) {
            error_log("TradeAccountService::switchCurrentAccount - " . $e->getMessage());
            Response::error('Failed to switch current account', 500);
        }
    }

    public static function updateFirstDeposit($account)
    {
      $firstDeposit = $account['first_deposit'];
      $account_id = $account['id_hash'];
      if($firstDeposit === 'no') {
        $conn = Database::getConnection();

        $stmt = $conn->prepare("UPDATE accounts SET first_deposit = 'yes' WHERE id_hash = ?");
        $stmt->bind_param("s", $account_id);

        if(!$stmt->execute()) {
          Response::error('Status update failed', 500);
        }

      }
    }

    public static function topUpBalance($input)
    {
        $conn = Database::getConnection();

        $amount = $input['amount'];
        $account_id = $input['account_id'];

        $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
        $stmt->bind_param("ds", $amount, $account_id);

        if ($stmt->execute()) {
            Response::success('Balance topup successful');
        } else {
            Response::error('Balance update failed', 500);
        }
    }

    public static function deductBalance($input)
    {
        $conn = Database::getConnection();

        $amount = $input['amount'];
        $account_id = $input['account_id'];

        // Check that balance is sufficient
        $check = $conn->prepare("SELECT balance FROM accounts WHERE id = ?");
        $check->bind_param("s", $account_id);
        $check->execute();
        
        $result = $check->get_result()->fetch_assoc();
        if(!$result) {
            Response::error('User not found', 404);
        }

        $currentBalance = $result['balance'];
        if($amount > $currentBalance) {
            Response::error('Insufficient balance', 412);
        }

        $stmt = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
        $stmt->bind_param("ds", $amount, $account_id);

        if ($stmt->execute()) {
            Response::success('Balance topup successful');
        } else {
            Response::error('Balance update failed', 500);
        }
    }
}
