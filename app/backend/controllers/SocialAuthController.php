<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/SocialLoginService.php';
require_once __DIR__ . '/../services/UserService.php'; // needed by SocialLoginService::createSocialUser
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class SocialAuthController
{
    /**
     * GET /api/auth?action=social_keys
     *
     * Returns the enabled providers and their public client credentials.
     * No auth required — called before the user is logged in.
     */
    public static function getSocialKeys(): void
    {
        $keys = SocialLoginService::getProviderKeys();
        Response::success($keys);
    }

    /**
     * POST /api/auth?action=social_login
     *
     * Receives: { provider, code, redirect_uri }
     * Returns:  { token: <JWT> }
     */
    public static function socialLogin(): void
    {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input    = SanitizationService::sanitize($rawInput);

        // Validate required fields
        $rules = [
            'provider'     => 'required|string',
            'code'         => 'required|string',
            'redirect_uri' => 'required|string',
        ];
        $errors = Validator::validate($input, $rules);
        if (!empty($errors)) {
            Response::error(['validation_errors' => $errors], 422);
            return;
        }

        $provider    = strtolower(trim($input['provider']));
        $code        = trim($input['code']);
        $redirectUri = trim($input['redirect_uri']);

        // Guard: only supported providers
        $supported = ['google', 'facebook', 'apple'];
        if (!in_array($provider, $supported)) {
            Response::error('Unsupported provider. Allowed: google, facebook, apple.', 400);
            return;
        }

        SocialLoginService::loginWithSocial($provider, $code, $redirectUri);
    }
}
