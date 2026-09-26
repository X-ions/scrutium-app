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

        preg_match(
            '/function scrutium_prepare_database_url\(string \$url\):\s*string\s*\{.*?\n\}/s',
            $source,
            $matches
        );

        $this->assertNotEmpty($matches, 'Could not locate scrutium_prepare_database_url().');

        eval($matches[0]);

        $callable = 'scrutium_prepare_database_url';

        return $callable;
    }

    public function test_a_neon_pooler_url_carries_the_endpoint_id(): void
    {
        $prepare = $this->neonUrlHelper();

        $result = $prepare(
            'postgresql://user:pass@ep-spring-forest-b7uollmz-pooler.c-13.us-east-1.aws.neon.tech/neondb?sslmode=require'
        );

        $this->assertStringContainsString('ep-spring-forest-b7uollmz-pooler', $result, 'Host should be preserved.');

        parse_str((string) parse_url($result, PHP_URL_QUERY), $query);

        // Without this the driver raises SQLSTATE[08006] "Endpoint ID is not
        // specified", which broke every session read and so every login.
        $this->assertArrayHasKey('options', $query, 'A Neon pooler URL must carry an options parameter.');
        $this->assertSame('endpoint=ep-spring-forest-b7uollmz', $query['options']);

        // The existing hardening must survive.
        $this->assertSame('require', $query['sslmode']);
        $this->assertSame('disable', $query['channel_binding']);
    }

    public function test_a_direct_neon_endpoint_also_carries_the_endpoint_id(): void
    {
        $prepare = $this->neonUrlHelper();

        $result = $prepare('postgres://user:pass@ep-quiet-pond-a1b2c3d4.us-east-2.aws.neon.tech/neondb');

        parse_str((string) parse_url($result, PHP_URL_QUERY), $query);

        $this->assertSame('endpoint=ep-quiet-pond-a1b2c3d4', $query['options']);
    }

    public function test_a_non_neon_url_is_left_without_endpoint_options(): void
    {
        $prepare = $this->neonUrlHelper();

        $result = $prepare('postgres://user:pass@db.example.com:5432/app');

        parse_str((string) parse_url($result, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey(
            'options',
            $query,
            'Only Neon hosts should get the endpoint option; a plain Postgres host must be untouched.'
        );
    }
}
