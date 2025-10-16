<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Cache
 *
 * A tiny file-based cache for API responses.
 * Stores JSON files like { stored_at, ttl, value } under storage/cache/.
 *
 * Usage:
 *   $data = Cache::remember('tmdb:genres', 86400, function () use ($client) {
 *       // expensive HTTP call
 *       return $client->getJson(...);
 *   });
 */
class Cache
{
    /** Absolute path to the cache directory (set from config) */
    private static string $dir = '';

    /**
     * Initialize the cache directory path (call once at bootstrap).
     */
    public static function init(string $cacheDir): void
    {
        self::$dir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
    }

    /**
     * Main helper: return cached value if present & fresh; otherwise fetch, store, and return.
     *
     * @param string   $key   Arbitrary string key (we sha1() it for a safe filename).
     * @param int      $ttl   Time-to-live in seconds.
     * @param callable $fetch Function that returns the value to cache (anything JSON-serializable).
     *
     * @return mixed The cached or freshly-fetched value.
     */
    public static function remember(string $key, int $ttl, callable $fetch)
    {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // Not cached or expired → compute fresh value
        $value = $fetch();

        // Store it for next time (ignore store failures silently to avoid breaking the app)
        try {
            self::put($key, $value, $ttl);
        } catch (\Throwable $e) {
            // You can log this in dev if you want
        }

        return $value;
    }

    /**
     * Store a value under a key with a TTL.
     * The value should be JSON-serializable (array/scalar).
     */
    public static function put(string $key, $value, int $ttl): void
    {
        self::ensureReady();

        $payload = [
            'stored_at' => time(),
            'ttl'       => $ttl,
            'value'     => $value,
        ];

        $path = self::pathFor($key);
        // Use exclusive locking to avoid race conditions on concurrent writes
        file_put_contents($path, json_encode($payload), LOCK_EX);
    }

    /**
     * Get a cached value if it exists and is still valid.
     * Returns null if missing or expired or unreadable.
     */
    public static function get(string $key)
    {
        self::ensureReady();

        $path = self::pathFor($key);
        if (!is_file($path)) {
            return null; // never cached
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null; // unreadable
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['stored_at'], $data['ttl'])) {
            return null; // corrupted or unexpected
        }

        $expiresAt = (int)$data['stored_at'] + (int)$data['ttl'];
        if (time() >= $expiresAt) {
            // Expired → best effort cleanup; ignore failure
            @unlink($path);
            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Remove a cached entry (optional helper).
     */
    public static function forget(string $key): void
    {
        $path = self::pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Build a safe path for a cache key (sha1 avoids illegal filename chars).
     */
    private static function pathFor(string $key): string
    {
        $hash = sha1($key);
        return self::$dir . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    /**
     * Ensure the cache directory is set and exists.
     */
    private static function ensureReady(): void
    {
        if (self::$dir === '') {
            throw new \RuntimeException('Cache::init() must be called with a valid directory path.');
        }
        if (!is_dir(self::$dir)) {
            // Attempt to create directory tree (first run convenience)
            @mkdir(self::$dir, 0775, true);
        }
    }
}
