<?php

/**
 * Vercel serverless entrypoint for Laravel (vercel-php).
 * Must run before public/index.php so cache/storage paths are writable.
 */
/*
| Errors are logged, never printed. Two reasons:
|
| 1. Correctness. Emitting *any* output before the response headers are sent
|    makes PHP discard them, including Set-Cookie. A stray deprecation notice
|    was therefore costing every visitor their session and turning every form
|    post into a 419.
| 2. Security. display_errors leaks absolute paths, SQL and stack frames.
|
| Laravel's own handler and the stderr log channel report failures.
*/
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$tmp = '/tmp/scrutium';
$dirs = [
    $tmp,
    $tmp.'/views',
    $tmp.'/storage',
    $tmp.'/storage/app',
    $tmp.'/storage/app/public',
    $tmp.'/storage/framework',
    $tmp.'/storage/framework/cache',
    $tmp.'/storage/framework/cache/data',
    $tmp.'/storage/framework/sessions',
    $tmp.'/storage/framework/views',
    $tmp.'/storage/logs',
];
foreach ($dirs as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

$forced = [
    'APP_PACKAGES_CACHE' => $tmp.'/packages.php',
    'APP_SERVICES_CACHE' => $tmp.'/services.php',
    'APP_EVENTS_CACHE' => $tmp.'/events.php',
    'VIEW_COMPILED_PATH' => $tmp.'/views',
    'APP_STORAGE_PATH' => $tmp.'/storage',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'APP_MAINTENANCE_STORE' => 'array',
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
];
foreach ($defaults as $key => $value) {
    if (getenv($key) === false || getenv($key) === '') {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

if ((getenv('FILESYSTEM_DISK') === false || getenv('FILESYSTEM_DISK') === '') && (! empty(getenv('AWS_BUCKET')) || ! empty(getenv('S3_BUCKET')))) {
    scrutium_putenv('FILESYSTEM_DISK', 's3');
}

$neonUrl = scrutium_env_first([
    'DB_URL',
    'DATABASE_URL',
    'POSTGRES_URL',
    'POSTGRES_PRISMA_URL',
    'DATABASE_URL_UNPOOLED',
    'POSTGRES_URL_NON_POOLING',
]);

if (is_string($neonUrl) && $neonUrl !== '') {
    $neonUrl = scrutium_prepare_database_url($neonUrl);
    scrutium_putenv('DB_URL', $neonUrl);
    scrutium_putenv('DB_CONNECTION', 'pgsql');
    scrutium_putenv('DB_SSLMODE', 'require');

    $sessionDriver = getenv('SESSION_DRIVER');
    if ($sessionDriver === false || $sessionDriver === '' || $sessionDriver === 'array') {
        scrutium_putenv('SESSION_DRIVER', 'database');
    }
    $cacheStore = getenv('CACHE_STORE');
    if ($cacheStore === false || $cacheStore === '' || $cacheStore === 'array') {
        scrutium_putenv('CACHE_STORE', 'database');
    }
} else {
    scrutium_putenv('DB_CONNECTION', getenv('DB_CONNECTION') ?: 'sqlite');
    $dbPath = getenv('DB_DATABASE') ?: ($tmp.'/database.sqlite');
    scrutium_putenv('DB_DATABASE', $dbPath);
    if (! file_exists($dbPath)) {
        @touch($dbPath);
    }
}

if (! is_file($tmp.'/packages.php')) {
    file_put_contents($tmp.'/packages.php', "<?php\nreturn array (\n);\n");
}

if (empty(getenv('APP_KEY'))) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SCRUTIUM CONFIG ERROR\n\n";
    echo "APP_KEY environment variable is required but not set.\n";
    echo "Generate a key locally with: php artisan key:generate --show\n";
    echo "Then add it to your Vercel project environment variables.\n";
    exit(1);
}

try {
    require __DIR__.'/../public/index.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SCRUTIUM BOOT ERROR\n\n";
    $current = $e;
    $i = 0;
    while ($current && $i < 5) {
        echo "--- Exception #{$i} ---\n";
        echo $current::class.': '.$current->getMessage()."\n";
        echo $current->getFile().':'.$current->getLine()."\n\n";
        if ($i === 0) {
            echo $current->getTraceAsString()."\n\n";
        }
        $current = $current->getPrevious();
        $i++;
    }
}

function scrutium_putenv(string $key, string $value): void
{
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

/** @param list<string> $keys */
function scrutium_env_first(array $keys): ?string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (! empty($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }
    }

    return null;
}

function scrutium_prepare_database_url(string $url): string
{
    $url = preg_replace('#^postgresql://#i', 'postgres://', $url) ?: $url;
    $parts = parse_url($url);
    if (! is_array($parts) || empty($parts['host'])) {
        return $url;
    }

    $host = $parts['host'];
    $endpoint = null;
    // Neon-style endpoint detection (ep-*)
    if (preg_match('/^(ep-[a-z0-9-]+)/i', $host, $matches) === 1) {
        $endpoint = preg_replace('/-pooler$/i', '', $matches[1]) ?: $matches[1];
    }

    $password = urldecode((string) ($parts['pass'] ?? ''));

    parse_str($parts['query'] ?? '', $query);
    $query['sslmode'] = $query['sslmode'] ?? 'require';
    // channel_binding=require breaks PHP PDO pgsql on Vercel/Neon
    $query['channel_binding'] = 'disable';

    // Neon pooled endpoints (ep-*-pooler.<region>.aws.neon.tech) terminate TLS
    // with SNI, so libpq must be told which endpoint it is reaching:
    //   SQLSTATE[08006] "Endpoint ID is not specified"
    // That parameter only survives if it reaches libpq intact, which means
    // surviving Laravel's config parsing, the DSN builder and PDO's option map.
    //
    // Rather than depend on all of that, talk to the endpoint directly: drop
    // the "-pooler" label and the connection needs no extra parameter at all.
    // The direct and pooled URLs share credentials, so this is a host rewrite
    // and nothing else. The cost is losing PgBouncer's connection pooling,
    // which is an optimisation, not a requirement.
    if ($endpoint !== null) {
        $host = $endpoint.'.'.preg_replace('/^[^.]+\./', '', $host);

        /*
         * Neon enforces SNI, so every connection needs the endpoint id:
         *   SQLSTATE[08006] "Endpoint ID is not specified"
         *
         * Three channels were tried and all fail with this runtime, because the
         * bundled libpq has no SNI support and PDO_PGSQL only forwards a
         * whitelist of DSN keywords:
         *
         *   ?options=endpoint%3D... on the URL -> Laravel's Connector::getOptions()
         *                                            expects a PDO option map and
         *                                            throws "array_diff_key(): arg 2
         *                                            must be of type array"
         *   options=... inside the pgsql: DSN -> dropped by PDO_PGSQL before libpq
         *   PGOPTIONS environment variable   -> not honoured by the bundled libpq
         *
         * Neon's documented workaround D is the channel that works: libpq parses
         * connection parameters out of the password field, and PDO passes the
         * password through untouched. This is the workaround Neon's own Laravel
         * guide recommends for older PDO_PGSQL drivers.
         *
         * Dropping "-pooler" above gives up PgBouncer connection pooling, which
         * is an optimisation rather than a requirement.
         */
        // Also exported for NeonPostgresConnector, which covers the pooled case
        // should the pooler host come back.
        scrutium_putenv('PGOPTIONS', 'endpoint='.$endpoint);
        scrutium_putenv('DB_NEON_ENDPOINT', $endpoint);
    }

    $user = rawurlencode(urldecode((string) ($parts['user'] ?? '')));

    /*
     * A Neon password carries the "endpoint=<id>$" prefix, and the "=" and "$"
     * separators must reach libpq verbatim. They cannot travel inside the
     * connection URL: every layer that handles a URL percent-encodes the
     * password, which turns them into "%3D" and "%24" and PostgreSQL then
     * rejects the connection with "invalid command-line argument for server
     * process".
     *
     * So the password is removed from the URL entirely and handed to the
     * application through the environment instead. config/database.php already
     * reads DB_PASSWORD, and because the URL no longer carries a password
     * component, nothing re-encodes or overrides it.
     */
    if ($endpoint !== null) {
        /*
         * The password may live in the URL or in DB_PASSWORD already, and Neon
         * credentials are sometimes supplied split across both. Prefer the one
         * that is actually populated, and never discard an existing
         * DB_PASSWORD by overwriting it with an empty one.
         */
        $existing = getenv('DB_PASSWORD');
        $secret = $password !== ''
            ? $password
            : (is_string($existing) ? $existing : '');

        if (! str_starts_with($secret, 'endpoint=')) {
            $secret = 'endpoint='.$endpoint.'$'.$secret;
        }

        scrutium_putenv('DB_PASSWORD', $secret);
        $password = '';
    }

    $password = rawurlencode($password);

    /*
     * The colon must be omitted along with the password, not left dangling.
     * "user:@host" parses with an empty "pass" component, and Laravel merges the
     * URL's parts *over* the connection config, so that empty string overwrites
     * the DB_PASSWORD set above and the driver is handed no password at all:
     *   SQLSTATE[08006] fe_sendauth: no password supplied
     */
    $auth = $password === '' ? $user : $user.':'.$password;
    $port = isset($parts['port']) ? ':'.$parts['port'] : '';
    $path = $parts['path'] ?? '/neondb';

    return 'postgres://'.$auth.'@'.$host.$port.$path.'?'.http_build_query($query);
}
