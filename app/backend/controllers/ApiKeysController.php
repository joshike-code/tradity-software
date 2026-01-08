<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/ApiKeysService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class ApiKeysController {

    public static function getApiKeys() {
        // Specify which keys to fetch - you can modify this list as needed
        $settings = ApiKeysService::getApiKeys(['FINNHUB_API_KEY', 'TWELVE_DATA_API_KEY']);
        Response::success($settings);
    }

    public static function updateApiKeys()
    {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Input names should match ENV key names exactly
        $rules = [
            'FINNHUB_API_KEY' => 'null|string',
            'TWELVE_DATA_API_KEY' => 'null|string',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        if(!isset($input['FINNHUB_API_KEY']) && !isset($input['TWELVE_DATA_API_KEY'])) {
            Response::error('No valid API keys provided for update.', 400);
        }

        // Update API keys - input names match ENV key names
        ApiKeysService::updateApiKeys($input);
    }
}



?>