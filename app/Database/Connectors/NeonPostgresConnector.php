<?php

namespace App\Database\Connectors;

use Illuminate\Database\Connectors\PostgresConnector;

/**
 * PostgreSQL connector that understands Neon's pooled endpoints.
 *
 * A Neon pooler host (ep-*-pooler.<region>.aws.neon.tech) terminates TLS using
 * SNI, so libpq must be told which endpoint it is connecting to. Neon takes
 * that as the `endpoint` libpq parameter:
 *
 *     ?options=endpoint%3D<endpoint-id>
 *
 * That value is a libpq parameter string, not a PDO option map. Laravel's
 * Connector::getOptions() does `array_diff_key($this->options, $config['options'])`
 * and blows up with "array_diff_key(): Argument #2 must be of type array, string
 * given" if the connection URL carries it. So the endpoint is passed separately
 * (DB_NEON_ENDPOINT) and injected into the DSN here, where libpq expects it.
 *
 * Without this the driver fails with SQLSTATE[08006] "Endpoint ID is not
 * specified" - which took down every session read, so no visitor ever received
 * a session cookie and every form post came back as a 419.
 */
class NeonPostgresConnector extends PostgresConnector
{
    /**
     * Keep the libpq parameter strings out of the PDO options array.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, mixed>
     */
    public function getOptions(array $config)
    {
        unset($config['options'], $config['neon_endpoint']);

        return parent::getOptions($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function getDsn(array $config): string
    {
        $dsn = parent::getDsn($config);

        $endpoint = $config['neon_endpoint'] ?? null;

        if (is_string($endpoint) && $endpoint !== '') {
            $dsn .= ';options='.rawurlencode('endpoint='.$endpoint);
        }

        return $dsn;
    }
}
