<?php

use Ratchet\Client\Connector as WsConnector;
use React\Socket\Connector;

require_once __DIR__ . '/../utility/Logger.php';

/**
 * Finnhub WebSocket Client
 * 
 * Streams real-time forex and commodity prices from Finnhub API
 * Documentation: https://finnhub.io/docs/api/websocket-trades
 */
class FinnhubWebSocketClient {
    private $server;
    private $pairs;
    private $loop;
    private $wsConnection;
    private $apiKey;
    private $reconnectAttempts = 0;
    private $maxReconnectAttempts = 10;
    
    // Candle aggregation properties
    private $candles = []; // Stores forming candles: [pair][interval] => candle data
    private $intervals = ['1m', '5m', '15m']; // Supported intervals
    private $lastCandleCheck = []; // Track when we last checked for closed candles
    
    // Market status tracking
    private $marketStatus = 'open'; // 'open' or 'closed' (for forex/commodities)
    private $stockMarketStatus = 'open'; // 'open' or 'closed' (for stocks)
    private $lastTradeTime = []; // Track last trade time per pair: [pair] => timestamp
    private $marketStatusCheckInterval = 60; // Check every 60 seconds
    private $noTradeThreshold = 300; // 5 minutes of no trades = market closed
    
    // Map our pair format to Finnhub symbols
    private $symbolMap = [
        // Forex pairs (use OANDA format: OANDA:currency_pair)
        'EUR/USD' => 'OANDA:EUR_USD',
        'GBP/USD' => 'OANDA:GBP_USD',
        'USD/JPY' => 'OANDA:USD_JPY',
        'USD/CHF' => 'OANDA:USD_CHF',
        'AUD/USD' => 'OANDA:AUD_USD',
        'USD/CAD' => 'OANDA:USD_CAD',
        'EUR/CAD' => 'OANDA:EUR_CAD',
        'EUR/GBP' => 'OANDA:EUR_GBP',
        'EUR/JPY' => 'OANDA:EUR_JPY',
        'GBP/JPY' => 'OANDA:GBP_JPY',
        'EUR/CAD' => 'OANDA:EUR_CAD',
        'EUR/CHF' => 'OANDA:EUR_CHF',
        'EUR/GBP' => 'OANDA:EUR_GBP',
        'EUR/JPY' => 'OANDA:EUR_JPY',
        'EUR/AUD' => 'OANDA:EUR_AUD',
        'AUD/JPY' => 'OANDA:AUD_JPY',
        
        // Commodities (use spot symbols)
        'XAU/USD' => 'OANDA:XAU_USD',  // Gold
        'XAG/USD' => 'OANDA:XAG_USD',  // Silver
        'XPT/USD' => 'OANDA:XPT_USD',  // Platinum
        'XPD/USD' => 'OANDA:XPD_USD',  // Palladium
        'WTI/USD' => 'OANDA:WTICO_USD', // WTI Crude Oil (US Oil)
        'BRENT/USD' => 'OANDA:BCO_USD', // Brent Crude Oil
        'NAT/USD' => 'OANDA:NATGAS_USD', // Natural Gas
        'SUGAR/USD' => 'OANDA:SUGAR_USD', // Sugar
        
        // Stocks (use stock symbols)
        'MSFT/USD' => 'MSFT',  // Microsoft
        'AAPL/USD' => 'AAPL',  // Apple
        'AMZN/USD' => 'AMZN',  // Amazon
        'META/USD' => 'META',  // Meta (Facebook)
        'NVDA/USD' => 'NVDA',  // NVIDIA
        'SPCX/USD' => 'SPCX',  // The SPAC and New Issue ETF
        
        // Indices
        'NASDAQ/USD' => 'OANDA:NAS100_USD', // NASDAQ 100
        'SNP500/USD' => 'OANDA:SPX500_USD', // S&P 500
    ];
    
    public function __construct($server, $pairs, $loop, $apiKey) {
        $this->server = $server;
        $this->pairs = $pairs;
        $this->loop = $loop;
        $this->apiKey = $apiKey;
        
        // Initialize candle storage for each pair and interval
        foreach ($pairs as $pair) {
            foreach ($this->intervals as $interval) {
                $this->candles[$pair][$interval] = null;
            }
            // Initialize last trade time for market status detection
            $this->lastTradeTime[$pair] = time();
        }
        
        // Set up periodic timer to check for closed candles (every second)
        $this->loop->addPeriodicTimer(1, function() {
            $this->checkAndBroadcastClosedCandles();
        });
        
        // Set up periodic timer to check market status (every 60 seconds)
        $this->loop->addPeriodicTimer($this->marketStatusCheckInterval, function() {
            $this->checkMarketStatus();
        });
        
        Logger::init('finnhub.log');
        Logger::info("[CANDLE] Candle aggregation initialized for forex/commodity pairs");
        Logger::info("[MARKET] Market status monitoring initialized (threshold: {$this->noTradeThreshold}s)");
    }
    
    public function connect() {
        if (empty($this->apiKey) || $this->apiKey === 'your_finnhub_api_key_here') {
            Logger::warning("Finnhub API key not configured. Skipping Finnhub connection.");
            Logger::info("Please set FINNHUB_API_KEY in your .env file to enable forex/commodity trading.");
            Logger::info("Get a free API key at: https://finnhub.io/register");
            return;
        }
        
        Logger::info("Connecting to Finnhub WebSocket...");
        
        // Finnhub WebSocket URL with API key
        $wsUrl = "wss://ws.finnhub.io?token=" . $this->apiKey;
        
        // Use Google's public DNS (8.8.8.8)
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $dns = $dnsResolverFactory->createCached('8.8.8.8', $this->loop);
        
        $connector = new WsConnector($this->loop, new Connector($this->loop, ['dns' => $dns]));
        
        $connector($wsUrl)->then(
            function($conn) {
                Logger::info("Connected to Finnhub WebSocket!");
                $this->wsConnection = $conn;
                $this->reconnectAttempts = 0;
                
                // Subscribe to all forex and commodity pairs
                $this->subscribeToPairs($conn);
                
                // Broadcast initial market status after connection
                // Give it 10 seconds to receive first trades, then determine initial status
                $this->loop->addTimer(10, function() {
                    $this->checkMarketStatus();
                    Logger::info("[MARKET] Initial market status check completed");
                });
                
                $conn->on('message', function($msg) {
                    $this->handleFinnhubMessage($msg);
                });
                
                $conn->on('close', function($code = null, $reason = null) {
                    Logger::warning("Finnhub WebSocket closed (Code: {$code}, Reason: {$reason})");
                    
                    if ($this->reconnectAttempts < $this->maxReconnectAttempts) {
                        $this->reconnectAttempts++;
                        $delay = min(5 * $this->reconnectAttempts, 60); // Max 60 seconds
                        Logger::info("Reconnecting in {$delay} seconds... (Attempt {$this->reconnectAttempts}/{$this->maxReconnectAttempts})");
                        
                        $this->loop->addTimer($delay, function() {
                            $this->connect();
                        });
                    } else {
                        Logger::error("Max reconnection attempts reached. Please check your API key and network connection.");
                    }
                });
                
                $conn->on('error', function($e) {
                    Logger::error("Finnhub WebSocket error: {$e->getMessage()}");
                });
            },
            function($e) {
                Logger::error("Could not connect to Finnhub: {$e->getMessage()}");
                
                if ($this->reconnectAttempts < $this->maxReconnectAttempts) {
                    $this->reconnectAttempts++;
                    $delay = min(5 * $this->reconnectAttempts, 60);
                    Logger::info("Retrying in {$delay} seconds... (Attempt {$this->reconnectAttempts}/{$this->maxReconnectAttempts})");
                    
                    $this->loop->addTimer($delay, function() {
                        $this->connect();
                    });
                } else {
                    Logger::error("Could not establish Finnhub connection after {$this->maxReconnectAttempts} attempts.");
                }
            }
        );
    }
    
    private function subscribeToPairs($conn) {
        foreach ($this->pairs as $pair) {
            // Check if this pair is forex or commodity
            if (isset($this->symbolMap[$pair])) {
                $finnhubSymbol = $this->symbolMap[$pair];
                
                // Subscribe to this symbol
                $subscribeMsg = json_encode([
                    'type' => 'subscribe',
                    'symbol' => $finnhubSymbol
                ]);
                
                $conn->send($subscribeMsg);
                
                // Identify pair type for logging
                $pairType = 'unknown';
                if (strpos($finnhubSymbol, 'OANDA:') === 0) {
                    $pairType = 'forex/commodity';
                } else {
                    $pairType = 'stock';
                }
                
                Logger::info("Subscribed to Finnhub symbol: {$finnhubSymbol} ({$pairType}) -> our pair: {$pair}");
            }
        }
        
        Logger::warning("[FINNHUB] Note: Stock real-time trades require a paid Finnhub subscription.");
        Logger::info("[FINNHUB] Free tier only includes forex (OANDA) data. Check https://finnhub.io/pricing");
    }
    
    private function handleFinnhubMessage($msg) {
        try {
            $data = json_decode($msg, true);
            
            // Finnhub sends different message types:
            // 1. {"type":"trade","data":[{"s":"OANDA:EUR_USD","p":1.0850,"t":1610087800000,"v":1},...]}
            // 2. {"type":"ping"} - heartbeat
            // 3. {"type":"error","msg":"..."} - error message
            
            if (!isset($data['type'])) {
                Logger::warning("[FINNHUB] Unknown message format: {$msg}");
                return;
            }
            
            if ($data['type'] === 'ping') {
                // Respond to ping to keep connection alive
                if ($this->wsConnection) {
                    $this->wsConnection->send(json_encode(['type' => 'pong']));
                }
                return;
            }
            
            if ($data['type'] === 'error') {
                Logger::error("[FINNHUB] Error message: " . ($data['msg'] ?? 'Unknown error'));
                return;
            }
            
            if ($data['type'] === 'trade' && isset($data['data'])) {
                $this->processTrades($data['data']);
            } else {
                Logger::debug("[FINNHUB] Unhandled message type '{$data['type']}': {$msg}");
            }
            
        } catch (Exception $e) {
            Logger::error("Error processing Finnhub message: " . $e->getMessage());
        }
    }
    
    private function processTrades($trades) {
        $priceUpdates = [];
        
        foreach ($trades as $trade) {
            // Trade structure: {"s":"OANDA:EUR_USD","p":1.0850,"t":1610087800000,"v":1}
            // s = symbol, p = price, t = timestamp, v = volume
            
            if (!isset($trade['s']) || !isset($trade['p']) || !isset($trade['t'])) {
                continue;
            }
            
            $finnhubSymbol = $trade['s'];
            $price = floatval($trade['p']);
            $timestamp = $trade['t']; // Milliseconds
            
            // Convert Finnhub symbol back to our pair format
            $ourPair = $this->getFinnhubSymbolToPair($finnhubSymbol);
            
            if ($ourPair) {
                // Convert to our internal key format (lowercase, no slashes)
                // EUR/USD -> eurusd, XAU/USD -> xauusd
                $pairKey = strtolower(str_replace('/', '', $ourPair));
                
                $priceUpdates[$pairKey] = $price;
                
                // Update last trade time for this pair (for market status detection)
                $this->lastTradeTime[$ourPair] = time();
                
                // Aggregate this tick into candles for all intervals
                $this->aggregateTick($ourPair, $price, $timestamp);
                
                Logger::debug("Finnhub price update: {$ourPair} ({$pairKey}) = {$price}");
            }
        }
        
        // Batch update all prices
        if (!empty($priceUpdates)) {
            $this->server->updatePrices($priceUpdates);
        }
    }
    
    private function getFinnhubSymbolToPair($finnhubSymbol) {
        // Reverse lookup: find our pair name from Finnhub symbol
        foreach ($this->symbolMap as $ourPair => $symbol) {
            if ($symbol === $finnhubSymbol) {
                return $ourPair;
            }
        }
        return null;
    }
    
    public function close() {
        if ($this->wsConnection) {
            // Unsubscribe from all symbols before closing
            foreach ($this->pairs as $pair) {
                if (isset($this->symbolMap[$pair])) {
                    $unsubscribeMsg = json_encode([
                        'type' => 'unsubscribe',
                        'symbol' => $this->symbolMap[$pair]
                    ]);
                    $this->wsConnection->send($unsubscribeMsg);
                }
            }
            
            $this->wsConnection->close();
        }
    }
    
    /**
     * Aggregate a price tick into candles for all intervals
     * 
     * @param string $pair - Trading pair (e.g., EUR/USD)
     * @param float $price - Current price
     * @param int $timestamp - Timestamp in milliseconds
     */
    private function aggregateTick($pair, $price, $timestamp) {
        foreach ($this->intervals as $interval) {
            // Get the interval in milliseconds
            $intervalMs = $this->getIntervalMilliseconds($interval);
            
            // Calculate the candle start time (rounded down to interval boundary)
            $candleStartTime = floor($timestamp / $intervalMs) * $intervalMs;
            
            // Check if this is a new candle or update to existing
            if ($this->candles[$pair][$interval] === null || 
                $this->candles[$pair][$interval]['timestamp'] !== $candleStartTime) {
                
                // New candle period - initialize
                $this->candles[$pair][$interval] = [
                    'pair' => $pair,
                    'symbol' => strtolower(str_replace('/', '', $pair)),
                    'interval' => $interval,
                    'timestamp' => $candleStartTime,
                    'time' => date('Y-m-d H:i:s', $candleStartTime / 1000),
                    'open' => $price,
                    'high' => $price,
                    'low' => $price,
                    'close' => $price,
                    'volume' => 0,
                    'trades' => 1,
                    'isClosed' => false
                ];
                
                Logger::info("[CANDLE] New {$interval} candle for {$pair}: Open={$price}");
            } else {
                // Update existing candle
                $candle = &$this->candles[$pair][$interval];
                $candle['high'] = max($candle['high'], $price);
                $candle['low'] = min($candle['low'], $price);
                $candle['close'] = $price;
                $candle['trades']++;
            }
        }
    }
    
    /**
     * Check for closed candles and broadcast them
     * Called every second by periodic timer
     */
    private function checkAndBroadcastClosedCandles() {
        $currentTime = round(microtime(true) * 1000); // Current time in milliseconds
        
        foreach ($this->pairs as $pair) {
            foreach ($this->intervals as $interval) {
                $candle = $this->candles[$pair][$interval];
                
                if ($candle === null) {
                    continue; // No candle yet
                }
                
                if ($candle['isClosed']) {
                    continue; // Already closed
                }
                
                // Get the interval duration
                $intervalMs = $this->getIntervalMilliseconds($interval);
                
                // Check if the current time has passed the candle's close time
                $candleCloseTime = $candle['timestamp'] + $intervalMs;
                
                if ($currentTime >= $candleCloseTime) {
                    // Mark candle as closed
                    $this->candles[$pair][$interval]['isClosed'] = true;
                    
                    Logger::info("[CANDLE] Closed {$interval} candle for {$pair}: O={$candle['open']} H={$candle['high']} L={$candle['low']} C={$candle['close']}");
                    
                    // Broadcast closed candle to all subscribed clients
                    $this->server->broadcastCandle($this->candles[$pair][$interval]);
                    
                    // Start a new candle for the next period
                    $nextCandleStart = $candleCloseTime;
                    $this->candles[$pair][$interval] = [
                        'pair' => $pair,
                        'symbol' => strtolower(str_replace('/', '', $pair)),
                        'interval' => $interval,
                        'timestamp' => $nextCandleStart,
                        'time' => date('Y-m-d H:i:s', $nextCandleStart / 1000),
                        'open' => $candle['close'], // Next candle opens at previous close
                        'high' => $candle['close'],
                        'low' => $candle['close'],
                        'close' => $candle['close'],
                        'volume' => 0,
                        'trades' => 0,
                        'isClosed' => false
                    ];
                }
            }
        }
    }
    
    /**
     * Convert interval string to milliseconds
     * 
     * @param string $interval - e.g., "1m", "5m", "15m"
     * @return int - Milliseconds
     */
    private function getIntervalMilliseconds($interval) {
        $intervalMap = [
            '1m' => 60 * 1000,
            '5m' => 5 * 60 * 1000,
            '15m' => 15 * 60 * 1000,
            '30m' => 30 * 60 * 1000,
            '1h' => 60 * 60 * 1000,
            '4h' => 4 * 60 * 60 * 1000,
            '1d' => 24 * 60 * 60 * 1000
        ];
        
        return $intervalMap[$interval] ?? 60 * 1000; // Default to 1 minute
    }
    
    /**
     * Check market status based on actual market hours
     * Forex/Commodities: Sunday 5 PM EST to Friday 5 PM EST
     * Stocks: Monday-Friday 2:30 PM GMT to 9:00 PM GMT
     * Also checks trade activity as a secondary indicator
     */
    private function checkMarketStatus() {
        $currentTime = time();
        
        // Check forex/commodity market status
        $expectedForexStatus = $this->isForexMarketOpen() ? 'open' : 'closed';
        
        // Check stock market status
        $expectedStockStatus = $this->isStockMarketOpen() ? 'open' : 'closed';
        
        // Get trade activity status for forex/commodities and stocks separately
        $anyRecentForexTrades = false;
        $anyRecentStockTrades = false;
        
        foreach ($this->lastTradeTime as $pair => $lastTime) {
            $timeSinceLastTrade = $currentTime - $lastTime;
            if ($timeSinceLastTrade < $this->noTradeThreshold) {
                // Determine if this is a stock or forex/commodity pair
                if (in_array($pair, ['MSFT/USD', 'AAPL/USD', 'AMZN/USD', 'META/USD', 'NVDA/USD', 'SPCX/USD'])) {
                    $anyRecentStockTrades = true;
                } else {
                    $anyRecentForexTrades = true;
                }
            }
        }
        
        // Determine final statuses
        $newForexStatus = $expectedForexStatus === 'open' && !$anyRecentForexTrades ? 'closed' : $expectedForexStatus;
        $newStockStatus = $expectedStockStatus === 'open' && !$anyRecentStockTrades ? 'closed' : $expectedStockStatus;
        
        $previousForexStatus = $this->marketStatus;
        $previousStockStatus = $this->stockMarketStatus;
        
        // Check if forex market status changed
        if ($previousForexStatus !== $newForexStatus) {
            $this->marketStatus = $newForexStatus;
            
            if ($newForexStatus === 'closed') {
                $reason = $expectedForexStatus === 'closed' ? 'outside market hours' : 'no trade activity';
                Logger::warning("[FOREX] Market status changed: OPEN → CLOSED ({$reason})");
            } else {
                Logger::info("[FOREX] Market status changed: CLOSED → OPEN (market hours + trade activity detected)");
            }
        }
        
        // Check if stock market status changed
        if ($previousStockStatus !== $newStockStatus) {
            $this->stockMarketStatus = $newStockStatus;
            
            if ($newStockStatus === 'closed') {
                $reason = $expectedStockStatus === 'closed' ? 'outside market hours' : 'no trade activity';
                Logger::warning("[STOCKS] Market status changed: OPEN → CLOSED ({$reason})");
            } else {
                Logger::info("[STOCKS] Market status changed: CLOSED → OPEN (market hours + trade activity detected)");
            }
        }
        
        // Broadcast market status if either changed
        if ($previousForexStatus !== $newForexStatus || $previousStockStatus !== $newStockStatus) {
            $this->broadcastMarketStatus($newForexStatus, $newStockStatus);
        }
        
        // Debug: Show market status information
        Logger::debug("[MARKET] Forex: {$newForexStatus} (expected: {$expectedForexStatus}, trades: " . ($anyRecentForexTrades ? 'yes' : 'no') . "), Stocks: {$newStockStatus} (expected: {$expectedStockStatus}, trades: " . ($anyRecentStockTrades ? 'yes' : 'no') . ")");
    }
    
    /**
     * Check if forex market is open based on time and day
     * Forex markets: Sunday 5 PM EST to Friday 5 PM EST
     * 
     * @return bool - true if market should be open
     */
    public static function isForexMarketOpen() {
        // Get current time in EST timezone (forex market timezone)
        $timezone = new \DateTimeZone('US/Eastern');
        $now = new \DateTime('now', $timezone);
        
        $dayOfWeek = (int)$now->format('w'); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
        $hour = (int)$now->format('H');
        $minute = (int)$now->format('i');
        $time = $hour * 60 + $minute; // Convert to minutes since midnight
        
        // Market opens Sunday at 5 PM (17:00) = 1020 minutes
        $openTime = 17 * 60; // 1020 minutes
        // Market closes Friday at 5 PM (17:00) = 1020 minutes
        $closeTime = 17 * 60;
        
        // Sunday: open from 5 PM onwards
        if ($dayOfWeek === 0) {
            return $time >= $openTime; // Sunday 5 PM or later
        }
        
        // Monday to Thursday: always open (assuming they don't have holidays)
        if ($dayOfWeek >= 1 && $dayOfWeek <= 4) {
            return true;
        }
        
        // Friday: open until 5 PM
        if ($dayOfWeek === 5) {
            return $time < $closeTime; // Friday before 5 PM
        }
        
        // Saturday: always closed
        if ($dayOfWeek === 6) {
            return false;
        }
        
        return false; // Default to closed
    }

    /**
     * Check if stock market is open based on time and day
     * Stock markets: Monday-Friday 2:30 PM GMT to 9:00 PM GMT
     * 
     * @return bool - true if market should be open
     */
    public static function isStockMarketOpen() {
        // Get current time in GMT timezone (stock market timezone)
        $timezone = new \DateTimeZone('GMT');
        $now = new \DateTime('now', $timezone);
        
        $dayOfWeek = (int)$now->format('w'); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
        $hour = (int)$now->format('H');
        $minute = (int)$now->format('i');
        $time = $hour * 60 + $minute; // Convert to minutes since midnight
        
        // Market opens at 2:30 PM (14:30) = 870 minutes
        $openTime = 14 * 60 + 30; // 870 minutes
        // Market closes at 9:00 PM (21:00) = 1260 minutes
        $closeTime = 21 * 60; // 1260 minutes
        
        // Monday to Friday: open from 2:30 PM to 9:00 PM GMT
        if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
            return $time >= $openTime && $time < $closeTime;
        }
        
        // Saturday and Sunday: always closed
        return false;
    }

    /**
     * Get the next market open time as a Unix timestamp
     * 
     * @return int|null - Unix timestamp of next open, or null if currently open
     */
    public static function getNextOpenTimestamp() {
        $timezone = new \DateTimeZone('US/Eastern');
        $now = new \DateTime('now', $timezone);
        
        if (self::isForexMarketOpen()) {
            return null;
        }
        
        $dayOfWeek = (int)$now->format('w');
        $hour = (int)$now->format('H');
        $minute = (int)$now->format('i');
        $timeInMinutes = $hour * 60 + $minute;
        $openTimeInMinutes = 17 * 60;
        
        $nextOpen = clone $now;
        
        if ($dayOfWeek === 0 && $timeInMinutes < $openTimeInMinutes) {
            // It's Sunday before 5 PM EST
            $nextOpen->setTime(17, 0, 0);
        } else {
            // It's Friday after 5 PM, Saturday, or Sunday (already open but somehow we got here)
            // Or a holiday check could go here if we had one.
            $nextOpen->modify('next Sunday');
            $nextOpen->setTime(17, 0, 0);
        }
        
        return $nextOpen->getTimestamp();
    }
    
    /**
     * Get the next stock market open time as a Unix timestamp
     * 
     * @return int|null - Unix timestamp of next open, or null if currently open
     */
    public static function getNextStockOpenTimestamp() {
        $timezone = new \DateTimeZone('GMT');
        $now = new \DateTime('now', $timezone);
        
        if (self::isStockMarketOpen()) {
            return null;
        }
        
        $dayOfWeek = (int)$now->format('w');
        $hour = (int)$now->format('H');
        $minute = (int)$now->format('i');
        $timeInMinutes = $hour * 60 + $minute;
        $openTimeInMinutes = 14 * 60 + 30; // 2:30 PM
        
        $nextOpen = clone $now;
        
        // If it's a weekday before 2:30 PM, market opens today
        if ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $timeInMinutes < $openTimeInMinutes) {
            $nextOpen->setTime(14, 30, 0);
        }
        // If it's Friday after market close or Saturday/Sunday, market opens next Monday
        elseif ($dayOfWeek === 5 || $dayOfWeek === 6 || $dayOfWeek === 0) {
            $nextOpen->modify('next Monday');
            $nextOpen->setTime(14, 30, 0);
        }
        // If it's a weekday after market close, market opens tomorrow
        else {
            $nextOpen->modify('+1 day');
            $nextOpen->setTime(14, 30, 0);
        }
        
        return $nextOpen->getTimestamp();
    }
    
    /**
     * Broadcast market status to all clients
     * 
     * @param string $forexStatus - 'open' or 'closed' for forex/commodities
     * @param string $stockStatus - 'open' or 'closed' for stocks
     */
    private function broadcastMarketStatus($forexStatus, $stockStatus) {
        // Prepare market status data with next open times
        $marketData = [
            'forex' => [
                'status' => $forexStatus,
                'market_type' => 'Forex & Commodities',
                'hours' => 'Sunday 5:00 PM EST - Friday 5:00 PM EST'
            ],
            'stocks' => [
                'status' => $stockStatus,
                'market_type' => 'Stocks & Indices',
                'hours' => 'Monday-Friday 2:30 PM - 9:00 PM GMT'
            ]
        ];
        
        // Add next open time for forex if closed
        if ($forexStatus === 'closed') {
            $nextOpen = self::getNextOpenTimestamp();
            if ($nextOpen) {
                $remaining = $nextOpen - time();
                $marketData['forex']['next_open'] = $nextOpen;
                $marketData['forex']['next_open_formatted'] = date('Y-m-d H:i:s T', $nextOpen);
                $marketData['forex']['remaining'] = $remaining; // seconds until open
                $marketData['forex']['remaining_formatted'] = floor($remaining / 3600) . "h " . floor(($remaining % 3600) / 60) . "m";
            }
        }
        
        // Add next open time for stocks if closed
        if ($stockStatus === 'closed') {
            $nextOpen = self::getNextStockOpenTimestamp();
            if ($nextOpen) {
                $remaining = $nextOpen - time();
                $marketData['stocks']['next_open'] = $nextOpen;
                $marketData['stocks']['next_open_formatted'] = date('Y-m-d H:i:s T', $nextOpen);
                $marketData['stocks']['remaining'] = $remaining; // seconds until open
                $marketData['stocks']['remaining_formatted'] = floor($remaining / 3600) . "h " . floor(($remaining % 3600) / 60) . "m";
            }
        }
        
        // The server's broadcastMarketStatus will send this to clients
        $this->server->broadcastMarketStatus($marketData);
        
        // Log the broadcast
        $logMsg = "[MARKET] Broadcasted status - Forex: {$forexStatus}";
        if ($forexStatus === 'closed' && isset($marketData['forex']['next_open'])) {
            $remaining = $marketData['forex']['next_open'] - time();
            $logMsg .= " (opens in " . floor($remaining / 3600) . "h " . floor(($remaining % 3600) / 60) . "m)";
        }
        $logMsg .= ", Stocks: {$stockStatus}";
        if ($stockStatus === 'closed' && isset($marketData['stocks']['next_open'])) {
            $remaining = $marketData['stocks']['next_open'] - time();
            $logMsg .= " (opens in " . floor($remaining / 3600) . "h " . floor(($remaining % 3600) / 60) . "m)";
        }
        Logger::info($logMsg);
    }
}
