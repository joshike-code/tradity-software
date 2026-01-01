<?php

ini_set('display_errors', 0);
ini_set('log_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class MailService
{
    private static function configureMailer(): PHPMailer
    {
        $keys = require __DIR__ . '/../config/keys.php';

        $mail = new PHPMailer(true);
        // Disable debug in production, enable only for testing
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->isSMTP();
        $mail->Host = $keys['phpmailer']['host'];
        $mail->SMTPAuth = $keys['phpmailer']['auth'];
        $mail->Username = $keys['phpmailer']['username'];
        $mail->Password = $keys['phpmailer']['password'];
        $mail->SMTPSecure = strtolower($keys['phpmailer']['security']) === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $keys['phpmailer']['port'];
        
        // Add SSL/TLS options for better compatibility
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom($keys['phpmailer']['from'], $keys['platform']['name']);
        $mail->isHTML(true);

        return $mail;
    }

    public static function sendOtpEmail(string $toEmail, string $otp, string $type = 'register'): bool
    {
        $platform = require __DIR__ . '/../config/keys.php';
        $platformName = $platform['platform']['name'];

        switch ($type) {
            case 'forgot-password':
                $subject = "Reset Your Password";
                $message = "We received a request to reset your password. <br><br>
                    Use the one-time password (OTP) below to proceed: <br><br>
                    <strong>$otp</strong> <br><br>
                    This code is valid for 30 minutes. If you didn't request a password reset, you can safely ignore this email.";
                break;
            
            case 'login':
                $subject = "Your Login Code";
                $message = "We received a login request for your $platformName account.<br><br>
                    Use the one-time password (OTP) below to proceed: <br><br>
                    <strong>$otp</strong> <br><br>
                    This code is valid for 30 minutes. If you didn't attempt to log in, please secure your account immediately.";
                break;
            
            case 'register':
            default:
                $subject = "Welcome to $platformName";
                $message = "Welcome aboard! To complete your registration, please use the one-time password (OTP) below.<br><br>
                    Your OTP is: <strong>$otp</strong>.<br>
                    This code will expire in 30 minutes.";
                break;
        }

        // Build email body
        $body = "<p style='mso-line-height-rule: exactly; font-family: tahoma, verdana, segoe, sans-serif; line-height: 24px; letter-spacing: 0; color: #181C25; font-size: 16px; margin: 0 0 12px;'> Hi Trader, </p>";
        $body .= "<p style='mso-line-height-rule: exactly; font-family: tahoma, verdana, segoe, sans-serif; line-height: 24px; letter-spacing: 0; color: #181C25; font-size: 16px; margin: 0 0 12px;'>$message</p>";

        return self::sendEmail($toEmail, $subject, $body);
    }

    public static function sendContactFormToAdmin(string $name, string $email, string $message): bool
    {
        $platform = require __DIR__ . '/../config/keys.php';
        $adminEmail = $platform['phpmailer']['admin'] ?? $platform['phpmailer']['from'];
        $subject = "New Contact Message from $name";

        $body = "<p><strong>Name:</strong> $name</p>
                <p><strong>Email:</strong> $email</p>
                <p><strong>Message:</strong><br>$message</p>";

        return self::sendEmail($adminEmail, $subject, $body);
    }

    public static function sendMailToSelectUser(string $message, string $subject, string $userName, string $toEmail): bool
    {
        // Build email body
        $body = "<p style='mso-line-height-rule: exactly; font-family: tahoma, verdana, segoe, sans-serif; line-height: 24px; letter-spacing: 0; color: #181C25; font-size: 16px; margin: 0 0 12px;'> Hi $userName, </p>";
        $body .= "<p style='mso-line-height-rule: exactly; font-family: tahoma, verdana, segoe, sans-serif; line-height: 24px; letter-spacing: 0; color: #181C25; font-size: 16px; margin: 0 0 12px;'>$message</p>";

        return self::sendEmail($toEmail, $subject, $body);
    }

    /**
     * Send credential change email (password/email change)
     * 
     * @param string $toEmail
     * @param string $userName
     * @param string $changeType Either 'email' or 'password'
     * @param string $changeUrl The URL with the encrypted token
     * @return bool
     */
    public static function sendCredentialChangeEmail(string $toEmail, string $userName, string $changeType, string $changeUrl): bool
    {
        $platform = require __DIR__ . '/../config/keys.php';
        $platformName = $platform['platform']['name'];

        $subject = $changeType === 'email' 
            ? "Email Change Request - $platformName"
            : "Password Change Request - $platformName";

        $actionText = $changeType === 'email' ? 'change your email address' : 'change your password';
        $securityNote = $changeType === 'email' 
            ? 'your current email address will be replaced'
            : 'your current password will be replaced';

        $message = "
            <p>Hello $userName,</p>
            <p>We received a request to $actionText for your $platformName account.</p>
            <p>To proceed with this change, please click the link below:</p>
            <div style='margin: 20px 0; text-align: center;'>
                <a href='$changeUrl' 
                   style='background-color: #007bff; color: white; padding: 12px 24px; 
                          text-decoration: none; border-radius: 4px; display: inline-block;'>
                    Confirm " . ucfirst($changeType) . " Change
                </a>
            </div>
            <p><strong>Important:</strong></p>
            <ul>
                <li>This link will expire in 1 hour for security reasons</li>
                <li>Once confirmed, $securityNote</li>
                <li>If you didn't request this change, please ignore this email</li>
            </ul>
            <p>For your security, do not share this link with anyone.</p>
            <p>If you have any questions, please contact our support team.</p>
            <p>Best regards,<br>The $platformName Team</p>
        ";

        return self::sendEmail($toEmail, $subject, $message);
    }

    /**
     * Send credential change confirmation email
     * 
     * @param string $toEmail
     * @param string $userName
     * @param string $changeType Either 'email' or 'password'
     * @param string $newValue New email address (only for email changes)
     * @return bool
     */
    public static function sendCredentialChangeConfirmation(string $toEmail, string $userName, string $changeType, string $newValue = ''): bool
    {
        $platform = require __DIR__ . '/../config/keys.php';
        $platformName = $platform['platform']['name'];

        $subject = $changeType === 'email' 
            ? "Email Address Updated - $platformName"
            : "Password Updated - $platformName";

        if ($changeType === 'email') {
            $message = "
                <p>Hello $userName,</p>
                <p>Your email address has been successfully updated to: <strong>$newValue</strong></p>
                <p>This change was made on " . date('F j, Y \a\t g:i A T') . "</p>
                <p>If you didn't make this change, please contact our support team immediately.</p>
                <p>Best regards,<br>The $platformName Team</p>
            ";
        } else {
            $message = "
                <p>Hello $userName,</p>
                <p>Your password has been successfully updated.</p>
                <p>This change was made on " . date('F j, Y \a\t g:i A T') . "</p>
                <p>If you didn't make this change, please contact our support team immediately.</p>
                <p><strong>Security tip:</strong> Keep your password safe and don't share it with anyone.</p>
                <p>Best regards,<br>The $platformName Team</p>
            ";
        }

        return self::sendEmail($toEmail, $subject, $message);
    }

    /**
     * Send account notification email
     * 
     * @param string $toEmail
     * @param string $userName
     * @param string $type Notification type: 'info', 'success', 'warning', 'alert'
     * @param string $title
     * @param string $message
     * @param string|null $cta Call-to-action text
     * @param string|null $ctaLink Call-to-action link
     * @return bool
     */
    public static function sendAccountNotification(
        string $toEmail,
        string $userName,
        string $type,
        string $title,
        string $message,
        ?string $cta = null,
        ?string $ctaLink = null
    ): bool {
        $platform = require __DIR__ . '/../config/keys.php';
        $platformName = $platform['platform']['name'];
        $platformUrl = $platform['platform']['url'];
        $themeColor = '#' . ($platform['platform']['theme_color'] ?? '006CBF');

        // $message = 'Test mail message to you so that you can see what a quite long message looks like and appreciate all we do here my friend.';
        
        // Build email body
        $body = "<p style='mso-line-height-rule: exactly; font-family: tahoma, verdana, segoe, sans-serif; line-height: 24px; letter-spacing: 0; color: #181C25; font-size: 16px; margin: 0 0 12px;'> Hi $userName, </p>";
        $body .= "<p style='mso-line-height-rule: exactly; font-family: tahoma, verdana, segoe, sans-serif; line-height: 24px; letter-spacing: 0; color: #181C25; font-size: 16px; margin: 0 0 12px;'>$message</p>";
        
        // Add CTA button if provided
        if ($cta && $ctaLink) {
            // Make link absolute if it's relative
            $fullLink = (strpos($ctaLink, 'http') === 0) ? $ctaLink : "https://{$platformUrl}/{$ctaLink}";

            $buttonColor = $themeColor;
            
            $body .= "
                <div style='margin: 20px 0; text-align: center;'>
                    <a href='$fullLink' 
                       style='background-color: $buttonColor; color: white; padding: 12px 24px; 
                              text-decoration: none; border-radius: 4px; display: inline-block;'>
                        $cta
                    </a>
                </div>
            ";
        }
        
        $body .= "<p>Best regards,<br>The $platformName Team</p>";
        
        return self::sendEmail($toEmail, $title, $body);
    }

    /**
     * Send admin notification (plain text email)
     * 
     * @param int $userId
     * @param string $userName
     * @param string $userEmail
     * @param string $type
     * @param string $title
     * @param string $message
     * @return bool
     */
    public static function sendAdminNotification(
        int $userId,
        string $userName,
        string $userEmail,
        string $type,
        string $title,
        string $message
    ): bool {
        try {
            $keys = require __DIR__ . '/../config/keys.php';
            $adminEmail = $keys['phpmailer']['admin'] ?? null;
            
            if (empty($adminEmail)) {
                return false; // No admin email configured
            }
            
            // Skip sending to admin for low-priority notifications
            $skipTypes = ['info'];
            if (in_array($type, $skipTypes)) {
                return false;
            }
            
            $platformName = $keys['platform']['name'];
            
            // Admin email subject with emoji
            $typeEmoji = [
                'success' => '✅',
                'warning' => '⚠️',
                'alert' => '🚨',
                'info' => 'ℹ️'
            ];
            $emoji = $typeEmoji[$type] ?? '📧';
            
            $subject = "$emoji [$type] $title - User #$userId";
            
            // Plain text email body for admin
            $body = "===== $platformName - Admin Notification =====\n\n";
            $body .= "NOTIFICATION TYPE: " . strtoupper($type) . "\n";
            $body .= "TITLE: $title\n\n";
            $body .= "USER DETAILS:\n";
            $body .= "- User ID: $userId\n";
            $body .= "- Name: $userName\n";
            $body .= "- Email: $userEmail\n\n";
            $body .= "MESSAGE SENT TO USER:\n";
            $body .= strip_tags($message) . "\n\n";
            $body .= "TIMESTAMP: " . date('Y-m-d H:i:s T') . "\n";
            $body .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n\n";
            $body .= "=============================================\n";
            $body .= "This is an automated notification. Do not reply.";
            
            // Send plain text email without template
            return self::sendEmail($adminEmail, $subject, $body, false);
            
        } catch (Exception $e) {
            error_log("MailService::sendAdminNotification - " . $e->getMessage());
            return false;
        }
    }

    private static function sendEmail(string $toEmail, string $subject, string $htmlMessage, bool $useTemplate = true): bool
    {
        try {
            $keys = require __DIR__ . '/../config/keys.php';
            $platformName = $keys['platform']['name'];
            $platformUrl = $keys['platform']['url'];
            $themeColor = '#' . ($platform['platform']['theme_color'] ?? '006CBF');
            $mainLogo = $keys['platform']['main_logo'];
            $logoLink = 'https://' . $platformUrl . '/app/backend/' . $mainLogo;
            // $logoLink = 'https://*******.live/tradity_logo.png'; // FOR LOCAL TESTING ONLY, REMOVE IN PRODUCTION
            
            $mail = self::configureMailer();
            $mail->addAddress($toEmail);
            $mail->Subject = $subject;
            
            // If not using template, send as plain text
            if (!$useTemplate) {
                $mail->isHTML(false);
                $mail->Body = $htmlMessage;
                $mail->send();
                return true;
            }

            // Load email template
            $template = file_get_contents(__DIR__ . '/../templates/mail.html');
            $warningText = 'Exercise caution when trading. Trading involves risks and may not be suitable for everyone. Ensure you understand the risks involved before engaging in trading activities.';
            
            // Define all placeholders
            $replacements = [
                '{{header}}' => $subject,
                '{{platform_name}}' => $platformName,
                '{{platform_url}}' => 'https://' . $platformUrl,
                '{{subject}}' => $subject,
                '{{msg}}' => $htmlMessage,
                '{{warning_text}}' => $warningText,
                '{{details_list}}' => '',
                '{{address}}' => $keys['platform']['address'],
                '{{licensed_by}}' => $keys['platform']['licensed_by'],
                '{{logo_link}}' => $logoLink,
                '{{theme_color}}' => $themeColor,
                '{{whatsapp_number}}' => 'https://wa.me/' .  $keys['platform']['whatsapp_number'],
                '{{live_chat}}' => 'https://' . $platformUrl . '/support',
                '{{help_centre}}' => 'https://' . $platformUrl . '/help',
                '{{terms}}' => 'https://' . $platformUrl . '/terms',
                '{{privacy}}' => 'https://' . $platformUrl . '/privacy',
                
                // Social Media Links (customize these in config/keys.php or hardcode)
                '{{facebook_link}}' => 'https://facebook.com/' . ($keys['social']['facebook'] ?? 'yourpage'),
                '{{twitter_link}}' => 'https://twitter.com/' . ($keys['social']['twitter'] ?? 'yourhandle'),
                '{{instagram_link}}' => 'https://instagram.com/' . ($keys['social']['instagram'] ?? 'yourpage'),
                '{{linkedin_link}}' => 'https://linkedin.com/company/' . ($keys['social']['linkedin'] ?? 'yourcompany'),
                
                // Footer Links
                '{{unsubscribe_link}}' => 'https://' . $platformUrl . '/app/account/settings',
            ];

            // Add preview text (hidden snippet shown in email client preview)
            $previewText = '<div style="display: none; max-height: 0px; overflow: hidden;">' .
                strip_tags($htmlMessage) .
                str_repeat('&nbsp;&zwnj;', 100) . '</div>';

            $detailsList = "<table cellspacing='0' role='presentation' width='100%' cellpadding='0' bgcolor='#f6f7f8' style='mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; background-color: #f6f7f8; border-radius: 16px;'>
                <tbody><tr>
                <td align='left' class='es-text-4105' style='margin: 0; padding: 20px;'><h6 style='font-family: tahoma, verdana, segoe, sans-serif; mso-line-height-rule: exactly; letter-spacing: 0px; font-size: 16px; font-style: normal; font-weight: normal; line-height: 22.4px; color: #181c25; margin: 0;'><strong>Key changes</strong></h6>
                <ul style='font-family: tahoma, verdana, segoe, sans-serif; margin-top: 10px; margin-bottom: 10px; padding: 0px 0px 0px 40px;'>
                <li style='color: #181C25; font-size: 16px; line-height: 22.4px; margin: 0px 0px 10px;'><h6 style='font-family: tahoma, verdana, segoe, sans-serif; mso-line-height-rule: exactly; letter-spacing: 0px; font-size: 16px; font-style: normal; font-weight: normal; line-height: 22.4px; color: #181c25; margin: 0;'>Pricing may be unavailable or delayed</h6></li>
                <li style='color: #181C25; font-size: 16px; line-height: 22.4px; margin: 0px 0px 10px;'><h6 style='font-family: tahoma, verdana, segoe, sans-serif; mso-line-height-rule: exactly; letter-spacing: 0px; font-size: 16px; font-style: normal; font-weight: normal; line-height: 22.4px; color: #181c25; margin: 0;'>Orders may be interrupted or rejected</h6></li>
                <li style='color: #181C25; font-size: 16px; line-height: 22.4px; margin: 0px 0px 10px;'><h6 style='font-family: tahoma, verdana, segoe, sans-serif; mso-line-height-rule: exactly; letter-spacing: 0px; font-size: 16px; font-style: normal; font-weight: normal; line-height: 22.4px; color: #181c25; margin: 0;'>Trading may be suspended on selected instruments until liquidity is restored</h6></li></ul></td>
                </tr>
            </tbody></table>";

            $template = $previewText . $template;
            $template = str_replace(array_keys($replacements), array_values($replacements), $template);

            $mail->Body = $template;
            $mail->AltBody = strip_tags($htmlMessage);

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mailer Error: {$e->getMessage()}");
            return false;
        }
    }
}
?>