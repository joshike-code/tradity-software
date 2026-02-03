<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';

class ActivityService
{
    public static function getUserActivity($user_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT * FROM activity WHERE user_id = ? ORDER BY date DESC");
            $stmt->bind_param("s", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $activities = [];
            while ($row = $result->fetch_assoc()) {
                $activities[] = $row;
            }

            Response::success($activities);
        } catch (Exception $e) {
            error_log("ActivityService::getUserActivity - " . $e->getMessage());
            Response::error('Failed to retrieve user activity', 500);
        }
    }

    public static function logActivity($user_id, $input) {
        try {
            $conn = Database::getConnection();
            
            $action = $input['action'];
            $status = $input['status'];
            $browser = $input['browser'] ?? self::detectBrowser();
            $country = $input['country'] ?? self::detectCountry();
            $ip_address = $input['ip_address'] ?? self::getClientIP();
            $date = gmdate('Y-m-d H:i:s');

            $stmt = $conn->prepare("INSERT INTO activity (user_id, action, browser, country, ip_address, status, date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $user_id, $action, $browser, $country, $ip_address, $status, $date);
            
            if ($stmt->execute()) {
                $activity_id = $conn->insert_id;
                
                // Get the created activity
                $stmt = $conn->prepare("SELECT * FROM activity WHERE id = ?");
                $stmt->bind_param("i", $activity_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $activity = $result->fetch_assoc();
                
                Response::success($activity, 'Activity logged successfully');
            } else {
                Response::error('Failed to log activity', 500);
            }
        } catch (Exception $e) {
            error_log("ActivityService::logActivity - " . $e->getMessage());
            Response::error('Failed to log activity', 500);
        }
    }

    /**
     * Log activity without sending Response (for internal use)
     * Returns true on success, false on failure
     */
    public static function logActivitySilent($user_id, $input) {
        try {
            $conn = Database::getConnection();
            
            $action = $input['action'];
            $status = $input['status'];
            $browser = $input['browser'] ?? self::detectBrowser();
            $country = $input['country'] ?? self::detectCountry();
            $ip_address = $input['ip_address'] ?? self::getClientIP();
            $date = gmdate('Y-m-d H:i:s');

            $stmt = $conn->prepare("INSERT INTO activity (user_id, action, browser, country, ip_address, status, date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $user_id, $action, $browser, $country, $ip_address, $status, $date);
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("ActivityService::logActivitySilent - " . $e->getMessage());
            return false;
        }
    }

    public static function getActivityById($activity_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT * FROM activity WHERE id = ?");
            $stmt->bind_param("i", $activity_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('Activity not found', 404);
                return null;
            }

            return $result->fetch_assoc();
        } catch (Exception $e) {
            error_log("ActivityService::getActivityById - " . $e->getMessage());
            Response::error('Failed to retrieve activity', 500);
            return null;
        }
    }

    public static function getAllActivity() {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT a.*, u.email FROM activity a JOIN users u ON a.user_id = u.id ORDER BY a.date DESC");
            $stmt->execute();
            $result = $stmt->get_result();

            $activities = [];
            while ($row = $result->fetch_assoc()) {
                $activities[] = $row;
            }

            Response::success($activities);
        } catch (Exception $e) {
            error_log("ActivityService::getAllActivity - " . $e->getMessage());
            Response::error('Failed to retrieve activities', 500);
        }
    }

    public static function searchActivityByAction($action) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT a.*, u.email FROM activity a JOIN users u ON a.user_id = u.id WHERE a.action LIKE ? ORDER BY a.date DESC");
            $search_term = "%{$action}%";
            $stmt->bind_param("s", $search_term);
            $stmt->execute();
            $result = $stmt->get_result();

            $activities = [];
            while ($row = $result->fetch_assoc()) {
                $activities[] = $row;
            }

            Response::success($activities);
        } catch (Exception $e) {
            error_log("ActivityService::searchActivityByAction - " . $e->getMessage());
            Response::error('Failed to search activities', 500);
        }
    }

    public static function deleteActivity($activity_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("DELETE FROM activity WHERE id = ?");
            $stmt->bind_param("i", $activity_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                Response::success(null, 'Activity deleted successfully');
            } else {
                Response::error('Activity not found', 404);
            }
        } catch (Exception $e) {
            error_log("ActivityService::deleteActivity - " . $e->getMessage());
            Response::error('Failed to delete activity', 500);
        }
    }

    public static function getUserActivityByDateRange($user_id, $start_date, $end_date) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT * FROM activity WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date DESC");
            $stmt->bind_param("sss", $user_id, $start_date, $end_date);
            $stmt->execute();
            $result = $stmt->get_result();

            $activities = [];
            while ($row = $result->fetch_assoc()) {
                $activities[] = $row;
            }

            Response::success($activities);
        } catch (Exception $e) {
            error_log("ActivityService::getUserActivityByDateRange - " . $e->getMessage());
            Response::error('Failed to retrieve activities by date range', 500);
        }
    }

    private static function getClientIP() {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }

    private static function detectBrowser() {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return 'Unknown';
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        
        if (strpos($userAgent, 'Chrome') !== false) {
            preg_match('/Chrome\/([0-9.]+)/', $userAgent, $matches);
            return 'Chrome ' . ($matches[1] ?? 'Unknown');
        } elseif (strpos($userAgent, 'Firefox') !== false) {
            preg_match('/Firefox\/([0-9.]+)/', $userAgent, $matches);
            return 'Firefox ' . ($matches[1] ?? 'Unknown');
        } elseif (strpos($userAgent, 'Safari') !== false && strpos($userAgent, 'Chrome') === false) {
            preg_match('/Version\/([0-9.]+)/', $userAgent, $matches);
            return 'Safari ' . ($matches[1] ?? 'Unknown');
        } elseif (strpos($userAgent, 'Edge') !== false) {
            preg_match('/Edge\/([0-9.]+)/', $userAgent, $matches);
            return 'Edge ' . ($matches[1] ?? 'Unknown');
        }
        
        return 'Unknown';
    }

    private static function detectCountry() {
        // This is a simplified country detection
        // In production, you might want to use a GeoIP service
        $ip = self::getClientIP();
        
        // For now, return a default value
        // You can integrate with services like MaxMind GeoIP or similar
        return 'US';
    }
}
