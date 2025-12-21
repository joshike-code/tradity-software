<?php

ini_set('display_errors', 0);
ini_set('log_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class NotificationService
{
    /**
     * Send notification to user (email + in-app)
     * 
     * @param int $userId User ID
     * @param string $type Notification type: 'info', 'success', 'warning', 'alert'
     * @param string $title Notification title
     * @param string $message Notification message
     * @param bool $sendEmail Whether to send email notification
     * @param string|null $cta Call-to-action text
     * @param string|null $ctaLink Call-to-action link
     * @return array
     */
    public static function sendNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        bool $sendEmail = true,
        ?string $cta = null,
        ?string $ctaLink = null
    ): array {
        try {
            // Save in-app notification to database
            $inAppResult = self::saveInAppNotification($userId, $type, $title, $message, $cta, $ctaLink);
            
            // Send email if requested
            $emailResult = true;
            if ($sendEmail) {
                $emailResult = self::sendEmailNotification($userId, $type, $title, $message, $cta, $ctaLink);
            }
            
            return [
                'success' => $inAppResult && $emailResult,
                'in_app' => $inAppResult,
                'email' => $emailResult
            ];
            
        } catch (Exception $e) {
            error_log("NotificationService::sendNotification - " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Queue email notification for asynchronous sending (non-blocking)
     * Emails are saved to queue and processed by cron job (cron/process_email_queue.php)
     * No manual commands needed - just set up cPanel cron job once
     */
    private static function queueEmailNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $cta,
        ?string $ctaLink
    ): void {
        try {
            // Prepare data for cron processing
            $data = json_encode([
                'userId' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'cta' => $cta,
                'ctaLink' => $ctaLink
            ]);
            
            // Save to queue file - cron will process it automatically
            $queueDir = __DIR__ . '/../queue/';
            
            if (!is_dir($queueDir)) {
                mkdir($queueDir, 0755, true);
            }
            
            $queueFile = $queueDir . 'email_queue_' . time() . '_' . uniqid() . '.json';
            file_put_contents($queueFile, $data);
            
            // That's it! The cron job will pick it up and send the emails
            
        } catch (Exception $e) {
            error_log("NotificationService::queueEmailNotification - " . $e->getMessage());
            // Don't throw - email failure shouldn't break the main process
        }
    }
    
    /**
     * Save in-app notification to database
     */
    public static function saveInAppNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $cta,
        ?string $ctaLink
    ): bool {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, cta, cta_link, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
            ");
            
            $stmt->bind_param("isssss", $userId, $type, $title, $message, $cta, $ctaLink);
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("NotificationService::saveInAppNotification - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send email notification (queues for background processing - NON-BLOCKING)
     */
    private static function sendEmailNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $cta,
        ?string $ctaLink
    ): bool {
        // Queue the email for background processing (instant return, no blocking)
        self::queueEmailNotification($userId, $type, $title, $message, $cta, $ctaLink);
        
        // Return true immediately - actual email sending happens in background via cron
        return true;
    }
    
    /**
     * Get user notifications
     * 
     * @param int $userId User ID
     * @param bool $unreadOnly If true, returns only unread notifications (default)
     * @param int $limit Maximum number of notifications to return
     * @return array Array of notifications
     */
    public static function getUserNotifications(int $userId, bool $unreadOnly = true, int $limit = 50): array
    {
        try {
            $conn = Database::getConnection();
            
            $whereClause = $unreadOnly 
                ? "WHERE user_id = ? AND is_read = 0" 
                : "WHERE user_id = ?";
            
            $stmt = $conn->prepare("
                SELECT id, type, title, message, cta, cta_link, is_read, created_at
                FROM notifications
                $whereClause
                ORDER BY created_at DESC
                LIMIT ?
            ");
            
            $stmt->bind_param("ii", $userId, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $notifications = [];
            while ($row = $result->fetch_assoc()) {
                $notifications[] = [
                    'id' => (int)$row['id'],
                    'type' => $row['type'],
                    'title' => $row['title'],
                    'msg' => $row['message'],
                    'cta' => $row['cta'],
                    'cta_link' => $row['cta_link'],
                    'is_read' => (bool)$row['is_read'],
                    'created_at' => $row['created_at']
                ];
            }
            
            return $notifications;
            
        } catch (Exception $e) {
            error_log("NotificationService::getUserNotifications - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark notification as read
     */
    public static function markAsRead(array $input, int $userId): bool
    {
        try {

            $notificationId = $input['notification_id'];
            $conn = Database::getConnection();
            $stmt = $conn->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE id = ? AND user_id = ?
            ");
            
            $stmt->bind_param("ii", $notificationId, $userId);
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("NotificationService::markAsRead - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark all notifications as read
     */
    public static function markAllAsRead(int $userId): bool
    {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE user_id = ? AND is_read = 0
            ");
            
            $stmt->bind_param("i", $userId);
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("NotificationService::markAllAsRead - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get unread notification count
     */
    public static function getUnreadCount(int $userId): int
    {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count
                FROM notifications
                WHERE user_id = ? AND is_read = 0
            ");
            
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return (int)$row['count'];
            
        } catch (Exception $e) {
            error_log("NotificationService::getUnreadCount - " . $e->getMessage());
            return 0;
        }
    }
    
    // ==================== PRESET NOTIFICATION METHODS ====================
    // NOTE: All methods below are safe to call without try-catch
    // They delegate to sendNotification() which handles all errors gracefully
    
    /**
     * Send welcome notification on registration
     */
    public static function sendWelcomeNotification(int $userId): void
    {
        $title = "Welcome to Tradity!";
        $message = "Start your trading journey with confidence. Fund your account, practice with our demo mode, and explore the markets. Our platform offers real-time charts, advanced tools, and 24/7 support to help you succeed.";
        
        self::sendNotification(
            $userId,
            'success',
            $title,
            $message,
            true,
            'Get Started',
            'trade'
        );
    }
    
    /**
     * Send deposit pending notification
     */
    public static function sendDepositPendingNotification(int $userId, float $amount, string $method): void
    {
        $title = "Deposit Request Received";
        $message = "Your deposit of $" . number_format($amount, 2) . " via $method has been received and is pending approval. We'll notify you once it's processed.";
        
        self::sendNotification(
            $userId,
            'info',
            $title,
            $message,
            true,
            'View Transactions',
            'transaction-history'
        );
    }
    
    /**
     * Send deposit approved notification
     */
    public static function sendDepositApprovedNotification(int $userId, float $amount, string $method): void
    {
        $title = "Deposit Approved!";
        $message = "Great news! Your deposit of $" . number_format($amount, 2) . " via $method has been approved and added to your account. You can start trading now!";
        
        self::sendNotification(
            $userId,
            'success',
            $title,
            $message,
            true,
            'Start Trading',
            'trade'
        );
    }
    
    /**
     * Send deposit rejected notification
     */
    public static function sendDepositRejectedNotification(int $userId, float $amount, string $reason = ''): void
    {
        $title = "Deposit Failed";
        $message = "Your deposit of $" . number_format($amount, 2) . " could not be completed.";
        if (!empty($reason)) {
            $message .= " Reason: $reason";
        }
        $message .= " Please contact support if you have questions.";
        
        self::sendNotification(
            $userId,
            'alert',
            $title,
            $message,
            true,
            'View Transactions',
            'transaction-history'
        );
    }
    
    /**
     * Send withdrawal pending notification
     */
    public static function sendWithdrawalPendingNotification(int $userId, float $amount, string $method): void
    {
        $title = "Withdrawal Request Received";
        $message = "Your withdrawal request of $" . number_format($amount, 2) . " via $method is being processed. You'll receive the funds once approved.";
        
        self::sendNotification(
            $userId,
            'info',
            $title,
            $message,
            true,
            'View Transactions',
            'transaction-history'
        );
    }
    
    /**
     * Send withdrawal approved notification
     */
    public static function sendWithdrawalApprovedNotification(int $userId, float $amount, string $method): void
    {
        $title = "Withdrawal Approved!";
        $message = "Your withdrawal of $" . number_format($amount, 2) . " via $method has been approved and processed. Funds should arrive shortly.";
        
        self::sendNotification(
            $userId,
            'success',
            $title,
            $message,
            true,
            'View Transactions',
            'transaction-history'
        );
    }
    
    /**
     * Send withdrawal rejected notification
     */
    public static function sendWithdrawalRejectedNotification(int $userId, float $amount, string $reason = ''): void
    {
        $title = "Withdrawal Not Approved";
        $message = "Your withdrawal request of $" . number_format($amount, 2) . " could not be approved.";
        if (!empty($reason)) {
            $message .= " Reason: $reason";
        }
        $message .= " The amount has been returned to your account.";
        
        self::sendNotification(
            $userId,
            'alert',
            $title,
            $message,
            true,
            'View Transactions',
            'transaction-history'
        );
    }
    
    /**
     * Send user account suspended notification
     */
    public static function sendUserAccountSuspendedNotification(int $userId, string $reason = ''): void
    {
        $title = "Account Suspended";
        $message = "Your account has been temporarily suspended.";
        if (!empty($reason)) {
            $message .= " Reason: $reason";
        }
        $message .= " Please contact support for assistance.";
        
        self::sendNotification(
            $userId,
            'alert',
            $title,
            $message,
            true,
            'Contact Support',
            'support'
        );
    }

    /**
     * Send trade account suspended notification
     */
    public static function sendTradeAccountSuspendedNotification(int $userId, string $type, string $id_hash, string $reason = ''): void
    {
        $title = "Trade Account Suspended";
        $message = "Your $type trade account ($id_hash) has been temporarily suspended.";
        if (!empty($reason)) {
            $message .= " Reason: $reason";
        }
        $message .= " Please contact support for assistance.";
        
        self::sendNotification(
            $userId,
            'alert',
            $title,
            $message,
            true,
            'Contact Support',
            'support'
        );
    }
    
    /**
     * Send user account reactivated notification
     */
    public static function sendUserAccountReactivatedNotification(int $userId): void
    {
        $title = "Account Reactivated";
        $message = "Good news! Your account has been reactivated. You can now have full access to your account.";
        
        self::sendNotification(
            $userId,
            'success',
            $title,
            $message,
            true,
            'Go to Dashboard',
            'traderhub'
        );
    }

    /**
     * Send trade account reactivated notification
     */
    public static function sendTradeAccountReactivatedNotification(int $userId, string $type, string $id_hash,): void
    {
        $title = "Account Reactivated";
        $message = "Good news! Your $type trade account ($id_hash) has been reactivated. You can now access all trading features again.";
        
        self::sendNotification(
            $userId,
            'success',
            $title,
            $message,
            true,
            "Start Trading",
            'trade'
        );
    }

    /**
     * Send bot trade start notification
     */
    public static function sendBotTradeStartNotification(int $userId, float $amount): void
    {
        $title = "Bot Trade Started";
        $message = "Your bot trade of $" . number_format($amount, 2) . " stake is now live and active. You can monitor its performance in your trading dashboard and quit at any time.";

        self::sendNotification(
            $userId,
            'success',
            $title,
            $message,
            true,
            'Bot Trades',
            'bot-trading'
        );
    }

    /**
     * Send bot trade end notification
     */
    public static function sendBotTradeEndNotification(int $userId, float $amount): void
    {
        $title = "Bot Trade Ended";
        $message = "Your bot trade has ended with a total profit of $" . number_format($amount, 2) . ". You can view the results in your trading dashboard.";

        self::sendNotification(
            $userId,
            'success',
            $title,
            $message,
            true,
            'Bot Trades',
            'bot-trading'
        );
    }
    
    /**
     * Send large trade notification (risk management)
     */
    public static function sendLargeTradeNotification(int $userId, float $amount, string $pair): void
    {
        $title = "Large Trade Opened";
        $message = "You've opened a large position of $" . number_format($amount, 2) . " on $pair. Please monitor your margin levels closely.";
        
        self::sendNotification(
            $userId,
            'warning',
            $title,
            $message,
            false, // Don't send email for this
            'View Trade',
            'trade'
        );
    }
    
    /**
     * Send margin call notification
     */
    public static function sendMarginCallNotification(int $userId, float $marginLevel): void
    {
        $title = "Margin Call Warning";
        $message = "Your margin level is at " . number_format($marginLevel, 2) . "%. Please deposit funds or close positions to avoid liquidation.";
        
        self::sendNotification(
            $userId,
            'alert',
            $title,
            $message,
            true,
            'Manage Positions',
            'trade'
        );
    }
    
    /**
     * Send KYC complete notification
     */
    public static function sendKYCCompleteNotification(int $userId): void
    {
        $title = "KYC Completed!";
        $message = "Your KYC has been submitted for approval. We will let you know if any error. Meanwhile, start trading now!";
        
        self::sendNotification(
            $userId,
            'success',
            $title,
            $message,
            true,
            'Start Trading',
            'trade'
        );
    }
    
    /**
     * Send KYC rejected notification
     */
    public static function sendKYCRejectedNotification(int $userId, string $kycCategoryCamel, string $reason = ''): void
    {
        $kycCategoryName = str_replace('_', ' ', $kycCategoryCamel);
        $kycCategoryDash = str_replace('_', '-', $kycCategoryCamel);
        $page = $kycCategoryDash === 'personal-details' ? 'account' : $kycCategoryDash;

        $title = "Verification Required";
        $message = "We couldn't verify your $kycCategoryName KYC at this time.";
        if (!empty($reason)) {
            $message .= " Reason: $reason";
        }
        $message .= " Please resubmit your documents or contact support.";
        
        self::sendNotification(
            $userId,
            'warning',
            $title,
            $message,
            true,
            'Resubmit Documents',
            "$page"
        );
    }
    
    /**
     * Send password changed notification
     */
    public static function sendPasswordChangedNotification(int $userId): void
    {
        $title = "Password Changed";
        $message = "Your password was recently changed. If you didn't make this change, please contact support immediately.";
        
        self::sendNotification(
            $userId,
            'info',
            $title,
            $message,
            true,
            'View Security',
            'email-passwords'
        );
    }
    
    /**
     * Send new login notification
     */
    public static function sendNewLoginNotification(int $userId, string $ipAddress, string $device): void
    {
        $title = "New Login Detected";
        $message = "A new login was detected from $device at IP: $ipAddress. If this wasn't you, please secure your account immediately.";
        
        self::sendNotification(
            $userId,
            'warning',
            $title,
            $message,
            true,
            'View Activity',
            'login-history'
        );
    }
}
