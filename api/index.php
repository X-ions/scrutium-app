<?php

/**
 * Vercel serverless entrypoint for Laravel (vercel-php).
 */

// Writable paths — Vercel filesystem is read-only except /tmp
$tmp = '/tmp/scrutium';
foreach ([
    $tmp,
    $tmp . '/storage',
    $tmp . '/storage/framework',
    $tmp . '/storage/framework/cache',
    $tmp . '/storage/framework/cache/data',
    $tmp . '/storage/framework/sessions',
    $tmp . '/storage/framework/views',
    $tmp . '/storage/logs',
    $tmp . '/bootstrap',
    $tmp . '/bootstrap/cache',
] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// Point Laravel caches / views at /tmp when not already set
$defaults = [
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'LOG_CHANNEL' => 'stderr',
    'SESSION_DRIVER' => 'cookie',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'FILESYSTEM_DISK' => 'local',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $tmp . '/database.sqlite',
    'VIEW_COMPILED_PATH' => $tmp . '/storage/framework/views',
    'APP_SERVICES_CACHE' => $tmp . '/bootstrap/cache/services.php',
    'APP_PACKAGES_CACHE' => $tmp . '/bootstrap/cache/packages.php',
    'APP_CONFIG_CACHE' => $tmp . '/bootstrap/cache/config.php',
    'APP_ROUTES_CACHE' => $tmp . '/bootstrap/cache/routes.php',
    'APP_EVENTS_CACHE' => $tmp . '/bootstrap/cache/events.php',
];

foreach ($defaults as $key => $value) {
    if (getenv($key) === false || getenv($key) === '') {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Ensure sqlite file exists if using sqlite
$dbPath = getenv('DB_DATABASE') ?: ($tmp . '/database.sqlite');
if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'sqlite' && !file_exists($dbPath)) {
    @touch($dbPath);
}

require __DIR__ . '/../public/index.php';
