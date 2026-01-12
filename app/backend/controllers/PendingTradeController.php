<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/PendingTradeService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class PendingTradeController {

    public static function getPendingTrades($user_id) {
       $trades = PendingTradeService::getPendingTrades($user_id);
       Response::success($trades);
    }
    
    public static function cancelPendingTrade($user_id, $trade_id) {
       PendingTradeService::cancelPendingTrade($user_id, $trade_id);
    }

    public static function submitPendingTrade($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'pair'  => 'required|string',
            'type'  => 'required|string',
            'lot'  => 'required|float',
            'order_value'  => 'required|float',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        PendingTradeService::submitPendingTrade($user_id, $input);
    }
}
