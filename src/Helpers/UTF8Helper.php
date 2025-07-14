<?php

namespace YetiSearch\Helpers;

/**
 * UTF-8 string helper using native PHP mbstring functions
 * Replacement for voku/portable-utf8 to ensure PHP 8.4 compatibility
 */
class UTF8Helper
{
    /**
     * Convert string to lowercase (UTF-8 safe)
     */
    public static function strtolower(string $string): string
    {
        return mb_strtolower($string, 'UTF-8');
    }
    
    /**
     * Get string length (UTF-8 safe)
     */
    public static function strlen(string $string): int
    {
        return mb_strlen($string, 'UTF-8');
    }
    
    /**
     * Find position of string (case-insensitive, UTF-8 safe)
     * 
     * @return int|false
     */
    public static function stripos(string $haystack, string $needle, int $offset = 0)
    {
        return mb_stripos($haystack, $needle, $offset, 'UTF-8');
    }
    
    /**
     * Normalize whitespace characters
     * Converts various Unicode whitespace characters to regular spaces
     */
    public static function normalize_whitespace(string $string): string
    {
        // Unicode whitespace characters
        $whitespace = [
            "\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07",
            "\x08", "\x09", "\x0A", "\x0B", "\x0C", "\x0D", "\x0E", "\x0F",
            "\x10", "\x11", "\x12", "\x13", "\x14", "\x15", "\x16", "\x17",
            "\x18", "\x19", "\x1A", "\x1B", "\x1C", "\x1D", "\x1E", "\x1F",
            "\x20", "\xC2\x85", "\xC2\xA0", "\xE1\x9A\x80", "\xE1\xA0\x8E",
            "\xE2\x80\x80", "\xE2\x80\x81", "\xE2\x80\x82", "\xE2\x80\x83",
            "\xE2\x80\x84", "\xE2\x80\x85", "\xE2\x80\x86", "\xE2\x80\x87",
            "\xE2\x80\x88", "\xE2\x80\x89", "\xE2\x80\x8A", "\xE2\x80\x8B",
            "\xE2\x80\x8C", "\xE2\x80\x8D", "\xE2\x80\x8E", "\xE2\x80\x8F",
            "\xE2\x80\xA8", "\xE2\x80\xA9", "\xE2\x80\xAA", "\xE2\x80\xAB",
            "\xE2\x80\xAC", "\xE2\x80\xAD", "\xE2\x80\xAE", "\xE2\x80\xAF",
            "\xE2\x81\x9F", "\xE3\x80\x80", "\xEF\xBB\xBF",
        ];
        
        // Replace all whitespace with regular space
        $string = str_replace($whitespace, ' ', $string);
        
        // Collapse multiple spaces
        return preg_replace('/\s+/', ' ', $string);
    }
    
    /**
     * Remove invisible/zero-width characters
     */
    public static function remove_invisible_characters(string $string): string
    {
        // Common invisible characters
        $invisible = [
            "\xE2\x80\x8B", // Zero Width Space
            "\xE2\x80\x8C", // Zero Width Non-Joiner
            "\xE2\x80\x8D", // Zero Width Joiner
            "\xEF\xBB\xBF", // UTF-8 BOM
            "\xE2\x80\x8E", // Left-to-Right Mark
            "\xE2\x80\x8F", // Right-to-Left Mark
            "\xE2\x80\xAA", // Left-to-Right Embedding
            "\xE2\x80\xAB", // Right-to-Left Embedding
            "\xE2\x80\xAC", // Pop Directional Formatting
            "\xE2\x80\xAD", // Left-to-Right Override
            "\xE2\x80\xAE", // Right-to-Left Override
        ];
        
        return str_replace($invisible, '', $string);
    }
}