<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Validator
 *
 * Sanitizes user input and extracts structured values
 * like year, year ranges, and ratings from free text.
 */
class Validator
{
    /**
     * Sanitize free text from a form or chat input.
     * - Trims whitespace
     * - Collapses internal whitespace to single spaces
     * - Removes most control characters
     * - Clamps length to a safe max (default ~2k)
     */
    public static function sanitizeText(string $s, int $maxLen = 2048): string
    {
        // Normalize newlines/tabs to spaces
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);

        // Remove other control chars (except common whitespace)
        $s = preg_replace('/[^\PC\s]/u', '', $s) ?? $s;

        // Collapse multiple spaces
        $s = preg_replace('/\s{2,}/', ' ', $s) ?? $s;

        $s = trim($s);

        // Clamp to a maximum length to prevent abuse
        if (mb_strlen($s) > $maxLen) {
            $s = mb_substr($s, 0, $maxLen);
        }

        return $s;
    }

    /**
     * Quick non-empty check after sanitize.
     */
    public static function notEmpty(string $s): bool
    {
        return mb_strlen(trim($s)) > 0;
    }

    /**
     * Extract a single 4-digit year (1900–2099) from text.
     * Returns the first match or null if none.
     */
    public static function extractYear(string $s): ?int
    {
        if (preg_match('/\b(19|20)\d{2}\b/', $s, $m)) {
            return (int)$m[0];
        }
        return null;
    }

    /**
     * Extract a year range like "2015-2020" or "2015 to 2020".
     * Returns [start, end] (ints) with start <= end, or null if not found.
     */
    public static function extractYearRange(string $s): ?array
    {
        // Allow separators like '-', '–', 'to', 'til', '—'
        $pattern = '/\b((?:19|20)\d{2})\s*(?:-|–|—|to|til)\s*((?:19|20)\d{2})\b/i';
        if (preg_match($pattern, $s, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            if ($a > $b) {
                [$a, $b] = [$b, $a];
            }
            return [$a, $b];
        }
        return null;
    }

    /**
     * Extract a "minimum rating" from text.
     * Understands:
     *  - "over 7", "over 7.5", "over 7,5"
     *  - "7+", "7.5+", "7,5+"
     *  - "min 8", "minimum 8"
     *  - "rating 8" / "score 8"
     *  Returns a float in range [0,10], or null if none.
     */
    public static function extractRating(string $s): ?float
    {
        $text = mb_strtolower($s);

        // Normalize comma decimals to dot ("7,5" -> "7.5")
        $normalized = preg_replace('/(\d),(\d)/', '$1.$2', $text) ?? $text;

        // Common patterns for "minimum rating"
        $patterns = [
            '/\b(over|min(?:imum)?)\s*(\d(?:\.\d)?)/u', // "over 7" or "min 7.5"
            '/\b(rating|score)\s*(\d(?:\.\d)?)/u',      // "rating 8"
            '/\b(\d(?:\.\d)?)\s*\+/u',                  // "7+" or "7.5+"
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $normalized, $m)) {
                // Number might be in group 2 or 1 depending on pattern
                $num = isset($m[2]) ? $m[2] : $m[1];
                $val = (float)$num;

                // Clamp to a sensible rating range
                if ($val < 0) $val = 0.0;
                if ($val > 10) $val = 10.0;

                return $val;
            }
        }

        return null;
    }
}
