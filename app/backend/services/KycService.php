<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/jwt_utils.php';

class KycService {
    private static $cache = [];

    public static function getPlatformKycConfig()
    {
        $config = self::fetchAllKycConfig();

        // my defaults
        // $config['allow'] = 'Yes';

        Response::success($config);
    }

    // Fetch single setting
    public static function getKycConfig($key, $default = null)
    {
        // Use cached if available
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $conn = Database::getConnection();
        $stmt = $conn->prepare("SELECT `value` FROM kyc_data WHERE `key` = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();

        $value = $result->num_rows ? $result->fetch_assoc()['value'] : $default;
        self::$cache[$key] = $value; // Cache it

        return $value;
    }

    public static function fetchAllKycConfig()
    {
        if (!empty(self::$cache)) return self::$cache;

        $conn = Database::getConnection();
        $sql = "SELECT `key`, `value` FROM kyc_data";
        $result = $conn->query($sql);

        $settings = [];

        while ($row = $result->fetch_assoc()) {
            $settings[$row['key']] = $row['value'];
        }

        self::$cache = $settings;
        return $settings;
    }

    // DYNAMIC to allow me add in db and still update via frontend
    public static function updateKycConfig($settings)
    {
        $conn = Database::getConnection();
        $stmt = $conn->prepare("UPDATE kyc_data SET `value` = ? WHERE `key` = ?");

        foreach ($settings as $key => $value) {
            $value = json_encode($value);
            $stmt->bind_param("ss", $value, $key);
            $stmt->execute();

            // Update cache
            self::$cache[$key] = $value;
        }

        $stmt->close();

        $kycConfig = self::fetchAllKycConfig();
        Response::success($kycConfig);
    }

    public static function getKycCompletionData($user_id)
    {
        try {
            $conn = Database::getConnection();
            
            // Get user's KYC required and filled status
            $stmt = $conn->prepare("
                SELECT 
                    personal_details_isRequired,
                    personal_details_isFilled,
                    trading_assessment_isRequired,
                    trading_assessment_isFilled,
                    financial_assessment_isRequired,
                    financial_assessment_isFilled,
                    identity_verification_isRequired,
                    identity_verification_isFilled,
                    income_verification_isRequired,
                    income_verification_isFilled,
                    address_verification_isRequired,
                    address_verification_isFilled
                FROM users 
                WHERE id = ?
            ");
            
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
            }
            
            $user = $result->fetch_assoc();
            
            // KYC categories
            $categories = [
                'personal_details',
                'trading_assessment',
                'financial_assessment',
                'identity_verification',
                'income_verification',
                'address_verification'
            ];
            
            $requiredCount = 0;
            $completedCount = 0;
            $incompleteCategories = [];
            
            foreach ($categories as $category) {
                $isRequired = $user[$category . '_isRequired'] === 'true';
                $isFilled = $user[$category . '_isFilled'] === 'true';
                
                if ($isRequired) {
                    $requiredCount++;
                    
                    if ($isFilled) {
                        $completedCount++;
                    } else {
                        $incompleteCategories[] = $category;
                    }
                }
            }
            
            // Calculate completion percentage
            $completionPercentage = $requiredCount > 0 
                ? round(($completedCount / $requiredCount) * 100, 2) 
                : 100; // If nothing is required, consider it 100% complete
            
                // [
                //     'completion_percentage' => 66.67,
                //     'completed_count' => 2,
                //     'required_count' => 3,
                //     'incomplete_categories' => [
                //         'identity_verification',
                //         'income_verification'
                //     ],
                //     'is_complete' => false
                // ]
            return [
                'completion_percentage' => $completionPercentage,
                'completed_count' => $completedCount,
                'required_count' => $requiredCount,
                'incomplete_categories' => $incompleteCategories,
                'is_complete' => $completionPercentage === 100.0
            ];
            
        } catch (Exception $e) {
            error_log("KycService::getKycCompletionData - " . $e->getMessage());
            return [
                'completion_percentage' => 0,
                'completed_count' => 0,
                'required_count' => 0,
                'incomplete_categories' => [],
                'is_complete' => false
            ];
        }
    }

    public static function checkUserKycPermission($service, $user_id)
    {
        try {
            // Check if KYC is required for this service (trade, deposit, withdrawal)
            $kycRequired = self::getKycConfig($service . '_requiresKyc', 'false');
            
            // If KYC is not required for this service, allow access
            if ($kycRequired === 'false') {
                return true;
            }
            
            // KYC is required, check user's completion status
            $kycData = self::getKycCompletionData($user_id);
            
            // Return true only if KYC is 100% complete
            return [
                'is_complete' => $kycData['is_complete'],
                'incomplete_categories' => $kycData['incomplete_categories']
            ];
            
        } catch (Exception $e) {
            error_log("KycService::checkUserKycPermission - " . $e->getMessage());
            // Fail safely - if we can't check, deny access
            return false;
        }
    }
}



?>