<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/PairsService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class PairsController {

    public static function getAllPairs() {
        $pairs = PairsService::getAllPairs();
        Response::success($pairs);
    }

    public static function getPairById($id) {
        $pair = PairsService::getPairById($id);
        Response::success($pair);
    }

    public static function updatePairStatus($id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'status'  => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $response = PairsService::updatePairStatus($id, $input);
        if($response) {
            self::getPairById($id);
        };
    }

    public static function updatePair($pair_name) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input - make fields optional for updates
        $rules = [
            'type'  => 'string',
            'pair1'  => 'string',
            'pair2'  => 'string',
            'digits'  => 'integer',
            'lot_size'  => 'float',
            'pip_price'  => 'float',
            'pip_value'  => 'float',
            'spread'  => 'float',
            'min_volume'  => 'float',
            'max_volume'  => 'float',
            'margin_percent'  => 'integer',
            'status'  => 'string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        PairsService::updatePair($pair_name, $input);
    }

    public static function deletePair($pair_name) {
        PairsService::deletePair($pair_name);
    }

    public static function getPairsByType($type) {
        PairsService::getPairsByType($type);
    }

    public static function getActivePairs() {
        PairsService::getActivePairs();
    }

    public static function togglePairStatus($pair_name) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'status'  => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        PairsService::updatePairStatus($pair_name, $input['status']);
    }
}
