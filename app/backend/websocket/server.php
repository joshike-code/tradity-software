<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory as LoopFactory;
use React\Socket\Connector;
use Ratchet\Client\Connector as WsConnector;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/jwt_utils.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../services/ProfitCalculationService.php';
require_once __DIR__ . '/../services/TradeMonitorService.php';
require_once __DIR__ . '/../services/TradeAlterMonitorService.php';
require_once __DIR__ . '/../services/TradeService.php';
require_once __DIR__ . '/../services/WebSocketNotificationQueue.php';
require_once __DIR__ . '/../services/AlteredCandleCacheService.php';

// Disable exit() in Response class for WebSocket server context
Response::disableExit();

class TradingWebSocketServer implements MessageComponentInterface {
    protected $clients;
    protected $authenticatedClients; // Map of userId => [connections]
    protected $binanceConnection;
    protected $currentPrices;
    protected $alteredPrices; // Map of alter-affected trades/pairs => altered prices
    protected $chartAlters; // Active chart alters for synthetic candle generation
    protected $subscribedPairs;
    protected $chartSubscriptions; // Map of resourceId => ['pair' => 'interval']
    protected $adminAccountSubscriptions; // Map of resourceId => [accountIds]
    protected $adminTradeSubscriptions; // Map of resourceId => [tradeIds]
    protected $formingCandles; // Track forming candles with high/low updates
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->authenticatedClients = [];
        $this->currentPrices = [];
        $this->alteredPrices = []; // Initialize altered prices storage
        $this->chartAlters = []; // Initialize chart alters storage
        $this->subscribedPairs = [];
        $this->chartSubscriptions = []; // Initialize chart subscriptions storage
        $this->adminAccountSubscriptions = []; // Initialize admin account subscriptions
        $this->adminTradeSubscriptions = []; // Initialize admin trade subscriptions
        $this->formingCandles = []; // Initialize forming candles tracker
        
        // Set up WebSocket notification callback for trade closures
        // This allows TradeService to notify WebSocket clients when trades are closed
        TradeService::setNotificationCallback(function($trade, $reason) {
            $this->notifyTradeClosed($trade);
        });
        
        echo "WebSocket Server initialized\n";
        echo "Trade closure notifications enabled (SL/TP/Margin/Alter/Admin)\n";
        echo "Chart alteration system enabled\n";
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
            
            // Log all message types
            echo "[MSG] Client {$from->resourceId} sent type: {$data['type']}\n";
            
            switch ($data['type']) {
                case 'auth':
                    $this->handleAuth($from, $data);
                    break;
                    
                case 'subscribe':
                    $this->handleSubscribe($from, $data);
                    break;
                    
                case 'subscribe_trades':
                    $this->handleSubscribeTrades($from, $data);
                    break;
                    
                case 'subscribe_chart':
                    echo "[MSG] Processing subscribe_chart for client {$from->resourceId}\n";
                    $this->handleSubscribeChart($from, $data);
                    break;
                    
                case 'unsubscribe_chart':
                    $this->handleUnsubscribeChart($from, $data);
                    break;
                    
                case 'subscribe_account':
                    // Admin: subscribe to any account's data
                    $this->handleAdminSubscribeAccount($from, $data);
                    break;
                    
                case 'unsubscribe_account':
                    // Admin: unsubscribe from account
                    $this->handleAdminUnsubscribeAccount($from, $data);
                    break;
                    
                case 'subscribe_trade':
                    // Admin: subscribe to any trade's live P&L
                    $this->handleAdminSubscribeTrade($from, $data);
                    break;
                    
                case 'unsubscribe_trade':
                    // Admin: unsubscribe from trade
                    $this->handleAdminUnsubscribeTrade($from, $data);
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
        
        // Clean up chart subscriptions
        if (isset($this->chartSubscriptions[$conn->resourceId])) {
            unset($this->chartSubscriptions[$conn->resourceId]);
            echo "Cleaned up chart subscriptions for client {$conn->resourceId}\n";
        }
        
        // Clean up admin account subscriptions
        if (isset($this->adminAccountSubscriptions[$conn->resourceId])) {
            unset($this->adminAccountSubscriptions[$conn->resourceId]);
            echo "Cleaned up admin account subscriptions for client {$conn->resourceId}\n";
        }
        
        // Clean up admin trade subscriptions
        if (isset($this->adminTradeSubscriptions[$conn->resourceId])) {
            unset($this->adminTradeSubscriptions[$conn->resourceId]);
            echo "Cleaned up admin trade subscriptions for client {$conn->resourceId}\n";
        }
        
        if ($conn->userId) {
            // Remove from authenticated clients
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
            // Verify JWT token
            $decoded = verify_jwt($data['token'], 'base');
            
            if ($decoded && isset($decoded->user_id)) {
                $userId = $decoded->user_id;
                $conn->userId = $userId;
                
                // Get user role from database
                $dbConn = Database::getConnection();
                $stmt = $dbConn->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    $conn->userRole = $user['role'];
                } else {
                    $conn->userRole = 'user'; // Default to user if not found
                }
                
                // Add to authenticated clients
                if (!isset($this->authenticatedClients[$userId])) {
                    $this->authenticatedClients[$userId] = [];
                }
                $this->authenticatedClients[$userId][] = $conn;
                
                $conn->send(json_encode([
                    'type' => 'auth_success',
                    'userId' => $userId,
                    'role' => $conn->userRole
                ]));
                
                echo "User {$userId} authenticated (Role: {$conn->userRole})\n";
                
                // Only send initial account data to regular users (not admins/superadmins)
                if ($conn->userRole === 'user') {
                    $this->sendAccountUpdate($userId);
                }
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
    
    private function handleSubscribeTrades(ConnectionInterface $conn, $data) {
        if (!$conn->userId) {
            $conn->send(json_encode(['error' => 'Not authenticated']));
            return;
        }
        
        // Enable trade subscription
        $conn->tradesSubscribed = true;
        
        // Determine which trade pairs to subscribe to
        if (!isset($data['pairs'])) {
            // No pairs specified = all trades
            $conn->subscribedTradePairs = null;
        } elseif ($data['pairs'] === 'all' || $data['pairs'] === '*') {
            // Explicitly request all trades
            $conn->subscribedTradePairs = null;
        } elseif (is_array($data['pairs']) && count($data['pairs']) > 0) {
            // Specific pairs only
            $conn->subscribedTradePairs = $data['pairs'];
        } else {
            // Invalid format, default to all
            $conn->subscribedTradePairs = null;
        }
        
        $conn->send(json_encode([
            'type' => 'trades_subscribed',
            'pairs' => $conn->subscribedTradePairs === null ? 'all' : $conn->subscribedTradePairs
        ]));
        
        // Send initial trades data
        $this->sendAccountUpdate($conn->userId);
    }
    
    private function handleGetAccount(ConnectionInterface $conn) {
        if (!$conn->userId) {
            $conn->send(json_encode(['error' => 'Not authenticated']));
            return;
        }
        
        $this->sendAccountUpdate($conn->userId);
    }
    
    private function handleSubscribeChart(ConnectionInterface $conn, $data) {
        if (!isset($data['pair']) || !isset($data['interval'])) {
            $conn->send(json_encode(['error' => 'Pair and interval required']));
            return;
        }
        
        $pair = $data['pair'];
        $interval = $data['interval'];
        
        echo "[SUBSCRIBE_CHART] Client {$conn->resourceId} requesting: pair='{$pair}', interval='{$interval}'\n";
        echo "[SUBSCRIBE_CHART] Current chartSubscriptions count: " . count($this->chartSubscriptions) . "\n";
        
        // Store subscription in server-level array instead of connection property
        $resourceId = $conn->resourceId;
        
        if (!isset($this->chartSubscriptions[$resourceId])) {
            $this->chartSubscriptions[$resourceId] = [];
        }
        
        // Add to subscriptions
        $this->chartSubscriptions[$resourceId][$pair] = $interval;
        
        echo "[SUBSCRIBE_CHART] ✓ Stored subscription: chartSubscriptions[{$resourceId}]['{$pair}'] = '{$interval}'\n";
        echo "[SUBSCRIBE_CHART] Total subscriptions for this client: " . count($this->chartSubscriptions[$resourceId]) . "\n";
        
        $conn->send(json_encode([
            'type' => 'chart_subscribed',
            'pair' => $pair,
            'interval' => $interval,
            'message' => "Subscribed to {$pair} {$interval} candles"
        ]));
        
        echo "Client {$conn->resourceId} subscribed to {$pair} chart ({$interval})\n";
    }
    
    private function handleUnsubscribeChart(ConnectionInterface $conn, $data) {
        if (!isset($data['pair'])) {
            $conn->send(json_encode(['error' => 'Pair required']));
            return;
        }
        
        $pair = $data['pair'];
        $resourceId = $conn->resourceId;
        
        if (isset($this->chartSubscriptions[$resourceId][$pair])) {
            unset($this->chartSubscriptions[$resourceId][$pair]);
            
            $conn->send(json_encode([
                'type' => 'chart_unsubscribed',
                'pair' => $pair
            ]));
            
            echo "Client {$conn->resourceId} unsubscribed from {$pair} chart\n";
        }
    }
    
    private function checkAdminPermission(ConnectionInterface $conn, $requiredPermissions) {
        if (!$conn->userId) {
            return ['allowed' => false, 'error' => 'Not authenticated'];
        }
        
        try {
            // Get user from database
            $dbConn = Database::getConnection();
            $stmt = $dbConn->prepare("SELECT role, permissions FROM users WHERE id = ?");
            $stmt->bind_param("i", $conn->userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return ['allowed' => false, 'error' => 'User not found'];
            }
            
            $user = $result->fetch_assoc();
            $role = $user['role'];
            
            // Superadmin has all permissions
            if ($role === 'superadmin') {
                return ['allowed' => true, 'role' => 'superadmin'];
            }
            
            // Admin must have specific permissions
            if ($role === 'admin') {
                $userPermissions = json_decode($user['permissions'], true) ?? [];
                
                // Check if user has any of the required permissions
                foreach ($requiredPermissions as $permission) {
                    if (in_array($permission, $userPermissions)) {
                        return ['allowed' => true, 'role' => 'admin', 'permissions' => $userPermissions];
                    }
                }
                
                return ['allowed' => false, 'error' => 'Insufficient permissions. Required: ' . implode(' or ', $requiredPermissions)];
            }
            
            return ['allowed' => false, 'error' => 'Access denied. Admin role required.'];
        } catch (Exception $e) {
            error_log("Permission check error: " . $e->getMessage());
            return ['allowed' => false, 'error' => 'Permission check failed'];
        }
    }
    
    private function handleAdminSubscribeAccount(ConnectionInterface $conn, $data) {
        // Check permission: manage_accounts OR manage_trades
        $permCheck = $this->checkAdminPermission($conn, ['manage_accounts', 'manage_trades']);
        
        if (!$permCheck['allowed']) {
            $conn->send(json_encode(['type' => 'error', 'message' => $permCheck['error']]));
            return;
        }
        
        if (!isset($data['account_id'])) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'account_id required']));
            return;
        }
        
        $accountId = $data['account_id'];
        $resourceId = $conn->resourceId;
        
        // Verify account exists
        try {
            $dbConn = Database::getConnection();
            $stmt = $dbConn->prepare("SELECT id_hash, balance FROM accounts WHERE id_hash = ?");
            $stmt->bind_param("s", $accountId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $conn->send(json_encode(['type' => 'error', 'message' => 'Account not found']));
                return;
            }
            
            // Initialize subscription storage
            if (!isset($this->adminAccountSubscriptions[$resourceId])) {
                $this->adminAccountSubscriptions[$resourceId] = [];
            }
            
            // Add subscription
            $this->adminAccountSubscriptions[$resourceId][] = $accountId;
            
            $conn->send(json_encode([
                'type' => 'account_subscribed',
                'account_id' => $accountId,
                'message' => "Subscribed to account {$accountId}"
            ]));
            
            echo "[ADMIN] Client {$resourceId} ({$permCheck['role']}) subscribed to account {$accountId}\n";
            
            // Send initial account data
            $this->sendAdminAccountUpdate($accountId);
            
        } catch (Exception $e) {
            error_log("Admin subscribe account error: " . $e->getMessage());
            $conn->send(json_encode(['type' => 'error', 'message' => 'Failed to subscribe to account']));
        }
    }
    
    private function handleAdminUnsubscribeAccount(ConnectionInterface $conn, $data) {
        if (!isset($data['account_id'])) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'account_id required']));
            return;
        }
        
        $accountId = $data['account_id'];
        $resourceId = $conn->resourceId;
        
        if (isset($this->adminAccountSubscriptions[$resourceId])) {
            $key = array_search($accountId, $this->adminAccountSubscriptions[$resourceId]);
            if ($key !== false) {
                unset($this->adminAccountSubscriptions[$resourceId][$key]);
                $conn->send(json_encode([
                    'type' => 'account_unsubscribed',
                    'account_id' => $accountId
                ]));
                echo "[ADMIN] Client {$resourceId} unsubscribed from account {$accountId}\n";
            }
        }
    }
    
    private function handleAdminSubscribeTrade(ConnectionInterface $conn, $data) {
        // Check permission: manage_trades
        $permCheck = $this->checkAdminPermission($conn, ['manage_trades']);
        
        if (!$permCheck['allowed']) {
            $conn->send(json_encode(['type' => 'error', 'message' => $permCheck['error']]));
            return;
        }
        
        if (!isset($data['trade_id'])) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'trade_id required']));
            return;
        }
        
        $tradeId = $data['trade_id'];
        $resourceId = $conn->resourceId;
        
        // Verify trade exists and is open
        try {
            $dbConn = Database::getConnection();
            $stmt = $dbConn->prepare("
                SELECT t.*, p.lot_size, p.digits 
                FROM trades t 
                LEFT JOIN pairs p ON t.pair = p.name 
                WHERE t.id = ? AND (t.close_date IS NULL OR t.close_date = '')
            ");
            $stmt->bind_param("i", $tradeId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $conn->send(json_encode(['type' => 'error', 'message' => 'Open trade not found']));
                return;
            }
            
            $trade = $result->fetch_assoc();
            
            // Initialize subscription storage
            if (!isset($this->adminTradeSubscriptions[$resourceId])) {
                $this->adminTradeSubscriptions[$resourceId] = [];
            }
            
            // Add subscription
            $this->adminTradeSubscriptions[$resourceId][] = $tradeId;
            
            $conn->send(json_encode([
                'type' => 'trade_subscribed',
                'trade_id' => $tradeId,
                'pair' => $trade['pair'],
                'type' => $trade['type'],
                'message' => "Subscribed to trade #{$tradeId}"
            ]));
            
            echo "[ADMIN] Client {$resourceId} ({$permCheck['role']}) subscribed to trade {$tradeId}\n";
            echo "[DEBUG] adminTradeSubscriptions now: " . json_encode($this->adminTradeSubscriptions) . "\n";
            
            // Send initial trade data
            $this->sendAdminTradeUpdate($tradeId);
            
        } catch (Exception $e) {
            error_log("Admin subscribe trade error: " . $e->getMessage());
            $conn->send(json_encode(['type' => 'error', 'message' => 'Failed to subscribe to trade']));
        }
    }
    
    private function handleAdminUnsubscribeTrade(ConnectionInterface $conn, $data) {
        if (!isset($data['trade_id'])) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'trade_id required']));
            return;
        }
        
        $tradeId = $data['trade_id'];
        $resourceId = $conn->resourceId;
        
        if (isset($this->adminTradeSubscriptions[$resourceId])) {
            $key = array_search($tradeId, $this->adminTradeSubscriptions[$resourceId]);
            if ($key !== false) {
                unset($this->adminTradeSubscriptions[$resourceId][$key]);
                $conn->send(json_encode([
                    'type' => 'trade_unsubscribed',
                    'trade_id' => $tradeId
                ]));
                echo "[ADMIN] Client {$resourceId} unsubscribed from trade {$tradeId}\n";
            }
        }
    }
    
    private function sendAdminAccountUpdate($accountId) {
        // Calculate account balances with current prices
        $balances = ProfitCalculationService::calculateAccountBalances($accountId, $this->currentPrices);
        
        if (!$balances) {
            return;
        }
        
        // Send to all clients subscribed to this account
        foreach ($this->adminAccountSubscriptions as $resourceId => $accountIds) {
            if (in_array($accountId, $accountIds)) {
                // Find the connection with this resourceId
                foreach ($this->clients as $conn) {
                    if ($conn->resourceId === $resourceId) {
                        $conn->send(json_encode([
                            'type' => 'admin_account_update',
                            'account_id' => $accountId,
                            'data' => [
                                'balance' => $balances['balance'],
                                'equity' => $balances['equity'],
                                'freeMargin' => $balances['freeMargin'],
                                'totalMargin' => $balances['totalMargin'],
                                'profit_loss' => $balances['profit_loss'],
                                'formattedProfit_loss' => $balances['formattedProfit_loss']
                            ],
                            'timestamp' => time()
                        ]));
                        break;
                    }
                }
            }
        }
    }
    
    private function sendAdminTradeUpdate($tradeId) {
        try {
            $dbConn = Database::getConnection();
            $stmt = $dbConn->prepare("
                SELECT t.*, p.lot_size, p.digits 
                FROM trades t 
                LEFT JOIN pairs p ON t.pair = p.name 
                WHERE t.id = ? AND (t.close_date IS NULL OR t.close_date = '')
            ");
            $stmt->bind_param("i", $tradeId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                // Trade not found or closed - notify subscribers
                foreach ($this->adminTradeSubscriptions as $resourceId => $tradeIds) {
                    if (in_array($tradeId, $tradeIds)) {
                        foreach ($this->clients as $conn) {
                            if ($conn->resourceId === $resourceId) {
                                $conn->send(json_encode([
                                    'type' => 'admin_trade_closed',
                                    'trade_id' => $tradeId,
                                    'message' => 'Trade has been closed'
                                ]));
                                
                                // Remove subscription
                                $key = array_search($tradeId, $this->adminTradeSubscriptions[$resourceId]);
                                if ($key !== false) {
                                    unset($this->adminTradeSubscriptions[$resourceId][$key]);
                                }
                                break;
                            }
                        }
                    }
                }
                return;
            }
            
            $trade = $result->fetch_assoc();
            
            // Get current price
            $pairKey = str_replace(['/', 'USD'], ['', 'USDT'], $trade['pair']);
            $pairKey = strtolower($pairKey);
            $currentPrice = isset($this->currentPrices[$pairKey]) ? $this->currentPrices[$pairKey] : null;
            
            $tradeData = [
                'id' => $trade['id'],
                'ref' => $trade['ref'],
                'pair' => $trade['pair'],
                'type' => $trade['type'],
                'lot' => floatval($trade['lot']),
                'leverage' => intval($trade['leverage']),
                'trade_price' => floatval($trade['trade_price']),
                'take_profit' => $trade['take_profit'],
                'stop_loss' => $trade['stop_loss'],
                'margin' => floatval($trade['margin']),
                'commission' => floatval($trade['commission']),
                'swap' => floatval($trade['swap']),
                'date' => $trade['date'],
                'account' => $trade['account']
            ];
            
            // Calculate live P&L if current price available
            if ($currentPrice !== null) {
                $pairConfig = [
                    'lot_size' => $trade['lot_size'] ?? 1,
                    'digits' => $trade['digits'] ?? 2
                ];
                
                $profitCalc = ProfitCalculationService::calculateTradeProfit($trade, $currentPrice, $pairConfig);
                if ($profitCalc) {
                    $tradeData['currentPrice'] = $currentPrice;
                    $tradeData['totalProfit'] = $profitCalc['totalProfit'];
                    $tradeData['formattedProfit'] = $profitCalc['formattedProfit'];
                    $tradeData['profitStatus'] = $profitCalc['profitStatus'];
                }
            } else {
                $tradeData['currentPrice'] = null;
                $tradeData['totalProfit'] = 0;
                $tradeData['formattedProfit'] = 'pending';
                $tradeData['profitStatus'] = 'pending';
            }
            
            // Send to all clients subscribed to this trade
            foreach ($this->adminTradeSubscriptions as $resourceId => $tradeIds) {
                if (in_array($tradeId, $tradeIds)) {
                    foreach ($this->clients as $conn) {
                        if ($conn->resourceId === $resourceId) {
                            $conn->send(json_encode([
                                'type' => 'admin_trade_update',
                                'trade_id' => $tradeId,
                                'data' => $tradeData,
                                'timestamp' => time()
                            ]));
                            break;
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("Admin trade update error: " . $e->getMessage());
        }
    }
    
    private function sendAccountUpdate($userId, $filterPairs = null) {
        if (!isset($this->authenticatedClients[$userId])) {
            return;
        }
        
        foreach ($this->authenticatedClients[$userId] as $conn) {
            // Only send account updates to regular users (not admins/superadmins)
            if (!isset($conn->userRole) || $conn->userRole !== 'user') {
                continue;
            }
            
            // Calculate account balances with current prices
            $balances = ProfitCalculationService::calculateCurrentAccountBalances($userId, $this->currentPrices);
            
            if ($balances) {
                $message = [
                    'type' => 'account_update',
                    'balance' => $balances['balance'],
                    'equity' => $balances['equity'],
                    'freeMargin' => $balances['freeMargin'],
                    'totalMargin' => $balances['totalMargin'],
                    'profit_loss' => $balances['profit_loss'],
                    'formattedProfit_loss' => $balances['formattedProfit_loss']
                ];
                
                // Only include trades if user is subscribed to trades
                if (isset($conn->tradesSubscribed) && $conn->tradesSubscribed === true) {
                    // Use connection-specific trade pair filter if available
                    $tradePairFilter = $filterPairs ?? $conn->subscribedTradePairs ?? null;
                    
                    // Get individual trade profits with optional filtering
                    // calculateTradeProfit will automatically apply altered prices
                    $tradeProfits = ProfitCalculationService::getOpenTradesProfits(
                        $userId, 
                        $this->currentPrices, 
                        $tradePairFilter
                    );
                    
                    $message['openTrades'] = $tradeProfits;
                    $message['tradeFilter'] = $tradePairFilter === null ? 'all' : $tradePairFilter;
                }
                
                $conn->send(json_encode($message));
            }
        }
    }
    
    private function notifyTradeClosed($closedTradeInfo) {
        // Get the user ID and trade ID from the closed trade info
        try {
            $userId = $closedTradeInfo['userid'];
            $tradeId = $closedTradeInfo['id'] ?? $closedTradeInfo['trade_id'] ?? null;
            // Handle both 'reason' (from queue) and 'close_reason' (from callback)
            $reason = $closedTradeInfo['reason'] ?? $closedTradeInfo['close_reason'] ?? 'manual';
            
            // Map reason to user-friendly message
            $reasonMessages = [
                'manual' => 'manually closed',
                'stop_loss' => 'Stop Loss triggered',
                'take_profit' => 'Take Profit triggered',
                'margin_call' => 'closed due to Margin Call',
                'stop_out' => 'closed due to Stop Out',
                'expire' => 'closed by Alter Trade completion',
                'admin' => 'closed by Admin'
            ];
            
            $message = $reasonMessages[$reason] ?? $reason;
            
            // Prepare notification message
            $notificationData = [
                'type' => 'trade_closed',
                'trade_id' => $tradeId,
                'ref' => $closedTradeInfo['ref'] ?? null,
                'pair' => $closedTradeInfo['pair'] ?? null,
                // Handle both 'type' (from callback) and 'trade_type' (from queue)
                'trade_type' => $closedTradeInfo['trade_type'] ?? $closedTradeInfo['type'] ?? null,
                'reason' => $reason,
                'profit' => $closedTradeInfo['profit'] ?? '0.00',
                'close_price' => $closedTradeInfo['close_price'] ?? null,
                'message' => $message . ' for ' . ($closedTradeInfo['pair'] ?? 'trade'),
                'timestamp' => time(),
                'user_id' => $userId // Include user_id for admin context
            ];
            
            // Send notification to the trade owner (regular user)
            if (isset($this->authenticatedClients[$userId])) {
                echo "[NOTIFY] Sending trade_closed notification to user {$userId} (reason: {$reason})\n";
                
                foreach ($this->authenticatedClients[$userId] as $conn) {
                    $conn->send(json_encode($notificationData));
                }
                
                // Force account update to refresh balance and open trades
                $this->sendAccountUpdate($userId);
            } else {
                echo "[NOTIFY] User {$userId} not connected to WebSocket\n";
            }
            
            // Send notification to admins subscribed to this specific trade
            if ($tradeId !== null) {
                $adminNotificationsSent = 0;
                
                echo "[DEBUG] Checking admin subscriptions for trade {$tradeId}...\n";
                echo "[DEBUG] adminTradeSubscriptions: " . json_encode($this->adminTradeSubscriptions) . "\n";
                echo "[DEBUG] Number of admin subscription keys: " . count($this->adminTradeSubscriptions) . "\n";
                
                foreach ($this->adminTradeSubscriptions as $resourceId => $subscribedTradeIds) {
                    echo "[DEBUG] Checking resourceId {$resourceId} with subscribed trades: " . json_encode($subscribedTradeIds) . "\n";
                    
                    // Check if this admin is subscribed to this trade
                    if (in_array($tradeId, $subscribedTradeIds)) {
                        echo "[DEBUG] ResourceId {$resourceId} is subscribed to trade {$tradeId}. Looking for connection...\n";
                        
                        // Find the admin connection
                        $foundConnection = false;
                        foreach ($this->clients as $conn) {
                            if ($conn->resourceId === $resourceId) {
                                echo "[NOTIFY] Sending trade_closed notification to admin (resourceId: {$resourceId}, trade: {$tradeId})\n";
                                $conn->send(json_encode($notificationData));
                                $adminNotificationsSent++;
                                $foundConnection = true;
                                
                                // Also send updated trade data to admin
                                $this->sendAdminTradeUpdate($tradeId);
                                break;
                            }
                        }
                        
                        if (!$foundConnection) {
                            echo "[DEBUG] Could not find connection for resourceId {$resourceId}\n";
                        }
                    } else {
                        echo "[DEBUG] ResourceId {$resourceId} is NOT subscribed to trade {$tradeId}\n";
                    }
                }
                
                if ($adminNotificationsSent > 0) {
                    echo "[NOTIFY] Sent trade_closed to {$adminNotificationsSent} subscribed admin(s)\n";
                } else {
                    echo "[DEBUG] No admins were notified for trade {$tradeId}\n";
                }
            }
        } catch (Exception $e) {
            error_log("WebSocket::notifyTradeClosed - " . $e->getMessage());
        }
    }
    
    public function updatePrices($prices) {
        // Debug: Log received prices
        echo "updatePrices called with: " . json_encode($prices) . "\n";
        
        // Merge new prices
        $this->currentPrices = array_merge($this->currentPrices, $prices);
        
        // SECURITY: Write live prices to cache with timestamp for trade operations
        // This prevents users from exploiting stale prices
        $livePricesFile = __DIR__ . '/../cache/websocket_live_prices.json';
        $livePricesData = [
            'timestamp' => time(),
            'prices' => $this->currentPrices
        ];
        $result = file_put_contents($livePricesFile, json_encode($livePricesData));
        if ($result === false) {
            error_log("ERROR: Failed to write live prices to cache!");
        }
        
        // ALTER TRADES MONITORING: Process active alter_trades and get altered prices
        // This runs before other monitoring to provide simulated prices
        $alterResult = TradeAlterMonitorService::processAlterTrades($this->currentPrices);
        if ($alterResult['success']) {
            $this->alteredPrices = $alterResult['altered_prices'];
            $this->chartAlters = $alterResult['chart_alters']; // Store chart alters for candle generation
            
            if ($alterResult['deleted_count'] > 0) {
                echo "[ALTER] Processed {$alterResult['deleted_count']} completed alter trades\n";
            }
            if ($alterResult['closed_trades'] > 0) {
                echo "[ALTER] Closed {$alterResult['closed_trades']} trades that reached target price\n";
            }
            if (!empty($this->chartAlters)) {
                echo "[ALTER CHART] Active chart alters: " . count($this->chartAlters) . "\n";
                
                // Update forming candles with altered prices (like frontend updateFormingCandle)
                $this->updateFormingCandles($this->chartAlters);
            }
        }
        
        // REAL-TIME MONITORING: Check for stop_loss and take_profit triggers
        // This runs every time prices update from Binance
        $monitorResult = TradeMonitorService::monitorAndCloseTrades($this->currentPrices);
        if ($monitorResult['success'] && $monitorResult['stats']['closed'] > 0) {
            echo "[MONITOR] Auto-closed {$monitorResult['stats']['closed']} trades (SL: {$monitorResult['stats']['stop_loss']}, TP: {$monitorResult['stats']['take_profit']})\n";
            
            // Note: Notifications are automatically sent via TradeService callback
            // No need to manually notify here
        }
        
        // MARGIN CALL MONITORING: Check for margin calls and stop outs
        // Runs less frequently (every 10th price update) to reduce overhead
        static $priceUpdateCount = 0;
        $priceUpdateCount++;
        
        if ($priceUpdateCount % 10 === 0) {
            $marginResult = TradeMonitorService::monitorMarginCalls($this->currentPrices, 50, 20);
            if ($marginResult['success'] && $marginResult['stats']['trades_closed'] > 0) {
                echo "[MARGIN] Margin calls: {$marginResult['stats']['margin_calls']}, Stop outs: {$marginResult['stats']['stop_outs']}, Trades closed: {$marginResult['stats']['trades_closed']}\n";
                
                // Note: Notifications are automatically sent via TradeService callback
                // No need to manually notify here
            }
        }
        
        // QUEUED NOTIFICATIONS: Process notifications from HTTP processes (admin actions, etc.)
        // Check for queued notifications from HTTP-initiated closures
        $queuedNotifications = WebSocketNotificationQueue::getAndClearQueue();
        if (!empty($queuedNotifications)) {
            echo "[QUEUE] Processing " . count($queuedNotifications) . " queued notifications\n";
            foreach ($queuedNotifications as $notification) {
                if ($notification['type'] === 'trade_closed') {
                    $this->notifyTradeClosed($notification);
                }
            }
        }
        
        // Debug: Count authenticated clients
        $clientCount = 0;
        foreach ($this->authenticatedClients as $userId => $connections) {
            $clientCount += count($connections);
        }
        echo "Broadcasting to {$clientCount} authenticated connections\n";
        
        // Broadcast price updates to all authenticated clients
        foreach ($this->authenticatedClients as $userId => $connections) {
            foreach ($connections as $conn) {
                // Skip price alteration for admin/superadmin roles
                $userRole = $conn->userRole ?? 'user';
                $skipAlter = in_array($userRole, ['admin', 'superadmin']);
                
                if (isset($conn->subscribedPairs) && is_array($conn->subscribedPairs)) {
                    echo "User {$userId} has subscribed pairs: " . json_encode($conn->subscribedPairs) . "\n";
                    
                    // Get user's account info once for all price checks
                    $accountType = null;
                    $accountId = null;
                    
                    if (!$skipAlter && !empty($this->chartAlters)) {
                        try {
                            $dbConn = Database::getConnection();
                            $stmt = $dbConn->prepare("
                                SELECT a.id_hash, a.type as acc_type 
                                FROM accounts a
                                JOIN users u ON a.id_hash = u.current_account
                                WHERE u.id = ?
                            ");
                            $stmt->bind_param("i", $userId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            if ($result->num_rows > 0) {
                                $accountInfo = $result->fetch_assoc();
                                $accountType = $accountInfo['acc_type'];
                                $accountId = $accountInfo['id_hash'];
                            }
                        } catch (Exception $e) {
                            error_log("Error getting account info for price alter: " . $e->getMessage());
                        }
                    }
                    
                    // Send price updates for subscribed pairs
                    foreach ($prices as $symbol => $price) {
                        // Check if this symbol matches any subscribed pair
                        $shouldSend = false;
                        $matchedPair = null;
                        
                        foreach ($conn->subscribedPairs as $subscribedPair) {
                            // Simple case-insensitive comparison
                            // Remove slashes and compare (e.g., BTC/USDT, BTCUSDT, btcusdt all match)
                            $normalizedSymbol = strtolower(str_replace('/', '', $symbol));
                            $normalizedPair = strtolower(str_replace('/', '', $subscribedPair));
                            
                            if ($normalizedSymbol === $normalizedPair) {
                                $shouldSend = true;
                                $matchedPair = $subscribedPair; // Store the original pair format
                                break;
                            }
                        }
                        
                        if ($shouldSend) {
                            // Convert symbol to pair format (btcusdt -> BTC/USD)
                            $pairFormat = strtoupper(str_replace('usdt', '/USD', $symbol));
                            
                            // Check if there's an altered price for this user on this pair
                            $finalPrice = $price;
                            $isAltered = false;
                            
                            if (!$skipAlter && $accountType !== null && !empty($this->chartAlters)) {
                                // Check for account_pair alter first (most specific)
                                foreach ($this->chartAlters as $alter) {
                                    if ($alter['mode'] === 'account_pair' && 
                                        $alter['account'] === $accountId && 
                                        $alter['pair'] === $pairFormat) {
                                        $finalPrice = $alter['current_price'];
                                        $isAltered = true;
                                        echo "[PRICE ALTER] User {$userId}: {$pairFormat} altered to {$finalPrice} (account_pair mode)\n";
                                        break;
                                    }
                                }
                                
                                // Check for pair alter (broader scope)
                                if (!$isAltered) {
                                    foreach ($this->chartAlters as $alter) {
                                        if ($alter['mode'] === 'pair' && 
                                            $alter['acc_type'] === $accountType && 
                                            $alter['pair'] === $pairFormat) {
                                            $finalPrice = $alter['current_price'];
                                            $isAltered = true;
                                            echo "[PRICE ALTER] User {$userId}: {$pairFormat} altered to {$finalPrice} (pair mode, acc_type={$accountType})\n";
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            if (!$isAltered) {
                                echo "Sending price update to user {$userId}: {$symbol} = {$price}\n";
                            }
                            
                            $conn->send(json_encode([
                                'type' => 'price_update',
                                'symbol' => strtoupper($symbol),
                                'price' => number_format($finalPrice, 2, '.', ''),
                                'is_altered' => $isAltered,
                                'timestamp' => time()
                            ]));
                        }
                    }
                } else {
                    echo "User {$userId} has no subscribedPairs set\n";
                }
            }
            
            // Send account update once per user (not per connection)
            $this->sendAccountUpdate($userId);
        }
        
        // Broadcast admin account subscriptions
        foreach ($this->adminAccountSubscriptions as $resourceId => $accountIds) {
            foreach ($accountIds as $accountId) {
                $this->sendAdminAccountUpdate($accountId);
            }
        }
        
        // Broadcast admin trade subscriptions
        foreach ($this->adminTradeSubscriptions as $resourceId => $tradeIds) {
            foreach ($tradeIds as $tradeId) {
                $this->sendAdminTradeUpdate($tradeId);
            }
        }
    }
    
    /**
     * Update forming candles with current altered prices
     * Mimics frontend updateFormingCandle() - tracks high/low over the candle period
     */
    public function updateFormingCandles($chartAlters) {
        $currentTime = time() * 1000; // Convert to milliseconds
        
        foreach ($chartAlters as $alter) {
            $price = $alter['current_price'];
            $pair = $alter['pair'];
            $mode = $alter['mode'];
            $accType = $alter['acc_type'] ?? null;
            $account = $alter['account'] ?? null;
            
            // Create unique key for this forming candle
            if ($mode === 'pair') {
                $candleKey = "pair_{$pair}_{$accType}";
            } elseif ($mode === 'account_pair') {
                $candleKey = "account_{$account}_{$pair}";
            } else {
                continue; // Skip 'trade' mode
            }
            
            // Initialize forming candle if it doesn't exist
            if (!isset($this->formingCandles[$candleKey])) {
                $this->formingCandles[$candleKey] = [
                    'open' => $price,
                    'high' => $price,
                    'low' => $price,
                    'close' => $price,
                    'volume' => 0,
                    'start_time' => $currentTime,
                    'pair' => $pair,
                    'mode' => $mode,
                    'acc_type' => $accType,
                    'account' => $account
                ];
                echo "[FORMING CANDLE] Initialized: {$candleKey} at price {$price}\n";
            } else {
                // Update existing forming candle (like frontend updateFormingCandle)
                $this->formingCandles[$candleKey]['close'] = $price;
                $this->formingCandles[$candleKey]['high'] = max($this->formingCandles[$candleKey]['high'], $price);
                $this->formingCandles[$candleKey]['low'] = min($this->formingCandles[$candleKey]['low'], $price);
                
                // Log significant updates
                $oldRange = $this->formingCandles[$candleKey]['high'] - $this->formingCandles[$candleKey]['low'];
                if ($oldRange > 0) {
                    echo "[FORMING CANDLE] Updated: {$candleKey} - O:{$this->formingCandles[$candleKey]['open']} H:{$this->formingCandles[$candleKey]['high']} L:{$this->formingCandles[$candleKey]['low']} C:{$price}\n";
                }
            }
        }
    }
    
    public function broadcastCandle($candle) {
        $pair = $candle['pair'];
        $interval = $candle['interval'];
        
        echo "\n=== BROADCAST CANDLE START ===\n";
        echo "[BROADCAST] Pair: {$pair}, Interval: {$interval}\n";
        echo "[BROADCAST] Chart alters count: " . count($this->chartAlters) . "\n";
        
        if (!empty($this->chartAlters)) {
            echo "[BROADCAST] Active chart alters:\n";
            foreach ($this->chartAlters as $idx => $alter) {
                echo "[BROADCAST]   #{$idx}: mode={$alter['mode']}, pair={$alter['pair']}, acc_type={$alter['acc_type']}, progress=" . round($alter['progress'] * 100, 2) . "%\n";
            }
        }
        
        $sentCount = 0;
        $alteredCount = 0;
        
        // Check if there are any active chart alters for this pair
        $hasChartAlters = !empty($this->chartAlters);
        
        // Track which alter cache files we've already saved for this candle (to avoid duplicates)
        $savedCaches = [];
        
        if ($hasChartAlters) {
            echo "[BROADCAST] Checking chart alters for {$pair} {$interval}...\n";
        }
        
        // Broadcast to all clients subscribed to this pair's chart
        foreach ($this->clients as $conn) {
            $resourceId = $conn->resourceId;
            
            echo "[BROADCAST] Checking client {$resourceId}...\n";
            
            // Check if this client has chart subscriptions
            if (!isset($this->chartSubscriptions[$resourceId])) {
                echo "[BROADCAST]   Client {$resourceId} has no chart subscriptions\n";
                continue;
            }
            
            $clientSubs = $this->chartSubscriptions[$resourceId];
            echo "[BROADCAST]   Client {$resourceId} subscriptions: " . json_encode($clientSubs) . "\n";
            
            // Check if subscribed to this pair and interval
            if (isset($clientSubs[$pair]) && $clientSubs[$pair] === $interval) {
                echo "[BROADCAST]   ✓ Client {$resourceId} is subscribed to {$pair} {$interval}\n";
                
                // Determine if we should send altered candle for this specific client
                $candleToSend = $candle;
                $userId = $conn->userId ?? null;
                
                if ($hasChartAlters && $userId !== null) {
                    // Get user's current account info to determine account type and account ID
                    try {
                        $dbConn = Database::getConnection();
                        $stmt = $dbConn->prepare("
                            SELECT a.id_hash, a.type as acc_type 
                            FROM accounts a
                            JOIN users u ON a.id_hash = u.current_account
                            WHERE u.id = ?
                        ");
                        $stmt->bind_param("i", $userId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            $accountInfo = $result->fetch_assoc();
                            $accountType = $accountInfo['acc_type']; // 'demo' or 'real'
                            $accountId = $accountInfo['id_hash'];
                            
                            echo "[BROADCAST]   User {$userId} account: type={$accountType}, id={$accountId}\n";
                            
                            // Check if this candle should be altered for this client
                            $alterInfo = TradeAlterMonitorService::shouldAlterCandle(
                                $this->chartAlters,
                                $pair,
                                $userId,
                                $accountType,
                                $accountId
                            );
                            
                            if ($alterInfo !== null) {
                                // Get the forming candle key for this alter
                                $formingKey = null;
                                if ($alterInfo['mode'] === 'pair') {
                                    $formingKey = "pair_{$pair}_{$accountType}";
                                } elseif ($alterInfo['mode'] === 'account_pair') {
                                    $formingKey = "account_{$accountId}_{$pair}";
                                }
                                
                                // Use tracked forming candle OHLC if available, otherwise generate synthetic
                                if ($formingKey && isset($this->formingCandles[$formingKey])) {
                                    $formingCandle = $this->formingCandles[$formingKey];
                                    
                                    // Create altered candle with tracked OHLC
                                    $candleToSend = array_merge($candle, [
                                        'open' => $formingCandle['open'],
                                        'high' => $formingCandle['high'],
                                        'low' => $formingCandle['low'],
                                        'close' => $formingCandle['close'],
                                        'is_altered' => true
                                    ]);
                                    
                                    echo "[BROADCAST] Using TRACKED forming candle for {$formingKey}: O:{$formingCandle['open']} H:{$formingCandle['high']} L:{$formingCandle['low']} C:{$formingCandle['close']}\n";
                                    
                                    // Reset forming candle for next period
                                    unset($this->formingCandles[$formingKey]);
                                } else {
                                    // Fallback: Generate synthetic candle (backward compatibility)
                                    $candleToSend = TradeAlterMonitorService::createAlteredCandle(
                                        $candle,
                                        $alterInfo,
                                        null // previousClose
                                    );
                                    echo "[BROADCAST] Using SYNTHETIC candle (no forming candle tracked)\n";
                                }
                                
                                $alteredCount++;
                                echo "[BROADCAST] Sending ALTERED candle to user {$userId} (account: {$accountType})\n";
                                
                                // Save altered candle to cache (once per mode+pair+account combination)
                                // Create unique cache key to prevent duplicate saves
                                $cacheKey = $alterInfo['mode'] . '_' . $pair . '_' . $interval;
                                if ($alterInfo['mode'] === 'pair') {
                                    $cacheKey .= '_' . $accountType;
                                } elseif ($alterInfo['mode'] === 'account_pair') {
                                    $cacheKey .= '_' . $accountId;
                                }
                                
                                // Only save once per candle per cache type
                                if (!isset($savedCaches[$cacheKey])) {
                                    $saved = AlteredCandleCacheService::saveAlteredCandle(
                                        $candleToSend,
                                        $alterInfo['mode'],
                                        $pair,
                                        $interval,
                                        $accountType,
                                        $accountId
                                    );
                                    
                                    if ($saved) {
                                        $savedCaches[$cacheKey] = true;
                                        echo "[BROADCAST] ✓ Saved altered candle to cache: {$cacheKey}\n";
                                    }
                                }
                            }
                        } else {
                            echo "[BROADCAST]   ⚠ User {$userId} has no current account!\n";
                        }
                    } catch (Exception $e) {
                        error_log("Error checking chart alter for user {$userId}: " . $e->getMessage());
                        echo "[BROADCAST]   ❌ Error: " . $e->getMessage() . "\n";
                        // Fallback to real candle on error
                    }
                }
                
                // Send candle (real or altered based on above logic)
                $jsonData = json_encode($candleToSend);
                $conn->send($jsonData);
                $sentCount++;
                
                if (!isset($alterInfo) || $alterInfo === null) {
                    echo "[BROADCAST] Sent REAL candle to client {$resourceId}\n";
                }
            }
        }
        
        if ($sentCount > 0) {
            $alteredMsg = $alteredCount > 0 ? " ({$alteredCount} altered)" : "";
            echo "Broadcast {$pair} {$interval} candle to {$sentCount} client(s){$alteredMsg}\n";
        } else {
            echo "[BROADCAST] No clients subscribed to {$pair} {$interval}\n";
        }
        echo "=== BROADCAST CANDLE END ===\n\n";
    }
}

// Binance WebSocket Client using ReactPHP
class BinanceWebSocketClient {
    private $server;
    private $pairs;
    private $loop;
    private $wsConnection;
    private $klineConnection;
    
    public function __construct($server, $pairs, $loop) {
        $this->server = $server;
        $this->pairs = $pairs;
        $this->loop = $loop;
    }
    
    public function connect() {
        echo "Connecting to Binance WebSocket...\n";
        
        // Build WebSocket stream URL for multiple pairs (ticker data)
        $streams = [];
        foreach ($this->pairs as $pair) {
            $symbol = strtolower(str_replace(['/', 'USD'], ['', 'USDT'], $pair));
            $streams[] = $symbol . '@ticker';
        }
        $streamString = implode('/', $streams);
        
        $wsUrl = "wss://stream.binance.com:9443/stream?streams=" . $streamString;
        
        // Use Google's public DNS (8.8.8.8) instead of React's default DNS
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $dns = $dnsResolverFactory->createCached('8.8.8.8', $this->loop);
        
        $connector = new WsConnector($this->loop, new Connector($this->loop, ['dns' => $dns]));
        
        $connector($wsUrl)->then(
            function($conn) {
                echo "Connected to Binance Ticker WebSocket!\n";
                $this->wsConnection = $conn;
                
                $conn->on('message', function($msg) {
                    $this->handleBinanceMessage($msg);
                });
                
                $conn->on('close', function($code = null, $reason = null) {
                    echo "Binance WebSocket closed (Code: {$code}, Reason: {$reason})\n";
                    echo "Reconnecting in 5 seconds...\n";
                    
                    // Reconnect after 5 seconds
                    $this->loop->addTimer(5, function() {
                        $this->connect();
                    });
                });
                
                $conn->on('error', function($e) {
                    echo "Binance WebSocket error: {$e->getMessage()}\n";
                });
            },
            function($e) {
                echo "Could not connect to Binance: {$e->getMessage()}\n";
                echo "Retrying in 5 seconds...\n";
                
                // Retry connection after 5 seconds
                $this->loop->addTimer(5, function() {
                    $this->connect();
                });
            }
        );
        
        // Connect to kline streams for chart data (1m, 5m intervals)
        $this->connectKlineStreams();
    }
    
    private function connectKlineStreams() {
        echo "Connecting to Binance Kline WebSocket...\n";
        
        // Build kline streams for common intervals
        $intervals = ['1m', '5m', '15m', '1h', '4h', '1d'];
        $klineStreams = [];
        foreach ($this->pairs as $pair) {
            $symbol = strtolower(str_replace(['/', 'USD'], ['', 'USDT'], $pair));
            foreach ($intervals as $interval) {
                $klineStreams[] = $symbol . '@kline_' . $interval;
            }
        }
        
        $streamString = implode('/', $klineStreams);
        $wsUrl = "wss://stream.binance.com:9443/stream?streams=" . $streamString;
        
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $dns = $dnsResolverFactory->createCached('8.8.8.8', $this->loop);
        
        $connector = new WsConnector($this->loop, new Connector($this->loop, ['dns' => $dns]));
        
        $connector($wsUrl)->then(
            function($conn) {
                echo "Connected to Binance Kline WebSocket!\n";
                $this->klineConnection = $conn;
                
                $conn->on('message', function($msg) {
                    $this->handleKlineMessage($msg);
                });
                
                $conn->on('close', function($code = null, $reason = null) {
                    echo "Binance Kline WebSocket closed (Code: {$code}, Reason: {$reason})\n";
                    echo "Reconnecting in 5 seconds...\n";
                    
                    // Reconnect after 5 seconds
                    $this->loop->addTimer(5, function() {
                        $this->connectKlineStreams();
                    });
                });
                
                $conn->on('error', function($e) {
                    echo "Binance Kline WebSocket error: {$e->getMessage()}\n";
                });
            },
            function($e) {
                echo "Could not connect to Binance Kline stream: {$e->getMessage()}\n";
                echo "Retrying in 5 seconds...\n";
                
                // Retry connection after 5 seconds
                $this->loop->addTimer(5, function() {
                    $this->connectKlineStreams();
                });
            }
        );
    }
    
    private function handleBinanceMessage($msg) {
        try {
            $data = json_decode($msg, true);
            
            if (isset($data['stream']) && isset($data['data'])) {
                $tickerData = $data['data'];
                
                // Extract symbol and price
                if (isset($tickerData['s']) && isset($tickerData['c'])) {
                    $symbol = strtolower($tickerData['s']);
                    $price = floatval($tickerData['c']);
                    
                    // Debug: Log received price
                    echo "Binance price update: {$symbol} = {$price}\n";
                    
                    // Update prices
                    $this->server->updatePrices([$symbol => $price]);
                }
            } else {
                // Debug: Log unexpected message format
                echo "Binance message without stream/data: " . substr($msg, 0, 100) . "\n";
            }
        } catch (Exception $e) {
            error_log("Error processing Binance message: " . $e->getMessage());
            echo "Error processing Binance message: " . $e->getMessage() . "\n";
        }
    }
    
    private function handleKlineMessage($msg) {
        try {
            $data = json_decode($msg, true);
            
            if (isset($data['stream']) && isset($data['data'])) {
                $klineData = $data['data'];
                
                // Kline data structure:
                // {
                //   "e": "kline",
                //   "E": 123456789,
                //   "s": "BTCUSDT",
                //   "k": {
                //     "t": 123400000, // Kline start time
                //     "T": 123460000, // Kline close time
                //     "s": "BTCUSDT",
                //     "i": "1m",      // Interval
                //     "f": 100,       // First trade ID
                //     "L": 200,       // Last trade ID
                //     "o": "0.0010",  // Open price
                //     "c": "0.0020",  // Close price
                //     "h": "0.0025",  // High price
                //     "l": "0.0015",  // Low price
                //     "v": "1000",    // Base asset volume
                //     "n": 100,       // Number of trades
                //     "x": false,     // Is this kline closed?
                //     "q": "1.0000",  // Quote asset volume
                //     "V": "500",     // Taker buy base asset volume
                //     "Q": "0.500",   // Taker buy quote asset volume
                //     "B": "123456"   // Ignore
                //   }
                // }
                
                if (isset($klineData['k'])) {
                    $k = $klineData['k'];
                    $symbol = strtolower($k['s']);
                    $interval = $k['i'];
                    
                    // Convert to our pair format (btcusdt -> BTC/USD)
                    $pair = strtoupper(str_replace('usdt', '/USD', $symbol));
                    
                    $candle = [
                        'type' => 'candle_update',
                        'pair' => $pair,
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'timestamp' => intval($k['t']),
                        'time' => date('Y-m-d H:i:s', intval($k['t']) / 1000),
                        'open' => floatval($k['o']),
                        'high' => floatval($k['h']),
                        'low' => floatval($k['l']),
                        'close' => floatval($k['c']),
                        'volume' => floatval($k['v']),
                        'trades' => intval($k['n']),
                        'isClosed' => $k['x']
                    ];
                    
                    // Only send closed candles to reduce noise
                    if ($k['x'] === true) {
                        echo "Kline closed: {$pair} {$interval} - O:{$k['o']} H:{$k['h']} L:{$k['l']} C:{$k['c']}\n";
                        $this->server->broadcastCandle($candle);
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error processing Binance kline message: " . $e->getMessage());
            echo "Error processing Binance kline message: " . $e->getMessage() . "\n";
        }
    }
    
    public function close() {
        if ($this->wsConnection) {
            $this->wsConnection->close();
        }
        if ($this->klineConnection) {
            $this->klineConnection->close();
        }
    }
}

// Start server
if (php_sapi_name() === 'cli') {
    $port = 8080;
    
    echo "Starting Trading WebSocket Server on port {$port}...\n";
    
    // Create PID file
    $pidFile = __DIR__ . '/server.pid';
    $pidData = [
        'pid' => getmypid(),
        'started_at' => time()
    ];
    file_put_contents($pidFile, json_encode($pidData));
    
    // Get active pairs from database
    $conn = Database::getConnection();
    $result = $conn->query("SELECT name FROM pairs WHERE status = 'active'");
    $pairs = [];
    while ($row = $result->fetch_assoc()) {
        $pairs[] = $row['name'];
    }
    
    echo "Monitoring " . count($pairs) . " pairs\n";
    
    // Create ReactPHP event loop
    $loop = LoopFactory::create();
    
    // Create WebSocket server
    $tradingServer = new TradingWebSocketServer();
    
    // Create socket server
    $webSock = new React\Socket\Server("0.0.0.0:{$port}", $loop);
    $webServer = new IoServer(
        new HttpServer(
            new WsServer($tradingServer)
        ),
        $webSock,
        $loop
    );
    
    // Start Binance WebSocket client
    $binanceClient = new BinanceWebSocketClient($tradingServer, $pairs, $loop);
    $binanceClient->connect();
    
    // Add periodic timer to check for stop signal and send account updates (every 2 seconds)
    $stopFile = __DIR__ . '/server.stop';
    
    // Log the stop file path once for debugging
    echo "Monitoring for stop signal at: {$stopFile}\n";
    
    // Check if stop file exists at startup (shouldn't exist)
    if (file_exists($stopFile)) {
        echo "WARNING: Stop file exists at startup! Removing it: {$stopFile}\n";
        @unlink($stopFile);
    }
    
    $loop->addPeriodicTimer(2, function() use ($tradingServer, $stopFile, $loop) {
        // Check for stop signal file (graceful shutdown for cPanel)
        $stopExists = file_exists($stopFile);
        
        if ($stopExists) {
            echo "\nStop signal received. Shutting down gracefully...\n";
            echo "Stop file found at: {$stopFile}\n";
            @unlink($stopFile);
            
            $pidFile = __DIR__ . '/server.pid';
            if (file_exists($pidFile)) {
                @unlink($pidFile);
            }
            
            $loop->stop();
            exit(0);
        }
        
        // Account updates are already sent in updatePrices, 
        // but this ensures updates even if no price changes
    });
    
    // Clean old altered candle caches once per day (86400 seconds)
    $loop->addPeriodicTimer(86400, function() {
        echo "\n[CACHE CLEANUP] Running daily cache cleanup...\n";
        $stats = AlteredCandleCacheService::cleanOldCaches(30); // Keep 30 days
        echo "[CACHE CLEANUP] Completed: " . json_encode($stats) . "\n";
    });
    
    echo "Server running!\n";
    echo "Clients can connect to: ws://localhost:{$port}\n";
    echo "Binance WebSocket connected and streaming prices...\n";
    
    // Register shutdown handler to clean up PID file
    register_shutdown_function(function() use ($pidFile) {
        if (file_exists($pidFile)) {
            @unlink($pidFile);
            echo "\nShutdown complete. PID file removed.\n";
        }
    });
    
    // Run the event loop
    $loop->run();
}
