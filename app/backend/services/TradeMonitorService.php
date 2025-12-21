<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ProfitCalculationService.php';
require_once __DIR__ . '/../services/TradeService.php';
require_once __DIR__ . '/../services/TradeAlterMonitorService.php';

/**
 * TradeMonitorService
 * 
 * Monitors open trades for stop_loss and take_profit triggers
 * Auto-closes trades when price conditions are met
 */
class TradeMonitorService
{
    /**
     * Check all open trades and close those that hit stop_loss or take_profit
     * 
     * @param array $currentPrices - Array of ['pairkey' => price] from WebSocket
     * @return array - Statistics about closed trades
     */
    public static function monitorAndCloseTrades($currentPrices) {
        try {
            $conn = Database::getConnection();
            
            // Get all open trades with stop_loss or take_profit set
            $query = "
                SELECT t.*, p.lot_size, p.digits, p.spread, p.pip_value, p.name as pair_name
                FROM trades t
                LEFT JOIN pairs p ON t.pair = p.name
                WHERE (t.close_date IS NULL OR t.close_date = '')
                AND (
                    (t.stop_loss IS NOT NULL AND t.stop_loss != '0' AND t.stop_loss != 0)
                    OR 
                    (t.take_profit IS NOT NULL AND t.take_profit != '0' AND t.take_profit != 0)
                )
                ORDER BY t.date ASC
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $tradesResult = $stmt->get_result();
            
            $closedTrades = [];
            $stats = [
                'checked' => 0,
                'closed' => 0,
                'stop_loss' => 0,
                'take_profit' => 0,
                'errors' => 0
            ];
            
            while ($trade = $tradesResult->fetch_assoc()) {
                $stats['checked']++;
                
                // Get current price for this pair
                $pairKey = str_replace(['/', 'USD'], ['', 'USDT'], $trade['pair']);
                $pairKey = strtolower($pairKey);
                
                if (!isset($currentPrices[$pairKey])) {
                    // No price available for this pair yet
                    continue;
                }
                
                $realPrice = floatval($currentPrices[$pairKey]);
                
                // Get the correct price (altered or real) - same pattern as calculateTradeProfit
                $alteredPrice = TradeAlterMonitorService::getAlteredPriceForTradeById(
                    $trade['id'],
                    $trade['ref'],
                    $trade['pair'],
                    $trade['account'],
                    $trade['trade_acc']
                );
                
                $currentPrice = $alteredPrice ?? $realPrice;
                
                echo "[MONITOR SL/TP] Trade #{$trade['id']} ({$trade['ref']}): Real price={$realPrice}, Altered price=" . ($alteredPrice ?? 'null') . ", Using={$currentPrice}\n";
                
                // Check if trade should be closed
                $trigger = ProfitCalculationService::checkTradeTriggers($trade, $currentPrice);
                
                if ($trigger && $trigger['shouldClose']) {
                    // Close the trade using centralized TradeService with notification
                    $closeResult = TradeService::closeTradeWithNotification(
                        $trigger['reason'],
                        ['id' => $trade['id']],
                        $currentPrice
                    );
                    
                    if ($closeResult['success']) {
                        $closedTrades[] = [
                            'trade_id' => $trade['id'],
                            'ref' => $trade['ref'],
                            'pair' => $trade['pair'],
                            'type' => $trade['type'],
                            'reason' => $trigger['reason'],
                            'profit' => $closeResult['data']['profit'] ?? '0.00',
                            'userid' => $trade['userid']
                        ];
                        
                        $stats['closed']++;
                        $stats[$trigger['reason']]++;
                        
                        echo "[MONITOR] Closed trade #{$trade['id']} ({$trade['pair']} {$trade['type']}) - {$trigger['reason']} hit at {$currentPrice}\n";
                    } else {
                        $stats['errors']++;
                        error_log("TradeMonitorService: Failed to close trade #{$trade['id']} - " . ($closeResult['message'] ?? 'Unknown error'));
                    }
                }
            }
            
            return [
                'success' => true,
                'stats' => $stats,
                'closedTrades' => $closedTrades
            ];
            
        } catch (Exception $e) {
            error_log("TradeMonitorService::monitorAndCloseTrades - " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get all trades that are approaching their stop_loss or take_profit
     * Useful for notifications/alerts
     * 
     * @param array $currentPrices - Array of ['pairkey' => price]
     * @param float $threshold - Percentage threshold (e.g., 5 = within 5%)
     * @return array - Trades approaching triggers
     */
    public static function getTradesNearTriggers($currentPrices, $threshold = 5.0) {
        try {
            $conn = Database::getConnection();
            
            $query = "
                SELECT t.*, p.lot_size, p.digits, p.name as pair_name
                FROM trades t
                LEFT JOIN pairs p ON t.pair = p.name
                WHERE (t.close_date IS NULL OR t.close_date = '')
                AND (
                    (t.stop_loss IS NOT NULL AND t.stop_loss != '0' AND t.stop_loss != 0)
                    OR 
                    (t.take_profit IS NOT NULL AND t.take_profit != '0' AND t.take_profit != 0)
                )
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $nearTriggers = [];
            
            while ($trade = $result->fetch_assoc()) {
                $pairKey = str_replace(['/', 'USD'], ['', 'USDT'], $trade['pair']);
                $pairKey = strtolower($pairKey);
                
                if (!isset($currentPrices[$pairKey])) {
                    continue;
                }
                
                $currentPrice = floatval($currentPrices[$pairKey]);
                $stop_loss = floatval($trade['stop_loss']);
                $take_profit = floatval($trade['take_profit']);
                
                // Check distance to stop_loss
                if ($stop_loss > 0) {
                    $distanceToSL = abs(($currentPrice - $stop_loss) / $currentPrice) * 100;
                    
                    if ($distanceToSL <= $threshold) {
                        $nearTriggers[] = [
                            'trade_id' => $trade['id'],
                            'ref' => $trade['ref'],
                            'pair' => $trade['pair'],
                            'type' => $trade['type'],
                            'trigger_type' => 'stop_loss',
                            'trigger_price' => $stop_loss,
                            'current_price' => $currentPrice,
                            'distance_percent' => round($distanceToSL, 2)
                        ];
                    }
                }
                
                // Check distance to take_profit
                if ($take_profit > 0) {
                    $distanceToTP = abs(($currentPrice - $take_profit) / $currentPrice) * 100;
                    
                    if ($distanceToTP <= $threshold) {
                        $nearTriggers[] = [
                            'trade_id' => $trade['id'],
                            'ref' => $trade['ref'],
                            'pair' => $trade['pair'],
                            'type' => $trade['type'],
                            'trigger_type' => 'take_profit',
                            'trigger_price' => $take_profit,
                            'current_price' => $currentPrice,
                            'distance_percent' => round($distanceToTP, 2)
                        ];
                    }
                }
            }
            
            return $nearTriggers;
            
        } catch (Exception $e) {
            error_log("TradeMonitorService::getTradesNearTriggers - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Monitor all accounts for margin calls and auto-close trades
     * 
     * @param array $currentPrices - Array of ['pairkey' => price] from WebSocket
     * @param float $marginCallLevel - Margin call threshold (default 50%)
     * @param float $stopOutLevel - Stop out threshold (default 20%)
     * @return array - Statistics about margin calls
     */
    public static function monitorMarginCalls($currentPrices, $marginCallLevel = 50, $stopOutLevel = 20) {
        try {
            $conn = Database::getConnection();
            
            // Get all ACCOUNTS with open trades (not users, since users can have multiple accounts)
            $query = "
                SELECT DISTINCT t.account, t.userid, a.balance
                FROM trades t
                INNER JOIN accounts a ON t.account = a.id_hash
                WHERE (t.close_date IS NULL OR t.close_date = '')
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $stats = [
                'accounts_checked' => 0,
                'margin_calls' => 0,
                'stop_outs' => 0,
                'trades_closed' => 0,
                'errors' => 0
            ];
            
            $closedTrades = [];
            
            while ($account = $result->fetch_assoc()) {
                $accountId = $account['account'];
                $userId = $account['userid'];
                $stats['accounts_checked']++;
                
                // Calculate balances for THIS SPECIFIC ACCOUNT using shared service
                $balances = ProfitCalculationService::calculateAccountBalances($accountId, $currentPrices);
                
                if (!$balances) {
                    continue;
                }
                
                // Check for margin call
                $marginCheck = ProfitCalculationService::checkMarginCall($balances, $marginCallLevel, $stopOutLevel);
                
                if ($marginCheck['requiresMarginCall']) {
                    echo "[MARGIN CALL] Account {$accountId} (User {$userId}): Margin Level = {$marginCheck['marginLevel']}% - Action: {$marginCheck['action']}\n";
                    
                    if ($marginCheck['requiresStopOut']) {
                        // STOP OUT: Close ALL trades for this account
                        $stats['stop_outs']++;
                        $closeResult = self::closeAllTradesForAccount($accountId, $currentPrices, 'margin_call');
                        $stats['trades_closed'] += $closeResult['closed'];
                        $closedTrades = array_merge($closedTrades, $closeResult['closedTrades']);
                        
                        echo "[STOP OUT] Account {$accountId}: Closed {$closeResult['closed']} trades\n";
                        
                    } else {
                        // MARGIN CALL: Close worst trades until margin is restored
                        $stats['margin_calls']++;
                        $closeResult = self::closeWorstTradesForAccount($accountId, $currentPrices, $balances, $marginCallLevel);
                        $stats['trades_closed'] += $closeResult['closed'];
                        $closedTrades = array_merge($closedTrades, $closeResult['closedTrades']);
                        
                        echo "[MARGIN CALL] Account {$accountId}: Closed {$closeResult['closed']} losing trades\n";
                    }
                }
            }
            
            return [
                'success' => true,
                'stats' => $stats,
                'closedTrades' => $closedTrades
            ];
            
        } catch (Exception $e) {
            error_log("TradeMonitorService::monitorMarginCalls - " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Close all trades for a specific account (Stop Out)
     * 
     * @param string $accountId - Account ID hash
     * @param array $currentPrices
     * @param string $reason
     * @return array
     */
    private static function closeAllTradesForAccount($accountId, $currentPrices, $reason = 'margin_call') {
        try {
            $conn = Database::getConnection();
            
            // Get all open trades for THIS ACCOUNT
            $stmt = $conn->prepare("
                SELECT t.*, p.lot_size, p.digits
                FROM trades t
                LEFT JOIN pairs p ON t.pair = p.name
                WHERE t.account = ?
                AND (t.close_date IS NULL OR t.close_date = '')
            ");
            $stmt->bind_param("s", $accountId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $closedTrades = [];
            $closed = 0;
            
            while ($trade = $result->fetch_assoc()) {
                $pairKey = str_replace(['/', 'USD'], ['', 'USDT'], $trade['pair']);
                $pairKey = strtolower($pairKey);
                
                if (!isset($currentPrices[$pairKey])) {
                    continue;
                }
                
                $currentPrice = floatval($currentPrices[$pairKey]);
                
                // Use centralized TradeService with notification
                $closeResult = TradeService::closeTradeWithNotification(
                    $reason,
                    ['id' => $trade['id']],
                    $currentPrice
                );
                
                if ($closeResult['success']) {
                    $profitStr = $closeResult['data']['profit'] ?? '0.00';
                    $closedTrades[] = [
                        'trade_id' => $trade['id'],
                        'ref' => $trade['ref'],
                        'pair' => $trade['pair'],
                        'type' => $trade['type'],
                        'reason' => $reason,
                        'profit' => $profitStr,
                        'userid' => $trade['userid'],
                        'account' => $accountId
                    ];
                    $closed++;
                }
            }
            
            return [
                'closed' => $closed,
                'closedTrades' => $closedTrades
            ];
            
        } catch (Exception $e) {
            error_log("TradeMonitorService::closeAllTradesForAccount - " . $e->getMessage());
            return [
                'closed' => 0,
                'closedTrades' => []
            ];
        }
    }
    
    /**
     * Close worst-performing trades for a specific account until margin level is restored
     * 
     * @param string $accountId - Account ID hash
     * @param array $currentPrices
     * @param array $currentBalances
     * @param float $targetMarginLevel
     * @return array
     */
    private static function closeWorstTradesForAccount($accountId, $currentPrices, $currentBalances, $targetMarginLevel = 50) {
        try {
            $conn = Database::getConnection();
            
            // Get trades for THIS ACCOUNT sorted by profit (worst first)
            $stmt = $conn->prepare("
                SELECT t.*, p.lot_size, p.digits
                FROM trades t
                LEFT JOIN pairs p ON t.pair = p.name
                WHERE t.account = ?
                AND (t.close_date IS NULL OR t.close_date = '')
            ");
            $stmt->bind_param("s", $accountId);
            $stmt->execute();
            $tradesResult = $stmt->get_result();
            
            // Build trades array with profits
            $trades = [];
            while ($trade = $tradesResult->fetch_assoc()) {
                $pairKey = str_replace(['/', 'USD'], ['', 'USDT'], $trade['pair']);
                $pairKey = strtolower($pairKey);
                
                if (isset($currentPrices[$pairKey])) {
                    $currentPrice = $currentPrices[$pairKey];
                    $pairConfig = [
                        'lot_size' => $trade['lot_size'] ?? 1,
                        'digits' => $trade['digits'] ?? 2
                    ];
                    
                    $profitCalc = ProfitCalculationService::calculateTradeProfit($trade, $currentPrice, $pairConfig);
                    $trade['calculatedProfit'] = $profitCalc ? $profitCalc['totalProfit'] : 0;
                    $trades[] = $trade;
                }
            }
            
            // Sort by profit (worst first)
            usort($trades, function($a, $b) {
                return $a['calculatedProfit'] <=> $b['calculatedProfit'];
            });
            
            $closedTrades = [];
            $closed = 0;
            $currentEquity = $currentBalances['equity'];
            $currentMargin = $currentBalances['totalMargin'];
            
            foreach ($trades as $trade) {
                // Check if margin level is now acceptable
                if ($currentMargin > 0) {
                    $marginLevel = ($currentEquity / $currentMargin) * 100;
                    
                    if ($marginLevel > $targetMarginLevel) {
                        echo "[MARGIN RESTORED] Account {$accountId}: Margin level now {$marginLevel}%\n";
                        break;
                    }
                }
                
                // Close this trade
                $pairKey = str_replace(['/', 'USD'], ['', 'USDT'], $trade['pair']);
                $pairKey = strtolower($pairKey);
                
                if (!isset($currentPrices[$pairKey])) {
                    continue;
                }
                
                $currentPrice = floatval($currentPrices[$pairKey]);
                
                // Use centralized TradeService with notification
                $closeResult = TradeService::closeTradeWithNotification(
                    'margin_call',
                    ['id' => $trade['id']],
                    $currentPrice
                );
                
                if ($closeResult['success']) {
                    $profitStr = $closeResult['data']['profit'] ?? '0.00';
                    $closedTrades[] = [
                        'trade_id' => $trade['id'],
                        'ref' => $trade['ref'],
                        'pair' => $trade['pair'],
                        'type' => $trade['type'],
                        'reason' => 'margin_call',
                        'profit' => $profitStr,
                        'userid' => $trade['userid'],
                        'account' => $accountId
                    ];
                    $closed++;
                    
                    // Update equity and margin for next iteration
                    $tradeProfit = floatval(str_replace('+', '', $profitStr));
                    $currentEquity += $tradeProfit;
                    $currentMargin -= floatval($trade['margin']);
                    
                    echo "[CLOSED] Trade #{$trade['id']} ({$trade['pair']}) - Profit: {$closeResult['profit']}\n";
                }
            }
            
            return [
                'closed' => $closed,
                'closedTrades' => $closedTrades
            ];
            
        } catch (Exception $e) {
            error_log("TradeMonitorService::closeWorstTradesForAccount - " . $e->getMessage());
            return [
                'closed' => 0,
                'closedTrades' => []
            ];
        }
    }
}
