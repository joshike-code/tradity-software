<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/jwt_utils.php';
require_once __DIR__ . '/../services/ProfitCalculationService.php';

class TradingWebSocketServer implements MessageComponentInterface {
    protected $clients;
    protected $authenticatedClients;
    protected $currentPrices;
    protected $subscribedPairs;
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->authenticatedClients = [];
        $this->currentPrices = [];
        $this->subscribedPairs = [];
        
        echo "WebSocket Server initialized\n";
    }
    
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->userId = null;
        echo "New connection: {$conn->resourceId}\n";
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        try {
            $data = json_decode($msg, true);
            
            if (!$data || !isset($data['type'])) {
                $from->send(json_encode(['error' => 'Invalid message format']));
                return;
            }
            
            switch ($data['type']) {
                case 'auth':
                    $this->handleAuth($from, $data);
                    break;
                    
                case 'subscribe':
                    $this->handleSubscribe($from, $data);
                    break;
                    
                case 'get_account':
                    $this->handleGetAccount($from);
                    break;
                    
                case 'ping':
                    $from->send(json_encode(['type' => 'pong']));
                    break;
                    
                default:
                    $from->send(json_encode(['error' => 'Unknown message type']));
            }
        } catch (Exception $e) {
            error_log("WebSocket message error: " . $e->getMessage());
            $from->send(json_encode(['error' => 'Server error']));
        }
    }
    
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        
        if ($conn->userId) {
            if (isset($this->authenticatedClients[$conn->userId])) {
                $key = array_search($conn, $this->authenticatedClients[$conn->userId], true);
                if ($key !== false) {
                    unset($this->authenticatedClients[$conn->userId][$key]);
                }
                
                if (empty($this->authenticatedClients[$conn->userId])) {
                    unset($this->authenticatedClients[$conn->userId]);
                }
            }
        }
        
        echo "Connection {$conn->resourceId} disconnected\n";
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        error_log("WebSocket error: " . $e->getMessage());
        $conn->close();
    }
    
    private function handleAuth(ConnectionInterface $conn, $data) {
        if (!isset($data['token'])) {
            $conn->send(json_encode(['type' => 'auth_error', 'message' => 'Token required']));
            return;
        }
        
        try {
            $decoded = verify_jwt($data['token'], 'base');
            
            if ($decoded && isset($decoded['user_id'])) {
                $userId = $decoded['user_id'];
                $conn->userId = $userId;
                
                if (!isset($this->authenticatedClients[$userId])) {
                    $this->authenticatedClients[$userId] = [];
                }
                $this->authenticatedClients[$userId][] = $conn;
                
                $conn->send(json_encode([
                    'type' => 'auth_success',
                    'userId' => $userId
                ]));
                
                echo "User {$userId} authenticated\n";
                
                $this->sendAccountUpdate($userId);
            } else {
                $conn->send(json_encode(['type' => 'auth_error', 'message' => 'Invalid token']));
            }
        } catch (Exception $e) {
            $conn->send(json_encode(['type' => 'auth_error', 'message' => 'Authentication failed']));
        }
    }
    
    private function handleSubscribe(ConnectionInterface $conn, $data) {
        if (!$conn->userId) {
            $conn->send(json_encode(['error' => 'Not authenticated']));
            return;
        }
        
        if (!isset($data['pairs']) || !is_array($data['pairs'])) {
            $conn->send(json_encode(['error' => 'Invalid pairs']));
            return;
        }
        
        $conn->subscribedPairs = $data['pairs'];
        
        $conn->send(json_encode([
            'type' => 'subscribed',
            'pairs' => $data['pairs']
        ]));
    }
    
    private function handleGetAccount(ConnectionInterface $conn) {
        if (!$conn->userId) {
            $conn->send(json_encode(['error' => 'Not authenticated']));
            return;
        }
        
        $this->sendAccountUpdate($conn->userId);
    }
    
    private function sendAccountUpdate($userId) {
        if (!isset($this->authenticatedClients[$userId])) {
            return;
        }
        
        $balances = ProfitCalculationService::calculateAccountBalances($userId, $this->currentPrices);
        
        if ($balances) {
            $message = json_encode([
                'type' => 'account_update',
                'balance' => $balances['balance'],
                'equity' => $balances['equity'],
                'freeMargin' => $balances['freeMargin'],
                'totalMargin' => $balances['totalMargin'],
                'profit_loss' => $balances['profit_loss'],
                'formattedProfit_loss' => $balances['formattedProfit_loss']
            ]);
            
            foreach ($this->authenticatedClients[$userId] as $conn) {
                $conn->send($message);
            }
        }
    }
    
    public function updatePrices($prices) {
        $this->currentPrices = array_merge($this->currentPrices, $prices);
        
        foreach ($this->authenticatedClients as $userId => $connections) {
            foreach ($connections as $conn) {
                if (isset($conn->subscribedPairs)) {
                    foreach ($prices as $pair => $price) {
                        $conn->send(json_encode([
                            'type' => 'price_update',
                            'pair' => $pair,
                            'price' => $price,
                            'timestamp' => time()
                        ]));
                    }
                }
                
                $this->sendAccountUpdate($userId);
            }
        }
    }
    
    public function fetchAndBroadcastPrices($pairs) {
        $prices = $this->fetchBinancePrices($pairs);
        if (!empty($prices)) {
            $this->updatePrices($prices);
        }
    }
    
    private function fetchBinancePrices($pairs) {
        try {
            $url = "https://api.binance.com/api/v3/ticker/price";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $data = json_decode($response, true);
                $prices = [];
                
                foreach ($data as $ticker) {
                    $symbol = strtolower($ticker['symbol']);
                    $prices[$symbol] = floatval($ticker['price']);
                }
                
                return $prices;
            }
        } catch (Exception $e) {
            error_log("Binance fetch error: " . $e->getMessage());
        }
        
        return [];
    }
}

// Start server
if (php_sapi_name() === 'cli') {
    $port = 8080;
    
    echo "Starting Trading WebSocket Server on port {$port}...\n";
    
    // Get active pairs from database
    $conn = Database::getConnection();
    $result = $conn->query("SELECT name FROM pairs WHERE status = 'active'");
    $pairs = [];
    while ($row = $result->fetch_assoc()) {
        $pairs[] = $row['name'];
    }
    
    echo "Monitoring " . count($pairs) . " pairs\n";
    
    $tradingServer = new TradingWebSocketServer();
    $server = IoServer::factory(
        new HttpServer(
            new WsServer($tradingServer)
        ),
        $port
    );
    
    // Fetch prices in background using periodic timer
    // This is a simple approach - fork process to fetch prices
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        // Fork failed - use single process (polling will block)
        echo "Warning: Could not fork process. Running in single-threaded mode.\n";
        echo "Price updates may be delayed.\n";
    } elseif ($pid == 0) {
        // Child process - fetch prices
        while (true) {
            $tradingServer->fetchAndBroadcastPrices($pairs);
            sleep(2); // Fetch every 2 seconds
        }
        exit(0);
    } else {
        // Parent process - run WebSocket server
        echo "Server running!\n";
        echo "Clients can connect to: ws://localhost:{$port}\n";
        echo "Price fetching process started (PID: {$pid})\n";
        
        $server->run();
    }
}
