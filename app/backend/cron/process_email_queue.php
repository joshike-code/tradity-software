<?php
/**
 * Email Queue Processor - cPanel Cron Compatible
 * 
 * This script processes queued emails and can be triggered by cPanel cron jobs
 * 
 * Setup in cPanel:
 * 1. Go to cPanel > Cron Jobs
 * 2. Add new cron job:
 *    - Interval: Every 5 minutes
 *    - Command: php /home/username/public_html/cron/process_email_queue.php
 *    OR
 *    - Command: wget -q -O- https://yourdomain.com/cron/process_email_queue.php
 * 
 * Security: Add a secret key check to prevent unauthorized access
 */

// Allow script to run for up to 5 minutes
set_time_limit(300);

// Prevent browser timeout
// if (php_sapi_name() !== 'cli') {
//     header('Content-Type: text/plain');
    
//     // Optional: Add secret key protection for web access
//     $keys = require __DIR__ . '/../config/keys.php';
//     $secretKey = $keys['cron_secret_key'] ?? 'change_this_in_production';
//     $providedKey = $_GET['key'] ?? '';
    
//     if ($providedKey !== $secretKey) {
//         http_response_code(403);
//         die("Access denied. Invalid cron key.\n");
//     }
// }

echo "Email Queue Processor started at " . date('Y-m-d H:i:s') . "\n";

try {
    require_once __DIR__ . '/../services/MailService.php';
    require_once __DIR__ . '/../config/db.php';
    
    $queueDir = __DIR__ . '/../queue/';
    
    // Create queue directory if it doesn't exist
    if (!is_dir($queueDir)) {
        mkdir($queueDir, 0755, true);
        echo "Created queue directory\n";
    }
    
    // Get all queue files
    $files = glob($queueDir . 'email_queue_*.json');
    
    if (empty($files)) {
        echo "No emails in queue\n";
        exit(0);
    }
    
    echo "Found " . count($files) . " email(s) to process\n\n";
    
    $processed = 0;
    $failed = 0;
    
    foreach ($files as $queueFile) {
        echo "Processing: " . basename($queueFile) . "... ";
        
        try {
            // Read queue data
            $data = json_decode(file_get_contents($queueFile), true);
            
            if (!$data) {
                echo "FAILED (invalid JSON)\n";
                unlink($queueFile); // Delete invalid file
                $failed++;
                continue;
            }
            
            // Extract notification data
            $userId = $data['userId'];
            $type = $data['type'];
            $title = $data['title'];
            $message = $data['message'];
            $cta = $data['cta'] ?? null;
            $ctaLink = $data['ctaLink'] ?? null;
            
            // Get user details
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT email, fname, lname FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo "FAILED (user not found)\n";
                unlink($queueFile);
                $failed++;
                continue;
            }
            
            $user = $result->fetch_assoc();
            $userName = trim(($user['fname'] ?? '') . ' ' . ($user['lname'] ?? '')) ?: 'Trader';
            $userEmail = $user['email'];
            
            // Send email to user
            $userEmailSent = MailService::sendAccountNotification(
                $userEmail,
                $userName,
                $type,
                $title,
                $message,
                $cta,
                $ctaLink
            );
            
            if (!$userEmailSent) {
                echo "FAILED (email send failed)\n";
                
                // Move to failed directory for retry later
                $failedDir = $queueDir . 'failed/';
                if (!is_dir($failedDir)) {
                    mkdir($failedDir, 0755, true);
                }
                rename($queueFile, $failedDir . basename($queueFile));
                $failed++;
                continue;
            }
            
            // Send admin notification
            MailService::sendAdminNotification($userId, $userName, $userEmail, $type, $title, $message);
            
            // Delete queue file after successful processing
            unlink($queueFile);
            
            echo "SUCCESS\n";
            $processed++;
            
        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
            error_log("Email Queue Processor Error: " . $e->getMessage());
            $failed++;
        }
    }
    
    echo "\n";
    echo "=================================\n";
    echo "Processing complete\n";
    echo "Successful: $processed\n";
    echo "Failed: $failed\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    echo "=================================\n";
    
    exit(0);
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    error_log("Email Queue Processor Fatal Error: " . $e->getMessage());
    exit(1);
}
