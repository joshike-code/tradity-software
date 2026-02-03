<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';

class TradeAlterService
{
    public static function getAllAlters() {
        try {
            $conn = Database::getConnection();

            $stmt = $conn->prepare("SELECT * FROM alter_trades ORDER BY date DESC");
            $stmt->execute();
            $result = $stmt->get_result();

            $alter_trade = [];
            while ($row = $result->fetch_assoc()) {
                $alter_trade[] = $row;
            }

            // Total users count
            $stmtTotal = $conn->query("SELECT COUNT(*) AS total_alters FROM alter_trades");
            $total_alters_count = intval($stmtTotal->fetch_assoc()['total_alters']);

            return [
                'alter_trades' => $alter_trade,
                'total_alters_count' => $total_alters_count
            ];
        } catch (Exception $e) {
            error_log("TradeAlterService::getAllAlters - " . $e->getMessage());
            Response::error('Failed to retrieve alter_trade', 500);
        }
    }

    public static function getAlterTrade($ref) {
        try {
            $conn = Database::getConnection();
            $mode = 'trade';
            
            $stmt = $conn->prepare("SELECT * FROM alter_trades WHERE trade_ref = ? AND alter_mode = ?");
            $stmt->bind_param("ss", $ref, $mode);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $alter_trade = $result->fetch_assoc();            
            if ($result->num_rows === 0) {
                $alter_trade = (object)[];
            }

            // Total users count
            $stmtTotal = $conn->query("SELECT COUNT(*) AS total_alters FROM alter_trades");
            $total_alters_count = intval($stmtTotal->fetch_assoc()['total_alters']);

            return [
                'alter_trade' => $alter_trade,
                'total_alters_count' => $total_alters_count
            ];
        } catch (Exception $e) {
            error_log("TradeAlterService::getAlterTrade - " . $e->getMessage());
            Response::error('Failed to retrieve alter trade', 500);
        }
    }

    public static function getAlterPair($pair, $acc_type) {
        try {
            $conn = Database::getConnection();
            $mode = 'pair';

            $stmt = $conn->prepare("SELECT * FROM alter_trades WHERE pair = ? AND acc_type = ? AND alter_mode = ?");
            $stmt->bind_param("sss", $pair, $acc_type, $mode);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $alter_trade = $result->fetch_assoc();

            if ($result->num_rows === 0) {
                $alter_trade = (object)[];
            }

            // Total users count
            $stmtTotal = $conn->query("SELECT COUNT(*) AS total_alters FROM alter_trades");
            $total_alters_count = intval($stmtTotal->fetch_assoc()['total_alters']);

            return [
                'alter_trade' => $alter_trade,
                'total_alters_count' => $total_alters_count
            ];
        } catch (Exception $e) {
            error_log("TradeAlterService::getAlterPair - " . $e->getMessage());
            Response::error('Failed to retrieve alter pair', 500);
        }
    }

    public static function getAlterAccountPair($account) {
        try {
            $conn = Database::getConnection();
            $mode = 'account_pair';
            
            $stmt = $conn->prepare("SELECT * FROM alter_trades WHERE account = ? AND alter_mode = ?");
            $stmt->bind_param("ss", $account, $mode);

            if (!$stmt) {
                Response::error('Failed to get alter account pair', 500);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            $alter_trade = [];
            while ($row = $result->fetch_assoc()) {
                $alter_trade[] = $row;
            }

            $stmt->close();

            // Total users count
            $stmtTotal = $conn->query("SELECT COUNT(*) AS total_alters FROM alter_trades");
            $total_alters_count = intval($stmtTotal->fetch_assoc()['total_alters']);

            return [
                'alter_trade' => $alter_trade,
                'total_alters_count' => $total_alters_count
            ];

        } catch (Exception $e) {
            error_log("TradeAlterService::getAlteraccountPair - " . $e->getMessage());
            Response::error('Failed to retrieve alter account pair', 500);
        }
    }

    public static function createAlterTrade($input, $reason) {
        try {
            $conn = Database::getConnection();
            $trade_ref = $input['ref'];
            $start_price = $input['start_price'];
            $target_price = $input['target_price'];
            $time = $input['time'];
            $close = $input['close'] === true ? 'true' : 'false';
            $mode = 'trade';
            $date = gmdate('Y-m-d H:i:s'); // GMT for frontend parsing
            $start_timestamp = time(); // Unix timestamp for WebSocket processing
            
            // Validate time duration
            $timeInt = intval($time);
            if ($timeInt <= 0) {
                Response::error('Time must be greater than 0 seconds', 400);
            }
            
            // Validate prices
            if ($start_price <= 0) {
                Response::error('Start price must be greater than 0', 400);
            }
            if ($target_price <= 0) {
                Response::error('Target price must be greater than 0', 400);
            }

            // check that alter trade doesn't exist for trade
            $check = $conn->prepare("SELECT * FROM alter_trades WHERE trade_ref = ?");
            $check->bind_param("s", $trade_ref);
            $check->execute();
            $alter_trade = $check->get_result()->fetch_assoc();
            if($alter_trade) {
                Response::error('Alter trade exists for this trade', 400);
            };

            $stmt = $conn->prepare("INSERT INTO alter_trades (trade_ref, start_price, target_price, alter_mode, time, close, reason, date, start_timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Prepare failed');
            }
            $stmt->bind_param("sddsssssi", $trade_ref, $start_price, $target_price, $mode, $time, $close, $reason, $date, $start_timestamp);
            if (!$stmt->execute()) {
                throw new Exception('Failed to create alter trade');
            }

            $result = self::getAlterTrade($trade_ref);
            return $result;
        } catch (Exception $e) {
            error_log("TradeAlterService::createAlterTrade - " . $e->getMessage());
            Response::error('Failed to create alter trade', 500);
        }
    }
    
    public static function createAlterPair($input, $reason) {
        try {
            $conn = Database::getConnection();
            $pair = $input['pair'];
            $acc_type = $input['acc_type'];
            $start_price = $input['start_price'];
            $target_price = $input['target_price'];
            $time = $input['time'];
            $alter_chart = $input['alter_chart'] === true ? 'true' : 'false';
            $close = $input['close'] === true ? 'true' : 'false';
            $mode = 'pair';
            $date = gmdate('Y-m-d H:i:s'); // GMT for frontend parsing
            $start_timestamp = time(); // Unix timestamp for WebSocket processing
            
            // Validate time duration
            $timeInt = intval($time);
            if ($timeInt <= 0) {
                Response::error('Time must be greater than 0 seconds', 400);
            }
            
            // Validate prices
            if ($start_price <= 0) {
                Response::error('Start price must be greater than 0', 400);
            }
            if ($target_price <= 0) {
                Response::error('Target price must be greater than 0', 400);
            }

            // check that alter trade doesn't exist for pair
            $check = $conn->prepare("SELECT * FROM alter_trades WHERE pair = ? AND acc_type = ?");
            $check->bind_param("ss", $pair, $acc_type);
            $check->execute();
            $alter_trade = $check->get_result()->fetch_assoc();
            if($alter_trade) {
                Response::error('Alter trade exists for pair', 400);
            };

            $stmt = $conn->prepare("INSERT INTO alter_trades (pair, acc_type, start_price, target_price, alter_mode, alter_chart, time, close, reason, date, start_timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Prepare failed');
            }
            $stmt->bind_param("ssddssssssi", $pair, $acc_type, $start_price, $target_price, $mode, $alter_chart, $time, $close, $reason, $date, $start_timestamp);
            if (!$stmt->execute()) {
                throw new Exception('Failed to create alter pair');
            }

            $result = self::getAlterPair($pair, $acc_type);
            return $result;
        } catch (Exception $e) {
            error_log("TradeAlterService::createAlterPair - " . $e->getMessage());
            Response::error('Failed to create alter pair', 500);
        }
    }

    public static function createAlterAccountPair($input, $reason) {
        try {
            $conn = Database::getConnection();
            $account = $input['account'];
            $pair = $input['pair'];
            $start_price = $input['start_price'];
            $target_price = $input['target_price'];
            $time = $input['time'];
            $alter_chart = $input['alter_chart'] === true ? 'true' : 'false';
            $close = $input['close'] === true ? 'true' : 'false';
            $mode = 'account_pair';
            $date = gmdate('Y-m-d H:i:s'); // GMT for frontend parsing
            $start_timestamp = time(); // Unix timestamp for WebSocket processing
            
            // Validate time duration
            $timeInt = intval($time);
            if ($timeInt <= 0) {
                Response::error('Time must be greater than 0 seconds', 400);
            }
            
            // Validate prices
            if ($start_price <= 0) {
                Response::error('Start price must be greater than 0', 400);
            }
            if ($target_price <= 0) {
                Response::error('Target price must be greater than 0', 400);
            }

            // check that alter trade doesn't exist for account
            $check = $conn->prepare("SELECT * FROM alter_trades WHERE account = ? AND account_pair = ?");
            $check->bind_param("ss", $account, $pair);
            $check->execute();
            $alter_trade = $check->get_result()->fetch_assoc();
            if($alter_trade) {
                Response::error('Alter trade exists for account with pair', 400);
            };

            // Only use account_pair column for account_pair mode (NOT pair column)
            $stmt = $conn->prepare("INSERT INTO alter_trades (account_pair, account, start_price, target_price, alter_mode, alter_chart, time, close, reason, date, start_timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Prepare failed');
            }
            $stmt->bind_param("ssddssssssi", $pair, $account, $start_price, $target_price, $mode, $alter_chart, $time, $close, $reason, $date, $start_timestamp);
            if (!$stmt->execute()) {
                throw new Exception('Failed to create alter account pair');
            }

            $result = self::getAlterAccountPair($account);
            return $result;
        } catch (Exception $e) {
            error_log("TradeAlterService::createAlterAccountPair - " . $e->getMessage());
            Response::error('Failed to create alter account pair', 500);
        }
    }

    public static function deleteAlterTrade($id, $mode) {
        try {
            $conn = Database::getConnection();

            // check alter trade info
            $check = $conn->prepare("SELECT * FROM alter_trades WHERE id = ?");
            $check->bind_param("s", $id);
            $check->execute();
            $alter_trade = $check->get_result()->fetch_assoc();

            if(!$alter_trade) {
                Response::error('Alter trade not found', 404);
            }

            if($alter_trade['reason'] === 'tutorial') {
                Response::error('Cannot delete tutorial alter trades', 403);
            }


            $stmt = $conn->prepare("DELETE FROM alter_trades WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                if($mode === 'trade') {
                    $result = self::getAlterTrade($alter_trade['trade_ref']);
                    Response::success($result);
                } elseif($mode === 'pair') {
                    $result = self::getAlterPair($alter_trade['pair'], $alter_trade['acc_type']);
                    Response::success($result);
                } elseif($mode === 'account_pair') {
                    $result = self::getAlterAccountPair($alter_trade['account']);
                    Response::success($result);
                } else {
                    $result = self::getAllAlters();
                    Response::success($result);
                }
            } else {
                Response::error('Alter trade not found', 404);
            }
        } catch (Exception $e) {
            error_log("TradeAlterService::deleteAlterTrade - " . $e->getMessage());
            Response::error('Failed to delete alter trade', 500);
        }
    }

}
