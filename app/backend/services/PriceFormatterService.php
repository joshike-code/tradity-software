<?php

/**
 * PriceFormatterService
 * 
 * Centralized service for formatting prices, volumes, and other numeric values
 * based on pair configuration from the database.
 * 
 * Ensures consistent decimal places across:
 * - WebSocket price broadcasts
 * - Chart candle data
 * - Trade profit/loss calculations
 * - Account balance displays
 */
class PriceFormatterService
{
    /**
     * Cache of pair configurations to avoid repeated database queries
     * Format: ['BTC/USD' => ['digits' => 2, 'volume_decimals' => 2, ...]]
     */
    private static $pairConfigCache = [];
    
    /**
     * Load pair configuration from database (with caching)
     * 
     * @param string $pairName - Pair name (e.g., 'BTC/USD', 'EUR/USD')
     * @return array|null - Pair configuration or null if not found
     */
    private static function getPairConfig($pairName) {
        // Check cache first
        if (isset(self::$pairConfigCache[$pairName])) {
            return self::$pairConfigCache[$pairName];
        }
        
        // Load from database
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("
                SELECT name, type, digits, volume_decimals, 
                       lot_size, pip_value, spread
                FROM pairs 
                WHERE name = ?
            ");
            $stmt->bind_param("s", $pairName);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $config = $result->fetch_assoc();
                self::$pairConfigCache[$pairName] = $config;
                return $config;
            }
            
            // Pair not found - return null
            return null;
            
        } catch (Exception $e) {
            error_log("PriceFormatterService::getPairConfig - " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Load all active pairs into cache (for bulk operations like server startup)
     */
    public static function preloadPairConfigs() {
        try {
            $conn = Database::getConnection();
            $result = $conn->query("
                SELECT name, type, digits, volume_decimals, 
                       lot_size, pip_value, spread
                FROM pairs 
                WHERE status = 'active'
            ");
            
            $count = 0;
            while ($row = $result->fetch_assoc()) {
                self::$pairConfigCache[$row['name']] = $row;
                $count++;
            }
            
            echo "[PRICE FORMATTER] Preloaded {$count} pair configurations\n";
            return $count;
            
        } catch (Exception $e) {
            error_log("PriceFormatterService::preloadPairConfigs - " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Format a price value based on pair configuration
     * 
     * @param string $pairName - Pair name (e.g., 'BTC/USD')
     * @param float $price - Raw price value
     * @return float - Formatted price (rounded to correct decimals)
     */
    public static function formatPrice($pairName, $price) {
        $config = self::getPairConfig($pairName);
        
        if (!$config) {
            // Fallback: use 2 decimals if pair not found
            return round($price, 2);
        }
        
        $decimals = intval($config['digits']);
        return round($price, $decimals);
    }
    
    /**
     * Format a volume/lot value based on pair configuration
     * 
     * @param string $pairName - Pair name
     * @param float $volume - Raw volume value
     * @return float - Formatted volume
     */
    public static function formatVolume($pairName, $volume) {
        $config = self::getPairConfig($pairName);
        
        if (!$config) {
            return round($volume, 2);
        }
        
        $decimals = intval($config['volume_decimals']);
        return round($volume, $decimals);
    }
    
    /**
     * Format an entire price update array for WebSocket broadcast
     * 
     * @param string $pairName - Pair name
     * @param float $price - Current price
     * @param array $additional - Additional fields (bid, ask, spread, etc.)
     * @return array - Formatted price data ready for broadcast
     */
    public static function formatPriceUpdate($pairName, $price, $additional = []) {
        $config = self::getPairConfig($pairName);
        
        $formatted = [
            'pair' => $pairName,
            'price' => self::formatPrice($pairName, $price),
            'timestamp' => time()
        ];
        
        // Add pair metadata if available
        if ($config) {
            $formatted['type'] = $config['type'];
            $formatted['decimals'] = intval($config['digits']);
        }
        
        // Format additional fields if provided
        if (isset($additional['bid'])) {
            $formatted['bid'] = self::formatPrice($pairName, $additional['bid']);
        }
        if (isset($additional['ask'])) {
            $formatted['ask'] = self::formatPrice($pairName, $additional['ask']);
        }
        if (isset($additional['spread'])) {
            $formatted['spread'] = $additional['spread'];
        }
        
        return $formatted;
    }
    
    /**
     * Format candle data based on pair configuration
     * 
     * @param string $pairName - Pair name
     * @param array $candle - Raw candle data (open, high, low, close)
     * @return array - Formatted candle data with string prices to preserve decimals in JSON
     */
    public static function formatCandle($pairName, $candle) {
        $config = self::getPairConfig($pairName);
        
        if (!$config) {
            // Fallback: round to 2 decimals, return as strings
            return [
                'type' => 'candle_update',
                'open' => number_format($candle['open'], 2, '.', ''),
                'high' => number_format($candle['high'], 2, '.', ''),
                'low' => number_format($candle['low'], 2, '.', ''),
                'close' => number_format($candle['close'], 2, '.', ''),
                'volume' => $candle['volume'] ?? 0,
                'timestamp' => $candle['timestamp'] ?? time(),
                'time' => $candle['time'] ?? date('Y-m-d H:i:s'),
                'interval' => $candle['interval'] ?? '1m',
                'isClosed' => $candle['isClosed'] ?? false,
                'pair' => $pairName
            ];
        }
        
        $priceDecimals = intval($config['digits']);
        
        return [
            'type' => 'candle_update',
            'open' => number_format($candle['open'], $priceDecimals, '.', ''),
            'high' => number_format($candle['high'], $priceDecimals, '.', ''),
            'low' => number_format($candle['low'], $priceDecimals, '.', ''),
            'close' => number_format($candle['close'], $priceDecimals, '.', ''),
            'volume' => $candle['volume'] ?? 0,
            'timestamp' => $candle['timestamp'] ?? time(),
            'time' => $candle['time'] ?? date('Y-m-d H:i:s'),
            'interval' => $candle['interval'] ?? '1m',
            'isClosed' => $candle['isClosed'] ?? false,
            'pair' => $pairName,
            'decimals' => $priceDecimals
        ];
    }
    
    /**
     * Get display-friendly price string (for UI)
     * 
     * @param string $pairName - Pair name
     * @param float $price - Price value
     * @return string - Formatted price string (e.g., "1.17283", "88234.56")
     */
    public static function formatPriceString($pairName, $price) {
        $config = self::getPairConfig($pairName);
        
        if (!$config) {
            return number_format($price, 2, '.', '');
        }
        
        $decimals = intval($config['digits']);
        return number_format($price, $decimals, '.', '');
    }
    
    /**
     * Clear the configuration cache (useful after pair updates)
     */
    public static function clearCache() {
        self::$pairConfigCache = [];
        echo "[PRICE FORMATTER] Cache cleared\n";
    }
    
    /**
     * Get cached pair count (for debugging)
     */
    public static function getCacheCount() {
        return count(self::$pairConfigCache);
    }
}
