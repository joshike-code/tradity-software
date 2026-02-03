<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../utility/EncryptionService.php';
require_once __DIR__ . '/../services/MailService.php';

class CredentialService
{
    /**
     * Request credential change (email or password)
     * 
     * @param int $user_id
     * @param string $change_type Either 'email' or 'password'
     * @return array
     */
    public static function requestCredentialChange(int $user_id, string $change_type)
    {
        try {
            // Validate change type
            if (!in_array($change_type, ['email', 'password'])) {
                Response::error('Invalid change type', 400);
            }

            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT email, fname, lname FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
            }

            $user = $result->fetch_assoc();
            $userName = trim($user['fname'] . ' ' . $user['lname']);
            $email = $user['email'];

            // Generate encrypted token
            $token = EncryptionService::generateCredentialToken($user_id, $change_type);
            
            // Create the change URL (adjust the base URL as needed)
            $keys = require __DIR__ . '/../config/keys.php';
            $baseUrl = $keys['platform']['url'] ?? 'http://localhost';
            $host = $keys['system']['host_link'] ?? '';
            $changeUrl = $baseUrl . $host . "api/change_credential?action=verify&type=$change_type&token=" . urlencode($token);

            // Send email
            $mailSent = MailService::sendCredentialChangeEmail($email, $userName, $change_type, $changeUrl);

            if ($mailSent) {
                Response::success('Change request email sent successfully');
            } else {
                Response::error('Failed to send email', 500);
            }

        } catch (Exception $e) {
            error_log("CredentialService::requestCredentialChange - " . $e->getMessage());
            Response::error('An error occurred while processing your request', 500);
        }
    }

    /**
     * Verify the credential change token
     * 
     * @param string $token
     * @return array
     */
    public static function verifyCredentialChangeToken(string $token): array
    {
        try {
            $validation = EncryptionService::validateCredentialToken($token);
            
            if (!$validation['valid']) {
                return [
                    'success' => false, 
                    'message' => $validation['error'],
                    'expired' => $validation['expired']
                ];
            }

            // Verify user still exists
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT email, fname, lname FROM users WHERE id = ?");
            $stmt->bind_param("i", $validation['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                return ['success' => false, 'message' => 'User not found', 'expired' => false];
            }

            $user = $result->fetch_assoc();

            return [
                'success' => true,
                'user_id' => $validation['user_id'],
                'type' => $validation['type'],
                'user_email' => $user['email'],
                'user_name' => trim($user['fname'] . ' ' . $user['lname'])
            ];

        } catch (Exception $e) {
            error_log("CredentialService::verifyCredentialChangeToken - " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while verifying the token'];
        }
    }

    /**
     * Update user credentials (email or password)
     * 
     * @param string $token
     * @param array $data ['email' => string] or ['password' => string]
     * @return array
     */
    public static function updateCredentials(string $token, array $data): array
    {
        try {
            // Verify token first
            $tokenValidation = self::verifyCredentialChangeToken($token);
            if (!$tokenValidation['success']) {
                return $tokenValidation;
            }

            $user_id = $tokenValidation['user_id'];
            $change_type = $tokenValidation['type'];
            $current_email = $tokenValidation['user_email'];
            $user_name = $tokenValidation['user_name'];

            // Validate that we have the right data for the change type
            if ($change_type === 'email' && !isset($data['email'])) {
                return ['success' => false, 'message' => 'New email address is required'];
            }

            if ($change_type === 'password' && !isset($data['password'])) {
                return ['success' => false, 'message' => 'New password is required'];
            }

            $conn = Database::getConnection();

            if ($change_type === 'email') {
                $new_email = $data['email'];
                
                // Check if email is already in use
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->bind_param("si", $new_email, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    return ['success' => false, 'message' => 'This email address is already in use'];
                }

                // Update email
                $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
                $stmt->bind_param("si", $new_email, $user_id);
                
                if ($stmt->execute()) {
                    // Send confirmation emails to both old and new email addresses
                    MailService::sendCredentialChangeConfirmation($current_email, $user_name, 'email', $new_email);
                    MailService::sendCredentialChangeConfirmation($new_email, $user_name, 'email', $new_email);
                    
                    return [
                        'success' => true, 
                        'message' => 'Email address updated successfully',
                        'new_email' => $new_email
                    ];
                } else {
                    return ['success' => false, 'message' => 'Failed to update email address'];
                }

            } else { // password change
                $new_password = $data['password'];
                
                // Hash the password (adjust hashing method as needed)
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update password
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $user_id);
                
                if ($stmt->execute()) {
                    // Send confirmation email
                    MailService::sendCredentialChangeConfirmation($current_email, $user_name, 'password');
                    
                    return [
                        'success' => true, 
                        'message' => 'Password updated successfully'
                    ];
                } else {
                    return ['success' => false, 'message' => 'Failed to update password'];
                }
            }

        } catch (Exception $e) {
            error_log("CredentialService::updateCredentials - " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while updating credentials'];
        }
    }

    /**
     * Get user's current credentials (for verification)
     * 
     * @param int $user_id
     * @return array
     */
    public static function getUserCredentials(int $user_id): array
    {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT email, fname, lname, acc_type FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                return ['success' => false, 'message' => 'User not found'];
            }

            $user = $result->fetch_assoc();
            
            return [
                'success' => true,
                'email' => $user['email'],
                'fname' => $user['fname'],
                'lname' => $user['lname'],
                'acc_type' => $user['acc_type']
            ];

        } catch (Exception $e) {
            error_log("CredentialService::getUserCredentials - " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while fetching user data'];
        }
    }
}
