<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/ProfitCalculationService.php';
require_once __DIR__ . '/../services/PriceFormatterService.php';

class PendingTradeService
{
    
    public static function getPendingTrades($user_id) {
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

            $stmt = $conn->prepare("SELECT * FROM pending_trades WHERE userid = ? AND account = ? ORDER BY date DESC");
            $stmt->bind_param("is", $user_id, $current_account);
            $stmt->execute();
            $result = $stmt->get_result();

            $pending_trades = [];
            while ($row = $result->fetch_assoc()) {
                $pending_trades[] = $row;
            }

            return $pending_trades;
        } catch (Exception $e) {
            error_log("PendingTradeService::getPendingTrades - " . $e->getMessage());
            Response::error('Failed to retrieve pending trades', 500);
        }
    }

    public static function submitPendingTrade($user_id, $input) {
        try {
            $conn = Database::getConnection();

            $account = TradeAccountService::getUserCurrentAccount($user_id);
            $current_account = $account['id_hash'];
            $leverage = intval($account['leverage']);

            // Get pair details
            $pair = $input['pair'];
            $stmt = $conn->prepare("SELECT spread, pip_value, digits, lot_size, margin_percent FROM pairs WHERE name = ?");
            $stmt->bind_param("s", $pair);
            $stmt->execute();
            $pairResult = $stmt->get_result();
            
            if ($pairResult->num_rows === 0) {
                Response::error('Pair not found', 404);
            }
            
            $pairData = $pairResult->fetch_assoc();

            // Get current market price from cache
            $currentPriceRaw = ProfitCalculationService::getCurrentPairPrice($pair);
            if ($currentPriceRaw === null) {
                Response::error('Unable to get current price for ' . $pair, 503);
            }

            // Format current price as string
            $current_price = PriceFormatterService::formatPriceString($pair, $currentPriceRaw);
            
            // Format trigger price (order_value) as string
            $triggerPriceRaw = floatval($input['order_value']);
            $trigger_price = PriceFormatterService::formatPriceString($pair, $triggerPriceRaw);

            // Determine order type based on trigger price vs current price
            $type = $input['type']; // 'buy' or 'sell'
            $order_type = self::determineOrderType($type, $triggerPriceRaw, $currentPriceRaw);

            // Calculate required margin (same as opening a trade)
            $requiredMargin = ProfitCalculationService::calculateRequiredMargin($input, $pairData, $leverage, $currentPriceRaw);
            if ($requiredMargin === null) {
                Response::error('Failed to calculate required margin', 500);
            }

            $margin = $requiredMargin['margin'];

            // Check if user has enough free margin
            // Get current prices for P&L calculation
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
            if ($balances && $balances['freeMargin'] < $margin) {
                Response::error('Insufficient free margin for pending order. Required: $' . number_format($margin, 2) . ', Available: $' . number_format($balances['freeMargin'], 2), 400);
            }

            // Generate unique reference
            $ref = rand(1000000000, 9999999999);
            $lot = $input['lot'];
            $stop_loss = $input['stop_loss'] ?? '0';
            $take_profit = $input['take_profit'] ?? '0';
            
            // Handle expiry_date conversion from ISO format to MySQL datetime
            // Frontend sends: '2026-01-11T23:43:00.000Z' (ISO 8601 UTC)
            // Database needs: '2026-01-11 23:43:00' (MySQL datetime)
            $expiry_date = null;
            if (isset($input['expiry_date']) && !empty($input['expiry_date'])) {
                try {
                    // Convert ISO format to MySQL datetime
                    $expiryDateTime = new DateTime($input['expiry_date']);
                    
                    // Validate that expiry date is in the future
                    $currentDateTime = new DateTime('now', new DateTimeZone('UTC'));
                    
                    if ($expiryDateTime <= $currentDateTime) {
                        Response::error('Expiry date must be in the future. Current GMT time: ' . $currentDateTime->format('Y-m-d H:i:s'), 400);
                    }
                    
                    $expiry_date = $expiryDateTime->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    Response::error('Invalid expiry date format: ' . $e->getMessage(), 400);
                }
            }
            
            $trade_acc = $account['type'];
            $date = gmdate('Y-m-d H:i:s');
            $status = 'pending';

            $stmt = $conn->prepare("INSERT INTO pending_trades (userid, account, ref, pair, type, order_type, trigger_price, current_price, margin, lot, leverage, stop_loss, take_profit, expiry_date, trade_acc, date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssssddsssssss", $user_id, $current_account, $ref, $pair, $type, $order_type, $trigger_price, $current_price, $margin, $lot, $leverage, $stop_loss, $take_profit, $expiry_date, $trade_acc, $date, $status);
            
            if ($stmt->execute()) {
                $pending_trades = self::getPendingTrades($user_id);
                Response::success([
                    'pendingTrades' => $pending_trades,
                    'message' => ucfirst(str_replace('_', ' ', $order_type)) . ' order created'
                ]);
            } else {
                Response::error('Failed to submit pending trade', 500);
            }
        } catch (Exception $e) {
            error_log("PendingTradeService::submitPendingTrade - " . $e->getMessage());
            Response::error('Failed to submit pending trade', 500);
        }
    }

    /**
     * Determine order type based on trade type and price comparison
     * 
     * @param string $type - 'buy' or 'sell'
     * @param float $triggerPrice - Price at which order should trigger
     * @param float $currentPrice - Current market price
     * @return string - 'buy_stop', 'sell_stop', 'buy_limit', or 'sell_limit'
     */
    private static function determineOrderType($type, $triggerPrice, $currentPrice) {
        if ($type === 'buy') {
            // Buy Stop: trigger price is ABOVE current price
            // Buy Limit: trigger price is BELOW current price
            return ($triggerPrice > $currentPrice) ? 'buy_stop' : 'buy_limit';
        } else {
            // Sell Stop: trigger price is BELOW current price
            // Sell Limit: trigger price is ABOVE current price
            return ($triggerPrice < $currentPrice) ? 'sell_stop' : 'sell_limit';
        }
    }
    
    /**
     * Cancel a pending trade
     * 
     * @param int $user_id
     * @param int $pending_trade_id
     */
    public static function cancelPendingTrade($user_id, $pending_trade_id) {
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
            
            // Update status to cancelled
            $status = 'cancelled';
            $stmt = $conn->prepare("UPDATE pending_trades SET status = ? WHERE id = ? AND userid = ? AND account = ? AND status = 'pending'");
            $stmt->bind_param("siis", $status, $pending_trade_id, $user_id, $current_account);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $pending_trades = self::getPendingTrades($user_id);
                Response::success([
                    'pendingTrades' => $pending_trades,
                    'message' => 'Pending order cancelled successfully'
                ]);
            } else {
                Response::error('Pending trade not found or already cancelled', 404);
            }
        } catch (Exception $e) {
            error_log("PendingTradeService::cancelPendingTrade - " . $e->getMessage());
            Response::error('Failed to cancel pending trade', 500);
        }
    }
}
