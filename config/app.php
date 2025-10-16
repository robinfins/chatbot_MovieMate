<?php
/**
 * Central app configuration.
 * Keep secrets in environment variables when possible.
 * For local dev, you can temporarily paste keys here—but don't commit real keys.
 */
return [
    // Prefer environment variables; fallback to empty placeholder in dev
    'tmdb_api_key' => getenv('TMDB_API_KEY') ?: '4cb09cadea77ef174ac84251e61466fc',
    'omdb_api_key' => getenv('OMDB_API_KEY') ?: '7823690',

    // Absolute path to cache directory (…/storage/cache)
    'cache_dir' => dirname(__DIR__) . '/storage/cache',

    // Time-to-live (seconds) for different caches
    'ttl' => [
        'tmdb_discover' => 1800,   // 30 minutes
        'tmdb_genres'   => 86400,  // 24 hours
        'omdb_details'  => 86400,  // 24 hours
    ],

    // 'dev' shows errors; 'prod' hides them
    'env' => 'dev',
];