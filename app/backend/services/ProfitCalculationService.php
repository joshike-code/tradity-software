<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

class ProfitCalculationService
{
    /**
     * Get current price for a trading pair
     * SECURITY: Only uses live WebSocket prices to prevent exploits
     * 
     * @param string $pair - Trading pair name (e.g., 'BTC/USD')
     * @param int $maxAge - Maximum age in seconds (default 10)
     * @return float|null - Current price or null if not available
     */
    public static function getCurrentPairPrice($pair, $maxAge = 10) {
        try {
            // Transform pair name to cache key format (e.g., BTC/USD -> btcusdt)
            $pairKey = str_replace(['/', 'USD'], ['', 'USDT'], $pair);
            $pairKey = strtolower($pairKey);
            
            // Check WebSocket live prices cache
            $livePricesFile = __DIR__ . '/../cache/websocket_live_prices.json';
            
            if (!file_exists($livePricesFile)) {
                error_log("ProfitCalculationService::getCurrentPairPrice - WebSocket not running (no live prices file)");
                return null;
            }
            
            $priceData = json_decode(file_get_contents($livePricesFile), true);
            
            if (!$priceData || !isset($priceData['timestamp']) || !isset($priceData['prices'])) {
                error_log("ProfitCalculationService::getCurrentPairPrice - Invalid live prices format");
                return null;
            }
            
            // Check if prices are fresh (prevent stale price exploits)
            $age = time() - $priceData['timestamp'];
            if ($age > $maxAge) {
                error_log("ProfitCalculationService::getCurrentPairPrice - Prices too old ({$age}s). WebSocket may be down.");
                return null;
            }
            
            // Get the price
            if (isset($priceData['prices'][$pairKey])) {
                return floatval($priceData['prices'][$pairKey]);
            }
            
            // Price not found for this pair
            error_log("ProfitCalculationService::getCurrentPairPrice - No live price for {$pair} (key: {$pairKey})");
            return null;
            
        } catch (Exception $e) {
            error_log("ProfitCalculationService::getCurrentPairPrice - " . $e->getMessage());
            return null;
        }
    }

    public static function calculateTradeProfit($trade, $currentPrice, $pairConfig) {
        try {
            // Check if there's an active alter for this trade
            // This makes ALL profit calculations alter-aware automatically
            if (isset($trade['id']) && isset($trade['ref']) && isset($trade['pair']) && isset($trade['account']) && isset($trade['trade_acc'])) {
                require_once __DIR__ . '/TradeAlterMonitorService.php';
                $alteredPrice = TradeAlterMonitorService::getAlteredPriceForTradeById(
                    $trade['id'],
                    $trade['ref'],
                    $trade['pair'],
                    $trade['account'],
                    $trade['trade_acc']
                );
                
                // Use altered price if available
                if ($alteredPrice !== null) {
                    $currentPrice = $alteredPrice;
                    // echo "[PROFIT_CALC] Using altered price $alteredPrice for trade {$trade['ref']}\n";
                }
            }
            
            $trade_price = floatval($trade['trade_price']);
            $tradeLot = floatval($trade['lot']);
            $lotSize = floatval($pairConfig['lot_size'] ?? 1);
            $lotValue = $tradeLot * $lotSize;
            
            // Calculate profit based on trade type
            if ($trade['type'] === 'buy') {
                $profit = $currentPrice - $trade_price;
            } else { // sell
                $profit = $trade_price - $currentPrice;
            }
            
            $totalProfit = $profit * $lotValue;
            $roundedProfit = number_format(abs($totalProfit), 2, '.', '');
            $rawProfit = number_format($totalProfit, 2, '.', '');
            $formattedProfit = $totalProfit > 0 ? "+{$roundedProfit}" : "-{$roundedProfit}";
            $profitStatus = $totalProfit > 0 ? 'profit' : ($totalProfit < 0 ? 'loss' : 'neutral');
            
            return [
                'totalProfit' => $totalProfit,
                'formattedProfit' => $formattedProfit,
                'rawProfit' => $rawProfit,
                'profitStatus' => $profitStatus,
                'currentPrice' => $currentPrice
            ];
        } catch (Exception $e) {
            error_log("ProfitCalculationService::calculateTradeProfit - " . $e->getMessage());
            return null;
        }
    }

    public static function calculateSpread($pair, $currentPrice) {
        try {
            $spread = floatval($pair['spread']) ?? null;
            $pip_value = floatval($pair['pip_value']) ?? null;
            $digits = intval($pair['digits']) ?? null;
            if ($spread === null || $pip_value === null || $digits === null) {
                return null;
            }
            $spreadUsd = $spread * $pip_value;
            
            // Calculate buy/sell prices
            $buyPrice = round($currentPrice + $spreadUsd, $digits);
            $sellPrice = round($currentPrice - $spreadUsd, $digits);
            $currentPrice = round($currentPrice, $digits);
            
            return [
                'currentPrice' => $currentPrice,
                'buyPrice' => $buyPrice,
                'sellPrice' => $sellPrice,
                'spread' => $spread,
                'spreadUsd' => $spreadUsd
            ];
        } catch (Exception $e) {
            error_log("ProfitCalculationService::calculateSpread - " . $e->getMessage());
            return null;
        }
    }
    
    public static function calculateRequiredMargin($trade, $pairData, $leverage, $currentPrice) {
        try {
            $lot = floatval($trade['lot']);
            $lot_size = floatval($pairData['lot_size']);
            $margin_percent = floatval($pairData['margin_percent']);
            
            // Calculate margin using pair's margin percent
            $lotValue = $lot * $lot_size;
            $tradeValue = $lotValue * $currentPrice;
            $leverageImpact = $tradeValue / $leverage;

            $buyMargin = $leverageImpact * ($margin_percent / 100);
            $sellMargin = 0;
            $margin = 0;

            if($trade['type'] === 'buy') {
                $margin = $buyMargin;
            } else {
                $margin = $sellMargin;
            }
            
            
            return [
                'buyMargin' => $buyMargin,
                'sellMargin' => $sellMargin,
                'margin' => $margin
            ];
        } catch (Exception $e) {
            error_log("ProfitCalculationService::calculateRequiredMargin - " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Calculate all balances for an account
     * Matches frontend getBalances logic
     * 
     * @param int $userId
     * @param array $currentPrices - Array of ['pair' => price]
     * @return array - Balance calculations
     */

    public static function calculateAccountBalances($accountId, $currentPrices) {
        try {
            $conn = Database::getConnection();
            
            // CRITICAL: Disable MySQL query cache to prevent stale data
            // This forces fresh data on every call - essential for real-time trading
            $conn->query("SET SESSION query_cache_type = OFF");
            
            // Get account balance
            $stmt = $conn->prepare("SELECT balance, leverage FROM accounts WHERE id_hash = ?");
            $stmt->bind_param("s", $accountId);
            $stmt->execute();
            $accountResult = $stmt->get_result();
            
            if ($accountResult->num_rows === 0) {
                return null;
            }
            
            $account = $accountResult->fetch_assoc();
            $balance = floatval($account['balance']);
            
            // Get all open trades for THIS ACCOUNT
            // Include all fields needed for alter price lookup: id, ref, pair, account, trade_acc
            $stmt = $conn->prepare("
                SELECT t.id, t.ref, t.pair, t.account, t.trade_acc, t.trade_price, t.lot, t.type, t.margin, 
                       p.lot_size, p.digits, p.name as pair_name 
                FROM trades t
                LEFT JOIN pairs p ON t.pair = p.name
                WHERE t.account = ? 
                AND (t.close_date IS NULL OR t.close_date = '')
            ");
            $stmt->bind_param("s", $accountId);
            $stmt->execute();
            $tradesResult = $stmt->get_result();
            
            $profit_loss = 0;
            $totalMargin = 0;
            $tradesProcessed = 0;
            $tradesSkipped = 0;
            
            while ($trade = $tradesResult->fetch_assoc()) {
                // Add up margins
                $totalMargin += floatval($trade['margin']);
                
                // Calculate P&L if we have current price
                $pairKey = str_replace(['/', 'USD'], ['', 'USDT'], $trade['pair']);
                $pairKey = strtolower($pairKey);
                
                if (isset($currentPrices[$pairKey])) {
                    $currentPrice = $currentPrices[$pairKey];
                    $pairConfig = [
                        'lot_size' => $trade['lot_size'] ?? 1,
                        'digits' => $trade['digits'] ?? 2
                    ];
                    
                    $profitCalc = self::calculateTradeProfit($trade, $currentPrice, $pairConfig);
                    if ($profitCalc) {
                        $profit_loss += $profitCalc['totalProfit'];
                        $tradesProcessed++;
                    }
                } else {
                    $tradesSkipped++;
                }
            }
            
            if ($tradesSkipped > 0) {
                error_log("[ACCOUNT CALC] WARNING: Account {$accountId} has {$tradesSkipped} trades with missing prices");
            }
            
            $roundedProfit = number_format(abs($profit_loss), 2, '.', '');
            $formattedProfit_loss = $profit_loss > 0 ? "+{$roundedProfit}" : ($profit_loss < 0 ? "-{$roundedProfit}" : '0.00');
            
            $equity = $balance + $profit_loss;
            $freeMargin = $equity - $totalMargin;
            
            return [
                'balance' => $balance,
                'equity' => $equity,
                'freeMargin' => $freeMargin,
                'totalMargin' => $totalMargin,
                'profit_loss' => $profit_loss,
                'formattedProfit_loss' => $formattedProfit_loss
            ];
        } catch (Exception $e) {
            error_log("ProfitCalculationService::calculateAccountBalances - " . $e->getMessage());
            return null;
        }
    }

    public static function calculateCurrentAccountBalances($userId, $currentPrices = []) {
        try {
            $conn = Database::getConnection();

            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return null;
            }
            
            $user = $result->fetch_assoc();
            $currentAccount = $user['current_account'];
            
            // Reuse the account-based calculation
            return self::calculateAccountBalances($currentAccount, $currentPrices);
            
        } catch (Exception $e) {
            error_log("ProfitCalculationService::calculateCurrentAccountBalances - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get individual trade profits for all open trades
     * 
     * @param int $userId
     * @param array $currentPrices - Array of ['pair' => price]
     * @param array $filterPairs - Optional array of pairs to filter (e.g., ['BTC/USD', 'ETH/USD'])
     * @return array - Array of trade profits (numeric indexed array)
     */
    public static function getOpenTradesProfits($userId, $currentPrices = [], $filterPairs = null) {
        try {
            $conn = Database::getConnection();
            
            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return [];
            }
            
            $user = $result->fetch_assoc();
            $currentAccount = $user['current_account'];
            
            // Build query with optional pair filtering
            $query = "
                SELECT t.*, p.lot_size, p.digits, p.name as pair_name 
                FROM trades t
                LEFT JOIN pairs p ON t.pair = p.name
                WHERE t.userid = ? 
                AND t.account = ? 
                AND (t.close_date IS NULL OR t.close_date = '')
                ORDER BY date DESC
            ";
            
            // Add pair filtering if specified
            if ($filterPairs !== null && is_array($filterPairs) && count($filterPairs) > 0) {
                $placeholders = implode(',', array_fill(0, count($filterPairs), '?'));
                $query .= " AND t.pair IN ($placeholders)";
            }
            
            $stmt = $conn->prepare($query);
            
            // Bind parameters
            if ($filterPairs !== null && is_array($filterPairs) && count($filterPairs) > 0) {
                $types = 'is' . str_repeat('s', count($filterPairs));
                $params = array_merge([$userId, $currentAccount], $filterPairs);
                $stmt->bind_param($types, ...$params);
            } else {
                $stmt->bind_param("is", $userId, $currentAccount);
            }
            
            $stmt->execute();
            $tradesResult = $stmt->get_result();
            
            $tradeProfits = [];
            
            while ($trade = $tradesResult->fetch_assoc()) {
                $tradeId = $trade['id'];
                $pairKey = str_replace(['/', 'USD'], ['', 'USDT'], $trade['pair']);
                $pairKey = strtolower($pairKey);
                
                // Always include the trade, but only calculate P&L if we have current price
                $tradeData = [
                    'id' => $tradeId,
                    'ref' => $trade['ref'],
                    'pair' => $trade['pair'],
                    'type' => $trade['type'],
                    'lot' => floatval($trade['lot']),
                    'leverage' => intval($trade['leverage']),
                    'trade_price' => floatval($trade['trade_price']),
                    'take_profit' => $trade['take_profit'],
                    'stop_loss' => $trade['stop_loss'],
                    'commission' => floatval($trade['commission']),
                    'swap' => floatval($trade['swap']),
                    'margin' => floatval($trade['margin']),
                    'date' => $trade['date']
                ];
                
                // Get current price for this pair
                $currentPrice = isset($currentPrices[$pairKey]) ? $currentPrices[$pairKey] : null;
                
                if ($currentPrice !== null) {
                    $pairConfig = [
                        'lot_size' => $trade['lot_size'] ?? 1,
                        'digits' => $trade['digits'] ?? 2
                    ];
                    
                    // calculateTradeProfit will automatically check for altered prices
                    $profitCalc = self::calculateTradeProfit($trade, $currentPrice, $pairConfig);
                    if ($profitCalc) {
                        $tradeData['formattedProfit'] = $profitCalc['formattedProfit'];
                        $tradeData['profitStatus'] = $profitCalc['profitStatus'];
                        $tradeData['totalProfit'] = $profitCalc['totalProfit'];
                        $tradeData['currentPrice'] = $profitCalc['currentPrice'];
                    }
                } else {
                    // No price available yet
                    $tradeData['formattedProfit'] = null;
                    $tradeData['profitStatus'] = 'pending';
                    $tradeData['totalProfit'] = 0;
                    $tradeData['currentPrice'] = null;
                }
                
                $tradeProfits[] = $tradeData;
            }
            
            return $tradeProfits;
        } catch (Exception $e) {
            error_log("ProfitCalculationService::getOpenTradesProfits - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if a trade should be closed due to stop-loss or take-profit
     * 
     * @param array $trade - Trade record
     * @param float $currentPrice - Current price (can be real or altered - caller handles this)
     * @return array|null - ['shouldClose' => bool, 'reason' => string] or null
     */
    public static function checkTradeTriggers($trade, $currentPrice) {
        try {
            $stop_loss = floatval($trade['stop_loss']);
            $take_profit = floatval($trade['take_profit']);
            
            if ($stop_loss > 0 || $take_profit > 0) {
                if ($trade['type'] === 'buy') {
                    // For buy trades
                    if ($take_profit > 0 && $currentPrice >= $take_profit) {
                        echo "[TRIGGER HIT] Trade {$trade['ref']}: TAKE PROFIT hit! Price {$currentPrice} >= TP {$take_profit}\n";
                        return ['shouldClose' => true, 'reason' => 'take_profit'];
                    }
                    if ($stop_loss > 0 && $currentPrice <= $stop_loss) {
                        echo "[TRIGGER HIT] Trade {$trade['ref']}: STOP LOSS hit! Price {$currentPrice} <= SL {$stop_loss}\n";
                        return ['shouldClose' => true, 'reason' => 'stop_loss'];
                    }
                } else { // sell
                    // For sell trades
                    if ($take_profit > 0 && $currentPrice <= $take_profit) {
                        echo "[TRIGGER HIT] Trade {$trade['ref']}: TAKE PROFIT hit! Price {$currentPrice} <= TP {$take_profit}\n";
                        return ['shouldClose' => true, 'reason' => 'take_profit'];
                    }
                    if ($stop_loss > 0 && $currentPrice >= $stop_loss) {
                        echo "[TRIGGER HIT] Trade {$trade['ref']}: STOP LOSS hit! Price {$currentPrice} >= SL {$stop_loss}\n";
                        return ['shouldClose' => true, 'reason' => 'stop_loss'];
                    }
                }
            }
            
            return ['shouldClose' => false, 'reason' => null];
        } catch (Exception $e) {
            error_log("ProfitCalculationService::checkTradeTriggers - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if account requires a margin call
     * 
     * @param array $balances - Result from calculateAccountBalances
     * @param float $marginCallLevel - Percentage (e.g., 50 for 50%)
     * @param float $stopOutLevel - Percentage (e.g., 20 for 20%)
     * @return array - Margin call status and details
     */
    public static function checkMarginCall($balances, $marginCallLevel = 50, $stopOutLevel = 20) {
        try {
            if ($balances['totalMargin'] <= 0) {
                return [
                    'requiresMarginCall' => false,
                    'requiresStopOut' => false,
                    'marginLevel' => null,
                    'action' => 'none'
                ];
            }
            
            $marginLevel = ($balances['equity'] / $balances['totalMargin']) * 100;
            
            // Stop Out: Close ALL trades immediately
            if ($marginLevel <= $stopOutLevel) {
                return [
                    'requiresMarginCall' => true,
                    'requiresStopOut' => true,
                    'marginLevel' => $marginLevel,
                    'action' => 'stop_out',
                    'message' => "Stop Out! Margin level at {$marginLevel}% - Closing all trades"
                ];
            }
            
            // Margin Call: Close worst trades until level is restored
            if ($marginLevel <= $marginCallLevel) {
                return [
                    'requiresMarginCall' => true,
                    'requiresStopOut' => false,
                    'marginLevel' => $marginLevel,
                    'action' => 'margin_call',
                    'message' => "Margin Call! Margin level at {$marginLevel}% - Closing losing trades"
                ];
            }
            
            // Safe
            return [
                'requiresMarginCall' => false,
                'requiresStopOut' => false,
                'marginLevel' => $marginLevel,
                'action' => 'none'
            ];
            
        } catch (Exception $e) {
            error_log("ProfitCalculationService::checkMarginCall - " . $e->getMessage());
            return [
                'requiresMarginCall' => false,
                'requiresStopOut' => false,
                'marginLevel' => null,
                'action' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get open trades sorted by performance (worst first)
     * Used for margin call - close worst-performing trades first
     * 
     * @param int $userId
     * @param array $currentPrices
     * @return array - Trades sorted by profit (most losing first)
     */
    public static function getTradesSortedByProfit($userId, $currentPrices) {
        try {
            // Get all open trades with their profits
            $trades = self::getOpenTradesProfits($userId, $currentPrices);
            
            // Sort by totalProfit ascending (most negative first)
            usort($trades, function($a, $b) {
                $profitA = $a['totalProfit'] ?? 0;
                $profitB = $b['totalProfit'] ?? 0;
                return $profitA <=> $profitB;
            });
            
            return $trades;
            
        } catch (Exception $e) {
            error_log("ProfitCalculationService::getTradesSortedByProfit - " . $e->getMessage());
            return [];
        }
    }
}
