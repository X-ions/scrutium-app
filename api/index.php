<?php

/**
 * Vercel serverless entrypoint for Laravel (vercel-php).
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$tmp = '/tmp/scrutium';
$dirs = [
    $tmp . '/storage/app/public',
    $tmp . '/storage/framework/cache/data',
    $tmp . '/storage/framework/sessions',
    $tmp . '/storage/framework/views',
    $tmp . '/storage/logs',
    $tmp . '/bootstrap/cache',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

$defaults = [
    'APP_NAME' => 'Scrutium',
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'https://scrutium.vercel.app',
    'APP_STORAGE_PATH' => $tmp . '/storage',
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

if (empty(getenv('APP_KEY'))) {
    $key = 'base64:' . base64_encode(random_bytes(32));
    putenv("APP_KEY={$key}");
    $_ENV['APP_KEY'] = $key;
    $_SERVER['APP_KEY'] = $key;
}

$dbPath = getenv('DB_DATABASE') ?: ($tmp . '/database.sqlite');
if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'sqlite' && !file_exists($dbPath)) {
    @touch($dbPath);
}

try {
    require __DIR__ . '/../public/index.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SCRUTIUM BOOT ERROR\n\n";
    $current = $e;
    $i = 0;
    while ($current && $i < 5) {
        echo "--- Exception #{$i} ---\n";
        echo get_class($current) . ': ' . $current->getMessage() . "\n";
        echo $current->getFile() . ':' . $current->getLine() . "\n\n";
        if ($i === 0) {
            echo $current->getTraceAsString() . "\n\n";
        }
        $current = $current->getPrevious();
        $i++;
    }
}
