<?php
/**
 * Cron Processor & Auto-Updater - cPanel Cron Compatible
 * 
 * Setup in cPanel:
 * 1. Go to cPanel > Cron Jobs
 * 2. Add new cron job:
 *    - Interval: Every 5 minutes
 *    - Command: php /home/username/public_html/cron/cron_update.php
 */

// Allow script to run for up to 5 minutes
set_time_limit(300);

echo "Cron update job started at " . date('Y-m-d H:i:s') . "\n";

try {
    require_once __DIR__ . '/../services/MailService.php';
    require_once __DIR__ . '/../services/PlatformService.php';
    require_once __DIR__ . '/../services/UpdateService.php';
    require_once __DIR__ . '/../controllers/ServerController.php';
    require_once __DIR__ . '/../config/db.php';
    
    // -------------------------------------------------------------
    // 1. Process Email Queue
    // -------------------------------------------------------------
    $queueDir = __DIR__ . '/../queue/';
    
    if (!is_dir($queueDir)) {
        mkdir($queueDir, 0755, true);
        echo "Created queue directory\n";
    }
    
    $files = glob($queueDir . 'email_queue_*.json');
    
    if (empty($files)) {
        echo "No emails in queue\n";
    } else {
        echo "Found " . count($files) . " email(s) to process\n\n";
        
        $processed = 0;
        $failed = 0;
        
        foreach ($files as $queueFile) {
            echo "Processing: " . basename($queueFile) . "... ";
            
            try {
                $data = json_decode(file_get_contents($queueFile), true);
                
                if (!$data) {
                    echo "FAILED (invalid JSON)\n";
                    unlink($queueFile);
                    $failed++;
                    continue;
                }
                
                $userId = $data['userId'];
                $type = $data['type'];
                $title = $data['title'];
                $message = $data['message'];
                $cta = $data['cta'] ?? null;
                $ctaLink = $data['ctaLink'] ?? null;
                
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
                    
                    $failedDir = $queueDir . 'failed/';
                    if (!is_dir($failedDir)) {
                        mkdir($failedDir, 0755, true);
                    }
                    rename($queueFile, $failedDir . basename($queueFile));
                    $failed++;
                    continue;
                }
                
                MailService::sendAdminNotification($userId, $userName, $userEmail, $type, $title, $message);
                unlink($queueFile);
                
                echo "SUCCESS\n";
                $processed++;
                
            } catch (Exception $e) {
                echo "ERROR: " . $e->getMessage() . "\n";
                error_log("Email Queue Processor Error: " . $e->getMessage());
                $failed++;
            }
        }
        
        echo "\n=================================\n";
        echo "Email queue processing complete\n";
        echo "Successful: $processed\n";
        echo "Failed: $failed\n";
        echo "=================================\n";
    }
} catch (Throwable $e) {
    echo "ERROR in Email Queue Processor: " . $e->getMessage() . "\n";
    error_log("Email Queue Processor Error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 2. Platform Auto-Update Check
// -------------------------------------------------------------
try {
    $autoUpdate = PlatformService::getSetting('auto_update', 'no');
    if ($autoUpdate === 'yes') {
        echo "\nChecking for platform updates...\n";

        // Prevent multiple concurrent update processes
        $status = UpdateService::getUpdateStatus();
        $inProgressStatuses = ['updating', 'downloading', 'extracting', 'migrating'];

        if (isset($status['status']) && in_array($status['status'], $inProgressStatuses, true)) {
            echo "Update already in progress ({$status['status']}). Skipping cron update.\n";
        } else {
            // Prevent Response::success / Response::error from killing the script prematurely
            Response::disableExit();

            // Run update process safely
            UpdateService::applyUpdate([]);
            echo "\nAuto-update process executed.\n";
        }
    } else {
        echo "\nAuto-update is disabled.\n";
    }
} catch (Throwable $e) {
    echo "ERROR during auto-update: " . $e->getMessage() . "\n";
    error_log("Cron Auto-Update Error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 3. WebSocket Server Restart Check
// -------------------------------------------------------------
try {
    $restartFile = __DIR__ . '/../restartserver.txt';
    if (file_exists($restartFile)) {
        echo "\nRestart notification file detected (restartserver.txt). Restarting WebSocket server...\n";
        Response::disableExit();
        ServerController::restartServer();
        echo "WebSocket server restart sequence completed.\n";
    }
} catch (Throwable $e) {
    echo "ERROR during WebSocket server restart: " . $e->getMessage() . "\n";
    error_log("Cron Server Restart Error: " . $e->getMessage());
}

echo "Cron update job finished at " . date('Y-m-d H:i:s') . "\n";