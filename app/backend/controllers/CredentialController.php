<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/CredentialService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class CredentialController {

    /**
     * Request credential change (send email with change link)
     */
    public static function requestCredentialChange($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'change_type' => 'required|string'
        ];
        
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $change_type = $input['change_type'];
        
        // Validate change type
        if (!in_array($change_type, ['email', 'password'])) {
            Response::error('Invalid change type. Must be either "email" or "password"', 400);
        }

        CredentialService::requestCredentialChange($user_id, $change_type);
    }

    /**
     * Verify credential change token (called when user clicks email link)
     * Redirects to frontend with token validation result
     */
    public static function verifyCredentialToken() {
        // Get token and type from GET parameters
        $token = $_GET['token'] ?? null;
        $type = $_GET['type'] ?? null;
        
        if (!$token) {
            self::redirectToFrontendWithError('Token is required');
            return;
        }

        $result = CredentialService::verifyCredentialChangeToken($token);
        
        if ($result['success']) {
            // Redirect to frontend change credentials page with valid token
            self::redirectToFrontend('change_credentials', [
                'token' => urlencode($token),
                'type' => $result['type'],
                'status' => 'valid'
            ]);
        } else {
            // Redirect to frontend with error
            $errorType = $result['expired'] ? 'expired' : 'invalid';
            self::redirectToFrontendWithError($result['message'], $errorType);
        }
    }

    /**
     * Redirect to frontend with success parameters
     */
    private static function redirectToFrontend($action, $params = []) {
        $keys = require __DIR__ . '/../config/keys.php';
        $frontendUrl = $keys['platform']['url'] ?? 'http://localhost/tradity-frontend';
        
        // Ensure URL has protocol
        if (!preg_match('/^https?:\/\//', $frontendUrl)) {
            $frontendUrl = 'https://' . $frontendUrl;
        }
        
        $queryString = http_build_query(array_merge(['action' => $action], $params));
        $redirectUrl = $frontendUrl . '?' . $queryString;
        
        header("Location: $redirectUrl");
        exit;
    }

    /**
     * Redirect to frontend with error parameters
     */
    private static function redirectToFrontendWithError($message, $errorType = 'error') {
        $keys = require __DIR__ . '/../config/keys.php';
        $frontendUrl = $keys['platform']['url'] ?? 'http://localhost/tradity-frontend';
        
        // Ensure URL has protocol
        if (!preg_match('/^https?:\/\//', $frontendUrl)) {
            $frontendUrl = 'https://' . $frontendUrl;
        }
        
        $params = [
            'action' => 'credential_error',
            'error' => urlencode($message),
            'error_type' => $errorType
        ];
        
        $queryString = http_build_query($params);
        $redirectUrl = $frontendUrl . '?' . $queryString;
        
        header("Location: $redirectUrl");
        exit;
    }

    /**
     * Update credentials after token verification
     */
    public static function updateCredentials() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Get token from GET parameter
        $token = $_GET['token'] ?? null;
        
        if (!$token) {
            Response::error('Token is required', 400);
        }

        // First verify the token to get the change type
        $tokenValidation = CredentialService::verifyCredentialChangeToken($token);
        if (!$tokenValidation['success']) {
            $status_code = $tokenValidation['expired'] ? 410 : 400;
            Response::error($tokenValidation['message'], $status_code);
        }

        $change_type = $tokenValidation['type'];

        // Validate input based on change type
        if ($change_type === 'email') {
            $rules = [
                'email' => 'required|email'
            ];
        } else {
            $rules = [
                'password' => 'required|password'
            ];
        }
        
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $result = CredentialService::updateCredentials($token, $input);
        
        if ($result['success']) {
            $response_data = ['message' => $result['message']];
            
            // Include new email in response for email changes
            if ($change_type === 'email' && isset($result['new_email'])) {
                $response_data['new_email'] = $result['new_email'];
            }
            
            Response::success($response_data);
        } else {
            Response::error($result['message'], 400);
        }
    }

    /**
     * Get current user credentials (for display purposes)
     */
    // public static function getCurrentCredentials($user_id) {
    //     $result = CredentialService::getUserCredentials($user_id);
        
    //     if ($result['success']) {
    //         // Remove sensitive data before sending to client
    //         unset($result['success']);
    //         Response::success($result);
    //     } else {
    //         Response::error($result['message'], 404);
    //     }
    // }
}
