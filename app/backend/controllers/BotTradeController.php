<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/BotTradeService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class BotTradeController {

    public static function getUserTrade($user_id) {
       $trade = BotTradeService::getUserTrade($user_id);
       if(!$trade) {
            Response::success([]);
       }
       Response::success($trade);
    }

    public static function startPauseTrade($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);

        $trade = BotTradeService::getUserTrade($user_id);
        
        // If no trade exists, create a new one
        if(!$trade || !isset($trade['stake'])) {
            // Validate Input for create
            $rules = [
                'stake'  => 'required|float',
            ];
            $input_errors = Validator::validate($input, $rules);
            if(!empty($input_errors)) {
                Response::error(['validation_errors' => $input_errors], 422);
            }
            BotTradeService::createTrade($user_id, $input);
        } else {
            // If trade exists, toggle pause/resume
            if($trade['is_paused'] === false) {
                // Validate Input for pause
                $rules = [
                    'profit'  => 'required|stringOrNumeric'
                ];
                $input_errors = Validator::validate($input, $rules);
                if(!empty($input_errors)) {
                    Response::error(['validation_errors' => $input_errors], 422);
                }
                BotTradeService::pauseTrade($user_id, $trade['ref'], $input);
            } else {
                BotTradeService::resumeTrade($user_id, $trade['ref'], $input);
            }
        }
    }

    public static function endTrade($user_id) {
        BotTradeService::endTrade($user_id);
    }

    public static function updateProfit($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'profit'  => 'required|float'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        BotTradeService::updateProfit($user_id, $input);
    }

    public static function getTradeById($trade_id) {
        $trade = BotTradeService::getTradeById($trade_id);
        Response::success($trade);
    }

    public static function getAllTrades($trade_acc) {
        BotTradeService::getAllTrades($trade_acc);
    }

    public static function searchAllTrades($search, $trade_acc) {
        BotTradeService::searchAllTrades($search, $trade_acc);
    }

    public static function getUserTradesByAccount($user_id, $account_id) {
        BotTradeService::getUserTradesByAccount($user_id, $account_id);
    }

    public static function deleteTrade($trade_id) {
        BotTradeService::deleteTrade($trade_id);
    }
}
