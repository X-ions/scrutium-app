<?php

use Tests\TestCase;

/**
 * Production runs PHP 8.5 while local development may run an older build with
 * no pdo_pgsql at all, so both sides of that split are guarded here.
 *
 * The original bug: config/database.php named PDO::PGSQL_ATTR_DISABLE_PREPARES,
 * which is deprecated from PHP 8.5. Merely naming it raised a deprecation
 * notice, and because api/index.php turned display_errors on, that notice was
 * printed before the response headers were emitted. PHP discards headers once
 * output has begun, so Set-Cookie never left the server, no visitor had a
 * session, and every form post failed with a 419.
 */
class ProductionOutputSafetyTest extends TestCase
{
    public function test_the_vercel_entrypoint_never_prints_errors(): void
    {
        $source = (string) file_get_contents(base_path('api/index.php'));

        preg_match_all("/ini_set\(\s*'display_(errors|startup_errors)'\s*,\s*'([^']*)'/", $source, $matches);

        $this->assertNotEmpty($matches[1], 'The ini_set display_errors calls should still be present.');

        foreach ($matches[1] as $index => $setting) {
            $this->assertNotSame(
                '1',
                trim($matches[2][$index]),
                "display_{$setting} must be off: printed output makes PHP drop Set-Cookie and leaks internals."
            );
        }
    }

    public function test_the_pgsql_config_prefers_the_non_deprecated_constant(): void
    {
        $source = (string) file_get_contents(config_path('database.php'));

        // Compare positions in code only; the explanatory comment names both.
        $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

        $modern = strpos($code, 'Pdo\Pgsql::ATTR_DISABLE_PREPARES');
        $legacy = strpos($code, 'PDO::PGSQL_ATTR_DISABLE_PREPARES');

        $this->assertNotFalse($modern, 'The config should resolve the PHP 8.5 constant.');

        if ($legacy !== false) {
            $this->assertLessThan(
                $legacy,
                $modern,
                'The PHP 8.5 constant must be preferred, with the deprecated one only as a fallback for older runtimes.'
            );
        }
    }

    public function test_the_pgsql_connection_keeps_its_prepared_statement_settings(): void
    {
        $options = config('database.connections.pgsql.options', []);

        $this->assertArrayHasKey(PDO::ATTR_EMULATE_PREPARES, $options);
        $this->assertTrue($options[PDO::ATTR_EMULATE_PREPARES]);

        // Only assert the disable-prepares flag where the runtime can define it.
        foreach (['Pdo\Pgsql::ATTR_DISABLE_PREPARES', 'PDO::PGSQL_ATTR_DISABLE_PREPARES'] as $constant) {
            if (defined($constant)) {
                $this->assertArrayHasKey(constant($constant), $options);

                return;
            }
        }

        $this->assertTrue(true, 'Neither PDO pgsql constant is available on this runtime; nothing to assert.');
    }

    /**
     * api/index.php must run before the Composer autoloader exists, so the
     * Neon URL helper is a standalone function. Load just that function into
     * this test's scope and exercise it for real.
     */
    private function neonUrlHelper(): callable
    {
        static $callable = null;

        if ($callable !== null) {
            return $callable;
        }

        $source = (string) file_get_contents(base_path('api/index.php'));

        // Both helpers, in dependency order: prepare_database_url() calls putenv().
        foreach ([
            '/function scrutium_putenv\(string \$key, string \$value\):\s*void\s*\{.*?\n\}/s',
            '/function scrutium_prepare_database_url\(string \$url\):\s*string\s*\{.*?\n\}/s',
        ] as $pattern) {
            preg_match($pattern, $source, $matches);

            $this->assertNotEmpty($matches, 'Could not extract a helper from api/index.php.');

            eval($matches[0]);
        }

        $callable = 'scrutium_prepare_database_url';

        return $callable;
    }

    public function test_a_neon_pooler_url_does_not_put_options_in_the_url(): void
    {
        $prepare = $this->neonUrlHelper();

        $result = $prepare(
            'postgresql://user:pass@ep-spring-forest-b7uollmz-pooler.c-13.us-east-1.aws.neon.tech/neondb?sslmode=require'
        );

        parse_str((string) parse_url($result, PHP_URL_QUERY), $query);

        // A URL-level "options" would be fed to Connector::getOptions(), which
        // calls array_diff_key() on it and fails because it expects a PDO
        // option map, not a libpq parameter string.
        $this->assertArrayNotHasKey('options', $query, 'libpq options must not travel in the connection URL.');

        // The existing hardening must survive.
        $this->assertSame('require', $query['sslmode']);
        $this->assertSame('disable', $query['channel_binding']);
    }

    public function test_the_connector_puts_the_endpoint_in_the_dsn(): void
    {
        $connector = new \App\Database\Connectors\NeonPostgresConnector;

        $config = [
            'host' => 'ep-spring-forest-b7uollmz-pooler.c-13.us-east-1.aws.neon.tech',
            'port' => '5432',
            'database' => 'neondb',
            'neon_endpoint' => 'ep-spring-forest-b7uollmz',
        ];

        $dsn = (function (array $config) {
            return $this->getDsn($config);
        })->call($connector, $config);

        $this->assertStringContainsString('endpoint=ep-spring-forest-b7uollmz', rawurldecode($dsn));

        // And the libpq parameter string must not leak into the PDO options.
        $options = $connector->getOptions($config);
        $this->assertIsArray($options);
        $this->assertArrayNotHasKey('neon_endpoint', $options);
    }

    public function test_the_connector_keeps_string_options_out_of_the_pdo_option_map(): void
    {
        $connector = new \App\Database\Connectors\NeonPostgresConnector;

        // This is the shape that produced array_diff_key(array, string).
        $options = $connector->getOptions([
            'host' => 'ep-x.us-east-1.aws.neon.tech',
            'database' => 'neondb',
            'options' => 'endpoint=ep-x',
        ]);

        $this->assertIsArray($options, 'getOptions() must always return an array.');
    }

    public function test_the_pgsql_config_carries_the_neon_endpoint(): void
    {
        // Only meaningful when api/index.php has derived one; on a local run it
        // is simply null, which is fine and means the connector adds nothing.
        $config = config('database.connections.pgsql');

        $this->assertArrayHasKey('neon_endpoint', $config);
    }
}
