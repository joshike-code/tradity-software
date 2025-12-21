<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/TradeService.php';

/**
 * TradeAlterMonitorService
 * 
 * Monitors and processes alter_trades entries to provide simulated price movements.
 * This service interpolates prices from start_price to target_price over a given time duration,
 * then optionally closes trades or resumes live pricing.
 * 
 * Supported alter modes:
 * - 'trade': Alter a single trade by trade_ref
 * - 'pair': Alter all trades on a specific pair
 * - 'account_pair': Alter all trades on a specific pair for a specific account
 */
class TradeAlterMonitorService
{
    /**
     * Process all active alter_trades and return altered prices
     * 
     * @param array $currentPrices Current live prices from Binance (associative array: pair => price)
     * @return array Result with altered prices and cleanup info
     */
    public static function processAlterTrades($currentPrices) {
        try {
            $conn = Database::getConnection();
            $currentTime = time();
            
            // Get all alter trades (we'll check completion in processAlterTrade)
            $stmt = $conn->prepare("SELECT * FROM alter_trades");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $alteredPrices = []; // Map of trade_id or pair => altered_price
            $chartAlters = []; // Chart alter information for chart generation
            $toDelete = []; // IDs of alter_trades to delete after processing
            $tradesToCloseData = []; // Trade IDs and their alter data for closing
            
            echo "[ALTER] Processing " . $result->num_rows . " alter_trade entries\n";
            
            while ($alterTrade = $result->fetch_assoc()) {
                echo "[ALTER] Processing alter ID {$alterTrade['id']}: mode={$alterTrade['alter_mode']}, time={$alterTrade['time']}s\n";
                
                $processResult = self::processAlterTrade($alterTrade, $currentTime, $currentPrices);
                
                echo "[ALTER] Result for ID {$alterTrade['id']}: status={$processResult['status']}\n";
                
                if ($processResult['status'] === 'active') {
                    // Merge altered prices
                    $alteredPrices = array_merge($alteredPrices, $processResult['altered_prices']);
                    echo "[ALTER] Active alter - applied " . count($processResult['altered_prices']) . " price alterations\n";
                    
                    // Track chart alters (only for pair and account_pair modes with alter_chart=true)
                    echo "[ALTER CHART DEBUG] Checking alter ID {$alterTrade['id']}: alter_chart='{$alterTrade['alter_chart']}', mode='{$alterTrade['alter_mode']}'\n";
                    
                    if ($alterTrade['alter_chart'] === 'true' && 
                        in_array($alterTrade['alter_mode'], ['pair', 'account_pair'])) {
                        
                        $pairToUse = $alterTrade['alter_mode'] === 'pair' ? $alterTrade['pair'] : $alterTrade['account_pair'];
                        
                        $chartAlters[] = [
                            'id' => $alterTrade['id'],
                            'mode' => $alterTrade['alter_mode'],
                            'pair' => $pairToUse,
                            'acc_type' => $alterTrade['acc_type'] ?? null,
                            'account' => $alterTrade['account'] ?? null,
                            'current_price' => $processResult['current_price'],
                            'start_price' => floatval($alterTrade['start_price']),
                            'target_price' => floatval($alterTrade['target_price']),
                            'progress' => $processResult['progress'],
                            'start_timestamp' => intval($alterTrade['start_timestamp']),
                            'duration' => floatval($alterTrade['time'])
                        ];
                        
                        echo "[ALTER CHART] ✓ Tracked chart alter ID {$alterTrade['id']} for pair '{$pairToUse}' ({$alterTrade['alter_mode']} mode, acc_type={$alterTrade['acc_type']})\n";
                    } else {
                        if ($alterTrade['alter_chart'] !== 'true') {
                            echo "[ALTER CHART] × Skipped alter ID {$alterTrade['id']}: alter_chart is '{$alterTrade['alter_chart']}' (not 'true')\n";
                        } else {
                            echo "[ALTER CHART] × Skipped alter ID {$alterTrade['id']}: mode is '{$alterTrade['alter_mode']}' (not pair/account_pair)\n";
                        }
                    }
                    
                } elseif ($processResult['status'] === 'completed') {
                    // Mark for deletion
                    $toDelete[] = $alterTrade['id'];
                    echo "[ALTER] Completed alter ID {$alterTrade['id']} - marking for deletion\n";
                    
                    // If close=true, mark trades for closing with target price
                    if ($alterTrade['close'] === 'true' && !empty($processResult['trades_to_close'])) {
                        foreach ($processResult['trades_to_close'] as $tradeId) {
                            $tradesToCloseData[] = [
                                'id' => $tradeId,
                                'target_price' => floatval($alterTrade['target_price'])
                            ];
                        }
                    }
                }
            }
            
            // Clean up completed alter_trades
            if (!empty($toDelete)) {
                self::deleteAlterTrades($toDelete);
            }
            
            // Close trades that reached target with close=true
            if (!empty($tradesToCloseData)) {
                self::closeTrades($tradesToCloseData);
            }
            
            return [
                'success' => true,
                'altered_prices' => $alteredPrices,
                'chart_alters' => $chartAlters,
                'deleted_count' => count($toDelete),
                'closed_trades' => count($tradesToCloseData)
            ];
            
        } catch (Exception $e) {
            error_log("TradeAlterMonitorService::processAlterTrades - " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'altered_prices' => [],
                'chart_alters' => []
            ];
        }
    }
    
    /**
     * Process a single alter_trade entry
     * 
     * @param array $alterTrade The alter_trade record from database
     * @param int $currentTime Current Unix timestamp
     * @param array $currentPrices Current live prices
     * @return array Processing result with status and altered prices
     */
    private static function processAlterTrade($alterTrade, $currentTime, $currentPrices) {
        try {
            // Use start_timestamp for accurate time calculations (avoids timezone issues)
            $startTime = intval($alterTrade['start_timestamp']);
            $duration = floatval($alterTrade['time']); // Duration in seconds
            $startPrice = floatval($alterTrade['start_price']);
            $targetPrice = floatval($alterTrade['target_price']);
            $alterMode = $alterTrade['alter_mode'];
            
            // Calculate elapsed time
            $elapsed = $currentTime - $startTime;
            
            echo "[ALTER] ID {$alterTrade['id']}: startTime=$startTime, currentTime=$currentTime, elapsed={$elapsed}s, duration={$duration}s\n";
            
            // Check if duration is valid
            if ($duration <= 0) {
                echo "[ALTER] ERROR: Duration is 0 or negative for alter ID {$alterTrade['id']}! Marking as completed.\n";
                return [
                    'status' => 'completed',
                    'trades_to_close' => []
                ];
            }
            
            // Check if alter period has completed
            if ($elapsed >= $duration) {
                echo "[ALTER] Alter ID {$alterTrade['id']} has completed (elapsed={$elapsed}s >= duration={$duration}s)\n";
                // Alter period completed
                $result = [
                    'status' => 'completed',
                    'trades_to_close' => []
                ];
                
                // Get affected trades for closing if needed
                if ($alterTrade['close'] === 'true') {
                    $affectedTrades = self::getAffectedTrades($alterTrade);
                    $result['trades_to_close'] = $affectedTrades;
                    echo "[ALTER] Will close " . count($affectedTrades) . " trades\n";
                }
                
                return $result;
            }
            
            // Calculate current interpolated price
            $progress = $elapsed / $duration; // 0.0 to 1.0
            $currentPrice = $startPrice + (($targetPrice - $startPrice) * $progress);
            
            echo "[ALTER] ID {$alterTrade['id']}: progress=" . ($progress * 100) . "%, currentPrice=$currentPrice (from $startPrice to $targetPrice)\n";
            
            // Get affected trades and apply altered price
            $alteredPrices = [];
            
            switch ($alterMode) {
                case 'trade':
                    // Alter single trade by ref
                    $tradeRef = $alterTrade['trade_ref'];
                    $alteredPrices['trade_' . $tradeRef] = $currentPrice;
                    break;
                    
                case 'pair':
                    // Alter all trades on this pair with specific acc_type (demo or real)
                    $pair = $alterTrade['pair'];
                    $accType = $alterTrade['acc_type'];
                    $alteredPrices['pair_' . $pair . '_' . $accType] = $currentPrice;
                    break;
                    
                case 'account_pair':
                    // Alter all trades on this pair for specific account
                    $account = $alterTrade['account'];
                    $accountPair = $alterTrade['account_pair'];
                    $alteredPrices['account_pair_' . $account . '_' . $accountPair] = $currentPrice;
                    break;
            }
            
            return [
                'status' => 'active',
                'altered_prices' => $alteredPrices,
                'progress' => $progress,
                'current_price' => $currentPrice
            ];
            
        } catch (Exception $e) {
            error_log("TradeAlterMonitorService::processAlterTrade - " . $e->getMessage());
            return [
                'status' => 'error',
                'altered_prices' => []
            ];
        }
    }
    
    /**
     * Get list of trades affected by an alter_trade entry
     * 
     * @param array $alterTrade The alter_trade record
     * @return array Array of trade IDs
     */
    private static function getAffectedTrades($alterTrade) {
        try {
            $conn = Database::getConnection();
            $tradeIds = [];
            
            switch ($alterTrade['alter_mode']) {
                case 'trade':
                    // Single trade by ref
                    $stmt = $conn->prepare("
                        SELECT id FROM trades 
                        WHERE ref = ? AND (close_date IS NULL OR close_date = '')
                    ");
                    $stmt->bind_param("s", $alterTrade['trade_ref']);
                    break;
                    
                case 'pair':
                    // All trades on pair with specific acc_type (demo or real)
                    $stmt = $conn->prepare("
                        SELECT id FROM trades 
                        WHERE pair = ? AND trade_acc = ? AND (close_date IS NULL OR close_date = '')
                    ");
                    $stmt->bind_param("ss", $alterTrade['pair'], $alterTrade['acc_type']);
                    break;
                    
                case 'account_pair':
                    // All trades on pair for specific account
                    $stmt = $conn->prepare("
                        SELECT id FROM trades 
                        WHERE pair = ? AND account = ? AND (close_date IS NULL OR close_date = '')
                    ");
                    $stmt->bind_param("ss", $alterTrade['account_pair'], $alterTrade['account']);
                    break;
                    
                default:
                    return [];
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $tradeIds[] = $row['id'];
            }
            
            return $tradeIds;
            
        } catch (Exception $e) {
            error_log("TradeAlterMonitorService::getAffectedTrades - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Delete completed alter_trade entries
     * 
     * @param array $ids Array of alter_trade IDs to delete
     */
    private static function deleteAlterTrades($ids) {
        try {
            if (empty($ids)) {
                return;
            }
            
            $conn = Database::getConnection();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            
            $stmt = $conn->prepare("DELETE FROM alter_trades WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            
            echo "[ALTER] Deleted " . count($ids) . " completed alter_trade entries\n";
            
        } catch (Exception $e) {
            error_log("TradeAlterMonitorService::deleteAlterTrades - " . $e->getMessage());
        }
    }
    
    /**
     * Close trades that reached target price with close=true
     * 
     * @param array $tradesToCloseData Array of ['id' => tradeId, 'target_price' => price]
     */
    private static function closeTrades($tradesToCloseData) {
        try {
            if (empty($tradesToCloseData)) {
                return;
            }
            
            $conn = Database::getConnection();
            
            foreach ($tradesToCloseData as $tradeData) {
                $tradeId = $tradeData['id'];
                $targetPrice = $tradeData['target_price'];
                
                // Get trade details
                $stmt = $conn->prepare("
                    SELECT t.*, p.lot_size, p.digits 
                    FROM trades t 
                    LEFT JOIN pairs p ON t.pair = p.name 
                    WHERE t.id = ?
                ");
                $stmt->bind_param("i", $tradeId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    continue;
                }
                
                $trade = $result->fetch_assoc();
                
                // Close trade with target price (not Binance price) and send notification
                $closeResult = TradeService::closeTradeWithNotification(
                    'expire',
                    ['id' => $trade['id']],
                    $targetPrice  // Pass the alter target price
                );
                
                if ($closeResult['success']) {
                    echo "[ALTER] Closed trade {$trade['ref']} at target price $" . number_format($targetPrice, 2) . "\n";
                } else {
                    echo "[ALTER] Failed to close trade {$trade['ref']}: {$closeResult['message']}\n";
                }
            }
            
        } catch (Exception $e) {
            error_log("TradeAlterMonitorService::closeTrades - " . $e->getMessage());
        }
    }
    
    /**
     * Get altered price for a specific trade
     * Checks if there's an active alter affecting this trade
     * 
     * @param array $trade Trade record from database
     * @param array $alteredPrices Map of altered prices from processAlterTrades
     * @param float $defaultPrice Default price to use if no alter active
     * @return float The price to use (altered or default)
     */
    public static function getAlteredPriceForTrade($trade, $alteredPrices, $defaultPrice) {
        // Priority order: trade > account_pair > pair
        
        // Check trade-specific alter
        $tradeKey = 'trade_' . $trade['ref'];
        if (isset($alteredPrices[$tradeKey])) {
            return $alteredPrices[$tradeKey];
        }
        
        // Check account-pair alter
        $accountPairKey = 'account_pair_' . $trade['account'] . '_' . $trade['pair'];
        if (isset($alteredPrices[$accountPairKey])) {
            return $alteredPrices[$accountPairKey];
        }
        
        // Check pair-specific alter with trade_acc (demo/real)
        $pairKey = 'pair_' . $trade['pair'] . '_' . $trade['trade_acc'];
        if (isset($alteredPrices[$pairKey])) {
            return $alteredPrices[$pairKey];
        }
        
        // No alter active, use default
        return $defaultPrice;
    }
    
    /**
     * Get altered price for a trade by ID
     * Looks up active alter_trades and calculates interpolated price if applicable
     * This is used by calculateTradeProfit to make ALL profit calculations alter-aware
     * 
     * @param int $tradeId Trade ID
     * @param string $tradeRef Trade reference
     * @param string $pair Trading pair
     * @param string $account Account ID
     * @param string $tradeAcc Account type (demo or real) - from trades.trade_acc column
     * @return float|null Altered price if active alter exists, null otherwise
     */
    public static function getAlteredPriceForTradeById($tradeId, $tradeRef, $pair, $account, $tradeAcc) {
        try {
            $conn = Database::getConnection();
            $currentTime = time();
            
            // Priority order: trade > account_pair > pair
            // 1. Check for trade-specific alter
            $stmt = $conn->prepare("
                SELECT * FROM alter_trades 
                WHERE alter_mode = 'trade' 
                AND trade_ref = ?
                LIMIT 1
            ");
            $stmt->bind_param("s", $tradeRef);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $alter = $result->fetch_assoc();
                return self::calculateInterpolatedPrice($alter, $currentTime);
            }
            
            // 2. Check for account-pair alter (uses account_pair column, not pair)
            $stmt = $conn->prepare("
                SELECT * FROM alter_trades 
                WHERE alter_mode = 'account_pair' 
                AND account = ? 
                AND account_pair = ?
                LIMIT 1
            ");
            $stmt->bind_param("ss", $account, $pair);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $alter = $result->fetch_assoc();
                return self::calculateInterpolatedPrice($alter, $currentTime);
            }
            
            // 3. Check for pair-wide alter with matching acc_type (demo or real)
            $stmt = $conn->prepare("
                SELECT * FROM alter_trades 
                WHERE alter_mode = 'pair' 
                AND pair = ?
                AND acc_type = ?
                LIMIT 1
            ");
            $stmt->bind_param("ss", $pair, $tradeAcc);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $alter = $result->fetch_assoc();
                return self::calculateInterpolatedPrice($alter, $currentTime);
            }
            
            // No active alter
            return null;
            
        } catch (Exception $e) {
            error_log("TradeAlterMonitorService::getAlteredPriceForTradeById - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Calculate interpolated price for an alter at current time
     * 
     * @param array $alter Alter trade record
     * @param int $currentTime Current Unix timestamp
     * @return float|null Interpolated price or null if alter completed/invalid
     */
    private static function calculateInterpolatedPrice($alter, $currentTime) {
        $startTime = intval($alter['start_timestamp']);
        $duration = floatval($alter['time']);
        $startPrice = floatval($alter['start_price']);
        $targetPrice = floatval($alter['target_price']);
        
        // Check if duration is valid
        if ($duration <= 0) {
            return null;
        }
        
        // Calculate elapsed time
        $elapsed = $currentTime - $startTime;
        
        // Check if alter has completed
        if ($elapsed >= $duration) {
            return null; // Alter completed
        }
        
        // Calculate interpolated price
        $progress = $elapsed / $duration;
        $currentPrice = $startPrice + (($targetPrice - $startPrice) * $progress);
        
        return $currentPrice;
    }
    
    /**
     * Generate synthetic OHLC candle for chart alteration
     * Creates realistic candles that follow the altered price progression
     * 
     * @param array $alterInfo Alter information with current_price, progress, etc.
     * @param string $interval Candle interval (1m, 5m, 15m, 1h, 4h, 1d)
     * @param int $candleStartTime Start timestamp for this candle (in milliseconds)
     * @param float $previousClose Previous candle close price (for continuity)
     * @return array Synthetic candle with {open, high, low, close, volume}
     */
    public static function generateSyntheticCandle($alterInfo, $interval, $candleStartTime, $previousClose = null) {
        try {
            $currentPrice = $alterInfo['current_price'];
            $startPrice = $alterInfo['start_price'];
            $targetPrice = $alterInfo['target_price'];
            $progress = $alterInfo['progress'];
            
            // Use previous close as open, or current price if first candle
            $open = $previousClose ?? $currentPrice;
            $close = $currentPrice;
            
            // Calculate price direction and range
            $direction = $targetPrice > $startPrice ? 'up' : 'down';
            $priceRange = abs($targetPrice - $startPrice);
            
            // Add realistic noise based on interval (longer intervals = more volatility)
            $volatilityMultipliers = [
                '1m' => 0.0005,  // 0.05% for 1 minute
                '5m' => 0.001,   // 0.1% for 5 minutes
                '15m' => 0.002,  // 0.2% for 15 minutes
                '1h' => 0.003,   // 0.3% for 1 hour
                '4h' => 0.005,   // 0.5% for 4 hours
                '1d' => 0.008    // 0.8% for 1 day
            ];
            
            $volatility = $volatilityMultipliers[$interval] ?? 0.002;
            
            // Generate high and low with realistic wicks
            // High/low should bracket the open-close range with some overflow
            $maxPrice = max($open, $close);
            $minPrice = min($open, $close);
            
            // Add wicks (random extension beyond open/close)
            $wickRange = $currentPrice * $volatility;
            $upperWick = $wickRange * (0.5 + (mt_rand(0, 100) / 200)); // 0.5x to 1x wick
            $lowerWick = $wickRange * (0.5 + (mt_rand(0, 100) / 200));
            
            $high = $maxPrice + $upperWick;
            $low = $minPrice - $lowerWick;
            
            // Ensure low doesn't go negative or too far from current price
            $low = max($low, $currentPrice * 0.98);
            
            // Generate realistic volume (higher volume for larger price movements)
            $baseVolume = 100000; // Base volume
            $volumeMultiplier = 1 + (abs($close - $open) / $open) * 10; // More volume on bigger moves
            $volume = $baseVolume * $volumeMultiplier * (0.8 + (mt_rand(0, 40) / 100)); // Random ±20%
            
            // Round prices to reasonable precision (2 decimals for most pairs)
            $decimals = 2;
            if ($currentPrice < 1) {
                $decimals = 4; // More precision for low-value pairs
            } elseif ($currentPrice > 10000) {
                $decimals = 0; // Less precision for high-value pairs
            }
            
            return [
                'open' => round($open, $decimals),
                'high' => round($high, $decimals),
                'low' => round($low, $decimals),
                'close' => round($close, $decimals),
                'volume' => round($volume, 2),
                'is_synthetic' => true // Flag to identify synthetic candles
            ];
            
        } catch (Exception $e) {
            error_log("TradeAlterMonitorService::generateSyntheticCandle - " . $e->getMessage());
            // Fallback to simple candle
            return [
                'open' => $alterInfo['current_price'],
                'high' => $alterInfo['current_price'],
                'low' => $alterInfo['current_price'],
                'close' => $alterInfo['current_price'],
                'volume' => 100000,
                'is_synthetic' => true
            ];
        }
    }
    
    /**
     * Check if a candle should be altered for a specific client
     * Determines if client should receive synthetic candle instead of real Binance candle
     * 
     * @param array $chartAlters Array of active chart alters from processAlterTrades
     * @param string $pair Trading pair (e.g., 'BTC/USD')
     * @param int $userId User ID of the client (null for admin)
     * @param string $accountType Account type (demo/real) - from user's current account
     * @param string $accountId Account ID hash - for account_pair mode
     * @return array|null Alter info if candle should be altered, null otherwise
     */
    public static function shouldAlterCandle($chartAlters, $pair, $userId, $accountType, $accountId = null) {
        try {
            if (empty($chartAlters)) {
                echo "[SHOULD ALTER] No chart alters available\n";
                return null;
            }
            
            echo "[SHOULD ALTER] Checking {$pair} for user {$userId} (account: {$accountId}, type: {$accountType})\n";
            echo "[SHOULD ALTER] Available chart alters: " . count($chartAlters) . "\n";
            foreach ($chartAlters as $idx => $alter) {
                echo "[SHOULD ALTER]   Alter #{$idx}: mode={$alter['mode']}, pair={$alter['pair']}, acc_type={$alter['acc_type']}, account={$alter['account']}\n";
            }
            
            // Priority: account_pair > pair
            // Check account_pair mode first (most specific)
            if ($accountId !== null) {
                foreach ($chartAlters as $alter) {
                    if ($alter['mode'] === 'account_pair' && 
                        $alter['account'] === $accountId && 
                        $alter['pair'] === $pair) {
                        echo "[SHOULD ALTER] ✓ MATCH: account_pair mode for account {$accountId} on {$pair}\n";
                        return $alter;
                    }
                }
            }
            
            // Check pair mode (applies to all accounts of specific type)
            if ($accountType !== null) {
                foreach ($chartAlters as $alter) {
                    echo "[SHOULD ALTER]   Comparing: alter pair='{$alter['pair']}' vs requested='{$pair}', alter acc_type='{$alter['acc_type']}' vs requested='{$accountType}'\n";
                    
                    if ($alter['mode'] === 'pair' && 
                        $alter['pair'] === $pair && 
                        $alter['acc_type'] === $accountType) {
                        echo "[SHOULD ALTER] ✓ MATCH: pair mode for {$accountType} accounts on {$pair}\n";
                        return $alter;
                    }
                }
            }
            
            echo "[SHOULD ALTER] × NO MATCH for {$pair}\n";
            return null;
            
        } catch (Exception $e) {
            error_log("TradeAlterMonitorService::shouldAlterCandle - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate complete synthetic candle with timestamp for broadcasting
     * This is the main entry point for chart alteration
     * 
     * @param array $realCandle Real Binance candle data
     * @param array $alterInfo Alter information
     * @param float|null $previousClose Previous candle close for continuity
     * @return array Complete candle ready for broadcast
     */
    public static function createAlteredCandle($realCandle, $alterInfo, $previousClose = null) {
        try {
            $interval = $realCandle['interval'];
            $candleStartTime = $realCandle['timestamp'];
            
            // Generate synthetic OHLC
            $syntheticOHLC = self::generateSyntheticCandle(
                $alterInfo, 
                $interval, 
                $candleStartTime, 
                $previousClose
            );
            
            // Merge with real candle structure (keep timestamps, pair info, etc.)
            $alteredCandle = array_merge($realCandle, $syntheticOHLC);
            $alteredCandle['is_altered'] = true; // Mark as altered
            
            echo "[ALTER CHART] Generated synthetic candle: {$realCandle['pair']} {$interval} - " .
                 "O:{$syntheticOHLC['open']} H:{$syntheticOHLC['high']} " .
                 "L:{$syntheticOHLC['low']} C:{$syntheticOHLC['close']}\n";
            
            return $alteredCandle;
            
        } catch (Exception $e) {
            error_log("TradeAlterMonitorService::createAlteredCandle - " . $e->getMessage());
            // Fallback to real candle
            return $realCandle;
        }
    }
}
