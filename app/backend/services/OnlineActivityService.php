<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';

class OnlineActivityService
{
    /**
     * Update account's online status
     * 
     * @param int $user_id
     * @param string $status - 'online', 'away', or 'offline'
     * @param int|null $last_activity_timestamp - Optional timestamp of last activity
     */
    public static function updateOnlineStatus($user_id, $status, $last_activity_timestamp = null) {
        try {
            $conn = Database::getConnection();
            
            // Validate status
            $valid_statuses = ['online', 'away', 'offline'];
            if (!in_array($status, $valid_statuses)) {
                Response::error('Invalid status. Must be online, away, or offline', 400);
                return;
            }

            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $account_id_hash = $user['current_account'];

            $current_time = gmdate('Y-m-d H:i:s');
            
            // If last_activity_timestamp is provided, convert it to MySQL datetime
            $last_activity = $current_time;
            if ($last_activity_timestamp) {
                $last_activity = gmdate('Y-m-d H:i:s', intval($last_activity_timestamp / 1000));
            }

            $stmt = $conn->prepare("UPDATE accounts SET online_status = ?, last_heartbeat = ?, last_activity = ? WHERE id_hash = ? AND user_id = ?");
            $stmt->bind_param("ssssi", $status, $current_time, $last_activity, $account_id_hash, $user_id);
            
            if ($stmt->execute()) {
                Response::success('Account online status updated successfully');
            } else {
                Response::error('Failed to update account online status', 500);
            }
        } catch (Exception $e) {
            error_log("OnlineActivityService::updateOnlineStatus - " . $e->getMessage());
            Response::error('Failed to update online status', 500);
        }
    }

    /**
     * Send heartbeat (update last_heartbeat timestamp for current account)
     * 
     * @param int $user_id
     * @param int|null $last_activity_timestamp
     */
    public static function sendHeartbeat($user_id, $last_activity_timestamp = null) {
        try {
            $conn = Database::getConnection();
            
            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $account_id_hash = $user['current_account'];
            
            $current_time = gmdate('Y-m-d H:i:s');
            
            // Get current account status
            $stmt = $conn->prepare("SELECT online_status, last_activity FROM accounts WHERE id_hash = ? AND user_id = ?");
            $stmt->bind_param("si", $account_id_hash, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('Account not found', 404);
                return;
            }
            
            $account = $result->fetch_assoc();
            
            // Calculate time since last activity
            $last_activity = $current_time;
            if ($last_activity_timestamp) {
                $last_activity = gmdate('Y-m-d H:i:s', intval($last_activity_timestamp / 1000));
            }
            
            // Determine status based on activity (5 minutes = 300 seconds)
            $time_diff = strtotime($current_time) - strtotime($last_activity);
            $new_status = $time_diff > 300 ? 'away' : 'online';
            
            $stmt = $conn->prepare("UPDATE accounts SET online_status = ?, last_heartbeat = ?, last_activity = ? WHERE id_hash = ? AND user_id = ?");
            $stmt->bind_param("ssssi", $new_status, $current_time, $last_activity, $account_id_hash, $user_id);
            
            if ($stmt->execute()) {
                Response::success('Heartbeat recorded');
            } else {
                Response::error('Failed to send heartbeat', 500);
            }
        } catch (Exception $e) {
            error_log("OnlineActivityService::sendHeartbeat - " . $e->getMessage());
            Response::error('Failed to send heartbeat', 500);
        }
    }

    /**
     * Get account's current online status
     * 
     * @param int $user_id
     */
    public static function getUserOnlineStatus($user_id) {
        try {
            $conn = Database::getConnection();
            
            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $account_id_hash = $user['current_account'];
            
            // Consider accounts offline if no heartbeat in last 2 minutes
            $two_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-2 minutes'));
            
            $stmt = $conn->prepare("
                SELECT 
                    id_hash, 
                    type, 
                    online_status, 
                    last_activity, 
                    last_heartbeat,
                    CASE 
                        WHEN online_status IN ('online', 'away') 
                        AND last_heartbeat IS NOT NULL
                        AND last_heartbeat > ? 
                        THEN online_status
                        ELSE 'offline'
                    END as current_online_status
                FROM accounts 
                WHERE id_hash = ? AND user_id = ?
            ");
            $stmt->bind_param("ssi", $two_minutes_ago, $account_id_hash, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Account not found', 404);
                return null;
            }

            $account = $result->fetch_assoc();
            
            Response::success([
                'account_id' => $account['id_hash'],
                'account_type' => $account['type'],
                'status' => $account['current_online_status'],
                'last_activity' => $account['last_activity'],
                'last_heartbeat' => $account['last_heartbeat']
            ]);
        } catch (Exception $e) {
            error_log("OnlineActivityService::getOnlineStatus - " . $e->getMessage());
            Response::error('Failed to get online status', 500);
        }
    }

    /**
     * Get account's current online status by account id_hash
     * 
     * @param string $account_id_hash
     */
    public static function getAccountOnlineStatus($account_id_hash) {
        try {
            $conn = Database::getConnection();
            
            // Consider accounts offline if no heartbeat in last 2 minutes
            $two_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-2 minutes'));
            
            $stmt = $conn->prepare("
                SELECT 
                    id_hash, 
                    type, 
                    online_status, 
                    last_activity, 
                    last_heartbeat,
                    CASE 
                        WHEN online_status IN ('online', 'away') 
                        AND last_heartbeat IS NOT NULL
                        AND last_heartbeat > ? 
                        THEN online_status
                        ELSE 'offline'
                    END as current_online_status
                FROM accounts 
                WHERE id_hash = ?
            ");
            $stmt->bind_param("ss", $two_minutes_ago, $account_id_hash);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Account not found', 404);
                return null;
            }

            $account = $result->fetch_assoc();
            
            Response::success([
                'account_id' => $account['id_hash'],
                'account_type' => $account['type'],
                'status' => $account['current_online_status'],
                'last_activity' => $account['last_activity'],
                'last_heartbeat' => $account['last_heartbeat']
            ]);
        } catch (Exception $e) {
            error_log("OnlineActivityService::getAccountOnlineStatus - " . $e->getMessage());
            Response::error('Failed to get account online status', 500);
        }
    }

    public static function getOnlineUsersCount() {
        try {
            $conn = Database::getConnection();
            
            // Consider accounts offline if no heartbeat in last 2 minutes
            $two_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-2 minutes'));
            
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT a.user_id) as count 
                FROM accounts a
                WHERE a.online_status IN ('online', 'away') 
                AND a.last_heartbeat > ?
            ");
            $stmt->bind_param("s", $two_minutes_ago);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            return (int) $row['count'];
        } catch (Exception $e) {
            error_log("OnlineActivityService::getOnlineUsersCount - " . $e->getMessage());
            Response::error('Failed to get online users count', 500);
        }
    }

    public static function markOffline($user_id) {
        try {
            $conn = Database::getConnection();
            
            // Get user's current account
            $stmt = $conn->prepare("SELECT current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }
            
            $user = $result->fetch_assoc();
            $account_id_hash = $user['current_account'];
            
            $current_time = gmdate('Y-m-d H:i:s');
            $status = 'offline';
            
            $stmt = $conn->prepare("UPDATE accounts SET online_status = ?, last_heartbeat = ? WHERE id_hash = ? AND user_id = ?");
            $stmt->bind_param("sssi", $status, $current_time, $account_id_hash, $user_id);
            
            if ($stmt->execute()) {
                Response::success('Account marked as offline');
            } else {
                Response::error('Failed to mark account as offline', 500);
            }
        } catch (Exception $e) {
            error_log("OnlineActivityService::markOffline - " . $e->getMessage());
            Response::error('Failed to mark account as offline', 500);
        }
    }

    /**
     * Cleanup stale online statuses (run via cron)
     * Marks accounts as offline if no heartbeat in last 5 minutes
     */
    public static function cleanupStaleStatuses() {
        try {
            $conn = Database::getConnection();
            
            // Mark as offline if no heartbeat in last 5 minutes
            $five_minutes_ago = gmdate('Y-m-d H:i:s', strtotime('-5 minutes'));
            
            $status = 'offline';
            $stmt = $conn->prepare("
                UPDATE accounts 
                SET online_status = ? 
                WHERE online_status != 'offline' 
                AND (last_heartbeat < ? OR last_heartbeat IS NULL)
            ");
            $stmt->bind_param("ss", $status, $five_minutes_ago);
            
            if ($stmt->execute()) {
                $affected_rows = $stmt->affected_rows;
                Response::success([
                    'cleaned_up' => $affected_rows,
                    'threshold_minutes' => 5
                ], "Cleaned up $affected_rows stale account statuses");
            } else {
                Response::error('Failed to cleanup stale statuses', 500);
            }
        } catch (Exception $e) {
            error_log("OnlineActivityService::cleanupStaleStatuses - " . $e->getMessage());
            Response::error('Failed to cleanup stale statuses', 500);
        }
    }
}