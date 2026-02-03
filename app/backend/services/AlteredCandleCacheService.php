<?php

/**
 * AlteredCandleCacheService
 * 
 * Manages persistent storage of altered candles for chart history.
 * When trades are altered with chart_alter='true', synthetic candles are saved
 * so that browser reloads maintain the altered chart history.
 * 
 * Cache files are organized by:
 * - Pair mode: cache/alter_history_{pair}_{acc_type}.json (e.g., btcusdt_demo.json)
 * - Account_pair mode: cache/alter_history_{account_id}_{pair}.json (e.g., 6760174451_btcusdt.json)
 * 
 * Each file contains a JSON array of altered candles keyed by timestamp.
 */
class AlteredCandleCacheService
{
    private static $cacheDir = __DIR__ . '/../cache/alter_history/';
    
    /**
     * Initialize cache directory
     */
    private static function initCacheDir() {
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    /**
     * Get cache filename for pair mode (all demo or all real accounts for a pair)
     * 
     * @param string $pair Trading pair (e.g., 'BTC/USD')
     * @param string $accType Account type ('demo' or 'real')
     * @param string $interval Candle interval (e.g., '5m')
     * @return string Cache filename
     */
    private static function getPairCacheFilename($pair, $accType, $interval) {
        // Convert pair to safe filename (BTC/USD -> btcusd)
        $pairSafe = strtolower(str_replace(['/', ' '], '', $pair));
        return self::$cacheDir . "pair_{$pairSafe}_{$accType}_{$interval}.json";
    }
    
    /**
     * Get cache filename for account_pair mode (specific account + pair)
     * 
     * @param string $accountId Account ID hash
     * @param string $pair Trading pair (e.g., 'BTC/USD')
     * @param string $interval Candle interval
     * @return string Cache filename
     */
    private static function getAccountPairCacheFilename($accountId, $pair, $interval) {
        $pairSafe = strtolower(str_replace(['/', ' '], '', $pair));
        return self::$cacheDir . "account_{$accountId}_{$pairSafe}_{$interval}.json";
    }
    
    /**
     * Load cached altered candles from file
     * 
     * @param string $filename Cache file path
     * @return array Associative array of timestamp => candle data
     */
    private static function loadCache($filename) {
        if (!file_exists($filename)) {
            return [];
        }
        
        $content = file_get_contents($filename);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }
        
        return $data;
    }
    
    /**
     * Save cached altered candles to file
     * 
     * @param string $filename Cache file path
     * @param array $data Candle data
     * @return bool Success
     */
    private static function saveCache($filename, $data) {
        self::initCacheDir();
        
        $json = json_encode($data, JSON_PRETTY_PRINT);
        $result = file_put_contents($filename, $json);
        
        return $result !== false;
    }
    
    /**
     * Save an altered candle to cache
     * 
     * @param array $candle Altered candle data with timestamp
     * @param string $mode 'pair' or 'account_pair'
     * @param string $pair Trading pair
     * @param string $interval Candle interval
     * @param string $accType Account type ('demo' or 'real') - required for pair mode
     * @param string|null $accountId Account ID hash - required for account_pair mode
     * @return bool Success
     */
    public static function saveAlteredCandle($candle, $mode, $pair, $interval, $accType = null, $accountId = null) {
        try {
            // Determine cache file based on mode
            if ($mode === 'pair') {
                if ($accType === null) {
                    error_log("AlteredCandleCacheService::saveAlteredCandle - accType required for pair mode");
                    return false;
                }
                $filename = self::getPairCacheFilename($pair, $accType, $interval);
            } elseif ($mode === 'account_pair') {
                if ($accountId === null) {
                    error_log("AlteredCandleCacheService::saveAlteredCandle - accountId required for account_pair mode");
                    return false;
                }
                $filename = self::getAccountPairCacheFilename($accountId, $pair, $interval);
            } else {
                error_log("AlteredCandleCacheService::saveAlteredCandle - invalid mode: {$mode}");
                return false;
            }
            
            // Load existing cache
            $cache = self::loadCache($filename);
            
            // Use candle timestamp as key (should be in milliseconds)
            $timestamp = $candle['timestamp'] ?? $candle['t'] ?? null;
            if ($timestamp === null) {
                error_log("AlteredCandleCacheService::saveAlteredCandle - candle missing timestamp");
                return false;
            }
            
            // Extract OHLC data (support both formats)
            $open = $candle['open'] ?? $candle['o'] ?? null;
            $high = $candle['high'] ?? $candle['h'] ?? null;
            $low = $candle['low'] ?? $candle['l'] ?? null;
            $close = $candle['close'] ?? $candle['c'] ?? null;
            $volume = $candle['volume'] ?? $candle['v'] ?? 0;
            
            // Validate that we have complete OHLC data
            if ($open === null || $high === null || $low === null || $close === null) {
                error_log("AlteredCandleCacheService::saveAlteredCandle - incomplete OHLC data: O={$open}, H={$high}, L={$low}, C={$close}");
                return false;
            }
            
            // Store candle data with proper numeric values
            $cache[$timestamp] = [
                'timestamp' => intval($timestamp),
                'time' => $candle['time'] ?? date('Y-m-d H:i:s', intval($timestamp) / 1000),
                'open' => floatval($open),
                'high' => floatval($high),
                'low' => floatval($low),
                'close' => floatval($close),
                'volume' => floatval($volume),
                'is_altered' => true,
                'saved_at' => time()
            ];
            
            // Save cache
            $success = self::saveCache($filename, $cache);
            
            if ($success) {
                error_log("[CACHE] Saved altered candle: mode={$mode}, pair={$pair}, interval={$interval}, timestamp={$timestamp}, O={$open}, H={$high}, L={$low}, C={$close}");
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log("AlteredCandleCacheService::saveAlteredCandle - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get altered candles for a specific pair and account type (pair mode)
     * 
     * @param string $pair Trading pair
     * @param string $interval Candle interval
     * @param string $accType Account type ('demo' or 'real')
     * @return array Altered candles keyed by timestamp
     */
    public static function getAlteredCandlesForPair($pair, $interval, $accType) {
        $filename = self::getPairCacheFilename($pair, $accType, $interval);
        return self::loadCache($filename);
    }
    
    /**
     * Get altered candles for a specific account and pair (account_pair mode)
     * 
     * @param string $accountId Account ID hash
     * @param string $pair Trading pair
     * @param string $interval Candle interval
     * @return array Altered candles keyed by timestamp
     */
    public static function getAlteredCandlesForAccountPair($accountId, $pair, $interval) {
        $filename = self::getAccountPairCacheFilename($accountId, $pair, $interval);
        return self::loadCache($filename);
    }
    
    /**
     * Merge altered candles with Binance historical candles
     * Priority: account_pair > pair > Binance data
     * 
     * @param array $binanceCandles Original candles from Binance
     * @param string $pair Trading pair
     * @param string $interval Candle interval
     * @param string $accType Account type ('demo' or 'real')
     * @param string|null $accountId Account ID hash (if specific account)
     * @return array Merged candles with altered ones replacing Binance data where applicable
     */
    public static function mergeCandlesWithAltered($binanceCandles, $pair, $interval, $accType, $accountId = null) {
        try {
            // Load cached altered candles
            $accountPairAlters = [];
            $pairAlters = [];
            
            if ($accountId !== null) {
                $accountPairAlters = self::getAlteredCandlesForAccountPair($accountId, $pair, $interval);
            }
            
            $pairAlters = self::getAlteredCandlesForPair($pair, $interval, $accType);
            
            // If no altered candles, return original
            if (empty($accountPairAlters) && empty($pairAlters)) {
                return $binanceCandles;
            }
            
            error_log("[CACHE MERGE] Found " . count($accountPairAlters) . " account_pair alters, " . count($pairAlters) . " pair alters for {$pair} {$interval}");
            
            // Merge candles
            $mergedCandles = [];
            
            foreach ($binanceCandles as $candle) {
                $timestamp = $candle['timestamp'];
                
                // Priority: account_pair > pair > original
                if (isset($accountPairAlters[$timestamp])) {
                    $mergedCandles[] = array_merge($candle, $accountPairAlters[$timestamp]);
                    error_log("[CACHE MERGE] Using account_pair altered candle for timestamp {$timestamp}");
                } elseif (isset($pairAlters[$timestamp])) {
                    $mergedCandles[] = array_merge($candle, $pairAlters[$timestamp]);
                    error_log("[CACHE MERGE] Using pair altered candle for timestamp {$timestamp}");
                } else {
                    $mergedCandles[] = $candle;
                }
            }
            
            return $mergedCandles;
            
        } catch (Exception $e) {
            error_log("AlteredCandleCacheService::mergeCandlesWithAltered - " . $e->getMessage());
            return $binanceCandles; // Fallback to original on error
        }
    }
    
    /**
     * Clean old cached candles (older than specified days)
     * 
     * @param int $daysToKeep Number of days to keep (default 30)
     * @return array Statistics about cleaned files
     */
    public static function cleanOldCaches($daysToKeep = 30) {
        try {
            self::initCacheDir();
            
            $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
            $files = glob(self::$cacheDir . '*.json');
            
            $stats = [
                'files_checked' => 0,
                'files_cleaned' => 0,
                'candles_removed' => 0
            ];
            
            foreach ($files as $file) {
                $stats['files_checked']++;
                
                $cache = self::loadCache($file);
                $originalCount = count($cache);
                
                // Remove old candles
                $cache = array_filter($cache, function($candle) use ($cutoffTime) {
                    $savedAt = $candle['saved_at'] ?? 0;
                    return $savedAt >= $cutoffTime;
                });
                
                $newCount = count($cache);
                $removed = $originalCount - $newCount;
                
                if ($removed > 0) {
                    if ($newCount === 0) {
                        // Delete empty cache file
                        unlink($file);
                        $stats['files_cleaned']++;
                        error_log("[CACHE CLEANUP] Deleted empty file: " . basename($file));
                    } else {
                        // Save updated cache
                        self::saveCache($file, $cache);
                        error_log("[CACHE CLEANUP] Removed {$removed} old candles from: " . basename($file));
                    }
                    
                    $stats['candles_removed'] += $removed;
                }
            }
            
            error_log("[CACHE CLEANUP] Checked {$stats['files_checked']} files, cleaned {$stats['files_cleaned']} files, removed {$stats['candles_removed']} candles");
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("AlteredCandleCacheService::cleanOldCaches - " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
