<?php

header('Content-Type: text/plain; charset=utf-8');

echo "PHP: " . PHP_VERSION . "\n";
echo "SAPI: " . PHP_SAPI . "\n";
echo "cwd: " . getcwd() . "\n";
echo "__DIR__: " . __DIR__ . "\n\n";

echo "vendor exists: " . (is_dir(__DIR__ . '/../vendor') ? 'yes' : 'no') . "\n";
echo "autoload exists: " . (file_exists(__DIR__ . '/../vendor/autoload.php') ? 'yes' : 'no') . "\n";
echo "bootstrap exists: " . (file_exists(__DIR__ . '/../bootstrap/app.php') ? 'yes' : 'no') . "\n";
echo "public/index exists: " . (file_exists(__DIR__ . '/../public/index.php') ? 'yes' : 'no') . "\n\n";

echo "APP_KEY set: " . (getenv('APP_KEY') ? 'yes' : 'no') . "\n";
echo "APP_ENV: " . (getenv('APP_ENV') ?: '(empty)') . "\n";
echo "APP_DEBUG: " . (getenv('APP_DEBUG') ?: '(empty)') . "\n\n";

echo "Writable /tmp: " . (is_writable('/tmp') ? 'yes' : 'no') . "\n";

try {
    require __DIR__ . '/../vendor/autoload.php';
    echo "autoload: OK\n";
} catch (Throwable $e) {
    echo "autoload ERROR: " . $e->getMessage() . "\n";
}

echo "\n--- phpinfo (extensions) ---\n";
echo "pdo_sqlite: " . (extension_loaded('pdo_sqlite') ? 'yes' : 'no') . "\n";
echo "mbstring: " . (extension_loaded('mbstring') ? 'yes' : 'no') . "\n";
echo "openssl: " . (extension_loaded('openssl') ? 'yes' : 'no') . "\n";
echo "tokenizer: " . (extension_loaded('tokenizer') ? 'yes' : 'no') . "\n";
echo "xml: " . (extension_loaded('xml') ? 'yes' : 'no') . "\n";
echo "ctype: " . (extension_loaded('ctype') ? 'yes' : 'no') . "\n";
echo "json: " . (extension_loaded('json') ? 'yes' : 'no') . "\n";
echo "bcmath: " . (extension_loaded('bcmath') ? 'yes' : 'no') . "\n";
echo "fileinfo: " . (extension_loaded('fileinfo') ? 'yes' : 'no') . "\n";
