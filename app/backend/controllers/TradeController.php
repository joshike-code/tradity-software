<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/TradeService.php';
require_once __DIR__ . '/../services/KycService.php';
require_once __DIR__ . '/../services/TradeAlterService.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class TradeController {

    public static function getUserTrades($user_id) {
       $trades = TradeService::getUserTrades($user_id);
       Response::success($trades);
    }

    public static function getOpenTrades($user_id) {
        $trades = TradeService::getOpenTrades($user_id);
        Response::success($trades);
    }

    public static function getClosedTrades($user_id) {
        $trades = TradeService::getClosedTrades($user_id);
        Response::success($trades);
    }

    public static function openTrade($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'pair'  => 'required|string',
            'type'  => 'required|string',
            'lot'  => 'required|float',
            'tourPrice' => 'null|float', // For tour guide tutorial trades only
            'tourTarget' => 'null|float' // For tour guide tutorial trades only
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $account = TradeAccountService::getUserCurrentAccount($user_id);

        // For Frontend TourGuide tutorial trade alterations
        if(isset($input['tourPrice']) && isset($input['tourTarget'])) {
            if($account['type'] !== 'demo') {
                Response::error('Tutorial trades only allowed on demo accounts', 403);
            }
            $alterinput = [
                'account' => $account['id_hash'],
                'pair' => 'BTC/USD',
                'start_price' => $input['tourPrice'],
                'target_price' => $input['tourTarget'],
                'time' => 180,
                'close' => false,
                'alter_chart' => true
            ];
            TradeAlterService::createAlterAccountPair($alterinput, 'tutorial');
        }

        // KYC check for real account trades
        if($account['type'] === 'real') {
            $kycStatus = KycService::checkUserKycPermission('trade', $user_id);
            if(!$kycStatus['is_complete']) {
                Response::error('KYC required:' . array_values($kycStatus['incomplete_categories'])[0], 403);
            }
        }

        TradeService::openTrade($user_id, $input);
    }

    public static function closeTrade($user_id, $reason = 'manual') {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'id'  => 'required|integer'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        // Use closeTradeWithNotification to notify subscribed admins
        $result = TradeService::closeTradeWithNotification($reason, $input);
        
        if ($result['success']) {
            $account = $result['data']['account'];

            // Get all open trades
            $trades = TradeService::getOpenTrades($user_id);
            Response::success(['openTrades' => $trades, 'balance' => $account['balance']]);
        } else {
            Response::error($result['message'], $result['code']);
        }
    }

    public static function closeTradeFromAdmin($reason = 'expire') {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'id'  => 'required|integer'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        // Use closeTradeWithNotification to send WebSocket notification
        $result = TradeService::closeTradeWithNotification($reason, $input);
        
        if ($result['success']) {
            $oldTradeData = $result['data']['trade'];
            $trade = TradeService::getTradeById($oldTradeData['id']);
            Response::success($trade);
        } else {
            Response::error($result['message'], $result['code']);
        }
    }

    public static function editTrade($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'id'  => 'required|integer',
            'take_profit'  => 'required|float',
            'stop_loss'  => 'required|float'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        TradeService::editTrade($user_id, $input);
    }

    public static function getTradeById($trade_id) {
        $trade = TradeService::getTradeById($trade_id);
        Response::success($trade);
    }

    public static function getAllTrades($trade_acc, $pair, $status, $type, $account) {
        TradeService::getAllTrades($trade_acc, $pair, $status, $type, $account);
    }

    public static function searchAllTrades($searchTerm, $trade_acc) {
        TradeService::searchAllTrades($searchTerm, $trade_acc);
    }

    public static function getUserTradesByAccount($user_id, $account_id) {
        TradeService::getUserTradesByAccount($user_id, $account_id);
    }

    public static function deleteTrade($trade_id) {
        TradeService::deleteTrade($trade_id);
    }

    public static function updateTrade($trade_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'stop_loss'  => 'float',
            'take_profit'  => 'float'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        TradeService::updateTrade($trade_id, $input);
    }

    public static function filterOpenTradesByDate($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'startDate'  => 'string',
            'endDate'  => 'string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        TradeService::filterOpenTradesByDate($user_id, $input);
    }

    public static function filterClosedTradesByDate($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'startDate'  => 'string',
            'endDate'  => 'string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        TradeService::filterClosedTradesByDate($user_id, $input);
    }
}
