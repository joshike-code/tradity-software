<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/AlteredCandleCacheService.php';
require_once __DIR__ . '/PriceFormatterService.php';

class ChartDataService
{
    /**
     * Fetch historical candlestick data from Binance
     * 
     * @param string $pair - Trading pair (e.g., 'BTC/USD')
     * @param string $interval - Candle interval (1m, 5m, 15m, 1h, 4h, 1d)
     * @param int $limit - Number of candles to fetch (max 1000)
     * @param int|null $userId - User ID to fetch altered candles for their account
     * @return array - OHLC candlestick data
     */
    public static function getHistoricalCandles($pair, $interval = '5m', $limit = 100, $userId = null) {
        try {
            // Check pair type from database
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT type FROM pairs WHERE name = ? AND status = 'active'");
            $stmt->bind_param("s", $pair);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error("Pair '{$pair}' not found or inactive", 404);
                return null;
            }
            
            $pairData = $result->fetch_assoc();
            $pairType = $pairData['type'];
            
            // Route to appropriate data source based on pair type
            if ($pairType === 'crypto') {
                return self::getBinanceHistoricalCandles($pair, $interval, $limit, $userId);
            } else {
                // For forex and commodities, use Twelve Data with smart caching
                return self::getTwelveDataHistoricalCandles($pair, $interval, $limit, $userId);
            }
            
        } catch (Exception $e) {
            error_log("ChartDataService::getHistoricalCandles - " . $e->getMessage());
            Response::error('Failed to fetch chart data', 500);
            return null;
        }
    }
    
    /**
     * Fetch historical candlestick data from Binance (for crypto pairs)
     * 
     * @param string $pair - Trading pair (e.g., 'BTC/USD')
     * @param string $interval - Candle interval
     * @param int $limit - Number of candles to fetch
     * @param int|null $userId - User ID
     * @return array - OHLC candlestick data
     */
    private static function getBinanceHistoricalCandles($pair, $interval, $limit, $userId) {
        try {
            // Transform pair to Binance format (e.g., BTC/USD -> BTCUSDT)
            $symbol = str_replace(['/', 'USD'], ['', 'USDT'], $pair);
            $symbol = strtoupper($symbol);
            
            // Validate interval
            $validIntervals = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d', '3d', '1w', '1M'];
            if (!in_array($interval, $validIntervals)) {
                Response::error('Invalid interval. Use: ' . implode(', ', $validIntervals), 400);
                return null;
            }
            
            // Validate limit (1-1000)
            $limit = max(1, min(1000, intval($limit)));
            
            // Binance Klines/Candlestick API endpoint
            $url = "https://api.binance.com/api/v3/klines?symbol={$symbol}&interval={$interval}&limit={$limit}";
            
            // Fetch data from Binance
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For XAMPP environments
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                $error = curl_error($ch);
                curl_close($ch);
                error_log("ChartDataService::getHistoricalCandles - cURL error: {$error}");
                Response::error('Failed to fetch chart data from Binance', 503);
                return null;
            }
            
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("ChartDataService::getHistoricalCandles - HTTP {$httpCode}: {$response}");
                Response::error('Binance API error', 503);
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (!is_array($data)) {
                error_log("ChartDataService::getHistoricalCandles - Invalid response format");
                Response::error('Invalid response from Binance', 500);
                return null;
            }
            
            // Transform Binance kline data to our format
            $candles = [];
            foreach ($data as $kline) {
                // Binance kline format:
                // [
                //   0: Open time,
                //   1: Open,
                //   2: High,
                //   3: Low,
                //   4: Close,
                //   5: Volume,
                //   6: Close time,
                //   7: Quote asset volume,
                //   8: Number of trades,
                //   9: Taker buy base asset volume,
                //   10: Taker buy quote asset volume,
                //   11: Ignore
                // ]
                
                $rawCandle = [
                    'timestamp' => intval($kline[0]), // Open time in milliseconds
                    'time' => date('Y-m-d H:i:s', intval($kline[0]) / 1000), // Human readable
                    'open' => floatval($kline[1]),
                    'high' => floatval($kline[2]),
                    'low' => floatval($kline[3]),
                    'close' => floatval($kline[4]),
                    'volume' => floatval($kline[5]),
                    'closeTime' => intval($kline[6]),
                    'trades' => intval($kline[8]),
                    'interval' => $interval,
                    'isClosed' => true
                ];
                
                // Format candle with correct decimal precision
                $candles[] = PriceFormatterService::formatCandle($pair, $rawCandle);
            }
            
            // Merge with altered candles if user is provided
            if ($userId !== null) {
                $candles = self::mergeWithAlteredCandles($candles, $pair, $interval, $userId);
            }
            
            return [
                'pair' => $pair,
                'symbol' => $symbol,
                'interval' => $interval,
                'count' => count($candles),
                'candles' => $candles
            ];
            
        } catch (Exception $e) {
            error_log("ChartDataService::getHistoricalCandles - " . $e->getMessage());
            Response::error('Failed to fetch chart data', 500);
            return null;
        }
    }
    
    /**
     * Merge Binance candles with altered candles for a specific user
     * 
     * @param array $binanceCandles Original candles from Binance
     * @param string $pair Trading pair
     * @param string $interval Candle interval
     * @param int $userId User ID
     * @return array Merged candles
     */
    private static function mergeWithAlteredCandles($binanceCandles, $pair, $interval, $userId) {
        try {
            // Get user's account type and account ID
            $conn = Database::getConnection();
            $stmt = $conn->prepare("
                SELECT a.id_hash, a.type as acc_type 
                FROM accounts a
                JOIN users u ON a.id_hash = u.current_account
                WHERE u.id = ?
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                // User has no current account, return original candles
                error_log("ChartDataService::mergeWithAlteredCandles - User {$userId} has no current account");
                return $binanceCandles;
            }
            
            $accountInfo = $result->fetch_assoc();
            $accType = $accountInfo['acc_type'];
            $accountId = $accountInfo['id_hash'];
            
            error_log("[CHART MERGE] User {$userId}: accType={$accType}, accountId={$accountId}");
            
            // Use AlteredCandleCacheService to merge candles
            // Priority: account_pair > pair > Binance data
            $mergedCandles = AlteredCandleCacheService::mergeCandlesWithAltered(
                $binanceCandles,
                $pair,
                $interval,
                $accType,
                $accountId
            );
            
            return $mergedCandles;
            
        } catch (Exception $e) {
            error_log("ChartDataService::mergeWithAlteredCandles - " . $e->getMessage());
            // Return original candles on error
            return $binanceCandles;
        }
    }
    
    /**
     * Fetch historical candles from Twelve Data for forex/commodity pairs
     * Uses smart caching to minimize API calls
     * 
     * Rate Limits (Free Tier):
     * - 8 API calls per minute
     * - 800 API calls per day
     * 
     * Caching Strategy:
     * - First checks database cache for existing candles
     * - If cache has enough data, returns immediately
     * - If cache miss, checks rate limits before API call
     * - If rate limited, returns partial cached data or error
     * - Successful API calls are cached for future requests
     * 
     * @param string $pair - Trading pair (e.g., 'EUR/USD')
     * @param string $interval - Candle interval
     * @param int $limit - Number of candles
     * @param int|null $userId - User ID
     * @return array - Historical candle data from Twelve Data (or cache)
     */
    private static function getTwelveDataHistoricalCandles($pair, $interval, $limit, $userId) {
        try {
            $conn = Database::getConnection();
            
            // Calculate time range for required candles
            $intervalSeconds = self::getIntervalSeconds($interval);
            $now = time();
            $oldestTimestamp = ($now - ($limit * $intervalSeconds)) * 1000; // Convert to milliseconds
            
            // First, try to get candles from cache
            $stmt = $conn->prepare("
                SELECT timestamp, open, high, low, close, volume 
                FROM historical_candles_cache 
                WHERE pair = ? AND `interval` = ? AND timestamp >= ?
                ORDER BY timestamp ASC
            ");
            $stmt->bind_param("ssi", $pair, $interval, $oldestTimestamp);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $cachedCandles = [];
            while ($row = $result->fetch_assoc()) {
                $cachedCandles[] = [
                    'timestamp' => (int)$row['timestamp'],
                    'time' => date('Y-m-d H:i:s', $row['timestamp'] / 1000),
                    'open' => $row['open'],
                    'high' => $row['high'],
                    'low' => $row['low'],
                    'close' => $row['close'],
                    'volume' => $row['volume'] ?? '0',
                    'closeTime' => (int)$row['timestamp'] + ($intervalSeconds * 1000),
                    'trades' => 0,
                    'interval' => $interval,
                    'isClosed' => true
                ];
            }
            
            // If we have enough cached data, use it
            if (count($cachedCandles) >= $limit) {
                error_log("Cache HIT for {$pair} {$interval} - " . count($cachedCandles) . " candles");
                
                // Get most recent candles up to limit
                $candles = array_slice($cachedCandles, -$limit);
                
                // Format candles with correct decimal precision
                $formattedCandles = [];
                foreach ($candles as $candle) {
                    $formattedCandles[] = PriceFormatterService::formatCandle($pair, $candle);
                }
                
                // Merge with altered candles if user is provided
                if ($userId !== null) {
                    $formattedCandles = self::mergeWithAlteredCandles($formattedCandles, $pair, $interval, $userId);
                }
                
                return [
                    'pair' => $pair,
                    'symbol' => str_replace('/', '', $pair),
                    'interval' => $interval,
                    'count' => count($formattedCandles),
                    'candles' => $formattedCandles,
                    'source' => 'cache'
                ];
            }
            
            // Cache miss or insufficient data - fetch from Twelve Data API
            error_log("Cache MISS for {$pair} {$interval} - fetching from Twelve Data API");
            
            // Check rate limit (8 calls per minute, 800 per day)
            if (!self::checkTwelveDataRateLimit()) {
                error_log("Twelve Data rate limit exceeded - using cached data if available");
                
                // If we have ANY cached data, use it even if less than requested
                if (count($cachedCandles) > 0) {
                    $candles = array_slice($cachedCandles, -$limit);
                    $formattedCandles = [];
                    foreach ($candles as $candle) {
                        $formattedCandles[] = PriceFormatterService::formatCandle($pair, $candle);
                    }
                    
                    if ($userId !== null) {
                        $formattedCandles = self::mergeWithAlteredCandles($formattedCandles, $pair, $interval, $userId);
                    }
                    
                    return [
                        'pair' => $pair,
                        'symbol' => str_replace('/', '', $pair),
                        'interval' => $interval,
                        'count' => count($formattedCandles),
                        'candles' => $formattedCandles,
                        'source' => 'cache_rate_limited'
                    ];
                }
                
                Response::error('Rate limit exceeded. Please try again in a moment.', 429);
                return null;
            }
            
            // Load Twelve Data API key
            $keys = require __DIR__ . '/../config/keys.php';
            $apiKey = $keys['twelve_data']['api_key'] ?? '';
            
            if (empty($apiKey)) {
                error_log("Twelve Data API key not configured");
                Response::error('Twelve Data API key not configured', 500);
                return null;
            }
            
            // Record API call attempt
            self::recordTwelveDataApiCall();
            
            // Map interval to Twelve Data format
            $intervalMap = [
                '1m' => '1min',
                '3m' => '3min',
                '5m' => '5min',
                '15m' => '15min',
                '30m' => '30min',
                '1h' => '1h',
                '2h' => '2h',
                '4h' => '4h',
                '1d' => '1day',
                '1w' => '1week',
                '1M' => '1month'
            ];
            $twelveDataInterval = $intervalMap[$interval] ?? '5min';
            
            // Request more candles than limit to ensure we have enough after filtering
            $outputSize = max($limit + 50, 100);
            
            // Build Twelve Data API URL with timezone parameter set to UTC
            $url = 'https://api.twelvedata.com/time_series?' . http_build_query([
                'symbol' => $pair,
                'interval' => $twelveDataInterval,
                'outputsize' => $outputSize,
                'apikey' => $apiKey,
                'format' => 'JSON',
                'timezone' => 'UTC'  // Request timestamps in UTC to avoid timezone issues
            ]);
            
            // Make API request
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                error_log("Twelve Data cURL error: {$curlError}");
                Response::error('Failed to fetch data from Twelve Data', 503);
                return null;
            }
            
            if ($httpCode !== 200) {
                error_log("Twelve Data API error: HTTP {$httpCode} - {$response}");
                Response::error('Failed to fetch data from Twelve Data', 500);
                return null;
            }
            
            $data = json_decode($response, true);
            
            // Check for API errors
            if (isset($data['status']) && $data['status'] === 'error') {
                $errorMsg = $data['message'] ?? 'Unknown error';
                error_log("Twelve Data API error: {$errorMsg}");
                Response::error("Twelve Data error: {$errorMsg}", 400);
                return null;
            }
            
            // Check response structure
            if (!isset($data['values']) || !is_array($data['values'])) {
                error_log("Twelve Data invalid response structure for {$pair}");
                Response::error('Invalid data from Twelve Data', 500);
                return null;
            }
            
            // Transform and cache Twelve Data response
            $candles = [];
            $insertStmt = $conn->prepare("
                INSERT INTO historical_candles_cache 
                (pair, `interval`, timestamp, open, high, low, close, volume, source) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'twelve_data')
                ON DUPLICATE KEY UPDATE 
                    open = VALUES(open),
                    high = VALUES(high),
                    low = VALUES(low),
                    close = VALUES(close),
                    volume = VALUES(volume),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            foreach ($data['values'] as $candle) {
                // Parse datetime to timestamp (Twelve Data now returns UTC time via timezone parameter)
                $datetime = new DateTime($candle['datetime'], new DateTimeZone('UTC'));
                $timestamp = $datetime->getTimestamp() * 1000; // Convert to milliseconds
                
                // Prepare volume (bind_param needs variables, not expressions)
                $volume = isset($candle['volume']) ? $candle['volume'] : '0';
                
                // Cache this candle
                $insertStmt->bind_param(
                    "ssisssss",
                    $pair,
                    $interval,
                    $timestamp,
                    $candle['open'],
                    $candle['high'],
                    $candle['low'],
                    $candle['close'],
                    $volume
                );
                $insertStmt->execute();
                
                $rawCandle = [
                    'timestamp' => $timestamp,
                    'time' => $candle['datetime'],
                    'open' => $candle['open'],
                    'high' => $candle['high'],
                    'low' => $candle['low'],
                    'close' => $candle['close'],
                    'volume' => $volume,
                    'closeTime' => $timestamp + ($intervalSeconds * 1000),
                    'trades' => 0,
                    'interval' => $interval,
                    'isClosed' => true
                ];
                
                // Format candle with correct decimal precision
                $candles[] = PriceFormatterService::formatCandle($pair, $rawCandle);
            }
            
            // Reverse array (Twelve Data returns newest first, we want oldest first)
            $candles = array_reverse($candles);
            
            // Limit to requested number of candles
            $candles = array_slice($candles, -$limit);
            
            error_log("Fetched and cached " . count($candles) . " candles for {$pair} from Twelve Data");
            
            // Merge with altered candles if user is provided
            if ($userId !== null) {
                $candles = self::mergeWithAlteredCandles($candles, $pair, $interval, $userId);
            }
            
            return [
                'pair' => $pair,
                'symbol' => str_replace('/', '', $pair),
                'interval' => $interval,
                'count' => count($candles),
                'candles' => $candles,
                'source' => 'twelve_data'
            ];
            
        } catch (Exception $e) {
            error_log("ChartDataService::getTwelveDataHistoricalCandles - " . $e->getMessage());
            Response::error('Failed to fetch Twelve Data chart data', 500);
            return null;
        }
    }
    
    /**
     * Convert interval string to seconds
     */
    private static function getIntervalSeconds($interval) {
        $map = [
            '1m' => 60,
            '3m' => 180,
            '5m' => 300,
            '15m' => 900,
            '30m' => 1800,
            '1h' => 3600,
            '2h' => 7200,
            '4h' => 14400,
            '6h' => 21600,
            '12h' => 43200,
            '1d' => 86400,
            '3d' => 259200,
            '1w' => 604800,
            '1M' => 2592000
        ];
        return $map[$interval] ?? 300; // Default to 5 minutes
    }
    
    /**
     * Get available chart intervals
     * 
     * @return array
     */
    public static function getAvailableIntervals() {
        return [
            [
                'value' => '1m',
                'label' => '1 Minute',
                'seconds' => 60
            ],
            [
                'value' => '3m',
                'label' => '3 Minutes',
                'seconds' => 180
            ],
            [
                'value' => '5m',
                'label' => '5 Minutes',
                'seconds' => 300
            ],
            [
                'value' => '15m',
                'label' => '15 Minutes',
                'seconds' => 900
            ],
            [
                'value' => '30m',
                'label' => '30 Minutes',
                'seconds' => 1800
            ],
            [
                'value' => '1h',
                'label' => '1 Hour',
                'seconds' => 3600
            ],
            [
                'value' => '2h',
                'label' => '2 Hours',
                'seconds' => 7200
            ],
            [
                'value' => '4h',
                'label' => '4 Hours',
                'seconds' => 14400
            ],
            [
                'value' => '6h',
                'label' => '6 Hours',
                'seconds' => 21600
            ],
            [
                'value' => '12h',
                'label' => '12 Hours',
                'seconds' => 43200
            ],
            [
                'value' => '1d',
                'label' => '1 Day',
                'seconds' => 86400
            ],
            [
                'value' => '1w',
                'label' => '1 Week',
                'seconds' => 604800
            ]
        ];
    }
    
    /**
     * Get chart data for multiple pairs (batch request)
     * 
     * @param array $input - Input with pairs array
     * @param int|null $userId - User ID for altered candles
     * @return array
     */
    public static function getBatchHistoricalCandles($input, $userId = null) {
        $pairs = $input['pairs'];
        $interval = $input['interval'] ?? '5m';
        $limit = isset($input['limit']) ? intval($input['limit']) : 100;
        
        $results = [];
        
        foreach ($pairs as $pair) {
            $data = self::getHistoricalCandles($pair, $interval, $limit, $userId);
            if ($data !== null) {
                $results[] = $data;
            }
        }
        
        
        return $results;
    }
    
    /**
     * Check if we're within Twelve Data rate limits
     * Free tier: 8 calls per minute, 800 calls per day
     * 
     * @return bool - True if within limits, false if exceeded
     */
    private static function checkTwelveDataRateLimit() {
        $conn = Database::getConnection();
        $now = time();
        
        // Check calls in the last minute (8 per minute limit)
        $oneMinuteAgo = $now - 60;
        $stmt = $conn->prepare("
            SELECT COUNT(*) as call_count 
            FROM twelve_data_api_calls 
            WHERE call_timestamp >= ?
        ");
        $stmt->bind_param("i", $oneMinuteAgo);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $minuteCallCount = $row['call_count'];
        
        if ($minuteCallCount >= 8) {
            error_log("Twelve Data rate limit: {$minuteCallCount} calls in last minute (max 8)");
            return false;
        }
        
        // Check calls in the last 24 hours (800 per day limit)
        $oneDayAgo = $now - 86400;
        $stmt = $conn->prepare("
            SELECT COUNT(*) as call_count 
            FROM twelve_data_api_calls 
            WHERE call_timestamp >= ?
        ");
        $stmt->bind_param("i", $oneDayAgo);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $dayCallCount = $row['call_count'];
        
        if ($dayCallCount >= 800) {
            error_log("Twelve Data rate limit: {$dayCallCount} calls in last 24 hours (max 800)");
            return false;
        }
        
        return true;
    }
    
    /**
     * Record a Twelve Data API call for rate limiting
     */
    private static function recordTwelveDataApiCall() {
        $conn = Database::getConnection();
        $now = time();
        
        $stmt = $conn->prepare("
            INSERT INTO twelve_data_api_calls (call_timestamp) 
            VALUES (?)
        ");
        $stmt->bind_param("i", $now);
        $stmt->execute();
        
        // Clean up old records (older than 24 hours)
        $oneDayAgo = $now - 86400;
        $conn->query("DELETE FROM twelve_data_api_calls WHERE call_timestamp < {$oneDayAgo}");
    }
}

