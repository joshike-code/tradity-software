<?php

class Response
{
    /**
     * Flag to control whether Response methods should exit
     * Set to false when running in WebSocket server context
     */
    private static $shouldExit = true;
    
    /**
     * Disable exit() calls - for use in WebSocket server
     */
    public static function disableExit()
    {
        self::$shouldExit = false;
    }
    
    /**
     * Enable exit() calls - default behavior for API
     */
    public static function enableExit()
    {
        self::$shouldExit = true;
    }
    
    /**
     * Check if Response is in exit mode
     */
    public static function willExit()
    {
        return self::$shouldExit;
    }
    
    public static function success($data, $code = 200)
    {
        // Prevent browser caching of API responses
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');
        
        http_response_code($code);
        echo json_encode($data);
        
        if (self::$shouldExit) {
            exit;
        }
    }

    public static function error($message, $code = 400)
    {
        // Prevent browser caching of API errors
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');
        
        http_response_code($code);
        echo json_encode(['status' => 'error', 'message' => $message]);
        
        if (self::$shouldExit) {
            exit;
        }
    }
}