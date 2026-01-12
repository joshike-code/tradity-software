<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/TradeService.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/ProfitCalculationService.php';
require_once __DIR__ . '/../services/PriceFormatterService.php';

class PendingTradeMonitorService
{
    /**
     * WebSocket notification callback
     * Set by WebSocket server to receive pending order notifications
     */
    private static $notificationCallback = null;
    
    /**
     * Set callback for WebSocket notifications
     * 
     * @param callable $callback Function to call when order is triggered: function($orderData, $tradeData)
     */
    public static function setNotificationCallback($callback) {
        self::$notificationCallback = $callback;
    }
    
    /**
     * Send notification about triggered pending order
     * 
     * @param array $order - Pending order data
     * @param array $tradeData - New trade data
     */
    private static function notifyOrderTriggered($order, $tradeData) {
        // Call callback if available (works if called from WebSocket server process)
        if (self::$notificationCallback && is_callable(self::$notificationCallback)) {
            try {
                call_user_func(self::$notificationCallback, $order, $tradeData);
            } catch (Exception $e) {
                error_log("PendingTradeMonitorService::notifyOrderTriggered - Callback error: " . $e->getMessage());
            }
        }
    }
    /**
     * Check all pending orders and trigger/expire them as needed
     * Should be called from WebSocket server on each price update
     * 
     * @param array $currentPrices - Array of current prices ['EUR/USD' => 1.17283, ...]
     * @return array - Summary of triggered and expired orders
     */
    public static function checkPendingOrders($currentPrices) {
        $triggered = 0;
        $expired = 0;
        $errors = [];
        
        try {
            $conn = Database::getConnection();
            
            // Get all pending orders - fetch all first to avoid result set issues
            $stmt = $conn->prepare("SELECT * FROM pending_trades WHERE status = 'pending' ORDER BY date ASC");
            $stmt->execute();
            $result = $stmt->get_result();
            $pendingOrders = [];
            while ($row = $result->fetch_assoc()) {
                $pendingOrders[] = $row;
            }
            
            $currentTime = gmdate('Y-m-d H:i:s');
            
            foreach ($pendingOrders as $order) {
                // Check expiry first
                if ($order['expiry_date'] !== null && $order['expiry_date'] <= $currentTime) {
                    if (self::expirePendingOrder($order['id'])) {
                        $expired++;
                        error_log("PendingTradeMonitor: Expired order #{$order['id']} for {$order['pair']}");
                    }
                    continue;
                }
                
                // Check if price condition is met
                $pair = $order['pair'];
                $pairKey = ProfitCalculationService::getPairKey($pair);
                
                if (!isset($currentPrices[$pairKey])) {
                    continue; // No price data available
                }
                
                $currentPrice = floatval($currentPrices[$pairKey]);
                $triggerPrice = floatval($order['trigger_price']);
                $orderType = $order['order_type'];
                
                $shouldTrigger = self::checkTriggerCondition($orderType, $currentPrice, $triggerPrice);
                
                if ($shouldTrigger) {
                    $result = self::triggerPendingOrder($order, $currentPrice);
                    if ($result['success']) {
                        $triggered++;
                        error_log("PendingTradeMonitor: Triggered {$orderType} order #{$order['id']} for {$pair} at {$currentPrice}");
                    } else {
                        $errors[] = "Order #{$order['id']}: {$result['message']}";
                        error_log("PendingTradeMonitor: Failed to trigger order #{$order['id']}: {$result['message']}");
                    }
                }
            }
            
            return [
                'success' => true,
                'triggered' => $triggered,
                'expired' => $expired,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            error_log("PendingTradeMonitorService::checkPendingOrders - " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to check pending orders: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Check if trigger condition is met based on order type
     * 
     * @param string $orderType - buy_stop, sell_stop, buy_limit, sell_limit
     * @param float $currentPrice - Current market price
     * @param float $triggerPrice - Price at which order should trigger
     * @return bool - True if should trigger
     */
    private static function checkTriggerCondition($orderType, $currentPrice, $triggerPrice) {
        switch ($orderType) {
            case 'buy_stop':
                // Trigger when price rises to or above trigger price
                return $currentPrice >= $triggerPrice;
                
            case 'buy_limit':
                // Trigger when price falls to or below trigger price
                return $currentPrice <= $triggerPrice;
                
            case 'sell_stop':
                // Trigger when price falls to or below trigger price
                return $currentPrice <= $triggerPrice;
                
            case 'sell_limit':
                // Trigger when price rises to or above trigger price
                return $currentPrice >= $triggerPrice;
                
            default:
                return false;
        }
    }
    
    /**
     * Trigger a pending order by converting it to an active trade
     * 
     * @param array $order - Pending order data
     * @param float $currentPrice - Current market price
     * @return array - Result with success status and message
     */
    private static function triggerPendingOrder($order, $currentPrice) {
        try {
            $conn = Database::getConnection();
            
            // Get pair data for spread calculation
            $pair = $order['pair'];
            $stmt = $conn->prepare("SELECT spread, pip_value, digits, lot_size, margin_percent FROM pairs WHERE name = ?");
            $stmt->bind_param("s", $pair);
            $stmt->execute();
            $pairResult = $stmt->get_result();
            
            if ($pairResult->num_rows === 0) {
                return ['success' => false, 'message' => 'Pair not found'];
            }
            
            $pairData = $pairResult->fetch_assoc();
            
            // Calculate spread and get execution price
            $spreadData = ProfitCalculationService::calculateSpread($pairData, $currentPrice);
            if ($spreadData === null) {
                return ['success' => false, 'message' => 'Failed to calculate spread'];
            }
            
            $type = $order['type'];
            $executionPriceRaw = ($type === 'buy') ? $spreadData['buyPrice'] : $spreadData['sellPrice'];
            
            // Format prices for database
            $price = PriceFormatterService::formatPriceString($pair, $spreadData['currentPrice']);
            $trade_price = PriceFormatterService::formatPriceString($pair, $executionPriceRaw);
            
            // Double-check free margin before opening trade
            $user_id = $order['userid'];
            $livePricesFile = __DIR__ . '/../cache/websocket_live_prices.json';
            $currentPrices = [];
            if (file_exists($livePricesFile)) {
                $cacheContent = file_get_contents($livePricesFile);
                $cacheData = json_decode($cacheContent, true);
                if ($cacheData && isset($cacheData['prices'])) {
                    $currentPrices = $cacheData['prices'];
                }
            }
            
            $balances = ProfitCalculationService::calculateCurrentAccountBalances($user_id, $currentPrices);
            $requiredMargin = floatval($order['margin']);
            
            if ($balances && $balances['freeMargin'] < $requiredMargin) {
                // Insufficient margin - cancel the order
                self::cancelPendingOrder($order['id'], 'Insufficient margin at trigger time');
                return [
                    'success' => false,
                    'message' => 'Insufficient margin. Order cancelled. Required: $' . number_format($requiredMargin, 2) . ', Available: $' . number_format($balances['freeMargin'], 2)
                ];
            }
            
            // Get platform settings
            $commission = PlatformService::getSetting('commission_amount', '0');
            $swap = PlatformService::getSetting('swap_amount', '0');
            
            // Create the trade
            $date = gmdate('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO trades (userid, account, ref, pair, type, trade_price, price, margin, lot, leverage, stop_loss, take_profit, commission, swap, profit, trade_acc, date, close_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $profit = '';
            $close_date = null;
            
            $stmt->bind_param(
                "issssssddiddddssss",
                $order['userid'],
                $order['account'],
                $order['ref'],
                $order['pair'],
                $order['type'],
                $trade_price,
                $price,
                $order['margin'],
                $order['lot'],
                $order['leverage'],
                $order['stop_loss'],
                $order['take_profit'],
                $commission,
                $swap,
                $profit,
                $order['trade_acc'],
                $date,
                $close_date
            );
            
            if ($stmt->execute()) {
                $tradeId = $stmt->insert_id;
                
                // Mark pending order as triggered
                $status = 'triggered';
                $updateStmt = $conn->prepare("UPDATE pending_trades SET status = ? WHERE id = ?");
                $updateStmt->bind_param("si", $status, $order['id']);
                $updateStmt->execute();
                
                // Prepare trade data for notification
                $tradeData = [
                    'trade_id' => $tradeId,
                    'execution_price' => $trade_price,
                    'message' => ucfirst(str_replace('_', ' ', $order['order_type'])) . ' triggered at ' . $trade_price
                ];
                
                // Send notification to user
                self::notifyOrderTriggered($order, $tradeData);
                
                return [
                    'success' => true,
                    'message' => $tradeData['message'],
                    'trade_id' => $tradeId,
                    'execution_price' => $trade_price
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to create trade'];
            }
            
        } catch (Exception $e) {
            error_log("PendingTradeMonitorService::triggerPendingOrder - " . $e->getMessage());
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }
    
    /**
     * Expire a pending order
     * 
     * @param int $order_id - Pending order ID
     * @return bool - Success status
     */
    private static function expirePendingOrder($order_id) {
        try {
            $conn = Database::getConnection();
            
            $status = 'expired';
            $stmt = $conn->prepare("UPDATE pending_trades SET status = ? WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("si", $status, $order_id);
            
            return $stmt->execute() && $stmt->affected_rows > 0;
        } catch (Exception $e) {
            error_log("PendingTradeMonitorService::expirePendingOrder - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cancel a pending order with a reason
     * 
     * @param int $order_id - Pending order ID
     * @param string $reason - Cancellation reason
     * @return bool - Success status
     */
    private static function cancelPendingOrder($order_id, $reason = '') {
        try {
            $conn = Database::getConnection();
            
            $status = 'cancelled';
            $stmt = $conn->prepare("UPDATE pending_trades SET status = ? WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("si", $status, $order_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                if ($reason) {
                    error_log("PendingTradeMonitor: Cancelled order #{$order_id} - Reason: {$reason}");
                }
                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("PendingTradeMonitorService::cancelPendingOrder - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check and expire orders only (separate from trigger checking)
     * Useful for scheduled tasks
     * 
     * @return array - Summary of expired orders
     */
    public static function checkExpiredOrders() {
        $expired = 0;
        
        try {
            $conn = Database::getConnection();
            $currentTime = gmdate('Y-m-d H:i:s');
            
            // Find expired orders
            $stmt = $conn->prepare("SELECT id, pair, order_type FROM pending_trades WHERE status = 'pending' AND expiry_date IS NOT NULL AND expiry_date <= ?");
            $stmt->bind_param("s", $currentTime);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($order = $result->fetch_assoc()) {
                if (self::expirePendingOrder($order['id'])) {
                    $expired++;
                    error_log("PendingTradeMonitor: Expired order #{$order['id']} for {$order['pair']}");
                }
            }
            
            return [
                'success' => true,
                'expired' => $expired
            ];
            
        } catch (Exception $e) {
            error_log("PendingTradeMonitorService::checkExpiredOrders - " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to check expired orders: ' . $e->getMessage()
            ];
        }
    }
}
