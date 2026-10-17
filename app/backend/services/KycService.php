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
                    address_verification_isFilled,
                    kyc_status,
                    kyc_reject_reason
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
            
            // Verification categories that require admin approval
            $verificationCategories = [
                'identity_verification',
                'income_verification',
                'address_verification'
            ];

            $requiredCount = 0;
            $completedCount = 0;
            $incompleteCategories = [];

            $verificationRequiredCount = 0;
            $verificationCompletedCount = 0;
            $incompleteVerificationCategories = [];
            
            foreach ($categories as $category) {
                $isRequired = $user[$category . '_isRequired'] === 'true';
                $isFilled = $user[$category . '_isFilled'] === 'true';
                $isVerificationCat = in_array($category, $verificationCategories);
                
                if ($isRequired) {
                    $requiredCount++;
                    if ($isVerificationCat) {
                        $verificationRequiredCount++;
                    }
                    
                    if ($isFilled) {
                        $completedCount++;
                        if ($isVerificationCat) {
                            $verificationCompletedCount++;
                        }
                    } else {
                        $incompleteCategories[] = $category;
                        if ($isVerificationCat) {
                            $incompleteVerificationCategories[] = $category;
                        }
                    }
                }
            }
            
            // Calculate completion percentage
            $completionPercentage = $requiredCount > 0 
                ? round(($completedCount / $requiredCount) * 100, 2) 
                : 100; // If nothing is required, consider it 100% complete

            $isComplete = $completionPercentage === 100.0;
            $hasVerificationRequired = ($verificationRequiredCount > 0);
            $currentStatus = $user['kyc_status'] ?? 'unsubmitted';

            // Auto-transition to pending if all required categories are filled and verification approval is needed
            if ($isComplete && $hasVerificationRequired && ($currentStatus === 'unsubmitted' || $currentStatus === 'rejected' || empty($currentStatus))) {
                $currentStatus = 'pending';
                $updateStmt = $conn->prepare("UPDATE users SET kyc_status = 'pending', kyc_reject_reason = NULL WHERE id = ?");
                $updateStmt->bind_param("i", $user_id);
                $updateStmt->execute();
            }
            
            return [
                'completion_percentage' => $completionPercentage,
                'completed_count' => $completedCount,
                'required_count' => $requiredCount,
                'incomplete_categories' => $incompleteCategories,
                'incomplete_verification_categories' => $incompleteVerificationCategories,
                'has_verification_required' => $hasVerificationRequired,
                'is_complete' => $isComplete,
                'kyc_status' => $currentStatus,
                'kyc_reject_reason' => $user['kyc_reject_reason'] ?? null,
                'is_approved' => ($currentStatus === 'approved' || (!$hasVerificationRequired && $isComplete)),
                'is_rejected' => ($currentStatus === 'rejected'),
                'is_pending' => ($currentStatus === 'pending' && $hasVerificationRequired)
            ];
            
        } catch (Exception $e) {
            error_log("KycService::getKycCompletionData - " . $e->getMessage());
            return [
                'completion_percentage' => 0,
                'completed_count' => 0,
                'required_count' => 0,
                'incomplete_categories' => [],
                'incomplete_verification_categories' => [],
                'has_verification_required' => false,
                'is_complete' => false,
                'kyc_status' => 'unsubmitted',
                'kyc_reject_reason' => null,
                'is_approved' => false,
                'is_rejected' => false,
                'is_pending' => false
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
                return [
                    'has_access' => true
                ];
            }
            
            // KYC is required, check user's completion status
            $kycData = self::getKycCompletionData($user_id);

            // 1. If KYC was rejected by admin -> user must refill
            if ($kycData['kyc_status'] === 'rejected') {
                $refillCategory = !empty($kycData['incomplete_verification_categories'])
                    ? array_values($kycData['incomplete_verification_categories'])[0]
                    : (!empty($kycData['incomplete_categories'])
                        ? array_values($kycData['incomplete_categories'])[0]
                        : 'identity_verification');

                return [
                    'has_access' => false,
                    'status' => 'rejected',
                    'error_message' => 'KYC rejected:' . $refillCategory,
                    'incomplete_categories' => $kycData['incomplete_categories'],
                    'is_complete' => $kycData['is_complete']
                ];
            }
            
            // 2. If KYC is incomplete (less than 100%)
            if (!$kycData['is_complete']) {
                $firstIncomplete = !empty($kycData['incomplete_categories'])
                    ? array_values($kycData['incomplete_categories'])[0]
                    : 'personal_details';
                return [
                    'has_access' => false,
                    'status' => 'incomplete',
                    'error_message' => 'KYC required:' . $firstIncomplete,
                    'incomplete_categories' => $kycData['incomplete_categories'],
                    'is_complete' => false
                ];
            }

            // 3. Admin approval check:
            // Only identity, income, and address verification require admin approval.
            // Other categories (personal details, trading assessment, financial assessment) do NOT need approval.
            if ($kycData['has_verification_required']) {
                if ($kycData['kyc_status'] !== 'approved') {
                    return [
                        'has_access' => false,
                        'status' => 'pending',
                        'error_message' => 'KYC pending approval',
                        'incomplete_categories' => [],
                        'is_complete' => true
                    ];
                }
            }
            
            // 4. Either verification is approved or no verification documents were required -> allow access
            return [
                'has_access' => true,
                'status' => 'approved',
                'is_complete' => true,
                'incomplete_categories' => []
            ];
            
        } catch (Exception $e) {
            error_log("KycService::checkUserKycPermission - " . $e->getMessage());
            // Fail safely - if we can't check, deny access
            return [
                'has_access' => false,
                'status' => 'error',
                'error_message' => 'Access denied'
            ];
        }
    }
}



?>