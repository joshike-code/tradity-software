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
    ],
    'paystack' => [
        'public_key' => $_ENV['PAYSTACK_PUBLIC_KEY'] ?? getenv('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => $_ENV['PAYSTACK_SECRET_KEY'] ?? getenv('PAYSTACK_SECRET_KEY'),
    ],
    'stripe' => [
        'public_key'     => $_ENV['STRIPE_PUBLIC_KEY'] ?? getenv('STRIPE_PUBLIC_KEY'),
        'secret_key'     => $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY'),
        'webhook_secret' => $_ENV['STRIPE_WEBHOOK_SECRET'] ?? getenv('STRIPE_WEBHOOK_SECRET'),
    ],
    'paypal' => [
        'client_id'      => $_ENV['PAYPAL_CLIENT_ID'] ?? getenv('PAYPAL_CLIENT_ID'),
        'client_secret'  => $_ENV['PAYPAL_CLIENT_SECRET'] ?? getenv('PAYPAL_CLIENT_SECRET'),
        'environment'    => $_ENV['PAYPAL_ENVIRONMENT'] ?? getenv('PAYPAL_ENVIRONMENT') ?? 'sandbox',
        'webhook_id'     => $_ENV['PAYPAL_WEBHOOK_ID'] ?? getenv('PAYPAL_WEBHOOK_ID'),
    ],
    'razorpay' => [
        'key_id'         => $_ENV['RAZORPAY_KEY_ID'] ?? getenv('RAZORPAY_KEY_ID'),
        'key_secret'     => $_ENV['RAZORPAY_KEY_SECRET'] ?? getenv('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => $_ENV['RAZORPAY_WEBHOOK_SECRET'] ?? getenv('RAZORPAY_WEBHOOK_SECRET'),
        'merchant_upi'   => $_ENV['RAZORPAY_MERCHANT_UPI'] ?? getenv('RAZORPAY_MERCHANT_UPI'),
    ],
    'airtm' => [
        'api_key'     => $_ENV['AIRTM_API_KEY'] ?? getenv('AIRTM_API_KEY'),
        'api_secret'  => $_ENV['AIRTM_API_SECRET'] ?? getenv('AIRTM_API_SECRET'),
        'environment' => $_ENV['AIRTM_ENVIRONMENT'] ?? getenv('AIRTM_ENVIRONMENT') ?? 'sandbox',
    ],
    'skrill' => [
        'merchant_email' => $_ENV['SKRILL_MERCHANT_EMAIL'] ?? getenv('SKRILL_MERCHANT_EMAIL'),
        'merchant_id'    => $_ENV['SKRILL_MERCHANT_ID'] ?? getenv('SKRILL_MERCHANT_ID'),
        'secret_word'    => $_ENV['SKRILL_SECRET_WORD'] ?? getenv('SKRILL_SECRET_WORD'),
        'api_key'        => $_ENV['SKRILL_API_KEY'] ?? getenv('SKRILL_API_KEY'),
    ],
    'neteller' => [
        'client_id'      => $_ENV['NETELLER_CLIENT_ID'] ?? getenv('NETELLER_CLIENT_ID'),
        'client_secret'  => $_ENV['NETELLER_CLIENT_SECRET'] ?? getenv('NETELLER_CLIENT_SECRET'),
        'merchant_email' => $_ENV['NETELLER_MERCHANT_EMAIL'] ?? getenv('NETELLER_MERCHANT_EMAIL'),
        'webhook_secret' => $_ENV['NETELLER_WEBHOOK_SECRET'] ?? getenv('NETELLER_WEBHOOK_SECRET'),
    ],
    'jeton' => [
        'api_key'         => $_ENV['JETON_API_KEY'] ?? getenv('JETON_API_KEY'),
        'merchant_secret' => $_ENV['JETON_MERCHANT_SECRET'] ?? getenv('JETON_MERCHANT_SECRET'),
        'merchant_id'     => $_ENV['JETON_MERCHANT_ID'] ?? getenv('JETON_MERCHANT_ID'),
    ],
    'polygon' => [
        'rpc_url'         => $_ENV['POLYGON_RPC_URL'] ?? getenv('POLYGON_RPC_URL') ?? 'https://polygon-rpc.com',
        'polygonscan_key' => $_ENV['POLYGONSCAN_API_KEY'] ?? getenv('POLYGONSCAN_API_KEY'),
        'deposit_address' => $_ENV['POLYGON_DEPOSIT_ADDRESS'] ?? getenv('POLYGON_DEPOSIT_ADDRESS') ?? '0x71C8fb962B28bA10d65b1d5A25cAf08722420999',
    ],
    'icashone' => [
        'merchant_id' => $_ENV['ICASHONE_MERCHANT_ID'] ?? getenv('ICASHONE_MERCHANT_ID'),
        'api_key'     => $_ENV['ICASHONE_API_KEY'] ?? getenv('ICASHONE_API_KEY'),
        'secret_key'  => $_ENV['ICASHONE_SECRET_KEY'] ?? getenv('ICASHONE_SECRET_KEY'),
    ],
    'voucherry' => [
        'merchant_id' => $_ENV['VOUCHERRY_MERCHANT_ID'] ?? getenv('VOUCHERRY_MERCHANT_ID'),
        'api_key'     => $_ENV['VOUCHERRY_API_KEY'] ?? getenv('VOUCHERRY_API_KEY'),
        'secret_key'  => $_ENV['VOUCHERRY_SECRET_KEY'] ?? getenv('VOUCHERRY_SECRET_KEY'),
    ]
];
