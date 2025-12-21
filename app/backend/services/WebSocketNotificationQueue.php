<?php

/**
 * WebSocket Notification Queue
 * 
 * Enables HTTP processes to send notifications to WebSocket-connected users
 * Uses a simple file-based queue that the WebSocket server monitors
 */
class WebSocketNotificationQueue
{
    private static $queueFile = __DIR__ . '/../cache/websocket_notifications_queue.json';
    
    /**
     * Queue a trade closure notification
     * Called by HTTP processes (admin actions, API calls)
     * 
     * @param array $trade Trade data
     * @param string $reason Closure reason
     */
    public static function queueTradeClosureNotification($trade, $reason) {
        try {
            $notification = [
                'type' => 'trade_closed',
                'trade_id' => $trade['id'] ?? null,
                'ref' => $trade['ref'] ?? null,
                'pair' => $trade['pair'] ?? null,
                'trade_type' => $trade['type'] ?? null,
                'reason' => $reason,
                'profit' => $trade['profit'] ?? '0.00',
                'close_price' => $trade['price'] ?? null,
                'userid' => $trade['userid'],
                'timestamp' => time(),
                'queued_at' => microtime(true)
            ];
            
            self::addToQueue($notification);
            
            error_log("[QUEUE] Queued trade_closed notification for user {$trade['userid']} (reason: {$reason})");
        } catch (Exception $e) {
            error_log("WebSocketNotificationQueue::queueTradeClosureNotification - " . $e->getMessage());
        }
    }
    
    /**
     * Add notification to queue file
     * 
     * @param array $notification Notification data
     */
    private static function addToQueue($notification) {
        $lockFile = self::$queueFile . '.lock';
        $fp = fopen($lockFile, 'w');
        
        if (flock($fp, LOCK_EX)) {
            // Read existing queue
            $queue = [];
            if (file_exists(self::$queueFile)) {
                $content = file_get_contents(self::$queueFile);
                if ($content) {
                    $queue = json_decode($content, true) ?? [];
                }
            }
            
            // Add new notification
            $queue[] = $notification;
            
            // Keep only last 1000 notifications to prevent file bloat
            if (count($queue) > 1000) {
                $queue = array_slice($queue, -1000);
            }
            
            // Write back to file
            file_put_contents(self::$queueFile, json_encode($queue, JSON_PRETTY_PRINT));
            
            flock($fp, LOCK_UN);
        }
        
        fclose($fp);
    }
    
    /**
     * Get all pending notifications and clear queue
     * Called by WebSocket server
     * 
     * @return array Pending notifications
     */
    public static function getAndClearQueue() {
        $lockFile = self::$queueFile . '.lock';
        $fp = fopen($lockFile, 'w');
        $notifications = [];
        
        if (flock($fp, LOCK_EX)) {
            if (file_exists(self::$queueFile)) {
                $content = file_get_contents(self::$queueFile);
                if ($content) {
                    $notifications = json_decode($content, true) ?? [];
                }
                
                // Clear the queue
                file_put_contents(self::$queueFile, json_encode([], JSON_PRETTY_PRINT));
            }
            
            flock($fp, LOCK_UN);
        }
        
        fclose($fp);
        
        return $notifications;
    }
}
