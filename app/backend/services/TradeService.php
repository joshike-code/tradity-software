<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/TransactionService.php';
require_once __DIR__ . '/../services/ProfitCalculationService.php';
require_once __DIR__ . '/../services/WebSocketNotificationQueue.php';

class TradeService
{
    /**
     * WebSocket notification callback
     * Set by WebSocket server to receive trade closure notifications
     */
    private static $notificationCallback = null;
    
    /**
     * Set callback for WebSocket notifications
     * 
     * @param callable $callback Function to call when trade is closed: function($tradeData, $reason)
     */
    public static function setNotificationCallback($callback) {
        self::$notificationCallback = $callback;
    }
    
    /**
     * Send notification about closed trade
     * 
     * @param array $trade Trade data
     * @param string $reason Close reason
     */
    private static function notifyTradeClosed($trade, $reason) {
        // Try callback first (works if called from WebSocket server process)
        if (self::$notificationCallback && is_callable(self::$notificationCallback)) {
            try {
                call_user_func(self::$notificationCallback, $trade, $reason);
            } catch (Exception $e) {
                error_log("TradeService::notifyTradeClosed - Callback error: " . $e->getMessage());
            }
        } else {
            // No callback means this is an HTTP request (not WebSocket server)
            // Queue the notification for WebSocket server to pick up
            WebSocketNotificationQueue::queueTradeClosureNotification($trade, $reason);
        }
    }
    
    public static function getUserTrades($user_id) {
        try {
            $conn = Database::getConnection();
            
            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $current_account = $user['current_account'];

            $stmt = $conn->prepare("SELECT * FROM trades WHERE userid = ? AND account = ? ORDER BY date DESC");
            $stmt->bind_param("is", $user_id, $current_account);
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            return $trades;
        } catch (Exception $e) {
            error_log("TradeService::getUserTrades - " . $e->getMessage());
            Response::error('Failed to retrieve trades', 500);
        }
    }

    public static function getOpenTrades($user_id) {
        try {
            $conn = Database::getConnection();
            
            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $current_account = $user['current_account'];

            // Get trades where close_date is empty or null (open trades)
            $stmt = $conn->prepare("SELECT * FROM trades WHERE userid = ? AND account = ? AND (close_date = '' OR close_date IS NULL) ORDER BY date DESC");
            $stmt->bind_param("is", $user_id, $current_account);
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            return $trades;
        } catch (Exception $e) {
            error_log("TradeService::getOpenTrades - " . $e->getMessage());
            Response::error('Failed to retrieve open trades', 500);
        }
    }

    public static function getClosedTrades($user_id) {
        try {
            $conn = Database::getConnection();
            
            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $current_account = $user['current_account'];

            // Get trades where close_date is not empty (closed trades)
            $stmt = $conn->prepare("SELECT * FROM trades WHERE userid = ? AND account = ? AND close_date != '' AND close_date IS NOT NULL ORDER BY close_date DESC");
            $stmt->bind_param("is", $user_id, $current_account);
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            return $trades;
        } catch (Exception $e) {
            error_log("TradeService::getClosedTrades - " . $e->getMessage());
            Response::error('Failed to retrieve closed trades', 500);
        }
    }

    public static function openTrade($user_id, $input) {
        try {
            $conn = Database::getConnection();
            
            $account = TradeAccountService::getUserCurrentAccount($user_id);
            $current_account = $account['id_hash'];
            $leverage = intval($account['leverage']);

            // Get pair details including spread, pip_value, and digits
            $pair = $input['pair'];
            $stmt = $conn->prepare("SELECT spread, pip_value, digits, lot_size, margin_percent FROM pairs WHERE name = ?");
            $stmt->bind_param("s", $pair);
            $stmt->execute();
            $pairResult = $stmt->get_result();
            
            if ($pairResult->num_rows === 0) {
                Response::error('Pair not found', 404);
            }
            
            $pairData = $pairResult->fetch_assoc();

            // Get current market price from cache
            $currentPrice = ProfitCalculationService::getCurrentPairPrice($pair);
            if ($currentPrice === null) {
                Response::error('Unable to get current price for ' . $pair, 503);
            }

            // Calculate spread in USD
            $spreadData = ProfitCalculationService::calculateSpread($pairData, $currentPrice);
            if ($spreadData === null) {
                Response::error('Failed to get spread data', 500);
            }
            
            // Buy/sell prices
            $buyPrice = $spreadData['buyPrice'];
            $sellPrice = $spreadData['sellPrice'];
            $price = $spreadData['currentPrice'];
            
            // Calculate buy/sell prices based on type
            $type = $input['type'];
            $trade_price = ($type === 'buy') ? $buyPrice : $sellPrice;

            // Calculate Required margin
            $requiredMargin = ProfitCalculationService::calculateRequiredMargin($input, $pairData, $leverage, $currentPrice);
            if ($requiredMargin === null) {
                Response::error('Failed to get required margin', 500);
            }

            $margin = $requiredMargin['margin'];

            // Generate unique trade reference
            $ref = rand(1000000000, 9999999999);
            $lot = $input['lot'];
            $stop_loss = $input['stop_loss'] ?? '0';
            $take_profit = $input['take_profit'] ?? '0';
            $commission = '0';
            $swap = '0';
            $profit = '';
            $trade_acc = $account['type'];
            $date = gmdate('Y-m-d H:i:s');
            $close_date = null;

            $stmt = $conn->prepare("INSERT INTO trades (userid, account, ref, pair, type, trade_price, price, margin, lot, leverage, stop_loss, take_profit, commission, swap, profit, trade_acc, date, close_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssddddiddddssss", $user_id, $current_account, $ref, $pair, $type, $trade_price, $price, $margin, $lot, $leverage, $stop_loss, $take_profit, $commission, $swap, $profit, $trade_acc, $date, $close_date);
            
            if ($stmt->execute()) {
                $trades = self::getOpenTrades($user_id);
                Response::success([
                    'openTrades' => $trades,
                    'tradePrice' => $trade_price
                ]);
            } else {
                Response::error('Failed to open trade', 500);
            }
        } catch (Exception $e) {
            error_log("TradeService::openTrade - " . $e->getMessage());
            Response::error('Failed to open trade', 500);
        }
    }

    public static function closeTrade($reason, $input, $overridePrice = null) {
        try {
            $conn = Database::getConnection();
            $trade_id = $input['id'];
            $close_date = gmdate('Y-m-d H:i:s');

            // Get the trade (no user_id needed - trade ID is unique)
            $stmt = $conn->prepare("SELECT t.*, p.lot_size, p.digits, p.spread, p.pip_value FROM trades t LEFT JOIN pairs p ON t.pair = p.name WHERE t.id = ?");
            $stmt->bind_param("i", $trade_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                return ['success' => false, 'message' => 'Trade not found', 'code' => 404];
            }

            $trade = $result->fetch_assoc();
            $user_id = $trade['userid']; // Get user_id from trade record
            $trade_ref = $trade['ref'];

            // Check if trade is open
            if (!empty($trade['close_date']) && $trade['close_date'] !== null) {
                return ['success' => false, 'message' => 'Trade is already closed', 'code' => 400];
            }
            
            // Get current market price (or use override from alter target)
            if ($overridePrice !== null) {
                $currentPrice = $overridePrice;
            } else {
                $currentPrice = ProfitCalculationService::getCurrentPairPrice($trade['pair']);
                if ($currentPrice === null) {
                    return ['success' => false, 'message' => 'Unable to get current price for ' . $trade['pair'], 'code' => 503];
                }
            }

            // Calculate profit/loss
            $profitData = ProfitCalculationService::calculateTradeProfit($trade, $currentPrice, $trade);
            if ($profitData === null) {
                return ['success' => false, 'message' => 'Failed to calculate trade profit', 'code' => 500];
            }

            $totalProfit = $profitData['totalProfit'];

            $profit_str = $totalProfit >= 0 ? '+' . number_format($totalProfit, 2, '.', '') : number_format($totalProfit, 2, '.', '');

            $stmt = $conn->prepare("UPDATE trades SET price = ?, profit = ?, close_reason=?, close_date = ? WHERE id = ?");
            $stmt->bind_param("dsssi", $currentPrice, $profit_str, $reason, $close_date, $trade_id);
            
            if ($stmt->execute()) {
                // Update account balance and return updated account Data
                // Skip balance checks for trade closures - P&L should always be applied
                $account = TradeAccountService::updateSpecificAccountBalance($user_id, $trade['account'], $profit_str, false);

                // Create a transaction record
                TransactionService::createTransaction($user_id, $account['id_hash'], $trade['pair'], $trade['type'], $trade['ref'], $profit_str, $account['balance']);

                return ['success' => true, 'data' => ['trade' => $trade, 'account' => $account, 'profit' => $profit_str, 'close_price' => $currentPrice], 'code' => 200];
            } else {
                return ['success' => false, 'message' => 'Failed to close trade', 'code' => 500];
            }
        } catch (Exception $e) {
            error_log("TradeService::closeTrade - " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to close trade', 'code' => 500];
        }
    }
    
    /**
     * Close trade and send WebSocket notification
     * Used by automated systems (monitors, alter trades) to close trades with notifications
     * 
     * @param string $reason Close reason (stop_loss, take_profit, margin_call, expire, etc.)
     * @param array $input Input containing trade id
     * @param float|null $overridePrice Override price (for alter trades)
     * @return array Result with success status
     */
    public static function closeTradeWithNotification($reason, $input, $overridePrice = null) {
        // Close the trade using the standard method
        $result = self::closeTrade($reason, $input, $overridePrice);
        
        // If successful, send WebSocket notification
        if ($result['success']) {
            $trade = $result['data']['trade'];
            $user_id = $trade['userid']; // Get user_id from trade data
            $profit = $result['data']['profit'] ?? '0.00';
            $closePrice = $result['data']['close_price'] ?? null;
            
            $closedTradeInfo = array_merge($trade, [
                'profit' => $profit,
                'close_reason' => $reason,
                'close_price' => $closePrice,
                'userid' => $user_id
            ]);
            
            self::notifyTradeClosed($closedTradeInfo, $reason);
        }
        
        return $result;
    }

    public static function editTrade($user_id, $input) {
        try {
            $conn = Database::getConnection();
            $trade_id = $input['id'];
            $take_profit = $input['take_profit'];
            $stop_loss = $input['stop_loss'];

            // Get the trade
            $stmt = $conn->prepare("SELECT * FROM trades WHERE id = ? AND userid = ?");
            $stmt->bind_param("ii", $trade_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Trade not found', 404);
                return;
            }

            $trade = $result->fetch_assoc();

            // Check if trade is open
            if (!empty($trade['close_date']) && $trade['close_date'] !== null) {
                Response::error('Trade is already closed', 400);
                return;
            }

            $stmt = $conn->prepare("UPDATE trades SET take_profit = ?, stop_loss = ? WHERE id = ? AND userid = ?");
            $stmt->bind_param("ssii", $take_profit, $stop_loss, $trade_id, $user_id);

            if ($stmt->execute()) {
                $trades = self::getOpenTrades($user_id);
                Response::success($trades);
            } else {
                Response::error('Failed to close trade', 500);
            }
        } catch (Exception $e) {
            error_log("TradeService::closeTrade - " . $e->getMessage());
            Response::error('Failed to close trade', 500);
        }
    }

    public static function getTradeById($trade_id) {
        try {
            $conn = Database::getConnection();
            
            // Consider accounts offline if no heartbeat in last 2 minutes
            $two_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-2 minutes'));

            $stmt = $conn->prepare("
            SELECT 
                t.id AS trade_id, t.ref, t.account, t.pair, t.type, t.trade_price, t.price, t.trade_acc, t.margin, t.lot, t.leverage, t.stop_loss, t.take_profit, t.profit, t.close_reason, t.date, t.close_date,
                u.id AS user_id, u.fname, u.lname, u.email,
                a.id AS account_id, a.id_hash, a.balance, a.online_status,
                CASE 
                    WHEN a.online_status IN ('online', 'away') 
                    AND a.last_heartbeat IS NOT NULL
                    AND a.last_heartbeat > ? 
                    THEN a.online_status
                    ELSE 'offline'
                END as current_online_status,
                a.last_heartbeat, a.last_activity
            FROM 
                trades t
            INNER JOIN 
                users u ON t.userid = u.id
            INNER JOIN 
                accounts a ON t.account = a.id_hash
            WHERE t.id = ?");
            $stmt->bind_param("si", $two_minutes_ago, $trade_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Trade not found', 404);
                return null;
            }

            return $result->fetch_assoc();
        } catch (Exception $e) {
            error_log("TradeService::getTradeById - " . $e->getMessage());
            Response::error('Failed to retrieve trade', 500);
            return null;
        }
    }

    public static function getAllTrades($trade_acc, $pair, $status, $type, $account) {
        try {
            $conn = Database::getConnection();

            $sql = "SELECT 
                t.id AS trade_id, t.ref, t.account, t.pair, t.type, t.trade_price, t.margin, t.lot, t.leverage, t.stop_loss, t.take_profit, t.profit, t.date, t.close_date,
                u.id AS user_id, u.fname, u.lname, u.email
            FROM 
                trades t
            INNER JOIN 
                users u ON t.userid = u.id
            WHERE 1=1";

            $params = [];
            $types = "";

            if(!empty($type)) {
                $sql .= " AND type = ?";
                $params[] = $type;
                $types .= "s";
            }
            if(!empty($pair)) {
                $sql .= " AND pair = ?";
                $params[] = $pair;
                $types .= "s";
            }
            if(!empty($status)) {
                if($status === 'open') {
                    $sql .= " AND (close_date IS NULL OR close_date = '')";
                } else if($status === 'closed') {
                    $sql .= " AND (close_date IS NOT NULL AND close_date != '')";
                }
            }
            if(!empty($account)) {
                $sql .= " AND account = ?";
                $params[] = $account;
                $types .= "s";
            }
            if(!empty($trade_acc)) {
                $sql .= " AND trade_acc = ?";
                $params[] = $trade_acc;
                $types .= "s";
            }
            
            $stmt = $conn->prepare($sql);

            if(!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = $result->fetch_all(MYSQLI_ASSOC);

            Response::success($trades);
        } catch (Exception $e) {
            error_log("TradeService::getAllTrades - " . $e->getMessage());
            Response::error('Failed to retrieve trades', 500);
        }
    }

    public static function searchAllTrades($searchTerm, $trade_acc = 'real') {
        try {
            $conn = Database::getConnection();

            $likeTerm = "%" . $searchTerm . "%";

            $query = "
                SELECT 
                    t.id AS trade_id, t.ref, t.account, t.pair, t.type, t.trade_price, t.margin, t.lot, t.leverage, t.stop_loss, t.take_profit, t.profit, t.date, t.close_date,
                    u.id AS user_id, u.fname, u.lname, u.email
                FROM 
                    trades t
                INNER JOIN 
                    users u ON t.userid = u.id
                WHERE (
                    t.ref LIKE ? OR
                    t.pair LIKE ? OR
                    t.type LIKE ? OR
                    u.email LIKE ? OR
                    t.account LIKE ?
                )
                AND t.trade_acc = ?
            ";

            $stmt = $conn->prepare($query);
            if (!$stmt) {
                // Response::error("Prepare failed: " . $conn->error, 500);
                return;
            }

            $stmt->bind_param("ssssss", $likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm, $trade_acc);
            $stmt->execute();

            $result = $stmt->get_result();
            $trades = [];

            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            Response::success($trades);
        } catch (Exception $e) {
            error_log("TradeService::searchTradesByRef - " . $e->getMessage());
            Response::error('Failed to search trades', 500);
        }
    }

    public static function getAdminTradeStats($trade_acc = 'real')
    {
        $conn = Database::getConnection();

        // Total open trades
        $openTotalQuery = "SELECT COUNT(*) AS total_open_trades FROM trades WHERE (close_date = '' OR close_date IS NULL) AND trade_acc = ?";
        $stmt = $conn->prepare($openTotalQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $trade_acc);
        $stmt->execute();
        $openTradesResult = $stmt->get_result();

        $total_open_trades_count = intval($openTradesResult->fetch_assoc()['total_open_trades']);

        // Total closed trades
        $closedTotalQuery = "SELECT COUNT(*) AS total_closed_trades FROM trades WHERE (close_date IS NOT NULL AND close_date != '') AND trade_acc = ?";
        $stmt = $conn->prepare($closedTotalQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $trade_acc);
        $stmt->execute();
        $closedTradesResult = $stmt->get_result();

        $total_closed_trades_count = intval($closedTradesResult->fetch_assoc()['total_closed_trades']);


        // ========================
        // 1. OPEN TRADES
        // ========================
        // Group by pair and type for open trades
        $openTradesQuery = "
            SELECT 
                pair,
                type,
                COUNT(*) AS count
            FROM trades
            WHERE (close_date = '' OR close_date IS NULL)
            AND trade_acc = ?
            GROUP BY pair, type
        ";
        $stmt = $conn->prepare($openTradesQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $trade_acc);
        $stmt->execute();

        $openTradesResult = $stmt->get_result();

        $openTradesByPair = [];
        while ($row = $openTradesResult->fetch_assoc()) {
            $pair = $row['pair'];
            $type = $row['type'];

            if (!isset($openTradesByPair[$pair])) {
                $openTradesByPair[$pair] = [
                    'total' => 0,
                    'buy' => 0,
                    'sell' => 0,
                ];
            }

            $openTradesByPair[$pair]['total'] += intval($row['count']);
            $openTradesByPair[$pair][$type] += intval($row['count']);
        }

        // ========================
        // 2. CLOSED TRADES
        // ========================
        // Group by pair and type for closed trades
        $closedTradesQuery = "
            SELECT 
                pair,
                type,
                COUNT(*) AS count
            FROM trades
            WHERE close_date IS NOT NULL AND close_date != ''
            AND trade_acc = ?
            GROUP BY pair, type
        ";
        $stmt = $conn->prepare($closedTradesQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $trade_acc);
        $stmt->execute();

        $closedTradesResult = $stmt->get_result();

        $closedTradesByPair = [];
        while ($row = $closedTradesResult->fetch_assoc()) {
            $pair = $row['pair'];
            $type = $row['type'];

            if (!isset($closedTradesByPair[$pair])) {
                $closedTradesByPair[$pair] = [
                    'total' => 0,
                    'buy' => 0,
                    'sell' => 0,
                ];
            }

            $closedTradesByPair[$pair]['total'] += intval($row['count']);
            $closedTradesByPair[$pair][$type] += intval($row['count']);
        }

        // ========================
        // 3. OPEN TRADES PNL (BY PAIR + TYPE)
        // ========================
        // Fetch open trades along with pair config needed to calculate live profit
        $openPnlQuery = "
            SELECT 
                t.id,
                t.pair,
                t.type,
                t.trade_price,
                t.lot,
                t.trade_price AS trade_price_duplicate,
                p.lot_size,
                p.digits
            FROM trades t
            LEFT JOIN pairs p ON t.pair = p.name
            WHERE (close_date = '' OR close_date IS NULL)
            AND t.trade_acc = ?
        ";
        $stmt = $conn->prepare($openPnlQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $trade_acc);
        $stmt->execute();

        $openPnlResult = $stmt->get_result();

        $openPnLByPair = new stdClass(); // Use object instead of array
        $openPnLByType = new stdClass(); // Use object instead of array
        $pendingPairs = []; // Track pairs with unavailable prices
        $hasPendingPrices = false; // Track if any prices are unavailable

        // Cache current prices per pair to avoid repeated lookups
        $currentPrices = [];

        while ($row = $openPnlResult->fetch_assoc()) {
            $pair = $row['pair'];
            $type = $row['type'];

            // Ensure we have a current price for this pair (cached)
            if (!isset($currentPrices[$pair])) {
                $currentPrices[$pair] = ProfitCalculationService::getCurrentPairPrice($pair);
            }

            $currentPrice = $currentPrices[$pair];

            // Build minimal trade array expected by calculateTradeProfit
            $tradeForCalc = [
                'trade_price' => isset($row['trade_price']) ? floatval($row['trade_price']) : 0.0,
                'lot' => isset($row['lot']) ? floatval($row['lot']) : 0.0,
                'type' => $type
            ];

            $pairConfig = [
                'lot_size' => isset($row['lot_size']) ? floatval($row['lot_size']) : 1,
                'digits' => isset($row['digits']) ? intval($row['digits']) : 2
            ];

            // Only calculate profit if price is available
            if ($currentPrice !== null) {
                $profitCalc = ProfitCalculationService::calculateTradeProfit($tradeForCalc, $currentPrice, $pairConfig);
                if ($profitCalc && isset($profitCalc['totalProfit'])) {
                    $profit = floatval($profitCalc['totalProfit']);
                    
                    if (!isset($openPnLByPair->$pair)) {
                        $openPnLByPair->$pair = (object)[
                            'buy' => 0.0,
                            'sell' => 0.0,
                        ];
                    }

                    // Accumulate profit (can be positive or negative)
                    $openPnLByPair->$pair->$type += $profit;

                    if (!isset($openPnLByType->$type)) {
                        $openPnLByType->$type = 0.0;
                    }

                    $openPnLByType->$type += $profit;
                }
            } else {
                // Mark this pair as pending (price unavailable)
                if (!in_array($pair, $pendingPairs)) {
                    $pendingPairs[] = $pair;
                }
                $hasPendingPrices = true;
            }
        }


        // ========================
        // 3. CLOSED TRADES PNL (BY PAIR + TYPE)
        // ========================
        $closedPnlQuery = "
            SELECT 
                pair,
                type,
                profit
            FROM trades
            WHERE close_date IS NOT NULL AND close_date != ''
            AND trade_acc = ?
        ";
        $stmt = $conn->prepare($closedPnlQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $trade_acc);
        $stmt->execute();

        $closedPnlResult = $stmt->get_result();

        $closedPnLByPair = [];
        $closedPnLByType = [];
        while ($row = $closedPnlResult->fetch_assoc()) {
            $pair = $row['pair'];
            $type = $row['type'];
            $profitStr = trim($row['profit']);

            // convert string profit like "+35.23" or "-78.75" to float
            $profit = floatval($profitStr);

            if (!isset($closedPnLByPair[$pair])) {
                $closedPnLByPair[$pair] = [
                    'buy' => 0.0,
                    'sell' => 0.0,
                ];
            }

            $closedPnLByPair[$pair][$type] += $profit;

            if (!isset($closedPnLByType[$type])) {
                $closedPnLByType[$type] = 0.0;
            }

            $closedPnLByType[$type] += $profit;
        }

        // ========================
        // STRUCTURE RESPONSE
        // ========================
        
        // Determine P&L status
        $pnlStatus = 'calculated'; // default: all prices available and calculated
        if ($total_open_trades_count === 0) {
            $pnlStatus = 'no_trades'; // no open trades at all
        } elseif ($hasPendingPrices) {
            $pnlStatus = 'pending'; // some or all prices unavailable
        }
        
        Response::success([
            'openTradesByPair' => $openTradesByPair,      // for pie chart (pair distribution + buy/sell counts)
            'closedTradesByPair' => $closedTradesByPair,  // for closed pie chart
            'openPnLByPair' => $openPnLByPair,            // for stacked bar chart by pair (buy/sell profit/loss) - always object
            'openPnLByType' => $openPnLByType,            // buy/sell profit/loss - always object
            'closedPnLByPair' => $closedPnLByPair,         // for stacked bar chart by pair (buy/sell profit/loss)
            'closedPnLByType' => $closedPnLByType,         // buy/sell profit/loss
            'total_open_trades_count' => $total_open_trades_count,
            'total_closed_trades_count' => $total_closed_trades_count,
            'pendingProfitPairs' => $pendingPairs,        // pairs with unavailable live prices
            'pnlStatus' => $pnlStatus,                    // 'no_trades' | 'pending' | 'calculated'
        ]);
    }

    public static function getAdminTradeStatsForAcc($account)
    {
        $conn = Database::getConnection();

        // Total open trades
        $openTotalQuery = "SELECT COUNT(*) AS total_open_trades FROM trades WHERE (close_date = '' OR close_date IS NULL) AND account = ?";
        $stmt = $conn->prepare($openTotalQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $account);
        $stmt->execute();
        $openTradesResult = $stmt->get_result();

        $total_open_trades_count = intval($openTradesResult->fetch_assoc()['total_open_trades']);

        // Total closed trades
        $closedTotalQuery = "SELECT COUNT(*) AS total_closed_trades FROM trades WHERE (close_date IS NOT NULL AND close_date != '') AND account = ?";
        $stmt = $conn->prepare($closedTotalQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $account);
        $stmt->execute();
        $closedTradesResult = $stmt->get_result();

        $total_closed_trades_count = intval($closedTradesResult->fetch_assoc()['total_closed_trades']);


        // ========================
        // 1. OPEN TRADES
        // ========================
        // Group by pair and type for open trades
        $openTradesQuery = "
            SELECT 
                pair,
                type,
                COUNT(*) AS count
            FROM trades
            WHERE (close_date = '' OR close_date IS NULL)
            AND account = ?
            GROUP BY pair, type
        ";
        $stmt = $conn->prepare($openTradesQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $account);
        $stmt->execute();

        $openTradesResult = $stmt->get_result();

        $openTradesByPair = [];
        while ($row = $openTradesResult->fetch_assoc()) {
            $pair = $row['pair'];
            $type = $row['type'];

            if (!isset($openTradesByPair[$pair])) {
                $openTradesByPair[$pair] = [
                    'total' => 0,
                    'buy' => 0,
                    'sell' => 0,
                ];
            }

            $openTradesByPair[$pair]['total'] += intval($row['count']);
            $openTradesByPair[$pair][$type] += intval($row['count']);
        }

        // ========================
        // 2. CLOSED TRADES
        // ========================
        // Group by pair and type for closed trades
        $closedTradesQuery = "
            SELECT 
                pair,
                type,
                COUNT(*) AS count
            FROM trades
            WHERE close_date IS NOT NULL AND close_date != ''
            AND account = ?
            GROUP BY pair, type
        ";
        $stmt = $conn->prepare($closedTradesQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $account);
        $stmt->execute();

        $closedTradesResult = $stmt->get_result();

        $closedTradesByPair = [];
        while ($row = $closedTradesResult->fetch_assoc()) {
            $pair = $row['pair'];
            $type = $row['type'];

            if (!isset($closedTradesByPair[$pair])) {
                $closedTradesByPair[$pair] = [
                    'total' => 0,
                    'buy' => 0,
                    'sell' => 0,
                ];
            }

            $closedTradesByPair[$pair]['total'] += intval($row['count']);
            $closedTradesByPair[$pair][$type] += intval($row['count']);
        }

        // ========================
        // 3. OPEN TRADES PNL (BY PAIR + TYPE)
        // ========================
        // Fetch open trades along with pair config needed to calculate live profit
        $openPnlQuery = "
            SELECT 
                t.id,
                t.pair,
                t.type,
                t.trade_price,
                t.lot,
                t.trade_price AS trade_price_duplicate,
                p.lot_size,
                p.digits
            FROM trades t
            LEFT JOIN pairs p ON t.pair = p.name
            WHERE (close_date = '' OR close_date IS NULL)
            AND t.account = ?
        ";
        $stmt = $conn->prepare($openPnlQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $account);
        $stmt->execute();

        $openPnlResult = $stmt->get_result();

        $openPnLByPair = new stdClass(); // Use object instead of array
        $openPnLByType = new stdClass(); // Use object instead of array
        $pendingPairs = []; // Track pairs with unavailable prices
        $hasPendingPrices = false; // Track if any prices are unavailable

        // Cache current prices per pair to avoid repeated lookups
        $currentPrices = [];

        while ($row = $openPnlResult->fetch_assoc()) {
            $pair = $row['pair'];
            $type = $row['type'];

            // Ensure we have a current price for this pair (cached)
            if (!isset($currentPrices[$pair])) {
                $currentPrices[$pair] = ProfitCalculationService::getCurrentPairPrice($pair);
            }

            $currentPrice = $currentPrices[$pair];

            // Build minimal trade array expected by calculateTradeProfit
            $tradeForCalc = [
                'trade_price' => isset($row['trade_price']) ? floatval($row['trade_price']) : 0.0,
                'lot' => isset($row['lot']) ? floatval($row['lot']) : 0.0,
                'type' => $type
            ];

            $pairConfig = [
                'lot_size' => isset($row['lot_size']) ? floatval($row['lot_size']) : 1,
                'digits' => isset($row['digits']) ? intval($row['digits']) : 2
            ];

            // Only calculate profit if price is available
            if ($currentPrice !== null) {
                $profitCalc = ProfitCalculationService::calculateTradeProfit($tradeForCalc, $currentPrice, $pairConfig);
                if ($profitCalc && isset($profitCalc['totalProfit'])) {
                    $profit = floatval($profitCalc['totalProfit']);
                    
                    if (!isset($openPnLByPair->$pair)) {
                        $openPnLByPair->$pair = (object)[
                            'buy' => 0.0,
                            'sell' => 0.0,
                        ];
                    }

                    // Accumulate profit (can be positive or negative)
                    $openPnLByPair->$pair->$type += $profit;

                    if (!isset($openPnLByType->$type)) {
                        $openPnLByType->$type = 0.0;
                    }

                    $openPnLByType->$type += $profit;
                }
            } else {
                // Mark this pair as pending (price unavailable)
                if (!in_array($pair, $pendingPairs)) {
                    $pendingPairs[] = $pair;
                }
                $hasPendingPrices = true;
            }
        }


        // ========================
        // 3. CLOSED TRADES PNL (BY PAIR + TYPE)
        // ========================
        $closedPnlQuery = "
            SELECT 
                pair,
                type,
                profit
            FROM trades
            WHERE close_date IS NOT NULL AND close_date != ''
            AND account = ?
        ";
        $stmt = $conn->prepare($closedPnlQuery);
        if (!$stmt) {
            Response::error("Prepare failed: " . $conn->error, 500);
        }
        $stmt->bind_param("s", $account);
        $stmt->execute();

        $closedPnlResult = $stmt->get_result();

        $closedPnLByPair = [];
        $closedPnLByType = [];
        while ($row = $closedPnlResult->fetch_assoc()) {
            $pair = $row['pair'];
            $type = $row['type'];
            $profitStr = trim($row['profit']);

            // convert string profit like "+35.23" or "-78.75" to float
            $profit = floatval($profitStr);

            if (!isset($closedPnLByPair[$pair])) {
                $closedPnLByPair[$pair] = [
                    'buy' => 0.0,
                    'sell' => 0.0,
                ];
            }

            $closedPnLByPair[$pair][$type] += $profit;

            if (!isset($closedPnLByType[$type])) {
                $closedPnLByType[$type] = 0.0;
            }

            $closedPnLByType[$type] += $profit;
        }

        // ========================
        // STRUCTURE RESPONSE
        // ========================
        
        // Determine P&L status
        $pnlStatus = 'calculated'; // default: all prices available and calculated
        if ($total_open_trades_count === 0) {
            $pnlStatus = 'no_trades'; // no open trades at all
        } elseif ($hasPendingPrices) {
            $pnlStatus = 'pending'; // some or all prices unavailable
        }
        
        Response::success([
            'openTradesByPair' => $openTradesByPair,      // for pie chart (pair distribution + buy/sell counts)
            'closedTradesByPair' => $closedTradesByPair,  // for closed pie chart
            'openPnLByPair' => $openPnLByPair,            // for stacked bar chart by pair (buy/sell profit/loss) - always object
            'openPnLByType' => $openPnLByType,            // buy/sell profit/loss - always object
            'closedPnLByPair' => $closedPnLByPair,         // for stacked bar chart by pair (buy/sell profit/loss)
            'closedPnLByType' => $closedPnLByType,         // buy/sell profit/loss
            'total_open_trades_count' => $total_open_trades_count,
            'total_closed_trades_count' => $total_closed_trades_count,
            'pendingProfitPairs' => $pendingPairs,        // pairs with unavailable live prices
            'pnlStatus' => $pnlStatus,                    // 'no_trades' | 'pending' | 'calculated'
        ]);
    }


    public static function getUserTradesByAccount($user_id, $account_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT * FROM trades WHERE userid = ? AND account = ? ORDER BY date DESC");
            $stmt->bind_param("is", $user_id, $account_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            Response::success($trades);
        } catch (Exception $e) {
            error_log("TradeService::getUserTradesByAccount - " . $e->getMessage());
            Response::error('Failed to retrieve trades', 500);
        }
    }

    public static function deleteTrade($trade_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("DELETE FROM trades WHERE id = ?");
            $stmt->bind_param("i", $trade_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                Response::success(null, 'Trade deleted successfully');
            } else {
                Response::error('Trade not found', 404);
            }
        } catch (Exception $e) {
            error_log("TradeService::deleteTrade - " . $e->getMessage());
            Response::error('Failed to delete trade', 500);
        }
    }

    public static function updateTrade($trade_id, $input) {
        try {
            $conn = Database::getConnection();
            
            $fields = [];
            $values = [];
            $types = '';

            if (isset($input['stop_loss'])) {
                $fields[] = 'stop_loss = ?';
                $values[] = $input['stop_loss'];
                $types .= 's';
            }

            if (isset($input['take_profit'])) {
                $fields[] = 'take_profit = ?';
                $values[] = $input['take_profit'];
                $types .= 's';
            }

            if (empty($fields)) {
                Response::error('No fields to update', 400);
                return;
            }

            $values[] = $trade_id;
            $types .= 'i';

            $sql = "UPDATE trades SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$values);
            
            if ($stmt->execute()) {
                Response::success(null, 'Trade updated successfully');
            } else {
                Response::error('Failed to update trade', 500);
            }
        } catch (Exception $e) {
            error_log("TradeService::updateTrade - " . $e->getMessage());
            Response::error('Failed to update trade', 500);
        }
    }

    public static function filterOpenTradesByDate($user_id, $input) {
        try {
            $startDate = $input['startDate']; // Date is in this format: 2025-09-15
            $endDate = $input['endDate']; // Date is in this format: 2025-09-15

            // Convert input dates to full datetime range for proper comparison
            $startDateTime = $startDate . ' 00:00:00'; // Start of day
            $endDateTime = $endDate . ' 23:59:59';     // End of day

            $conn = Database::getConnection();
            
            // Get user's current account for consistency
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $current_account = $user['current_account'];

            // Filter open trades by date range and current account
            $stmt = $conn->prepare("SELECT * FROM trades WHERE userid = ? AND account = ? AND (close_date = '' OR close_date IS NULL) AND date BETWEEN ? AND ? ORDER BY date DESC");
            $stmt->bind_param("isss", $user_id, $current_account, $startDateTime, $endDateTime);
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            Response::success($trades);
        } catch (Exception $e) {
            error_log("TradeService::filterOpenTradesByDate - " . $e->getMessage());
            Response::error('Failed to filter open trades', 500);
        }
    }

    public static function filterClosedTradesByDate($user_id, $input) {
        try {
            $startDate = $input['startDate']; // Date is in this format: 2025-09-15
            $endDate = $input['endDate']; // Date is in this format: 2025-09-15

            // Convert input dates to full datetime range for proper comparison
            $startDateTime = $startDate . ' 00:00:00'; // Start of day
            $endDateTime = $endDate . ' 23:59:59';     // End of day

            $conn = Database::getConnection();
            
            // Get user's current account for consistency
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $current_account = $user['current_account'];

            // Filter closed trades by close_date range and current account
            $stmt = $conn->prepare("SELECT * FROM trades WHERE userid = ? AND account = ? AND close_date != '' AND close_date IS NOT NULL AND close_date BETWEEN ? AND ? ORDER BY close_date DESC");
            $stmt->bind_param("isss", $user_id, $current_account, $startDateTime, $endDateTime);
            $stmt->execute();
            $result = $stmt->get_result();

            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = $row;
            }

            Response::success($trades);
        } catch (Exception $e) {
            error_log("TradeService::filterClosedTradesByDate - " . $e->getMessage());
            Response::error('Failed to filter closed trades', 500);
        }
    }
}
