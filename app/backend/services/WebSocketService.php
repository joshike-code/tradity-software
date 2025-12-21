<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/keys.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/jwt_utils.php';

class WebSocketService
{
    /**
     * Get WebSocket connection information for a user
     * Returns WebSocket URL, port, and authentication token
     */
    public static function getConnectionInfo($user_id) {
        try {
            $conn = Database::getConnection();
            
            // Get user details
            $stmt = $conn->prepare("SELECT id, role, permissions FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            
            // Generate fresh JWT token for WebSocket authentication
            // Token expires in 24 hours (WebSocket connections are long-lived)
            $token = generate_jwt([
                'user_id' => $user['id'], 
                'role' => $user['role'], 
                'permissions' => $user['permissions'], 
                'exp' => time() + 86400 // 24 hours expiry
            ], 'base');

            // Get WebSocket server configuration
            $keys = require __DIR__ . '/../config/keys.php';
            $websocketConfig = self::getWebSocketConfig();
            
            return [
                'websocket_url' => $websocketConfig['url'],
                'websocket_port' => $websocketConfig['port'],
                'token' => $token,
                'user_id' => $user['id'],
                'connection_instructions' => [
                    'protocol' => $websocketConfig['protocol'],
                    'authentication' => 'Send JWT token immediately after connection',
                    'message_format' => 'JSON',
                    'supported_actions' => [
                        'auth' => 'Authenticate with JWT token',
                        'subscribe' => 'Subscribe to trading pairs',
                        'get_account' => 'Get account balance and positions',
                        'ping' => 'Keep connection alive'
                    ]
                ]
            ];
        } catch (Exception $e) {
            error_log("WebSocketService::getConnectionInfo - " . $e->getMessage());
            Response::error('Failed to generate WebSocket connection info', 500);
        }
    }
    
    /**
     * Get WebSocket server configuration based on environment
     */
    private static function getWebSocketConfig() {
        $keys = require __DIR__ . '/../config/keys.php';
        
        // Check if we're in production or development
        $isProduction = isset($keys['system']['environment']) && $keys['system']['environment'] === 'production';
        
        if ($isProduction) {
            // Production: Use domain from config with WSS (secure WebSocket)
            $domain = $keys['system']['domain'] ?? 'your-domain.com';
            return [
                'protocol' => 'wss',
                'url' => "wss://{$domain}:8080",
                'port' => 8080
            ];
        } else {
            // Development: Use localhost with WS (non-secure WebSocket)
            return [
                'protocol' => 'ws',
                'url' => 'ws://localhost:8080',
                'port' => 8080
            ];
        }
    }
    
    /**
     * Get current WebSocket server status
     */
    public static function getServerStatus() {
        try {
            require_once __DIR__ . '/../controllers/ServerController.php';
            
            // Get server status from ServerController
            $status = ServerController::getServerStatus(false); // false = don't send response
            
            return $status;
        } catch (Exception $e) {
            error_log("WebSocketService::getServerStatus - " . $e->getMessage());
            return [
                'running' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
