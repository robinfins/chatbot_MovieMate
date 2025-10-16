<?php
declare(strict_types=1);

session_start();

/**
 * Enable error reporting (useful during development).
 * We'll switch this based on config later.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * 1. Load the config
 * This gives us API keys, cache directory path, etc.
 */
$config = require __DIR__ . '/../config/app.php';

/**
 * 2. Require our support classes
 * Later we’ll use Composer autoload, but for now we require them manually.
 */
require_once __DIR__ . '/../app/Support/HttpClient.php';
require_once __DIR__ . '/../app/Support/Cache.php';

use App\Support\HttpClient;
use App\Support\Cache;

/**
 * 3. Initialize the cache system
 * This sets the directory where cache files will be stored.
 */
Cache::init($config['cache_dir']);

require_once __DIR__ . '/../app/Support/Validator.php';
require_once __DIR__ . '/../app/Support/Intent.php';

use App\Support\Validator;
use App\Support\Intent;

$tests = [
    'Find a comedy over 7.5 from 2015 to 2020',
    'Find a movie starring Tom Hanks',
    'Tell me about "Inception"',
    "What's Interstellar about",
    'Find movies from 2020',
    'Recommend sci-fi 7+',
    'What’s it about?', // follow-up
];

$out = [];
foreach ($tests as $t) {
    $clean = Validator::sanitizeText($t);
    $out[] = [
        'input' => $t,
        'intent' => Intent::parse($clean),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;