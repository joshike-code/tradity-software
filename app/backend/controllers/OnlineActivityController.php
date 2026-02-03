<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/OnlineActivityService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class OnlineActivityController {

    public static function updateOnlineStatus($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'status' => 'required|string'
        ];
        
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $status = $input['status'];
        $last_activity = $input['last_activity'] ?? null;

        OnlineActivityService::updateOnlineStatus($user_id, $status, $last_activity);
    }

    public static function sendHeartbeat($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        $last_activity = $input['last_activity'] ?? null;

        OnlineActivityService::sendHeartbeat($user_id, $last_activity);
    }

    public static function getUserOnlineStatus($user_id) {
        OnlineActivityService::getUserOnlineStatus($user_id);
    }

    public static function getAccountOnlineStatus($user_id) {
        OnlineActivityService::getAccountOnlineStatus($user_id);
    }

    public static function markOffline($user_id) {
        OnlineActivityService::markOffline($user_id);
    }

    public static function cleanupStaleStatuses() {
        OnlineActivityService::cleanupStaleStatuses();
    }
}