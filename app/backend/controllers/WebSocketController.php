<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/WebSocketService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/jwt_utils.php';

class WebSocketController {

    /**
     * Get WebSocket connection information for authenticated user
     * Returns WebSocket URL and fresh JWT token for WebSocket authentication
     */
    public static function getConnectionInfo($user_id) {
        try {
            $connectionInfo = WebSocketService::getConnectionInfo($user_id);
            Response::success($connectionInfo);
        } catch (Exception $e) {
            error_log("WebSocketController::getConnectionInfo - " . $e->getMessage());
            Response::error('Failed to get WebSocket connection info', 500);
        }
    }
}
