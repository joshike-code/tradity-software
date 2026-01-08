<?php

use Ratchet\Client\Connector as WsConnector;
use React\Socket\Connector;

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
        
        // Oil (use futures or CFD symbols - these may need adjustment based on Finnhub availability)
        'WTI/USD' => 'OANDA:WTICO_USD', // WTI Crude Oil
        'BRENT/USD' => 'OANDA:BCO_USD', // Brent Crude Oil
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
        }
        
        // Set up periodic timer to check for closed candles (every second)
        $this->loop->addPeriodicTimer(1, function() {
            $this->checkAndBroadcastClosedCandles();
        });
        
        echo "[CANDLE] Candle aggregation initialized for forex/commodity pairs\n";
    }
    
    public function connect() {
        if (empty($this->apiKey) || $this->apiKey === 'your_finnhub_api_key_here') {
            echo "WARNING: Finnhub API key not configured. Skipping Finnhub connection.\n";
            echo "Please set FINNHUB_API_KEY in your .env file to enable forex/commodity trading.\n";
            echo "Get a free API key at: https://finnhub.io/register\n";
            return;
        }
        
        echo "Connecting to Finnhub WebSocket...\n";
        
        // Finnhub WebSocket URL with API key
        $wsUrl = "wss://ws.finnhub.io?token=" . $this->apiKey;
        
        // Use Google's public DNS (8.8.8.8)
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $dns = $dnsResolverFactory->createCached('8.8.8.8', $this->loop);
        
        $connector = new WsConnector($this->loop, new Connector($this->loop, ['dns' => $dns]));
        
        $connector($wsUrl)->then(
            function($conn) {
                echo "Connected to Finnhub WebSocket!\n";
                $this->wsConnection = $conn;
                $this->reconnectAttempts = 0;
                
                // Subscribe to all forex and commodity pairs
                $this->subscribeToPairs($conn);
                
                $conn->on('message', function($msg) {
                    $this->handleFinnhubMessage($msg);
                });
                
                $conn->on('close', function($code = null, $reason = null) {
                    echo "Finnhub WebSocket closed (Code: {$code}, Reason: {$reason})\n";
                    
                    if ($this->reconnectAttempts < $this->maxReconnectAttempts) {
                        $this->reconnectAttempts++;
                        $delay = min(5 * $this->reconnectAttempts, 60); // Max 60 seconds
                        echo "Reconnecting in {$delay} seconds... (Attempt {$this->reconnectAttempts}/{$this->maxReconnectAttempts})\n";
                        
                        $this->loop->addTimer($delay, function() {
                            $this->connect();
                        });
                    } else {
                        echo "Max reconnection attempts reached. Please check your API key and network connection.\n";
                    }
                });
                
                $conn->on('error', function($e) {
                    echo "Finnhub WebSocket error: {$e->getMessage()}\n";
                });
            },
            function($e) {
                echo "Could not connect to Finnhub: {$e->getMessage()}\n";
                
                if ($this->reconnectAttempts < $this->maxReconnectAttempts) {
                    $this->reconnectAttempts++;
                    $delay = min(5 * $this->reconnectAttempts, 60);
                    echo "Retrying in {$delay} seconds... (Attempt {$this->reconnectAttempts}/{$this->maxReconnectAttempts})\n";
                    
                    $this->loop->addTimer($delay, function() {
                        $this->connect();
                    });
                } else {
                    echo "Could not establish Finnhub connection after {$this->maxReconnectAttempts} attempts.\n";
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
                echo "Subscribed to Finnhub symbol: {$finnhubSymbol} (our pair: {$pair})\n";
            }
        }
    }
    
    private function handleFinnhubMessage($msg) {
        try {
            $data = json_decode($msg, true);
            
            // Finnhub sends different message types:
            // 1. {"type":"trade","data":[{"s":"OANDA:EUR_USD","p":1.0850,"t":1610087800000,"v":1},...]}
            // 2. {"type":"ping"} - heartbeat
            
            if (!isset($data['type'])) {
                return;
            }
            
            if ($data['type'] === 'ping') {
                // Respond to ping to keep connection alive
                if ($this->wsConnection) {
                    $this->wsConnection->send(json_encode(['type' => 'pong']));
                }
                return;
            }
            
            if ($data['type'] === 'trade' && isset($data['data'])) {
                $this->processTrades($data['data']);
            }
            
        } catch (Exception $e) {
            error_log("Error processing Finnhub message: " . $e->getMessage());
            echo "Error processing Finnhub message: " . $e->getMessage() . "\n";
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
                
                // Aggregate this tick into candles for all intervals
                $this->aggregateTick($ourPair, $price, $timestamp);
                
                echo "Finnhub price update: {$ourPair} ({$pairKey}) = {$price}\n";
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
                
                echo "[CANDLE] New {$interval} candle for {$pair}: Open={$price}\n";
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
                    
                    echo "[CANDLE] Closed {$interval} candle for {$pair}: O={$candle['open']} H={$candle['high']} L={$candle['low']} C={$candle['close']}\n";
                    
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
}
