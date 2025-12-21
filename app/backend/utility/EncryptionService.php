<?php

class EncryptionService
{
    private static string $secret_key = 'h93fcnv92ffu53ncvsoeh938d5';

    /**
     * Encrypt a timestamp with current time
     * 
     * @return string Base64 encoded encrypted data
     */
    public static function encryptTimestamp(): string
    {
        $timestamp = time();
        $cipher_method = 'AES-256-CBC';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher_method));
        
        $encrypted = openssl_encrypt($timestamp, $cipher_method, self::$secret_key, 0, $iv);
        
        return base64_encode($encrypted . '::' . $iv);
    }

    /**
     * Decrypt and validate timestamp (expires after 1 hour)
     * 
     * @param string $encrypted_data Base64 encoded encrypted data
     * @return array ['valid' => bool, 'expired' => bool, 'error' => string|null]
     */
    public static function validateEncryptedTimestamp(string $encrypted_data): array
    {
        $cipher_method = 'AES-256-CBC';
        
        // Decode and split data
        $decoded_data = base64_decode($encrypted_data);
        if ($decoded_data === false || strpos($decoded_data, '::') === false) {
            return ['valid' => false, 'expired' => false, 'error' => 'Invalid format'];
        }
        
        $parts = explode('::', $decoded_data, 2);
        if (count($parts) !== 2) {
            return ['valid' => false, 'expired' => false, 'error' => 'Invalid data structure'];
        }
        
        [$encrypted_timestamp, $iv] = $parts;
        
        // Validate IV length
        if (strlen($iv) !== openssl_cipher_iv_length($cipher_method)) {
            return ['valid' => false, 'expired' => false, 'error' => 'Invalid IV length'];
        }
        
        // Decrypt timestamp
        $decrypted = openssl_decrypt($encrypted_timestamp, $cipher_method, self::$secret_key, 0, $iv);
        
        if ($decrypted === false) {
            return ['valid' => false, 'expired' => false, 'error' => 'Decryption failed'];
        }
        
        $timestamp = (int) $decrypted;
        $current_time = time();
        $time_diff = $current_time - $timestamp;
        
        // Check if expired (1 hour = 3600 seconds)
        if ($time_diff > 3600) {
            return ['valid' => false, 'expired' => true, 'error' => 'Token expired'];
        }
        
        if ($time_diff < 0) {
            return ['valid' => false, 'expired' => false, 'error' => 'Invalid timestamp'];
        }
        
        return ['valid' => true, 'expired' => false, 'error' => null];
    }

    /**
     * Generate a secure token for credential change operations
     * 
     * @param int $user_id
     * @param string $type Either 'email' or 'password'
     * @return string
     */
    public static function generateCredentialToken(int $user_id, string $type): string
    {
        $data = [
            'user_id' => $user_id,
            'type' => $type,
            'timestamp' => time(),
            'nonce' => bin2hex(random_bytes(16))
        ];
        
        $json_data = json_encode($data);
        $cipher_method = 'AES-256-CBC';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher_method));
        
        $encrypted = openssl_encrypt($json_data, $cipher_method, self::$secret_key, 0, $iv);
        
        return base64_encode($encrypted . '::' . $iv);
    }

    /**
     * Validate and decode credential change token
     * 
     * @param string $token
     * @return array ['valid' => bool, 'user_id' => int|null, 'type' => string|null, 'expired' => bool, 'error' => string|null]
     */
    public static function validateCredentialToken(string $token): array
    {
        $cipher_method = 'AES-256-CBC';
        
        // Decode and split data
        $decoded_data = base64_decode($token);
        if ($decoded_data === false || strpos($decoded_data, '::') === false) {
            return ['valid' => false, 'user_id' => null, 'type' => null, 'expired' => false, 'error' => 'Invalid token format'];
        }
        
        $parts = explode('::', $decoded_data, 2);
        if (count($parts) !== 2) {
            return ['valid' => false, 'user_id' => null, 'type' => null, 'expired' => false, 'error' => 'Invalid token structure'];
        }
        
        [$encrypted_data, $iv] = $parts;
        
        // Validate IV length
        if (strlen($iv) !== openssl_cipher_iv_length($cipher_method)) {
            return ['valid' => false, 'user_id' => null, 'type' => null, 'expired' => false, 'error' => 'Invalid IV'];
        }
        
        // Decrypt data
        $decrypted = openssl_decrypt($encrypted_data, $cipher_method, self::$secret_key, 0, $iv);
        
        if ($decrypted === false) {
            return ['valid' => false, 'user_id' => null, 'type' => null, 'expired' => false, 'error' => 'Token decryption failed'];
        }
        
        $data = json_decode($decrypted, true);
        if ($data === null) {
            return ['valid' => false, 'user_id' => null, 'type' => null, 'expired' => false, 'error' => 'Invalid token data'];
        }
        
        // Validate required fields
        if (!isset($data['user_id'], $data['type'], $data['timestamp'])) {
            return ['valid' => false, 'user_id' => null, 'type' => null, 'expired' => false, 'error' => 'Missing required fields'];
        }
        
        // Check expiration (1 hour)
        $current_time = time();
        $time_diff = $current_time - $data['timestamp'];
        
        if ($time_diff > 3600) {
            return ['valid' => false, 'user_id' => $data['user_id'], 'type' => $data['type'], 'expired' => true, 'error' => 'Token expired'];
        }
        
        if ($time_diff < 0) {
            return ['valid' => false, 'user_id' => null, 'type' => null, 'expired' => false, 'error' => 'Invalid timestamp'];
        }
        
        return [
            'valid' => true,
            'user_id' => (int) $data['user_id'],
            'type' => $data['type'],
            'expired' => false,
            'error' => null
        ];
    }
}
