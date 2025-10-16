<?php
declare(strict_types=1);

namespace App\Support;

use App\Support\Validator;

/**
 * Intent
 *
 * Rule-based intent parser that converts free text into a structured array:
 * [
 *   'name'        => string,            // one of the supported intents
 *   'genre'       => ?string,           // genre slug (e.g., 'comedy')
 *   'min_rating'  => ?float,            // 0..10
 *   'year'        => ?int,              // single year
 *   'year_range'  => ?array{int,int},   // [start, end]
 *   'actor'       => ?string,           // extracted actor name if present
 *   'title'       => ?string,           // extracted title if present
 * ]
 *
 * Supported intents (MVP):
 * - find_by_genre_and_rating
 * - find_by_actor_lead
 * - find_by_title
 * - details_followup
 * - find_by_year
 */
class Intent
{
    /**
     * Minimal English genre dictionary. These keys are what you'll map to TMDB later.
     * You can add aliases (e.g., 'romcom' => 'comedy') as needed.
     */
    private static array $genreMap = [
        'action'     => 'action',
        'adventure'  => 'adventure',
        'animation'  => 'animation',
        'comedy'     => 'comedy',
        'crime'      => 'crime',
        'documentary'=> 'documentary',
        'drama'      => 'drama',
        'family'     => 'family',
        'fantasy'    => 'fantasy',
        'history'    => 'history',
        'horror'     => 'horror',
        'music'      => 'music',
        'mystery'    => 'mystery',
        'romance'    => 'romance',
        'scifi'      => 'science fiction',
        'sci-fi'     => 'science fiction',
        'science fiction' => 'science fiction',
        'thriller'   => 'thriller',
        'war'        => 'war',
        'western'    => 'western',
    ];

    /**
     * Parse free text into an intent structure.
     */
    public static function parse(string $rawText): array
    {
        // 1) Clean & normalize text
        $text = Validator::sanitizeText($rawText);
        $lc   = mb_strtolower($text);

        // 2) Extract common slots using Validator
        $minRating = Validator::extractRating($lc);
        $yearRange = Validator::extractYearRange($lc);
        $year      = $yearRange ? null : Validator::extractYear($lc); // prefer range when present

        // 3) Try to detect a genre keyword (map to canonical value)
        $genre = self::extractGenre($lc);

        // 4) Simple “title” heuristic:
        //    If user says "tell me about <Title>" or "what is <Title> about", try to grab quoted or capitalized segments.
        //    This is intentionally conservative; details_followup will handle the "den/it" case via session.
        $title = self::extractQuotedTitle($text) ?? self::extractTitleHint($text);

        // 5) Actor extraction:
        //    Look for patterns like "starring Tom Hanks", "with Tom Hanks", "featuring Tom Hanks"
        $actor = self::extractActor($text);

        // 6) Intent routing (order matters; most specific first)
        // find_by_title: explicit “about”/“tell me about” or quoted title
        if (self::looksLikeFindByTitle($lc, $title)) {
            return [
                'name'       => 'find_by_title',
                'genre'      => null,
                'min_rating' => $minRating,
                'year'       => $year,
                'year_range' => $yearRange,
                'actor'      => null,
                'title'      => $title,
            ];
        }

        // find_by_actor_lead: explicit actor phrasing or "starring/with <name>"
        if ($actor !== null) {
            return [
                'name'       => 'find_by_actor_lead',
                'genre'      => $genre,
                'min_rating' => $minRating,
                'year'       => $year,
                'year_range' => $yearRange,
                'actor'      => $actor,
                'title'      => null,
            ];
        }

        // details_followup: follow-ups like "what's it about?", "who directed it?", "tell me more"
        if (self::looksLikeDetailsFollowup($lc)) {
            return [
                'name'       => 'details_followup',
                'genre'      => null,
                'min_rating' => null,
                'year'       => null,
                'year_range' => null,
                'actor'      => null,
                'title'      => $title, // may be null; controller can fall back to session
            ];
        }

        // find_by_genre_and_rating: has a genre OR rating, and looks like a "find/show/recommend" query
        if (self::looksLikeDiscovery($lc) && ($genre !== null || $minRating !== null || $year !== null || $yearRange !== null)) {
            return [
                'name'       => 'find_by_genre_and_rating',
                'genre'      => $genre,
                'min_rating' => $minRating ?? 7.0, // sensible default for “highly-rated”
                'year'       => $year,
                'year_range' => $yearRange,
                'actor'      => null,
                'title'      => null,
            ];
        }

        // find_by_year: if it looks like a find and only year filters are present
        if (self::looksLikeDiscovery($lc) && !$genre && !$minRating && ($year || $yearRange)) {
            return [
                'name'       => 'find_by_year',
                'genre'      => null,
                'min_rating' => null,
                'year'       => $year,
                'year_range' => $yearRange,
                'actor'      => null,
                'title'      => null,
            ];
        }

        // Fallback: treat as discovery with no clear signals; controller can respond with help text
        return [
            'name'       => 'find_by_genre_and_rating',
            'genre'      => $genre,
            'min_rating' => $minRating,
            'year'       => $year,
            'year_range' => $yearRange,
            'actor'      => $actor,
            'title'      => $title,
        ];
    }

    /**
     * Try to match a known genre keyword in the text.
     */
    private static function extractGenre(string $lc): ?string
    {
        // check multi-word genres first
        $multi = ['science fiction'];
        foreach ($multi as $m) {
            if (mb_strpos($lc, $m) !== false) {
                return 'science fiction';
            }
        }

        // single token checks (include aliases like 'scifi'/'sci-fi')
        foreach (self::$genreMap as $key => $canonical) {
            // match as a whole word to avoid accidental matches (e.g., 'action' inside another word)
            if (preg_match('/\b' . preg_quote($key, '/') . '\b/u', $lc)) {
                return $canonical;
            }
        }
        return null;
    }

    /**
     * Extract title inside quotes if present: "Inception", 'The Matrix'
     */
    private static function extractQuotedTitle(string $text): ?string
    {
        if (preg_match('/["“](.+?)["”]/u', $text, $m)) {
            $t = trim($m[1]);
            return mb_strlen($t) >= 2 ? $t : null;
        }
        if (preg_match("/'(.*?)'/u", $text, $m)) {
            $t = trim($m[1]);
            return mb_strlen($t) >= 2 ? $t : null;
        }
        return null;
    }

    /**
     * Heuristic for unquoted titles when phrased like:
     * "tell me about Inception", "what is Interstellar about"
     */
    private static function extractTitleHint(string $text): ?string
    {
        $patterns = [
            '/\btell me about\s+([A-Z][\w\s:\'-]+)/u',
            '/\bwhat(?:\'s|\s+is)?\s+([A-Z][\w\s:\'-]+)\s+about\b/u',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                $title = trim($m[1]);
                // stop at trailing filler words (basic cut)
                $title = preg_replace('/\s+(please|thanks?)\b.*$/i', '', $title) ?? $title;
                return mb_strlen($title) >= 2 ? $title : null;
            }
        }
        return null;
    }

    /**
     * Extract actor when phrased like:
     * "starring Tom Hanks", "with Tom Hanks", "featuring Tom Hanks"
     */
    private static function extractActor(string $text): ?string
    {
        $patterns = [
            '/\bstarring\s+([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+)*)/u',
            '/\bwith\s+([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+)*)/u',
            '/\bfeaturing\s+([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+)*)/u',
            '/\bstar(?:ring)?\s+([A-Z][A-Za-z\.\'\-]+(?:\s+[A-Z][A-Za-z\.\'\-]+)*)/u',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                $name = trim($m[1]);
                // basic sanity: must contain at least a first name
                if (mb_strlen($name) >= 2) {
                    return $name;
                }
            }
        }
        return null;
    }

    /**
     * True if the message looks like a discovery request ("find/show/recommend").
     */
    private static function looksLikeDiscovery(string $lc): bool
    {
        return (bool) preg_match('/\b(find|show|recommend|suggest|give me|any|looking for)\b/u', $lc);
    }

    /**
     * True if the message looks like a title request (about/explain).
     */
    private static function looksLikeFindByTitle(string $lc, ?string $title): bool
    {
        if ($title !== null) return true;
        return (bool) preg_match('/\b(tell me about|what(\'s| is)? .* about|plot of)\b/u', $lc);
    }

    /**
     * True if it looks like a follow-up on the last movie (using "it/that/this/one").
     */
    private static function looksLikeDetailsFollowup(string $lc): bool
    {
        return (bool) preg_match('/\b(what(\'s| is)? it about|who directed it|tell me more|details|more info)\b/u', $lc);
    }
}