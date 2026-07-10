<?php
require_once __DIR__ . '/env-loader.php';
require_once __DIR__ . '/../core/response.php';

loadEnv(__DIR__ . '/../.env');

if(!file_exists(__DIR__ . '/../.env')) {
    Response::error('env file not found', 500);
}

return [
    'system' => [
        'host_link' => $_ENV['HOST_LINK'] ?? getenv('HOST_LINK'),
        'environment' => $_ENV['ENVIRONMENT'] ?? getenv('ENVIRONMENT'),
    ],
    'platform' => [
        'name' => $_ENV['PLATFORM_NAME'] ?? getenv('PLATFORM_NAME'),
        'main_logo' => $_ENV['MAIN_LOGO'] ?? getenv('MAIN_LOGO'),
        'main_icon' => $_ENV['MAIN_ICON'] ?? getenv('MAIN_ICON'),
        'theme_name' => $_ENV['THEME_NAME'] ?? getenv('THEME_NAME'),
        'theme_color' => $_ENV['THEME_COLOR'] ?? getenv('THEME_COLOR'),
        'url' => $_ENV['PLATFORM_URL'] ?? getenv('PLATFORM_URL'),
        'address' => $_ENV['PLATFORM_ADDRESS'] ?? getenv('PLATFORM_ADDRESS'),
        'whatsapp_number' => $_ENV['PLATFORM_WHATSAPP_NUMBER'] ?? getenv('PLATFORM_WHATSAPP_NUMBER'),
        'licensed_by' => $_ENV['PLATFORM_LICENSED_BY'] ?? getenv('PLATFORM_LICENSED_BY'),
        'supportmail' => $_ENV['PLATFORM_SUPPORT_MAIL'] ?? getenv('PLATFORM_SUPPORT_MAIL')
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST'),
        'port' => $_ENV['DB_HOST'] ?? getenv('DB_PORT'),
        'name' => $_ENV['DB_NAME'] ?? getenv('DB_NAME'),
        'username' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME'),
        'password' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD'),
    ],
    'jwt' => [
        'secret_key' => $_ENV['JWT_SECRET_KEY'] ?? getenv('JWT_SECRET_KEY')
    ],
    'websocket_control_key' => $_ENV['WEBSOCKET_CONTROL_KEY'] ?? getenv('WEBSOCKET_CONTROL_KEY') ?? 'change_this_secret_key_in_production',
    'cron_secret_key' => $_ENV['CRON_SECRET_KEY'] ?? getenv('CRON_SECRET_KEY') ?? 'change_this_cron_key_in_production',
    'degiant' => [
        'passkey' => $_ENV['DEGIANT_PASSKEY'] ?? getenv('DEGIANT_PASSKEY')
    ],
    'finnhub' => [
        'api_key' => $_ENV['FINNHUB_API_KEY'] ?? getenv('FINNHUB_API_KEY')
    ],
    'twelve_data' => [
        'api_key' => $_ENV['TWELVE_DATA_API_KEY'] ?? getenv('TWELVE_DATA_API_KEY')
    ],
    'exchangeratesapi' => [
        'api_key' => $_ENV['EXCHANGE_RATES_API_KEY'] ?? getenv('EXCHANGE_RATES_API_KEY')
    ],
    'phpmailer' => [
        'host' => $_ENV['PHPMAILER_HOST'] ?? getenv('PHPMAILER_HOST'),
        'username' => $_ENV['PHPMAILER_USERNAME'] ?? getenv('PHPMAILER_USERNAME'),
        'from' => $_ENV['PHPMAILER_FROM'] ?? getenv('PHPMAILER_FROM'),
        'password' => $_ENV['PHPMAILER_PASSWORD'] ?? getenv('PHPMAILER_PASSWORD'),
        'auth' => filter_var($_ENV['PHPMAILER_AUTH'] ?? getenv('PHPMAILER_AUTH'), FILTER_VALIDATE_BOOLEAN),
        'security' => $_ENV['PHPMAILER_SECURITY'] ?? getenv('PHPMAILER_SECURITY'),
        'port' => $_ENV['PHPMAILER_PORT'] ?? getenv('PHPMAILER_PORT'),
        'admin' => $_ENV['PHPMAILER_ADMIN'] ?? getenv('PHPMAILER_ADMIN')
    ],
    'social' => [
        'google' => [
            'client_id'     => $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID'),
            'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET'),
        ],
        'facebook' => [
            'app_id'     => $_ENV['FACEBOOK_APP_ID'] ?? getenv('FACEBOOK_APP_ID'),
            'app_secret' => $_ENV['FACEBOOK_APP_SECRET'] ?? getenv('FACEBOOK_APP_SECRET'),
        ],
        'apple' => [
            'client_id'   => $_ENV['APPLE_CLIENT_ID'] ?? getenv('APPLE_CLIENT_ID'),
            'team_id'     => $_ENV['APPLE_TEAM_ID'] ?? getenv('APPLE_TEAM_ID'),
            'key_id'      => $_ENV['APPLE_KEY_ID'] ?? getenv('APPLE_KEY_ID'),
            'private_key' => $_ENV['APPLE_PRIVATE_KEY'] ?? getenv('APPLE_PRIVATE_KEY'),
        ],
    ]
];
