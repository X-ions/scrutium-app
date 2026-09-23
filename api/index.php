<?php

/**
 * Vercel serverless entrypoint for Laravel (vercel-php).
 * Does not rely on public/index.php so we can override storage path.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Writable paths — Vercel is read-only except /tmp
$tmp = '/tmp/scrutium';
$dirs = [
    $tmp,
    $tmp . '/storage',
    $tmp . '/storage/app',
    $tmp . '/storage/app/public',
    $tmp . '/storage/framework',
    $tmp . '/storage/framework/cache',
    $tmp . '/storage/framework/cache/data',
    $tmp . '/storage/framework/sessions',
    $tmp . '/storage/framework/views',
    $tmp . '/storage/logs',
    $tmp . '/bootstrap',
    $tmp . '/bootstrap/cache',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// Sensible serverless defaults (do not override if already set in Vercel)
$defaults = [
    'APP_NAME' => 'Scrutium',
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'https://scrutium.vercel.app',
    'LOG_CHANNEL' => 'stderr',
    'LOG_LEVEL' => 'debug',
    'SESSION_DRIVER' => 'cookie',
    'SESSION_LIFETIME' => '120',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'FILESYSTEM_DISK' => 'local',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $tmp . '/database.sqlite',
    'VIEW_COMPILED_PATH' => $tmp . '/storage/framework/views',
];

foreach ($defaults as $key => $value) {
    if (getenv($key) === false || getenv($key) === '') {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Temporary APP_KEY if not set — allows boot so real errors surface.
// Replace with a real key in Vercel Project Settings ASAP.
if (empty(getenv('APP_KEY'))) {
    $key = 'base64:' . base64_encode(random_bytes(32));
    putenv("APP_KEY={$key}");
    $_ENV['APP_KEY'] = $key;
    $_SERVER['APP_KEY'] = $key;
}

// SQLite file
$dbPath = getenv('DB_DATABASE') ?: ($tmp . '/database.sqlite');
if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'sqlite' && !file_exists($dbPath)) {
    @touch($dbPath);
}

// Autoload + bootstrap
require __DIR__ . '/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__ . '/../bootstrap/app.php';

// Critical: point storage (logs, cache, sessions, views) at /tmp
$app->useStoragePath($tmp . '/storage');

$app->handleRequest(Request::capture());
