<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/update_env.php';

class InstallService
{
    private static $connection = null;

    public static function createProfile(array $input, array $logos) {

        $hostlink = '/app/backend/';
        $environment = 'production';
        $dbHost = $input['db_host'];
        $dbName = $input['db_name'];
        $dbUser = $input['db_user'];
        $dbPass = $input['db_pass'] ?? '';
        $dbPort = '';
        $platformName = $input['platform_name'];
        $platformURl = $input['platform_url'];
        $address = $input['address'];
        $whatsappNumber = $input['whatsapp_number'];
        $licensedBy = $input['licensed_by'];
        $supportMail = $input['support_mail'];
        $theme = $input['theme'];
        $themeColor = ltrim($input['theme_color'], '#') ?? '006CBF'; // Remove # to avoid .env comment issues
        $mainLogo = $logos['main_logo'];
        $mainIcon = $logos['main_icon'];
        $appIcon180 = $logos['app_icon_180'];
        $appIcon512 = $logos['app_icon_512'];
        $favicon = $logos['favicon'];
        $jwtSecret = 'ujbdi93ndufis30dbksdrtdcalg94';

        self::checkDBCredentials($dbHost, $dbUser, $dbPass, $dbName,);

        // Write backend .env
        $envContent = "# System Configuration\nHOST_LINK=$hostlink\nENVIRONMENT=$environment\n\n# Database Configuration\nDB_HOST=$dbHost\nDB_PORT=$dbPort\nDB_NAME=$dbName\nDB_USERNAME=$dbUser\nDB_PASSWORD=$dbPass\n\n# Platform Configuration\nPLATFORM_NAME=$platformName\nMAIN_LOGO=$mainLogo\nMAIN_ICON=$mainIcon\nTHEME_NAME=$theme\nTHEME_COLOR=$themeColor\nPLATFORM_URL=$platformURl\nPLATFORM_ADDRESS=$address\nPLATFORM_WHATSAPP_NUMBER=$whatsappNumber\nPLATFORM_LICENSED_BY=$licensedBy\nPLATFORM_SUPPORT_MAIL=$supportMail\n\n# JWT Configuration\nJWT_SECRET_KEY=$jwtSecret\n\n# Degiant Configuration\nDEGIANT_PASSKEY=\n\n# Exchange Rates API Configuration\nEXCHANGE_RATES_API_KEY=\n\n# PHPMailer Configuration\nPHPMAILER_HOST=\nPHPMAILER_USERNAME=\nPHPMAILER_FROM=\nPHPMAILER_PASSWORD=\nPHPMAILER_AUTH=true\nPHPMAILER_SECURITY=TLS\nPHPMAILER_PORT=587\nPHPMAILER_ADMIN=\n\n";
        file_put_contents(__DIR__ . '/../.env', $envContent);

        // Install in index
        $indexPath = realpath(__DIR__ . '/../../index.html');
        if (file_exists($indexPath)) {
            $indexContent = file_get_contents($indexPath);
            $indexContent = str_replace('Tradity', $platformName, $indexContent);
            $indexContent = str_replace('backend/logos/default/main_logo_tradity.png', "backend/$mainLogo", $indexContent);
            $indexContent = str_replace('backend/logos/default/main_icon_512_tradity.png', "backend/$mainIcon", $indexContent);
            $indexContent = str_replace('backend/logos/default/favicon_tradity.png', "backend/$favicon", $indexContent);
            $indexContent = str_replace('backend/logos/app_icon_180_1765966074_7799f4.png', "backend/ $appIcon180", $indexContent);
            $indexContent = str_replace('seaBlue', $theme, $indexContent);
            file_put_contents($indexPath, $indexContent);
        }

        // Install in landing index
        $indexPath = realpath(__DIR__ . '/../../../index.html');
        if (file_exists($indexPath)) {
            $indexContent = file_get_contents($indexPath);
            $indexContent = str_replace('Tradity', $platformName, $indexContent);
            $indexContent = str_replace('app/backend/logos/default/main_logo_tradity.png', "backend/$mainLogo", $indexContent);
            $indexContent = str_replace('app/backend/logos/default/favicon_tradity.png', "backend/$favicon", $indexContent);
            file_put_contents($indexPath, $indexContent);
        }

        // Create new config
        $config = [
            "platformName" => $platformName,
            "meta_description" => "$platformName - Professional broker for trading financial markets",
            "meta_keywords" => "$platformName, Broker, Forex, Stocks, Crypto, Trading, Options",
            "logo" => "app/backend/$mainLogo",
            "icon" => "app/backend/$mainIcon",
            "favicon" => "app/backend/$favicon",
            "email" => "$supportMail",
            "address" => "$address",
            "licensed_by" => "$licensedBy",
            "whatsapp" => "$whatsappNumber",
        ];
        $configPath = realpath(__DIR__ . '/../../') . '/config.json';
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Create new manifest
        $manifest = [
            "name" => strtoupper($platformName),
            "short_name" => $platformName,
            "start_url" => "/app/",
            "scope" => "/app/",
            "theme_color" => "#FFFFFF",
            "description" => "$platformName - Professional broker for trading financial markets",
            "display" => "fullscreen",
            "icons" => [
                [
                    "src" => "backend/$appIcon512",
                    "sizes" => "512x512",
                    "type" => "image/png"
                ],
                [
                    "src" => "backend/$appIcon180",
                    "sizes" => "180x180",
                    "type" => "image/png"
                ],
                [
                    "src" => "backend/$favicon",
                    "sizes" => "32x32",
                    "type" => "image/png"
                ],
            ],
            "categories" => ["Forex", "Stocks", "Crypto", "Trading", "Options"]
        ];
        $manifestPath = realpath(__DIR__ . '/../../') . '/manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Run migrations
        $result = include __DIR__ . '/../run-migrations.php';
        if (is_array($result)) {
            if($result['status'] === 'error') {
                $e = $result['error'];
                error_log("Update migrations error: {$e}");
                Response::error("Migration failed.", 400);
            }
        } else {
            Response::error("Unknown migration error.", 400);
        }

        //Update .env from .env-example
        require_once __DIR__ . '/EnvSyncService.php';
        $envSyncResult = EnvSyncService::syncEnvironmentFiles();
        
        if ($envSyncResult['status'] === 'error') {
            error_log("ENV sync error: {$envSyncResult['message']}");
            Response::error("Environment synchronization failed: " . $envSyncResult['message'], 400);
        }

        Response::success("Installation credentials saved successfully.");
    }

    public static function uploadLogo(array $input) {
        try {

            $upload_dir = __DIR__ . '/../logos/';
            
            // Create uploads directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $allowed_mime_types = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png'
            ];

            $max_size = 5 * 1024 * 1024; // 5MB in bytes
            $uploaded_files = [];

            // Process both logos
            $logos = [
                'main_logo' => $input['main_logo'],
                'main_icon' => $input['main_icon']
            ];

            foreach ($logos as $logo_type => $fileData) {
                // Extract MIME type and base64 data
                if (!preg_match('/^data:([^;]+);base64,(.+)$/', $fileData, $matches)) {
                    Response::error("Invalid base64 file format for {$logo_type}", 400);
                    return;
                }

                $mimeType = $matches[1];
                $base64Data = $matches[2];

                // Validate MIME type
                if (!isset($allowed_mime_types[$mimeType])) {
                    Response::error("Invalid file format for {$logo_type}. Only JPG, JPEG, PNG allowed", 400);
                    return;
                }

                $extension = $allowed_mime_types[$mimeType];

                // Decode base64 data
                $fileContent = base64_decode($base64Data);
                if ($fileContent === false) {
                    Response::error("Invalid base64 data for {$logo_type}", 400);
                    return;
                }

                // Check file size (5MB max)
                if (strlen($fileContent) > $max_size) {
                    Response::error("File too large for {$logo_type}. Maximum size is 5MB", 400);
                    return;
                }

                // Generate unique filename with logo type prefix
                $filename = $logo_type . '_' . time() . '_' . bin2hex(random_bytes(5));
                $file_path = $upload_dir . $filename . '.' . $extension;
                $relative_path = 'logos/' . $filename . '.' . $extension;

                // Save file
                if (!file_put_contents($file_path, $fileContent)) {
                    Response::error("Failed to save {$logo_type}", 500);
                    return;
                }

                $uploaded_files[$logo_type] = $relative_path;
            }

            // Generate resized versions of main_icon
            $mainIcon = self::resizeImage($uploaded_files['main_icon'], 512, 512, 'main_icon_512');
            $favicon = self::resizeImage($uploaded_files['main_icon'], 32, 32, 'favicon');
            
            // Generate Apple Touch Icon with white background and centered logo
            $appIcon180 = self::createAppleTouchIcon($uploaded_files['main_icon'], 180, 'app_icon_180');
            $appIcon512 = self::createAppleTouchIcon($uploaded_files['main_icon'], 512, 'app_icon_512');

            $logos = [
                'main_logo' => $uploaded_files['main_logo'],
                'main_icon' => $mainIcon,
                'app_icon_180' => $appIcon180,
                'app_icon_512' => $appIcon512,
                'favicon' => $favicon,
            ];

            self::createProfile($input, $logos);
        } catch (Exception $e) {
            error_log("InstallService::uploadLogo - " . $e->getMessage());
            Response::error('Failed to upload logos', 500);
        }
    }

    /**
     * Resize image to specified dimensions
     * Uses GD Library with fallback to original image if GD not available
     * 
     * @param string $originalPath - Relative path to original image
     * @param int $width - Target width
     * @param int $height - Target height
     * @param string $prefix - Filename prefix for resized image
     * @return string - Relative path to resized image (or original if resize fails)
     */
    private static function resizeImage($originalPath, $width, $height, $prefix) {
        try {
            // Check if GD library is available
            if (!extension_loaded('gd')) {
                error_log("InstallService::resizeImage - GD Library not available. Using original image.");
                return $originalPath; // Fallback to original
            }

            $upload_dir = __DIR__ . '/../logos/';
            $source_file = __DIR__ . '/../' . $originalPath;

            // Check if source file exists
            if (!file_exists($source_file)) {
                error_log("InstallService::resizeImage - Source file not found: {$source_file}");
                return $originalPath;
            }

            // Get image info
            $imageInfo = getimagesize($source_file);
            if ($imageInfo === false) {
                error_log("InstallService::resizeImage - Invalid image file");
                return $originalPath;
            }

            $mimeType = $imageInfo['mime'];

            // Create image resource from original
            $sourceImage = null;
            switch ($mimeType) {
                case 'image/jpeg':
                case 'image/jpg':
                    $sourceImage = imagecreatefromjpeg($source_file);
                    $extension = 'jpg';
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($source_file);
                    $extension = 'png';
                    break;
                default:
                    error_log("InstallService::resizeImage - Unsupported image type: {$mimeType}");
                    return $originalPath;
            }

            if ($sourceImage === false) {
                error_log("InstallService::resizeImage - Failed to create image resource");
                return $originalPath;
            }

            // Create resized image with transparency support
            $resizedImage = imagecreatetruecolor($width, $height);
            
            // Preserve transparency for PNG
            if ($mimeType === 'image/png') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                imagefilledrectangle($resizedImage, 0, 0, $width, $height, $transparent);
            }

            // Resize image
            $sourceWidth = imagesx($sourceImage);
            $sourceHeight = imagesy($sourceImage);
            imagecopyresampled(
                $resizedImage, 
                $sourceImage, 
                0, 0, 0, 0, 
                $width, $height, 
                $sourceWidth, $sourceHeight
            );

            // Generate filename for resized image
            $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $extension;
            $resized_file_path = $upload_dir . $filename;
            $relative_path = 'logos/' . $filename;

            // Save resized image
            $saved = false;
            if ($mimeType === 'image/png') {
                $saved = imagepng($resizedImage, $resized_file_path, 9); // Max compression
            } else {
                $saved = imagejpeg($resizedImage, $resized_file_path, 90); // 90% quality
            }

            // Free memory
            imagedestroy($sourceImage);
            imagedestroy($resizedImage);

            if (!$saved) {
                error_log("InstallService::resizeImage - Failed to save resized image");
                return $originalPath;
            }

            return $relative_path;

        } catch (Exception $e) {
            error_log("InstallService::resizeImage - " . $e->getMessage());
            return $originalPath; // Fallback to original on any error
        }
    }

    /**
     * Create Apple Touch Icon with white background and centered logo
     * Apple requires icons to have padding and background for rounded corners
     * 
     * @param string $originalPath - Relative path to original image
     * @param int $size - Final size (180x180 recommended for Apple)
     * @param string $prefix - Filename prefix
     * @return string - Relative path to Apple Touch Icon (or original if fails)
     */
    private static function createAppleTouchIcon($originalPath, $size, $prefix) {
        try {
            // Check if GD library is available
            if (!extension_loaded('gd')) {
                error_log("InstallService::createAppleTouchIcon - GD Library not available. Using fallback.");
                return self::resizeImage($originalPath, $size, $size, $prefix);
            }

            $upload_dir = __DIR__ . '/../logos/';
            $source_file = __DIR__ . '/../' . $originalPath;

            // Check if source file exists
            if (!file_exists($source_file)) {
                error_log("InstallService::createAppleTouchIcon - Source file not found");
                return self::resizeImage($originalPath, $size, $size, $prefix);
            }

            // Get image info
            $imageInfo = getimagesize($source_file);
            if ($imageInfo === false) {
                error_log("InstallService::createAppleTouchIcon - Invalid image file");
                return self::resizeImage($originalPath, $size, $size, $prefix);
            }

            $mimeType = $imageInfo['mime'];

            // Create image resource from original
            $sourceImage = null;
            switch ($mimeType) {
                case 'image/jpeg':
                case 'image/jpg':
                    $sourceImage = imagecreatefromjpeg($source_file);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($source_file);
                    break;
                default:
                    error_log("InstallService::createAppleTouchIcon - Unsupported image type");
                    return self::resizeImage($originalPath, $size, $size, $prefix);
            }

            if ($sourceImage === false) {
                error_log("InstallService::createAppleTouchIcon - Failed to create image resource");
                return self::resizeImage($originalPath, $size, $size, $prefix);
            }

            // Create canvas with white background
            $canvas = imagecreatetruecolor($size, $size);
            $white = imagecolorallocate($canvas, 255, 255, 255); // White background
            imagefill($canvas, 0, 0, $white);

            // Calculate logo size with padding (70% of canvas size for safe zone)
            $logoSize = (int)($size * 0.7);
            $padding = (int)(($size - $logoSize) / 2);

            // Resize logo to fit with padding
            $logoResized = imagecreatetruecolor($logoSize, $logoSize);
            
            // Preserve transparency during resize
            imagealphablending($logoResized, false);
            imagesavealpha($logoResized, true);
            $transparent = imagecolorallocatealpha($logoResized, 255, 255, 255, 127);
            imagefilledrectangle($logoResized, 0, 0, $logoSize, $logoSize, $transparent);

            // Resize original logo
            $sourceWidth = imagesx($sourceImage);
            $sourceHeight = imagesy($sourceImage);
            imagecopyresampled(
                $logoResized,
                $sourceImage,
                0, 0, 0, 0,
                $logoSize, $logoSize,
                $sourceWidth, $sourceHeight
            );

            // Enable alpha blending for canvas to merge logo with background
            imagealphablending($canvas, true);

            // Place resized logo onto white canvas (centered)
            imagecopy($canvas, $logoResized, $padding, $padding, 0, 0, $logoSize, $logoSize);

            // Generate filename
            $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.png';
            $final_file_path = $upload_dir . $filename;
            $relative_path = 'logos/' . $filename;

            // Save as PNG (Apple Touch Icons should be PNG)
            $saved = imagepng($canvas, $final_file_path, 9);

            // Free memory
            imagedestroy($sourceImage);
            imagedestroy($logoResized);
            imagedestroy($canvas);

            if (!$saved) {
                error_log("InstallService::createAppleTouchIcon - Failed to save icon");
                return self::resizeImage($originalPath, $size, $size, $prefix);
            }

            return $relative_path;

        } catch (Exception $e) {
            error_log("InstallService::createAppleTouchIcon - " . $e->getMessage());
            return self::resizeImage($originalPath, $size, $size, $prefix); // Fallback
        }
    }

    public static function checkDBCredentials($host, $username, $password, $db_name) {

        try {
            self::$connection = new mysqli($host, $username, $password, $db_name);
        } catch (Exception $e) {
            Response::error('Database connection failed', 400);
        }

        return true;
    }

    public static function updatePasskey(array $input) {

        $passkey = $input['passkey'];
        $envFile = __DIR__ . '/../.env';
        $envKey = 'DEGIANT_PASSKEY';

        try {
            updateEnvValue($envFile, $envKey, $passkey);
            Response::success("Installation credentials submitted.");
        } catch (Exception $e) {
            error_log("Passkey update Error: {$e->getMessage()}");
            Response::error("An error occured", 500);
        }
    }
}



?>