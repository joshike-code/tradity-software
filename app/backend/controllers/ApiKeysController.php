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
        $settings = ApiKeysService::getApiKeys(['FINNHUB_API_KEY', 'TWELVE_DATA_API_KEY', 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'FACEBOOK_APP_ID', 'FACEBOOK_APP_SECRET', 'APPLE_CLIENT_ID', 'APPLE_TEAM_ID', 'APPLE_KEY_ID', 'APPLE_PRIVATE_KEY']);
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
            'GOOGLE_CLIENT_ID' => 'null|string',
            'GOOGLE_CLIENT_SECRET' => 'null|string',
            'FACEBOOK_APP_ID' => 'null|string',
            'FACEBOOK_APP_SECRET' => 'null|string',
            'APPLE_CLIENT_ID' => 'null|string',
            'APPLE_TEAM_ID' => 'null|string',
            'APPLE_KEY_ID' => 'null|string',
            'APPLE_PRIVATE_KEY' => 'null|string',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        // We cannot do this. Rather we check whether the input keys names exist in the env file
        // if(!isset($input['FINNHUB_API_KEY']) && !isset($input['TWELVE_DATA_API_KEY'])) {
        //     Response::error('No valid API keys provided for update.', 400);
        // }

        // Update API keys - input names match ENV key names
        ApiKeysService::updateApiKeys($input);
    }
}



?>