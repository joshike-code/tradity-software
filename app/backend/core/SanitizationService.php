<?php
namespace Core;

class SanitizationService
{
    public static function sanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                // Trim and clean strings
                $value = trim($value);
                $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                $value = strip_tags($value);

                // If numeric string, cast properly
                if (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float) $value : (int) $value;
                }

                $sanitized[$key] = $value;

            } elseif (is_numeric($value)) {
                // Ensure numeric values are cast
                $sanitized[$key] = strpos((string)$value, '.') !== false 
                    ? (float) $value 
                    : (int) $value;

            } elseif (is_array($value)) {
                // Recursively sanitize arrays
                $sanitized[$key] = self::sanitize($value);

            } else {
                // Fallback for bools/nulls/objects
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public static function sanitizeParam(string $value)
    {
        $value = trim($value);
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $value = strip_tags($value);

        // Cast numeric strings correctly
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }

        return $value;
    }

    public static function sanitizeUrl(string $value)
    {
        $url = preg_replace('/^(https?:\/\/)+/', '', $value);
        return 'https://' . $url;
    }
}
