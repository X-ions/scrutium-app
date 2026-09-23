<?php

header('Content-Type: text/plain; charset=utf-8');

$root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

echo "PHP: " . PHP_VERSION . "\n";
echo "SAPI: " . PHP_SAPI . "\n";
echo "cwd: " . getcwd() . "\n";
echo "root: " . $root . "\n\n";

$paths = [
    'vendor',
    'vendor/autoload.php',
    'bootstrap/app.php',
    'config/app.php',
    'resources/views',
    'resources/views/pages/dashboard/ecommerce.blade.php',
    'public/index.php',
    'public/build/manifest.json',
    'routes/web.php',
];
foreach ($paths as $rel) {
    $full = $root . '/' . $rel;
    echo $rel . ': ' . (file_exists($full) ? 'yes' : 'NO') . "\n";
}

echo "\nAPP_KEY set: " . (getenv('APP_KEY') ? 'yes' : 'no') . "\n";
echo "APP_ENV: " . (getenv('APP_ENV') ?: '(empty)') . "\n";
echo "APP_DEBUG: " . (getenv('APP_DEBUG') ?: '(empty)') . "\n";
echo "APP_SERVICES_CACHE: " . (getenv('APP_SERVICES_CACHE') ?: '(empty)') . "\n";

echo "\n--- listing root ---\n";
$entries = @scandir($root) ?: [];
echo implode("\n", $entries) . "\n";
