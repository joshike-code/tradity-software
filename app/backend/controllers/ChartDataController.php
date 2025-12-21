<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/ChartDataService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class ChartDataController {

    public static function getHistoricalCandles($pair, $interval, $limit, $userId = null) {
        $chartData = ChartDataService::getHistoricalCandles($pair, $interval, $limit, $userId);
        Response::success($chartData);
    }

    public static function getBatchHistoricalCandles($userId = null) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'pairs'  => 'required|array'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $chartData = ChartDataService::getBatchHistoricalCandles($input, $userId);
            
        Response::success([
            'count' => count($chartData),
            'data' => $chartData
        ]);
    }
}
