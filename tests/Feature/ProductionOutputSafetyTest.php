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
}
