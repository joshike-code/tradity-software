<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/TransactionService.php';
require_once __DIR__ . '/../services/PlatformService.php';
require_once __DIR__ . '/../services/NotificationService.php';

class BotTradeService
{
    public static function getUserTrade($user_id) {
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

            $stmt = $conn->prepare("SELECT * FROM bot_trades WHERE userid = ? AND account = ?");
            $stmt->bind_param("is", $user_id, $current_account);
            $stmt->execute();

            $result = $stmt->get_result()->fetch_assoc();

            if(!$result) {
                return null; // Return null instead of empty object
            }

            $result['is_paused'] = $result['is_paused'] === 'true' ? true : false;

            return $result;
        } catch (Exception $e) {
            error_log("BotTradeService::getUserTrades - " . $e->getMessage());
            Response::error('Failed to retrieve bot trade', 500);
        }
    }

    public static function createTrade($user_id, $input) {
        try {
            $conn = Database::getConnection();

            $stake = $input['stake'];

            if ($stake <= 0) {
                Response::error('Stake must be greater than 0', 400);
            }
            
            $account = TradeAccountService::getUserCurrentAccount($user_id);
            $account_id = $account['id_hash'];

            // Fetch user balance & Ensure the balance is a float and check if the user has enough balance
            $balance = (float) $account['balance'];
            if ($balance < $stake) {
                Response::error('Insufficient balance', 402);
            }

            // Generate unique trade reference
            $ref = rand(1000000000, 9999999999);
            $trade_acc = 'demo'; // Default to demo, can be made dynamic
            $increment_rate = PlatformService::getSetting('increment_rate', 0.1);
            $date = gmdate('Y-m-d H:i:s');

            $conn->begin_transaction();

            try {
                // Create the bot trade in bot_trades
                $stmt = $conn->prepare("INSERT INTO bot_trades (userid, account, ref, stake, trade_acc, increment_rate, start_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issdsds", $user_id, $account_id, $ref, $stake, $trade_acc, $increment_rate, $date);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to create bot trade');
                }

                // Update user balance 
                $update = $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id_hash = ?");
                $update->bind_param("ds", $stake, $account['id_hash']);

                if (!$update->execute()) {
                    throw new Exception('Failed to update balance');
                }

                // Fetch updated user balance
                $balanceStmt = $conn->prepare("SELECT balance FROM accounts WHERE id = ?");
                $balanceStmt->bind_param("i", $account['id']);
                $balanceStmt->execute();
                $balanceResult = $balanceStmt->get_result();
                $updatedBalance = $balanceResult->fetch_assoc()['balance'] ?? null;

                // Log to payments table
                $insert = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, type, status, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $method = "bot_trade";
                $type = "debit";
                $status = "success";
                $tx_ref = uniqid("tx_");
                $insert->bind_param("isdsssss", $user_id, $account_id, $stake, $tx_ref, $method, $type, $status, $date);
                $insert->execute();

                $account = TradeAccountService::getAccountById($user_id, $account_id);
                TransactionService::createTransaction(
                    $user_id,
                    $account_id,
                    'bot_trade',
                    'invest-in',
                    $ref,
                    "-$stake",
                    $account['balance']
                );

                NotificationService::sendBotTradeStartNotification($user_id, $stake);

                // Commit the transaction
                $conn->commit();

                $trade = self::getUserTrade($user_id);
                Response::success($trade);
            } catch (Exception $e) {
                // Rollback the transaction in case of an error
                $conn->rollback();
                error_log("ERROR " . $e);
                Response::error('Something went wrong. Please try again', 500);
            }

        } catch (Exception $e) {
            error_log("BotTradeService::createTrade - " . $e->getMessage());
            Response::error('Failed to create bot trade', 500);
        }
    }

    public static function pauseTrade($user_id, $trade_ref, $input) {
        try {
            $conn = Database::getConnection();

            $is_paused = 'true';
            $paused_time = gmdate('Y-m-d H:i:s');
            $resume_time = null;
            $profit = $input['profit'];

            $stmt = $conn->prepare("UPDATE bot_trades SET profit = ?, is_paused = ?, paused_time = ?, resume_time = ? WHERE ref = ? AND userid = ?");
            $stmt->bind_param("ssssii", $profit, $is_paused, $paused_time, $resume_time, $trade_ref, $user_id);

            if ($stmt->execute()) {
                $trade = self::getUserTrade($user_id);
                Response::success($trade);
            } else {
                Response::error('Failed to pause bot trade', 500);
            }
        } catch (Exception $e) {
            error_log("BotTradeService::pauseTrade - " . $e->getMessage());
            Response::error('Failed to pause bot trade', 500);
        }
    }

    public static function resumeTrade($user_id, $trade_ref, $input) {
        try {
            $conn = Database::getConnection();

            $is_paused = 'false';
            $resume_time = gmdate('Y-m-d H:i:s');
            $paused_time = null;

            $stmt = $conn->prepare("UPDATE bot_trades SET is_paused = ?, resume_time = ?, paused_time = ? WHERE ref = ? AND userid = ?");
            $stmt->bind_param("sssii", $is_paused, $resume_time, $paused_time, $trade_ref, $user_id);

            if ($stmt->execute()) {
                $trade = self::getUserTrade($user_id);
                Response::success($trade);
            } else {
                Response::error('Failed to resume bot trade', 500);
            }
        } catch (Exception $e) {
            error_log("BotTradeService::resumeTrade - " . $e->getMessage());
            Response::error('Failed to resume bot trade', 500);
        }
    }

    public static function updateProfit($user_id, $input) {
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

            $profit = $input['profit'];

            // Check that there is an existing trade
            $stmt = $conn->prepare("SELECT * FROM bot_trades WHERE userid = ? AND account = ?");
            $stmt->bind_param("is", $user_id, $current_account);
            $stmt->execute();

            $result = $stmt->get_result()->fetch_assoc();

            if(!$result) {
                Response::error('No bot trade found for account', 404);
            }

            $stmt = $conn->prepare("UPDATE bot_trades SET profit = ? WHERE account = ? AND userid = ?");
            $stmt->bind_param("ssi", $profit, $current_account, $user_id);

            if (!$stmt->execute()) {
                Response::error('Failed to update profit', 500);
            }

            $trade = self::getUserTrade($user_id);
            Response::success($trade);
        } catch (Exception $e) {
            error_log("BotTradeService::updateProfit - " . $e->getMessage());
            Response::error('Failed to update profit', 500);
        }
    }

    public static function endTrade($user_id) {
        try {
            $trade = self::getUserTrade($user_id);
            if(!$trade) {
                Response::error('No bot trade found for account', 404);
            }

            $stake = $trade['stake'];
            $profit = $trade['profit'];
            $is_paused = $trade['is_paused'];
            $ref = $trade['ref'];
            if($is_paused === 'false') {
                Response::error('Bot trade still running', 400);
            }

            $user_account = TradeAccountService::getUserCurrentAccount($user_id);
            $account_id = $user_account['id_hash'];
            $current_balance = floatval($user_account['balance']);
            $amount = floatval($stake) + floatval($profit);
            $amount_value = floatval($amount);

            // Check if it's a withdrawal and if there are sufficient funds
            if($amount_value < 0 && abs($amount_value) > $current_balance) {
                Response::error('Insufficient funds', 402);
            }

            $conn = Database::getConnection();

            $conn->begin_transaction();

            try {

                // Update balance
                $stmt = $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id_hash = ?");
                $stmt->bind_param("ds", $amount_value, $user_account['id_hash']);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update balance');
                }

                // Delete bot trade
                $stmt = $conn->prepare("DELETE FROM bot_trades WHERE ref = ?");
                $stmt->bind_param("s", $ref);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to delete bot trade');
                }

                // Log to payments table
                $insert = $conn->prepare("INSERT INTO payments (user_id, account, amount, tx_ref, method, type, status, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $method = "bot_trade";
                $type = "credit";
                $status = "success";
                $tx_ref = uniqid("tx_");
                $date = gmdate('Y-m-d H:i:s');
                $insert->bind_param("isdsssss", $user_id, $account_id, $amount_value, $tx_ref, $method, $type, $status, $date);
                $insert->execute();

                $account = TradeAccountService::getAccountById($user_id, $account_id);
                TransactionService::createTransaction(
                    $user_id,
                    $account_id,
                    'bot_trade',
                    'invest-out',
                    $ref,
                    "+$amount_value",
                    $account['balance']
                );

                NotificationService::sendBotTradeEndNotification($user_id, $profit);

                // Commit the transaction
                $conn->commit();

                $trade = self::getUserTrade($user_id);
                Response::success($trade);

            } catch (Exception $e) {
                // Rollback the transaction in case of an error
                $conn->rollback();
                error_log("ERROR " . $e);
                Response::error("An error occured: $e", 500);
            }

        } catch (Exception $e) {
            error_log("BotTradeService::updateProfit - " . $e->getMessage());
            Response::error('Failed to update profit', 500);
        }
    }

    public static function getTradeById($trade_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("
            SELECT 
                t.*,
                u.id AS user_id, u.fname, u.lname, u.email,
                a.id_hash, a.balance
            FROM 
                bot_trades t
            INNER JOIN 
                users u ON t.userid = u.id
            INNER JOIN 
                accounts a ON t.account = a.id_hash
            WHERE t.id = ?");
            $stmt->bind_param("i", $trade_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Bot Trade not found', 404);
                return null;
            }

            return $result->fetch_assoc();
        } catch (Exception $e) {
            error_log("BotTradeService::getTradeById - " . $e->getMessage());
            Response::error('Failed to retrieve bot trade', 500);
            return null;
        }
    }

    public static function getAllTrades($trade_acc) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT t.*, 
            u.email, u.fname, u.lname
            FROM bot_trades t 
            JOIN users u ON t.userid = u.id
            WHERE t.trade_acc = ?
            ORDER BY t.start_time DESC");
            $stmt->bind_param("s", $trade_acc);
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            // Total trades
            $stmtTotal = $conn->query("SELECT COUNT(*) AS total_trades FROM bot_trades");
            $total_trades_count = intval($stmtTotal->fetch_assoc()['total_trades']);

            Response::success([
                'total_trades'     => $trades,
                'total_trades_count' => $total_trades_count
            ]);
        } catch (Exception $e) {
            error_log("BotTradeService::getAllTrades - " . $e->getMessage());
            Response::error('Failed to retrieve bot trades', 500);
        }
    }

    public static function searchAllTrades($search, $trade_acc) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT t.*, 
            u.email 
            FROM bot_trades t JOIN users u ON t.userid = u.id
            WHERE t.ref LIKE ? AND t.trade_acc = ? ORDER BY t.date DESC");
            $search_term = "%{$search}%";
            $stmt->bind_param("ss", $search_term, $trade_acc);
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            Response::success($trades);
        } catch (Exception $e) {
            error_log("BotTradeService::searchTradesByRef - " . $e->getMessage());
            Response::error('Failed to search bot trades', 500);
        }
    }

    public static function getUserTradesByAccount($user_id, $account_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT * FROM bot_trades WHERE userid = ? AND account = ? ORDER BY date DESC");
            $stmt->bind_param("is", $user_id, $account_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            Response::success($trades);
        } catch (Exception $e) {
            error_log("BotTradeService::getUserTradesByAccount - " . $e->getMessage());
            Response::error('Failed to retrieve trades', 500);
        }
    }

    public static function deleteTrade($trade_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("DELETE FROM bot_trades WHERE id = ?");
            $stmt->bind_param("i", $trade_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                Response::success(null, 'Trade deleted successfully');
            } else {
                Response::error('Trade not found', 404);
            }
        } catch (Exception $e) {
            error_log("BotTradeService::deleteTrade - " . $e->getMessage());
            Response::error('Failed to delete bot trade', 500);
        }
    }
}
