<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/AlteredCandleCacheService.php';

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
                
                $candles[] = [
                    'timestamp' => intval($kline[0]), // Open time in milliseconds
                    'time' => date('Y-m-d H:i:s', intval($kline[0]) / 1000), // Human readable
                    'open' => floatval($kline[1]),
                    'high' => floatval($kline[2]),
                    'low' => floatval($kline[3]),
                    'close' => floatval($kline[4]),
                    'volume' => floatval($kline[5]),
                    'closeTime' => intval($kline[6]),
                    'trades' => intval($kline[8])
                ];
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
}
