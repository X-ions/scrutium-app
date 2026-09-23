<?php

/**
 * Vercel serverless entrypoint for Laravel (vercel-php).
 * Must run before public/index.php so cache/storage paths are writable.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$tmp = '/tmp/scrutium';
$dirs = [
    $tmp,
    $tmp . '/views',
    $tmp . '/storage',
    $tmp . '/storage/app',
    $tmp . '/storage/app/public',
    $tmp . '/storage/framework',
    $tmp . '/storage/framework/cache',
    $tmp . '/storage/framework/cache/data',
    $tmp . '/storage/framework/sessions',
    $tmp . '/storage/framework/views',
    $tmp . '/storage/logs',
];
foreach ($dirs as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// Always override: Vercel filesystem is read-only except /tmp.
$forced = [
    'APP_PACKAGES_CACHE' => $tmp . '/packages.php',
    'APP_SERVICES_CACHE' => $tmp . '/services.php',
    'APP_EVENTS_CACHE' => $tmp . '/events.php',
    'VIEW_COMPILED_PATH' => $tmp . '/views',
    'APP_STORAGE_PATH' => $tmp . '/storage',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'APP_MAINTENANCE_STORE' => 'array',
    'CACHE_STORE' => 'array',
    'CACHE_DRIVER' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'LOG_CHANNEL' => 'stderr',
    'LOG_LEVEL' => 'debug',
];
foreach ($forced as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$defaults = [
    'APP_NAME' => 'Scrutium',
    'APP_ENV' => 'production',
    'APP_URL' => 'https://scrutium.vercel.app',
    'FILESYSTEM_DISK' => 'local',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $tmp . '/database.sqlite',
];
foreach ($defaults as $key => $value) {
    if (getenv($key) === false || getenv($key) === '') {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

if (! is_file($tmp . '/packages.php')) {
    file_put_contents($tmp . '/packages.php', "<?php\nreturn array (\n);\n");
}

if (empty(getenv('APP_KEY'))) {
    $key = 'base64:' . base64_encode(random_bytes(32));
    putenv("APP_KEY={$key}");
    $_ENV['APP_KEY'] = $key;
    $_SERVER['APP_KEY'] = $key;
}

$dbPath = getenv('DB_DATABASE') ?: ($tmp . '/database.sqlite');
if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'sqlite' && ! file_exists($dbPath)) {
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
        echo $current::class . ': ' . $current->getMessage() . "\n";
        echo $current->getFile() . ':' . $current->getLine() . "\n\n";
        if ($i === 0) {
            echo $current->getTraceAsString() . "\n\n";
        }
        $current = $current->getPrevious();
        $i++;
    }
}
