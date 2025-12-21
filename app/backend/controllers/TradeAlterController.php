<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/TradeAlterService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class TradeAlterController {

    public static function getAllAlters() {
       $alters = TradeAlterService::getAllAlters();
       Response::success($alters);
    }

    public static function getAlterTrade($ref) {
       $alterValue = TradeAlterService::getAlterTrade($ref);
       Response::success($alterValue);
    }

    public static function getAlterPair($pair, $acc_type) {
       $alterValue = TradeAlterService::getAlterPair($pair, $acc_type);
       Response::success($alterValue);
    }

    public static function getAlterAccountPair($account) {
       $alterValues = TradeAlterService::getAlterAccountPair($account);
       Response::success($alterValues);
    }

    public static function deleteAlterTrade($id, $mode) {
        TradeAlterService::deleteAlterTrade($id, $mode);
    }

    public static function createAlterTrade($reason = 'admin') {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'ref'  => 'required|stringOrNumeric',
            'start_price'  => 'required|float',
            'target_price'  => 'required|float',
            'time'  => 'required|stringOrNumeric',
            'close'  => 'required|boolean'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $result = TradeAlterService::createAlterTrade($input, $reason);
        Response::success($result);
    }

    public static function createAlterPair($reason = 'admin') {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'pair'  => 'required|string',
            'acc_type' => 'required|string',
            'start_price'  => 'required|float',
            'target_price'  => 'required|float',
            'time'  => 'required|float',
            'close'  => 'required|boolean',
            'alter_chart'  => 'required|boolean'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $result = TradeAlterService::createAlterPair($input, $reason);
        Response::success($result);
    }

    public static function createAlterAccountPair($reason = 'admin') {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'account'  => 'required|stringOrNumeric',
            'pair'  => 'required|string',
            'start_price'  => 'required|float',
            'target_price'  => 'required|float',
            'time'  => 'required|float',
            'close'  => 'required|boolean',
            'alter_chart'  => 'required|boolean'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $result = TradeAlterService::createAlterAccountPair($input, $reason);
        Response::success($result);
    }
}
