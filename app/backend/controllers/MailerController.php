<?php
session_start();

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/MailService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class MailerController {

    public static function mailAdmin() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'name'  => 'required|string',
            'email'  => 'required|email',
            'message'  => 'required|string',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        $name = $input['name'] ?? '';
        $email = $input['email'] ?? '';
        $message = $input['message'] ?? '';

        MailService::sendContactFormToAdmin($name, $email, $message);
    }

    public static function mailUser($user_id) {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);
        
        // Validate Input
        $rules = [
            'subject'  => 'required|string',
            'body'  => 'required|string',
            'send_mail'  => 'required|boolean',
            'send_in_app'  => 'required|boolean',
        ];
        $input_errors = Validator::validate($input, $rules);
        if(!empty($input_errors)) {
            Response::error(['validation_errors' => $input_errors], 422);
        }

        if($input['send_in_app']) {
            // Save in-app notification
            $status = NotificationService::saveInAppNotification(
                $user_id,
                'admin_message',
                $input['subject'],
                $input['body'],
                null,
                null
            );

            if(!$status) {
                Response::error('Failed to save in-app notification', 500);
            }
        }   

        if($input['send_mail']) {
            // Send email
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT email, fname, lname FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
            }
            
            $user = $result->fetch_assoc();
            $email = $user['email'];
            $userName = trim(($user['fname'] ?? '') . ' ' . ($user['lname'] ?? '')) ?: 'Trader';
            $status = MailService::sendMailToSelectUser($input['body'], $input['subject'], $userName, $email);
            if(!$status) {
                if($input['send_in_app']) {
                    Response::error('In app messages sent but failed to send email', 500);
                }
                Response::error('Failed to send email', 500);
            }
        }

        Response::success('Message sent successfully');
    }
}

