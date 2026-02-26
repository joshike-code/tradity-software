<?php

/**
 * Simple file logger for WebSocket server and background processes
 */
class Logger {
    private static $logFile = null;
    private static $logDir = __DIR__ . '/../logs';
    private static $maxFileSize = 10485760; // 10 MB default
    private static $maxRotatedFiles = 5; // Keep 5 old log files
    
    /**
     * Initialize logger with a specific log file
     * 
     * @param string $filename - Log file name (will be created in logs/ directory)
     * @param int $maxSizeMB - Maximum log file size in MB before rotation (default: 10 MB)
     * @param int $keepFiles - Number of rotated log files to keep (default: 5)
     */
    public static function init($filename = 'websocket.log', $maxSizeMB = 10, $keepFiles = 5) {
        // Create logs directory if it doesn't exist
        if (!file_exists(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
        
        self::$logFile = self::$logDir . '/' . $filename;
        self::$maxFileSize = $maxSizeMB * 1048576; // Convert MB to bytes
        self::$maxRotatedFiles = $keepFiles;
    }
    
    /**
     * Write a log message to file
     * 
     * @param string $message - Message to log
     * @param string $level - Log level (INFO, WARNING, ERROR, DEBUG)
     */
    public static function log($message, $level = 'INFO') {
        if (self::$logFile === null) {
            self::init();
        }
        
        // Check if rotation is needed before writing
        self::rotateIfNeeded();
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
        
        // Write to file
        file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Also output to console if running interactively (check if running from terminal)
        if (php_sapi_name() === 'cli') {
            // On Windows, posix_isatty doesn't exist, so check differently
            if (function_exists('posix_isatty') && defined('STDOUT')) {
                if (@posix_isatty(STDOUT)) {
                    echo $logEntry;
                }
            } else {
                // Windows fallback - just echo (will appear in console if interactive)
                echo $logEntry;
            }
        }
    }
    
    /**
     * Rotate log file if it exceeds max size
     */
    private static function rotateIfNeeded() {
        if (!file_exists(self::$logFile)) {
            return; // No file to rotate
        }
        
        $fileSize = @filesize(self::$logFile);
        if ($fileSize === false || $fileSize < self::$maxFileSize) {
            return; // File doesn't exist or is below size limit
        }
        
        // Rotate existing backup files
        // Example: log.4 -> log.5, log.3 -> log.4, etc.
        for ($i = self::$maxRotatedFiles - 1; $i > 0; $i--) {
            $oldFile = self::$logFile . '.' . $i;
            $newFile = self::$logFile . '.' . ($i + 1);
            
            if (file_exists($oldFile)) {
                if ($i === self::$maxRotatedFiles - 1) {
                    // Delete the oldest file
                    @unlink($newFile);
                }
                @rename($oldFile, $newFile);
            }
        }
        
        // Rotate current log file to .1
        @rename(self::$logFile, self::$logFile . '.1');
        
        // Log rotation event in new file
        $timestamp = date('Y-m-d H:i:s');
        $rotationMsg = "[{$timestamp}] [INFO] Log rotated - previous file exceeded " . 
                       round(self::$maxFileSize / 1048576, 2) . " MB\n";
        file_put_contents(self::$logFile, $rotationMsg, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Log info message
     */
    public static function info($message) {
        self::log($message, 'INFO');
    }
    
    /**
     * Log warning message
     */
    public static function warning($message) {
        self::log($message, 'WARNING');
    }
    
    /**
     * Log error message
     */
    public static function error($message) {
        self::log($message, 'ERROR');
    }
    
    /**
     * Log debug message
     */
    public static function debug($message) {
        self::log($message, 'DEBUG');
    }
    
    /**
     * Get the current log file path
     */
    public static function getLogFile() {
        if (self::$logFile === null) {
            self::init();
        }
        return self::$logFile;
    }
    
    /**
     * Clear the log file
     */
    public static function clear() {
        if (self::$logFile !== null && file_exists(self::$logFile)) {
            file_put_contents(self::$logFile, '');
        }
    }
}
