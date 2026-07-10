<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/jwt_utils.php';
require_once __DIR__ . '/../services/OtpService.php';
require_once __DIR__ . '/../services/ActivityService.php';
require_once __DIR__ . '/../services/KycService.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/PlatformService.php';
require_once __DIR__ . '/../services/NotificationService.php';

use Firebase\JWT\JWT;

class SocialLoginService
{
    /**
     * Returns public OAuth provider info for the frontend
     * (client_id/app_id + enabled flag — never the secret).
     */
    public static function getProviderKeys(): array
    {
        $keys = require __DIR__ . '/../config/keys.php';
        $social = $keys['social'];

        return [
            'google' => [
                'enabled'   => !empty($social['google']['client_id']),
                'client_id' => $social['google']['client_id'] ?? '',
            ],
            'facebook' => [
                'enabled' => !empty($social['facebook']['app_id']),
                'app_id'  => $social['facebook']['app_id'] ?? '',
            ],
            'apple' => [
                'enabled'   => !empty($social['apple']['client_id']),
                'client_id' => $social['apple']['client_id'] ?? '',
            ],
        ];
    }

    /**
     * Main social login entry point.
     * Exchanges provider auth code for email, finds or creates user, returns JWT.
     *
     * @param string $provider     One of: google, facebook, apple
     * @param string $code         Authorization code from provider redirect
     * @param string $redirectUri  Must exactly match URI registered with provider
     */
    public static function loginWithSocial(string $provider, string $code, string $redirectUri): void
    {
        try {
            // 1. Exchange code with provider and retrieve user profile
            $profile = self::fetchProviderProfile($provider, $code, $redirectUri);

            if (!$profile || empty($profile['email'])) {
                Response::error('Unable to retrieve email from provider. Ensure email permission is granted.', 401);
                return;
            }

            $email  = strtolower(trim($profile['email']));
            $fname  = $profile['fname'] ?? null;
            $lname  = $profile['lname'] ?? null;

            // 2. Look up user by email
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, status, role, permissions FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                // 3a. User not found → auto-create account (social signup)
                $userId = self::createSocialUser($email, $fname, $lname);
                if (!$userId) {
                    Response::error('Failed to create account. Please try again.', 500);
                    return;
                }

                // Re-fetch the newly created user
                $stmt2 = $conn->prepare("SELECT id, status, role, permissions FROM users WHERE id = ?");
                $stmt2->bind_param("i", $userId);
                $stmt2->execute();
                $result = $stmt2->get_result();
            }

            // 3b. User found (existing or just created)
            $user = $result->fetch_assoc();

            if ($user['status'] === 'suspended') {
                Response::error('Account suspended. Please contact support.', 403);
                return;
            }

            // 4. Log activity
            ActivityService::logActivitySilent($user['id'], [
                'action' => 'login',
                'status' => 'success',
                'method' => 'social_' . $provider,
            ]);

            // 5. Issue JWT — identical payload shape to OTP/password login
            $token = generate_jwt([
                'user_id'     => $user['id'],
                'role'        => $user['role'],
                'permissions' => $user['permissions'],
                'exp'         => time() + 3600,
            ], 'base');

            Response::success(['token' => $token]);

        } catch (Exception $e) {
            error_log("SocialLoginService::loginWithSocial ({$provider}) - " . $e->getMessage());
            Response::error('An error occurred during social login.', 500);
        }
    }

    // =========================================================================
    // PROVIDER-SPECIFIC EXCHANGE METHODS
    // =========================================================================

    /**
     * Route to the correct provider and return a normalised profile array:
     *   ['email' => string, 'fname' => string|null, 'lname' => string|null]
     */
    private static function fetchProviderProfile(string $provider, string $code, string $redirectUri): ?array
    {
        switch ($provider) {
            case 'google':
                return self::fetchGoogleProfile($code, $redirectUri);
            case 'facebook':
                return self::fetchFacebookProfile($code, $redirectUri);
            case 'apple':
                return self::fetchAppleProfile($code, $redirectUri);
            default:
                Response::error('Unsupported provider: ' . $provider, 400);
                return null;
        }
    }

    // -------------------------------------------------------------------------
    // Google
    // -------------------------------------------------------------------------

    private static function fetchGoogleProfile(string $code, string $redirectUri): ?array
    {
        $keys = require __DIR__ . '/../config/keys.php';
        $cfg  = $keys['social']['google'];

        if (empty($cfg['client_id']) || empty($cfg['client_secret'])) {
            Response::error('Google login is not configured.', 503);
            return null;
        }

        // Step 1: exchange code for access_token
        $tokenResponse = self::httpPost('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (!$tokenResponse || empty($tokenResponse['access_token'])) {
            error_log("SocialLoginService::fetchGoogleProfile - token exchange failed: " . json_encode($tokenResponse));
            Response::error('Invalid or expired Google authorization code.', 400);
            return null;
        }

        // Step 2: fetch user info
        $userInfo = self::httpGet(
            'https://www.googleapis.com/oauth2/v3/userinfo',
            ['Authorization: Bearer ' . $tokenResponse['access_token']]
        );

        if (!$userInfo || empty($userInfo['email'])) {
            Response::error('Failed to retrieve Google user profile.', 401);
            return null;
        }

        return [
            'email' => $userInfo['email'],
            'fname' => $userInfo['given_name'] ?? null,
            'lname' => $userInfo['family_name'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Facebook
    // -------------------------------------------------------------------------

    private static function fetchFacebookProfile(string $code, string $redirectUri): ?array
    {
        $keys = require __DIR__ . '/../config/keys.php';
        $cfg  = $keys['social']['facebook'];

        if (empty($cfg['app_id']) || empty($cfg['app_secret'])) {
            Response::error('Facebook login is not configured.', 503);
            return null;
        }

        // Step 1: exchange code for access_token
        $tokenResponse = self::httpGet(
            'https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
                'client_id'     => $cfg['app_id'],
                'client_secret' => $cfg['app_secret'],
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
            ])
        );

        if (!$tokenResponse || empty($tokenResponse['access_token'])) {
            error_log("SocialLoginService::fetchFacebookProfile - token exchange failed: " . json_encode($tokenResponse));
            Response::error('Invalid or expired Facebook authorization code.', 400);
            return null;
        }

        // Step 2: fetch user info (email, name)
        $userInfo = self::httpGet(
            'https://graph.facebook.com/me?' . http_build_query([
                'fields'       => 'id,email,first_name,last_name',
                'access_token' => $tokenResponse['access_token'],
            ])
        );

        if (!$userInfo || empty($userInfo['email'])) {
            // Facebook may not return email if the user hasn't granted the permission
            // or if the account has no email (phone-number accounts)
            Response::error('Facebook did not return an email address. Please ensure email permission is granted or use another login method.', 401);
            return null;
        }

        return [
            'email' => $userInfo['email'],
            'fname' => $userInfo['first_name'] ?? null,
            'lname' => $userInfo['last_name'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Apple
    // -------------------------------------------------------------------------

    private static function fetchAppleProfile(string $code, string $redirectUri): ?array
    {
        $keys = require __DIR__ . '/../config/keys.php';
        $cfg  = $keys['social']['apple'];

        if (empty($cfg['client_id']) || empty($cfg['team_id']) || empty($cfg['key_id']) || empty($cfg['private_key'])) {
            Response::error('Apple login is not configured.', 503);
            return null;
        }

        // Step 1: build Apple client_secret (ES256 JWT signed with private key)
        $clientSecret = self::buildAppleClientSecret($cfg);
        if (!$clientSecret) {
            Response::error('Failed to build Apple client secret.', 500);
            return null;
        }

        // Step 2: exchange code for id_token
        $tokenResponse = self::httpPost('https://appleid.apple.com/auth/token', [
            'client_id'     => $cfg['client_id'],
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (!$tokenResponse || empty($tokenResponse['id_token'])) {
            error_log("SocialLoginService::fetchAppleProfile - token exchange failed: " . json_encode($tokenResponse));
            Response::error('Invalid or expired Apple authorization code.', 400);
            return null;
        }

        // Step 3: decode id_token (JWT) — we only need the payload, no signature verification
        // Apple's public keys can change, but for email extraction this is acceptable.
        // For production-grade security, verify against Apple's JWKS endpoint.
        $idTokenParts = explode('.', $tokenResponse['id_token']);
        if (count($idTokenParts) < 2) {
            Response::error('Malformed Apple id_token.', 400);
            return null;
        }

        $payload = json_decode(base64_decode(str_pad(strtr($idTokenParts[1], '-_', '+/'), strlen($idTokenParts[1]) % 4, '=', STR_PAD_RIGHT)), true);

        if (!$payload || empty($payload['email'])) {
            Response::error('Apple did not return an email address.', 401);
            return null;
        }

        // Apple only provides name on the very first login via the request body
        // (sent by the frontend as user JSON). We can't retrieve it from the id_token.
        // fname/lname will be null for Apple; user can fill them in profile later.
        return [
            'email' => $payload['email'],
            'fname' => null,
            'lname' => null,
        ];
    }

    /**
     * Build a short-lived ES256 JWT to use as Apple's client_secret.
     * Apple requires this to be signed with the private key from the developer portal.
     * Max expiry: 6 months (Apple hard limit).
     */
    private static function buildAppleClientSecret(array $cfg): ?string
    {
        try {
            // Restore newlines in the private key (stored as \n literals in .env)
            $privateKey = str_replace('\n', "\n", $cfg['private_key']);

            $header = [
                'alg' => 'ES256',
                'kid' => $cfg['key_id'],
            ];

            $payload = [
                'iss' => $cfg['team_id'],
                'iat' => time(),
                'exp' => time() + (60 * 60 * 24 * 180), // 180 days (Apple max)
                'aud' => 'https://appleid.apple.com',
                'sub' => $cfg['client_id'],
            ];

            return JWT::encode($payload, $privateKey, 'ES256', $cfg['key_id'], $header);

        } catch (Exception $e) {
            error_log("SocialLoginService::buildAppleClientSecret - " . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // USER AUTO-CREATION
    // =========================================================================

    /**
     * Create a new user account from a social login.
     * Mirrors UserService::registerUser() but without OTP/password.
     *
     * @return int|null  The new user's numeric ID, or null on failure
     */
    private static function createSocialUser(string $email, ?string $fname, ?string $lname): ?int
    {
        try {
            $conn = Database::getConnection();

            $id       = uniqid('usr_', true);
            // Random unusable password — social users authenticate via provider, not password
            $password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $role     = 'user';
            $ref_code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

            // KYC config defaults
            $kycConfig = KycService::fetchAllKycConfig();
            $personal_details_isRequired        = $kycConfig['personal_details_isRequired'];
            $trading_assessment_isRequired      = $kycConfig['trading_assessment_isRequired'];
            $financial_assessment_isRequired    = $kycConfig['financial_assessment_isRequired'];
            $identity_verification_isRequired   = $kycConfig['identity_verification_isRequired'];
            $income_verification_isRequired     = $kycConfig['income_verification_isRequired'];
            $address_verification_isRequired    = $kycConfig['address_verification_isRequired'];

            // Geo-IP (non-blocking — falls back to null gracefully)
            $ipData      = UserService::getIpData();
            $ip_address  = $ipData['ipAddress'] ?? null;
            $reg_country = $ipData['countryCode'] ?? null;
            $country     = $reg_country;

            $referred_by = null; // Social signups don't pass ref codes (yet)

            $stmt = $conn->prepare("
                INSERT INTO users
                    (id, email, password, fname, lname, ip_address, reg_country, country, role,
                     ref_code, referred_by,
                     personal_details_isRequired, trading_assessment_isRequired,
                     financial_assessment_isRequired, identity_verification_isRequired,
                     income_verification_isRequired, address_verification_isRequired)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                error_log("SocialLoginService::createSocialUser - prepare failed: " . $conn->error);
                return null;
            }

            $stmt->bind_param(
                "sssssssssssssssss",
                $id, $email, $password, $fname, $lname,
                $ip_address, $reg_country, $country, $role,
                $ref_code, $referred_by,
                $personal_details_isRequired, $trading_assessment_isRequired,
                $financial_assessment_isRequired, $identity_verification_isRequired,
                $income_verification_isRequired, $address_verification_isRequired
            );

            if (!$stmt->execute()) {
                error_log("SocialLoginService::createSocialUser - execute failed: " . $stmt->error);
                return null;
            }

            $newUserId = $conn->insert_id;

            // Create a demo trade account and switch to it (mirrors registerUser)
            $defaultBalance = PlatformService::getSetting('demo_account_balance', 10000);
            $account        = TradeAccountService::createAccount($newUserId, 'demo', $defaultBalance);
            TradeAccountService::switchCurrentAccount($newUserId, $account['id_hash']);

            // Send welcome notification
            NotificationService::sendWelcomeNotification($newUserId);

            return $newUserId;

        } catch (Exception $e) {
            error_log("SocialLoginService::createSocialUser - " . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // HTTP HELPERS (same cURL pattern as UserService::getIpData)
    // =========================================================================

    /**
     * HTTP POST — sends application/x-www-form-urlencoded, returns decoded JSON or null.
     */
    private static function httpPost(string $url, array $params): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $errno) {
            error_log("SocialLoginService::httpPost - cURL error ({$errno}) for {$url}");
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("SocialLoginService::httpPost - invalid JSON from {$url}: " . $response);
            return null;
        }

        return $decoded;
    }

    /**
     * HTTP GET — returns decoded JSON or null.
     */
    private static function httpGet(string $url, array $headers = []): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($response === false || $errno) {
            error_log("SocialLoginService::httpGet - cURL error ({$errno}) for {$url}");
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("SocialLoginService::httpGet - invalid JSON from {$url}: " . $response);
            return null;
        }

        return $decoded;
    }
}
