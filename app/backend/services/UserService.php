<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/jwt_utils.php';
require_once __DIR__ . '/../services/OtpService.php';
require_once __DIR__ . '/../services/MailService.php';
require_once __DIR__ . '/../services/TradeAccountService.php';
require_once __DIR__ . '/../services/ActivityService.php';
require_once __DIR__ . '/../services/KycService.php';
require_once __DIR__ . '/../services/OnlineActivityService.php';
require_once __DIR__ . '/../services/PlatformService.php';
require_once __DIR__ . '/../services/NotificationService.php';

class UserService
{
    // Login first step - send OTP to email (secure: no user enumeration)
    public static function preLoginUser(string $email) {
        try {
        
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, role, status FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            // SECURITY: Always return success to prevent user enumeration
            // But only send OTP if user actually exists
            if ($result->num_rows === 0) {
                // Add artificial delay to match timing of real OTP send
                usleep(rand(100000, 300000)); // 100-300ms delay
                
                // Return success even though email doesn't exist
                // This prevents attackers from knowing which emails are valid
                Response::success('OTP sent. Check your email');
            }
            
            // User exists - check if account is active
            $user = $result->fetch_assoc();
            
            if ($user['status'] === 'suspended') {
                // Don't reveal account is suspended - just don't send OTP
                // Return generic success message
                usleep(rand(100000, 300000)); // Match timing
                Response::success('OTP sent. Check your email');
            }

            // User exists and is active - send OTP
            $otp = OtpService::generateOtp($email);

            Response::success(['message' => 'OTP sent', 'otp' => $otp]);
            if (!MailService::sendOtpEmail($email, $otp, 'login')) {
                // Log error but don't reveal to user
                error_log("Failed to send OTP to {$email}");
                Response::error('Failed to send OTP. Please try again', 500);
            }
            
            Response::success('OTP sent. Check your email');
        
        } catch (Exception $e) {
            error_log("UserService::preLoginUser - " . $e->getMessage());
            Response::error('An error occurred', 500);
        }
    }
    
    // Option 1: Login with OTP (after preLoginUser)
    public static function loginWithOtp(string $email, string $otp) {
        try {
            
            // Validate OTP first
            $validateOtp = OtpService::validateOtp($email, $otp);
            if (!$validateOtp) {
                Response::error('Invalid or expired OTP', 401);
            }
        
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, status, role, permissions FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
        
            // If the user is not found
            if ($result->num_rows === 0) {
                Response::error('User not found', 401);
            }
        
            // Fetch the user data
            $user = $result->fetch_assoc();

            if ($user['status'] === 'suspended') {
                Response::error('Account suspended', 403);
            }
        
            // Log successful login activity
            ActivityService::logActivitySilent($user['id'], [
                'action' => 'login',
                'status' => 'success',
                'method' => 'otp'
            ]);
            
            $token = generate_jwt([
                'user_id' => $user['id'], 
                'role' => $user['role'], 
                'permissions' => $user['permissions'], 
                'exp' => time() + 3600
            ], 'base');
            
            Response::success(['token' => $token]);
        
        } catch (Exception $e) {
            error_log("UserService::loginWithOtp - " . $e->getMessage());
            Response::error('An error occurred', 500);
        }
    }
    
    // Option 2: Login with password (traditional login - "Use password instead")
    public static function loginWithPassword(string $email, string $password) {
        try {
        
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, password, status, role, permissions FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
        
            // If the user is not found
            if ($result->num_rows === 0) {
                Response::error('Invalid credentials', 401);
            }
        
            // Fetch the user data
            $user = $result->fetch_assoc();

            if ($user['status'] === 'suspended') {
                Response::error('Account suspended', 403);
            }

            // Verify the password
            if (!password_verify($password, $user['password'])) {
                Response::error('Invalid credentials', 401);
            }
        
            // Log successful login activity
            ActivityService::logActivitySilent($user['id'], [
                'action' => 'login',
                'status' => 'success'
            ]);
            
            $token = generate_jwt([
                'user_id' => $user['id'], 
                'role' => $user['role'], 
                'permissions' => $user['permissions'], 
                'exp' => time() + 3600
            ], 'base');
            
            Response::success(['token' => $token]);
        
        } catch (Exception $e) {
            error_log("UserService::loginWithPassword - " . $e->getMessage());
            Response::error('An error occurred', 500);
        }
    }
    
    // Legacy method - kept for backward compatibility
    public static function loginUser(string $email, string $password, string $type) {
        try {

            if (empty($email) || empty($password)) {
                Response::error('Email and password are required', 400);
            }
        
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, password, status, role, permissions FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
        
            // If the user is not found
            if ($result->num_rows === 0) {
                Response::error('Invalid email', 401);
                exit;
            }
        
            // Fetch the user data
            $user = $result->fetch_assoc();

            if($user['status'] === 'suspended') {
                Response::error('User suspended', 400);
                exit;
            }

            // Verify the password
            if (!password_verify($password, $user['password'])) {
                Response::error('Invalid password', 401);
                exit;
            }
        
            // Log successful login activity
            ActivityService::logActivitySilent($user['id'], [
                'action' => 'login',
                'status' => 'success'
            ]);
            
            $token = generate_jwt(['user_id' => $user['id'], 'role' => $user['role'], 'permissions' => $user['permissions'], 'exp' => time() + 3600], 'base');
            Response::success(['token' => $token]);
        
        } catch (Exception $e) {
            error_log("UserService::loginUser - " . $e->getMessage());
            Response::error('An error occurred', 500);
        }
    }

    public static function preRegisterUser(array $input)
    {
        $conn = Database::getConnection();
        $email = $input['email'];

        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            Response::error("Email already exists", 400);
        }

        // Generate OTP
        $otp = OtpService::generateOtp($email);

        Response::success(['message' => 'OTP sent', 'otp' => $otp]);
        if (!MailService::sendOtpEmail($email, $otp, 'register')) {
            Response::error('Failed to send OTP email', 500);
        }
        Response::success('OTP Sent');
    }

    public static function verifyRegisterUser(array $input)
    {
        $conn = Database::getConnection();
        $email = $input['email'];
        $otp = $input['otp'];

        $validateOtp = OtpService::validateOtp($email, $otp);
        if(!$validateOtp) {
            Response::error('Invalid or expired OTP', 401);
        }

        Response::success('OTP Validated');
    }

    public static function registerUser(array $input)
    {
        session_start();
        $conn = Database::getConnection();

        $email = $input['email'];
        $otp = $input['otp'];

        $validateOtp = OtpService::validateOtp($email, $otp);
        if(!$validateOtp) {
            Response::error('Invalid or expired OTP', 401);
        }

        $id = uniqid('usr_', true);
        $password = password_hash($input['password'], PASSWORD_DEFAULT);
        $fname = null;
        $lname = null;
        $role = 'user';

        // Generate unique referral code
        $ref_code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $referred_by = $input['ref'] ?? null;
        if ($referred_by) {
            $checkRef = $conn->prepare("SELECT id FROM users WHERE ref_code = ?");
            $checkRef->bind_param("s", $referred_by);
            $checkRef->execute();
            $checkResult = $checkRef->get_result();
        
            if ($checkResult->num_rows === 0) {
                // No need to make noise
                // Response::error("Invalid referral code provided", 400);
                $referred_by = null;
            }
        }

        $kycConfig = KycService::fetchAllKycConfig();
        $personal_details_isRequired = $kycConfig['personal_details_isRequired'];
        $trading_assessment_isRequired = $kycConfig['trading_assessment_isRequired'];
        $financial_assessment_isRequired = $kycConfig['financial_assessment_isRequired'];
        $identity_verification_isRequired = $kycConfig['identity_verification_isRequired'];
        $income_verification_isRequired = $kycConfig['income_verification_isRequired'];
        $address_verification_isRequired = $kycConfig['address_verification_isRequired'];

        $ipData = self::getIpData();
        $ip_address = $ipData['ipAddress'] ?? null;
        $country = $reg_country = $ipData['countryCode'] ?? null;

        $stmt = $conn->prepare("INSERT INTO users (id, email, password, fname, lname, ip_address, reg_country, country, role, ref_code, referred_by, personal_details_isRequired, trading_assessment_isRequired, financial_assessment_isRequired, identity_verification_isRequired, income_verification_isRequired, address_verification_isRequired) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            Response::error('error: prepare failed', 500);
        }

        $stmt->bind_param("sssssssssssssssss", $id, $email, $password, $fname, $lname, $ip_address, $reg_country, $country, $role, $ref_code, $referred_by, $personal_details_isRequired, $trading_assessment_isRequired, $financial_assessment_isRequired, $identity_verification_isRequired, $income_verification_isRequired, $address_verification_isRequired);

        if ($stmt->execute()) {
            unset($_SESSION['verified_email']);

            // Create Demo Trade Account for user and switch to demo
            $user_id = $conn->insert_id;
            $default_balance = PlatformService::getSetting('demo_account_balance', 10000);
            $account = TradeAccountService::createAccount($user_id, 'demo', $default_balance);
            TradeAccountService::switchCurrentAccount($user_id, $account['id_hash']);

            NotificationService::sendWelcomeNotification($user_id);
            return true;
        } else {
            Response::error('Registration failed. Email may already be in use.', 400);
        }
    }

    //Forgot Password 1
    public static function checkEmail(array $input) {
        $email = $input['email'] ?? null;
        if (!$email) {
            Response::error('No email provided', 400);
        }
    
        $conn = Database::getConnection();
        $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
    
        // If the user is found
        if ($result) {
            // Generate OTP
            $otp = OtpService::generateOtp($email);
            
            if (!MailService::sendOtpEmail($email, $otp, 'forgot-password')) {
                Response::error('Failed to send OTP email', 500);
            }
            Response::success('OTP Sent');
        } else {
            Response::error('Invalid email', 401);
        }

        Response::error('Something went wrong', 500);
    }

    //Forgot Password 2
    public static function createNewPassword($input, $action)
    {
        
        $otp = $input['otp'] ?? null;
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;
        if($action === 'confirm') {
            $validateOtp = OtpService::validateOtp($email, $otp);
            if($validateOtp) {
                Response::success('OTP validated');
            } else {
                Response::error('Invalid OTP', 401);
            }
        }

        $validateOtp = OtpService::validateOtp($email, $otp); //Validate again to block smart guys hahahahaah
        if(!$validateOtp) {
            Response::error('Invalid OTP', 401);
        }
        $conn = Database::getConnection();


        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $hashed, $email);

        if ($stmt->execute()) {
            Response::success(['message' => 'Password updated']);
        } else {
            Response::error('Password update failed', 500);
        }
    }

    public static function getUserById($id)
    {
        $conn = Database::getConnection();
        
        // Consider accounts offline if no heartbeat in last 2 minutes
        $two_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-2 minutes'));
        
        $stmt = $conn->prepare("
            SELECT 
                u.email, 
                u.fname, 
                u.lname, 
                u.phone, 
                u.ip_address, 
                u.reg_country, 
                u.country, 
                u.ref_code, 
                u.permissions, 
                u.status, 
                u.current_account, 
                u.personal_details_isRequired, 
                u.trading_assessment_isRequired, 
                u.financial_assessment_isRequired, 
                u.identity_verification_isRequired, 
                u.income_verification_isRequired, 
                u.address_verification_isRequired, 
                u.personal_details_isFilled, 
                u.trading_assessment_isFilled, 
                u.financial_assessment_isFilled, 
                u.identity_verification_isFilled, 
                u.income_verification_isFilled, 
                u.address_verification_isFilled, 
                u.date_registered,
                CASE 
                    WHEN EXISTS (
                        SELECT 1 
                        FROM accounts a 
                        WHERE a.user_id = u.id 
                        AND a.online_status IN ('online', 'away') 
                        AND a.last_heartbeat > ?
                    ) THEN 'online'
                    ELSE 'offline'
                END as online_status,
                (
                    SELECT MAX(a.last_heartbeat)
                    FROM accounts a
                    WHERE a.user_id = u.id
                ) as last_heartbeat,
                (
                    SELECT MAX(a.last_activity)
                    FROM accounts a
                    WHERE a.user_id = u.id
                ) as last_activity
            FROM users u
            WHERE u.id = ?
        ");
        $stmt->bind_param("ss", $two_minutes_ago, $id);
        $stmt->execute();
        if (!$stmt) {
            Response::error('Could not get user', 500);
        }

        $result = $stmt->get_result()->fetch_assoc();

        if (!$result) {
            Response::error('User not found', 404);
        }

        return $result;
    }

    // Admin methods
    public static function getAllUsers($role = 'user') {
        $conn = Database::getConnection();
        
        // Consider accounts offline if no heartbeat in last 2 minutes
        $two_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-2 minutes'));
        
        $stmt = $conn->prepare("
            SELECT 
                u.id, 
                u.fname, 
                u.lname, 
                u.email, 
                u.status, 
                u.reg_country, 
                u.permissions, 
                u.date_registered,
                CASE 
                    WHEN EXISTS (
                        SELECT 1 
                        FROM accounts a 
                        WHERE a.user_id = u.id 
                        AND a.online_status IN ('online', 'away') 
                        AND a.last_heartbeat > ?
                    ) THEN 'online'
                    ELSE 'offline'
                END as online_status,
                (
                    SELECT MAX(a.last_heartbeat)
                    FROM accounts a
                    WHERE a.user_id = u.id
                ) as last_heartbeat
            FROM users u
            WHERE u.role = ?
            ORDER BY last_heartbeat DESC
        ");
        $stmt->bind_param("ss", $two_minutes_ago, $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $users = [];
        
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        // Total users count
        $stmtTotal = $conn->query("SELECT COUNT(*) AS total_users FROM users WHERE role = '$role'");
        $total_users_count = intval($stmtTotal->fetch_assoc()['total_users']);

        Response::success([
            'total_users'     => $users,
            'total_users_count' => $total_users_count
        ]);
    }

    public static function deleteUser($user_id, $role = 'user') {
        $conn = Database::getConnection();

        // Check if user exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = ?");
        $stmt->bind_param("ss", $user_id, $role);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if (!$result) {
            Response::error('User not found', 404);
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("s", $user_id);

        if ($stmt->execute()) {
            Response::success("User deleted successfully.");
        } else {
            Response::error("Failed to delete user.", 500);
        }
    }

    public static function getAdminUserStats()
    {
        $conn = Database::getConnection();

        // Total users
        $stmtTotal = $conn->query("SELECT COUNT(*) AS total_users FROM users WHERE role = 'user'");
        $totalUsers = intval($stmtTotal->fetch_assoc()['total_users']);

        $onlineUsersCount = OnlineActivityService::getOnlineUsersCount();

        Response::success([
            'total_users'     => $totalUsers,
            'online_users'    => $onlineUsersCount
        ]);
    }

    public static function updateUserStatus($user_id, $input)
    {
        $conn = Database::getConnection();

        $status = $input['status'];
        if($status !== 'active' && $status !== 'suspended') {
            Response::error('Invalid input', 422);
        };

        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $user_id);

        if (!$stmt->execute()) {
            Response::error('Status update failed', 500);
        };

        if($status === 'active') {
            NotificationService::sendUserAccountReactivatedNotification($user_id);
        }
        if($status === 'suspended') {
            NotificationService::sendUserAccountSuspendedNotification($user_id);
        }

        return true;
    }

    public static function updateKycStatus($user_id, $input)
    {
        $conn = Database::getConnection();

        $status = $input['status'];
        $category = $input['category'];
        if($status !== 'reject') {
            Response::error('Invalid input', 422);
        };

        $status_value = 'false';

        $stmt = $conn->prepare("UPDATE users SET $category = ? WHERE id = ?");
        $stmt->bind_param("si", $status_value, $user_id);

        if (!$stmt->execute()) {
            Response::error('Status update failed', 500);
        };

        // $kycCategoryName = str_replace('-', ' ', preg_replace('/_[^_]+$/', '', $category));
        $kycCategoryCamel = str_replace('_isFilled', '', $category);
        if($status === 'reject') {
            NotificationService::sendKYCRejectedNotification($user_id, $kycCategoryCamel);
        }

        return true;
    }

    public static function updateKycConfig($user_id, $input)
    {
        $conn = Database::getConnection();

        $personal_details_isRequired = json_encode($input['personal_details_isRequired']); 
        $trading_assessment_isRequired= json_encode($input['trading_assessment_isRequired']);
        $financial_assessment_isRequired= json_encode($input['financial_assessment_isRequired']);
        $identity_verification_isRequired= json_encode($input['identity_verification_isRequired']);
        $income_verification_isRequired= json_encode($input['income_verification_isRequired']);
        $address_verification_isRequired= json_encode($input['address_verification_isRequired']);

        $stmt = $conn->prepare("UPDATE users SET personal_details_isRequired = ?, trading_assessment_isRequired = ?, financial_assessment_isRequired = ?, identity_verification_isRequired = ?, income_verification_isRequired = ?, address_verification_isRequired = ? WHERE id = ?");
        $stmt->bind_param("ssssssi", $personal_details_isRequired, $trading_assessment_isRequired, $financial_assessment_isRequired, $identity_verification_isRequired, $income_verification_isRequired, $address_verification_isRequired, $user_id);

        if (!$stmt->execute()) {
            Response::error('Status update failed', 500);
        };

        return true;
    }

    public static function updateUserProfile($user_id, $input)
    {
        $conn = Database::getConnection();

        $fname = $input['fname'];
        $lname = $input['lname'];
        $email = $input['email'];
        $phone = $input['phone'] ?? null;
        $permissions = $input['permissions'] ?? null;
        if($permissions !== null) {
            $permissions = json_encode($permissions);
        };

        // Check email exists for another user
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->bind_param("ss", $email, $user_id);
        $check->execute();
        
        $checkResult = $check->get_result();

        if ($checkResult->num_rows > 0) {
            Response::error('Email already in use by another user', 400);
        }

        $stmt = $conn->prepare("UPDATE users SET fname = ?, lname = ?, email = ?, phone = ?, permissions = ? WHERE id = ?");
        $stmt->bind_param("ssssss", $fname, $lname, $email, $phone, $permissions, $user_id);

        if ($stmt->execute()) {
            $userData = self::getUserById($user_id);
            Response::success($userData);
        } else {
            Response::error('Profile update failed', 500);
        }
    }

    public static function createAdmin($input)
    {
        $conn = Database::getConnection();

        $fname = $input['fname'];
        $lname = $input['lname'];
        $email = $input['email'];
        $phone = $input['phone'] ?? null;
        $password = password_hash($input['password'], PASSWORD_DEFAULT);
        $permissions = json_encode($input['permissions']);

        $role = 'admin';
        $ref_code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $referred_by = null;

        // Check email exists for another user
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        
        $checkResult = $check->get_result();

        if ($checkResult->num_rows > 0) {
            Response::error('Email already in use by another user', 400);
        }

        $stmt = $conn->prepare("INSERT INTO users (email, password, fname, lname, phone, role, permissions, ref_code, referred_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            Response::error('error: prepare failed', 500);
        }

        $stmt->bind_param("sssssssss", $email, $password, $fname, $lname, $phone, $role, $permissions, $ref_code, $referred_by);

        if ($stmt->execute()) {
            Response::success("Admin added successfully");
        } else {
            Response::error('Profile update failed', 500);
        }
    }

    public static function updateUserPassword($user_id, $oldPassword, $newPassword)
    {
        $conn = Database::getConnection();

        $stmt = $conn->prepare("SELECT id, password FROM users WHERE id = ?");
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        // Verify the password
        if (!password_verify($oldPassword, $user['password'])) {
            Response::error('Invalid password', 400);
            exit;
        }

        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("ss", $hashed, $user_id);

        if ($stmt->execute()) {
            Response::success(['message' => 'Password updated']);
        } else {
            Response::error('Password update failed', 500);
        }
    }

    public static function getUserReferralCount($user_id) {
        $conn = Database::getConnection();
    
        // Get the ref_code of the user
        $stmt = $conn->prepare("SELECT ref_code FROM users WHERE id = ?");
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
    
        if (!$result || !isset($result['ref_code'])) {
            Response::error('User not found or has no referral code', 404);
        }
    
        $ref_code = $result['ref_code'];
    
        // Count how many users used this ref_code
        $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE referred_by = ?");
        $countStmt->bind_param("s", $ref_code);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();

        return $countResult;
    }

    public static function searchUsersByEmail($searchTerm)
    {
        $conn = Database::getConnection();

        $likeTerm = "%" . $searchTerm . "%";

        // Consider accounts offline if no heartbeat in last 2 minutes
        $two_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-2 minutes'));

        $query = "
            SELECT 
                u.id, 
                u.fname, 
                u.lname, 
                u.email, 
                u.status, 
                u.reg_country, 
                u.permissions, 
                u.date_registered,
                CASE 
                    WHEN EXISTS (
                        SELECT 1 
                        FROM accounts a 
                        WHERE a.user_id = u.id 
                        AND a.online_status IN ('online', 'away') 
                        AND a.last_heartbeat > ?
                    ) THEN 'online'
                    ELSE 'offline'
                END as online_status,
                (
                    SELECT MAX(a.last_heartbeat)
                    FROM accounts a
                    WHERE a.user_id = u.id
                ) as last_heartbeat
            FROM users u
            WHERE (
                u.email LIKE ? OR
                u.fname LIKE ? OR
                u.lname LIKE ?
            )
            ORDER BY last_heartbeat DESC
        ";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            // Response::error("Prepare failed: " . $conn->error, 500);
            return;
        }

        $stmt->bind_param("ssss", $two_minutes_ago, $likeTerm, $likeTerm, $likeTerm);
        $stmt->execute();

        $result = $stmt->get_result();
        $users = [];

        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        Response::success($users);
    }

    public static function getClientIp(): string
    {
        $ipKeys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ipList = explode(',', $_SERVER[$key]);
                foreach ($ipList as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        // Fallback
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }


    public static function getIpData(): array
    {
        $clientIp = self::getClientIp();

        // Providers to try (order matters)
        $providers = [
            "https://freeipapi.com/api/json/$clientIp",
            // "https://ipapi.co/{$clientIp}/json/",
            // "https://ipinfo.io/{$clientIp}/json",
        ];

        foreach ($providers as $url) {
            $ch = curl_init();

            // Basic options; prefer IPv4 to avoid IPv6/DNS problems on some hosts
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 4,   // seconds to connect
                CURLOPT_TIMEOUT        => 7,   // total seconds
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MyApp/1.0; +https://example.com)',
                // CURLOPT_SSL_VERIFYPEER => true, // keep enabled in production
                // CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            // Execute
            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            $err      = curl_error($ch);
            $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $errno) {
                // Non-fatal: log and try next provider
                error_log("IpService: cURL error for {$url} — ({$errno}) {$err}");
                continue;
            }

            if ($status < 200 || $status >= 300) {
                // Non-200 responses (403, 429, 500, etc)
                error_log("IpService: HTTP status {$status} from {$url}");
                continue;
            }

            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("IpService: invalid JSON from {$url}: " . json_last_error_msg());
                continue;
            }

            // Got valid JSON — return it
            return $decoded ?? [];
        }

        // All providers failed — return empty array (safe fallback)
        return [];
    }
}

?>