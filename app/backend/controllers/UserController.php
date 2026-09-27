<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once __DIR__ . '/../services/UserService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';
require_once __DIR__ . '/../services/TradeAccountService.php';

class UserController {

    public static function getUser($user_id, $role) {
        $userData = UserService::getUserById($user_id);
        if($userData['status'] === 'suspended') {
            Response::error('User suspended', 400);
        }
        if($role === 'user') {
            $account = TradeAccountService::getAccountById($user_id, $userData['current_account']);
            if($account['status'] === 'suspended') {
                Response::error('Trade account suspended', 400);
            }
        } else {
            $account = null;
        }
        Response::success(['user' => $userData, 'trade_account' => $account]);
    }

    public static function getUserToAdmin($user_id) {
        $userData = UserService::getUserById($user_id);
        $accounts = TradeAccountService::getUserAccounts($user_id);
        Response::success(['user' => $userData, 'trade_accounts' => $accounts]);
    }

    public static function getUsers() {
        UserService::getAllUsers();
    }

    public static function deleteUser($user_id) {
        UserService::deleteUser($user_id);
    }

    public static function getAdmin($user_id) {
        $userData = UserService::getUserById($user_id);
        Response::success($userData);
    }

    public static function getAdmins() {
        UserService::getAllUsers('admin');
    }

    public static function deleteAdmin($user_id) {
        UserService::deleteUser($user_id, 'admin');
    }

    public static function loginAsUser($user_id) {
        UserService::adminLoginAsUser($user_id);
    }

    public static function searchUsersByEmail($email) {
        UserService::searchUsersByEmail($email);
    }

    public static function getReferralCount($user_id) {
        $count = UserService::getUserReferralCount($user_id);
        Response::success(['referrals' => $count['count']]);
    }

    public static function checkEmail() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'email'  => 'required|email'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        UserService::checkEmail($input);
    }

    public static function createNewPassword($action) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Convert OTP to string (in case JSON decoded it as integer)
        if (isset($input['otp'])) {
            $input['otp'] = (string)$input['otp'];
        }
        
        // Validate Input
        $rules = [
            'otp'  => 'required|string',
            'password'  => 'required|password',
            'email'  => 'required|email'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        UserService::createNewPassword($input, $action);
    }

    public static function updateUserStatus($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'status'  => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $response = UserService::updateUserStatus($user_id, $input);
        if($response) {
            self::getUserToAdmin($user_id);
        };
    }

    public static function updateKycStatus($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'status'  => 'required|string',
            'category' => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $response = UserService::updateKycStatus($user_id, $input);
        if($response) {
            self::getUserToAdmin($user_id);
        };
    }

    public static function updateKycConfig($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'personal_details_isRequired' => 'required|boolean', 
            'trading_assessment_isRequired' => 'required|boolean', 
            'financial_assessment_isRequired' => 'required|boolean', 
            'identity_verification_isRequired' => 'required|boolean', 
            'income_verification_isRequired' => 'required|boolean', 
            'address_verification_isRequired' => 'required|boolean'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $response = UserService::updateKycConfig($user_id, $input);
        if($response) {
            self::getUserToAdmin($user_id);
        };
    }

    public static function updateProfile($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'fname'  => 'required|string',
            'lname'  => 'required|string',
            'email'  => 'required|email'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        UserService::updateUserProfile($user_id, $input);
    }

    public static function createNewUser() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'fname'  => 'required|string',
            'lname'  => 'required|string',
            'email'  => 'required|email',
            'country'  => 'required|string',
            'password'  => 'required|password',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        UserService::createNewUser($input);
    }

    public static function createUsersFromCsv() {
        $users = [];

        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $content = file_get_contents($_FILES['csv_file']['tmp_name']);
            $users = self::parseCsvContent($content);
        } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $content = file_get_contents($_FILES['file']['tmp_name']);
            $users = self::parseCsvContent($content);
        } else {
            $rawBody = file_get_contents("php://input");
            $rawInput = json_decode($rawBody, true);

            if (isset($rawInput['users']) && is_array($rawInput['users'])) {
                $users = $rawInput['users'];
            } elseif (isset($rawInput['csv_content']) && is_string($rawInput['csv_content'])) {
                $users = self::parseCsvContent($rawInput['csv_content']);
            } elseif (isset($rawInput['csv_file']) && is_string($rawInput['csv_file'])) {
                $fileData = $rawInput['csv_file'];
                if (preg_match('/^data:([^;]+);base64,(.*)$/', $fileData, $matches)) {
                    $users = self::parseCsvContent(base64_decode($matches[2]));
                } else {
                    $users = self::parseCsvContent($fileData);
                }
            } elseif (!empty($rawBody) && strpos($rawBody, ',') !== false) {
                $users = self::parseCsvContent($rawBody);
            } else {
                Response::error('No CSV file or user data received', 400);
            }
        }

        if (empty($users)) {
            Response::error('No valid user records found in CSV', 400);
        }

        UserService::createUsersFromCsv($users);
    }

    public static function parseCsvContent(string $content): array {
        // Strip BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (trim($content) === '') {
            Response::error('Uploaded CSV file is empty', 400);
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $headerRow = fgetcsv($stream);
        if (!$headerRow) {
            fclose($stream);
            Response::error('Could not parse CSV header row', 400);
        }

        $headerMap = [];
        foreach ($headerRow as $index => $colName) {
            $cleaned = strtolower(trim(str_replace([' ', '_', '-', '.', '"', "'"], '', (string)$colName)));
            if (in_array($cleaned, ['fname', 'firstname', 'first', 'givenname'])) {
                $headerMap[$index] = 'fname';
            } elseif (in_array($cleaned, ['lname', 'lastname', 'last', 'surname', 'familyname'])) {
                $headerMap[$index] = 'lname';
            } elseif (in_array($cleaned, ['email', 'emailaddress', 'useremail', 'mail'])) {
                $headerMap[$index] = 'email';
            } elseif (in_array($cleaned, ['country', 'regcountry', 'countrycode', 'countryofregistration'])) {
                $headerMap[$index] = 'country';
            } elseif (in_array($cleaned, ['password', 'pass', 'newpassword', 'userpassword'])) {
                $headerMap[$index] = 'password';
            }
        }

        $requiredCols = ['fname', 'lname', 'email', 'country', 'password'];
        $mappedCols = array_values($headerMap);
        $missingCols = array_diff($requiredCols, $mappedCols);

        if (!empty($missingCols)) {
            fclose($stream);
            Response::error('CSV header is missing required column(s): ' . implode(', ', $missingCols) . '. Expected: First Name, Last Name, Email, Country, Password.', 422);
        }

        $rows = [];
        $rowNumber = 1;
        while (($row = fgetcsv($stream)) !== false) {
            $rowNumber++;
            $nonEmpty = array_filter($row, fn($cell) => trim((string)$cell) !== '');
            if (empty($nonEmpty)) {
                continue;
            }

            $user = ['_row' => $rowNumber];
            foreach ($headerMap as $idx => $key) {
                $user[$key] = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
            }
            $rows[] = $user;
        }

        fclose($stream);
        return $rows;
    }

    public static function updateAdmin($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'fname'  => 'required|string',
            'lname'  => 'required|string',
            'email'  => 'required|email',
            'permissions'  => 'required|permission',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        UserService::updateUserProfile($user_id, $input);
    }

    public static function createAdmin() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'fname'  => 'required|string',
            'lname'  => 'required|string',
            'email'  => 'required|email',
            'password'  => 'required|password',
            'permissions'  => 'required|permission',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        UserService::createAdmin($input);
    }
    
    public static function updatePassword($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'oldPassword'  => 'required|stringOrNumeric',
            'newPassword'  => 'required|password',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $oldPassword = $input['oldPassword'] ?? null;
        $newPassword = $input['newPassword'] ?? null;
        
        UserService::updateUserPassword($user_id, $oldPassword, $newPassword);
    }

    public static function preLoginUser() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'email'  => 'required|email'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $email = $input['email'];

        UserService::preLoginUser($email);
    }

    public static function loginWithOtp() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Convert OTP to string (in case JSON decoded it as integer)
        if (isset($input['otp'])) {
            $input['otp'] = (string)$input['otp'];
        }
        
        // Validate Input
        $rules = [
            'email'  => 'required|email',
            'otp'  => 'required|string',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $email = $input['email'];
        $otp = $input['otp'];

        UserService::loginWithOtp($email, $otp);
    }

    public static function loginWithPassword() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'email'  => 'required|email',
            'password'  => 'required|stringOrNumeric'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $email = $input['email'];
        $password = $input['password'];

        UserService::loginWithPassword($email, $password);
    }
    
    // For Demo testing version of this software PLEASE DELETE LATER
    public static function loginAsDemoAdmin() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'email'  => 'required|email',
            'password'  => 'required|stringOrNumeric'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $email = $input['email'];
        $password = $input['password'];

        UserService::loginAsDemoAdmin($email, $password);
    }

    public static function preRegister() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'email'  => 'required|email'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        UserService::preRegisterUser($input);
    }

    public static function verifyRegisterUser() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Convert OTP to string (in case JSON decoded it as integer)
        if (isset($input['otp'])) {
            $input['otp'] = (string)$input['otp'];
        }
        
        // Validate Input
        $rules = [
            'email'  => 'required|email',
            'otp'  => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        UserService::verifyRegisterUser($input);
    }

    public static function register() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Convert OTP to string (in case JSON decoded it as integer)
        if (isset($input['otp'])) {
            $input['otp'] = (string)$input['otp'];
        }
        
        // Validate Input
        $rules = [
            'email'  => 'required|email',
            'password'  => 'required|password',
            'otp'  => 'required|string'
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        $type = '';

        $registerStatus = UserService::registerUser($input);
        if($registerStatus) {
            UserService::loginUser($email, $password, $type);
        }
    }
}

