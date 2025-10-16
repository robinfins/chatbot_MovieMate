<?php
declare(strict_types=1);

namespace App\Support;

/**
 * HttpClient
 *
 * This class is a small wrapper around PHP's cURL functions.
 * It lets us make HTTP GET requests in a consistent way and
 * always returns results in the same structured format.
 *
 * We’ll use this for calling external APIs like TMDB and OMDb.
 */
class HttpClient
{
    /**
     * Perform an HTTP GET request.
     *
     * @param string $url     The full URL (including query parameters).
     * @param array  $headers Optional HTTP headers, e.g. ["Authorization: Bearer ..."]
     *
     * @return array Result in a standardized format:
     *               [
     *                 'ok'     => bool,         // true if status code 200–299
     *                 'status' => int,          // HTTP status code (0 if cURL failed)
     *                 'body'   => string|null,  // raw response body
     *                 'error'  => string|null   // error message if something went wrong
     *               ]
     */
    public static function get(string $url, array $headers = []): array
    {
        // Initialize a new cURL session
        $ch = curl_init();

        // Configure options for this request
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,                 // the full URL to call
            CURLOPT_RETURNTRANSFER => true,      // return the response as a string (not directly print it)
            CURLOPT_FOLLOWLOCATION => true,      // follow redirects if the server says so (e.g., 301 → 200)
            CURLOPT_TIMEOUT => 8,                // total time to wait (seconds) before giving up
            CURLOPT_CONNECTTIMEOUT => 5,         // max time to wait for connection (seconds)
            CURLOPT_HTTPHEADER => array_merge([  // set default + extra headers
                'Accept: application/json',      // ask server for JSON if possible
                'User-Agent: FilmvennBot/1.0',   // identify our app (some APIs require this)
            ], $headers),
        ]);

        // Execute the HTTP request
        $body = curl_exec($ch);

        // Capture any error from cURL itself (e.g., timeout, DNS failure)
        $error = curl_error($ch);

        // Get the HTTP status code (e.g., 200, 404, 500)
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Always close the cURL handle to free resources
        curl_close($ch);

        // If the request completely failed (body === false), return an error result
        if ($body === false) {
            return [
                'ok' => false,        // request failed
                'status' => 0,        // no HTTP status code (since it didn’t connect)
                'body' => null,       // no response body
                'error' => $error ?: 'Unknown cURL error', // error message
            ];
        }

        // Otherwise, return a standardized result
        return [
            'ok' => $status >= 200 && $status < 300, // only mark success if status code is 2xx
            'status' => $status,                     // HTTP status code
            'body' => $body,                         // raw response body (string)
            'error' => $status >= 200 && $status < 300 ? null : $error,
        ];
    }

    /**
     * Perform an HTTP GET request and decode JSON directly.
     *
     * This is a convenience wrapper around get().
     * It calls get(), checks the response, and then runs json_decode().
     *
     * @param string $url
     * @param array  $headers
     *
     * @return array Result in the same format as get(), but with
     *               'body' containing a PHP array instead of a raw string.
     */
    public static function getJson(string $url, array $headers = []): array
    {
        // First perform the GET request
        $res = self::get($url, $headers);

        // If it failed or body is empty, just return the same error result
        if (!$res['ok'] || !$res['body']) {
            return $res;
        }

        // Try decoding JSON into a PHP associative array
        $decoded = json_decode($res['body'], true);

        // Check if decoding worked
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'ok' => false,
                'status' => $res['status'],
                'body' => null,
                'error' => 'JSON decode error: ' . json_last_error_msg(),
            ];
        }

        // Replace raw body with decoded array
        $res['body'] = $decoded;
        return $res;
    }
}