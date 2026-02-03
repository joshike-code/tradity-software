<?php

use Core\SanitizationService;

require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class NotificationController {

    public static function getNotifications($user_id) {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $unreadOnly = isset($_GET['unread_only']) ? filter_var($_GET['unread_only'], FILTER_VALIDATE_BOOLEAN) : true;
        
        $notifications = NotificationService::getUserNotifications($user_id, $unreadOnly, $limit);
        Response::success($notifications);
    }

    public static function getUnreadCount($user_id) {
        $count = NotificationService::getUnreadCount($user_id);
        Response::success(['count' => $count]);
    }

    public static function markAsRead($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'notification_id'  => 'required|integer'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }
        
        $result = NotificationService::markAsRead($input, $user_id);
        
        if ($result) {
            Response::success('Notification marked as read');
        } else {
            Response::error('Failed to mark notification as read', 500);
        }
    }

    public static function markAllAsRead($user_id) {
        $result = NotificationService::markAllAsRead($user_id);
        
        if ($result) {
            Response::success('All notifications marked as read');
        } else {
            Response::error('Failed to mark notifications as read', 500);
        }
    }
}
