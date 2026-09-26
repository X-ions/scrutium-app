<?php

namespace App\Database\Connections;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;

/**
 * Keeps boolean bindings usable on PostgreSQL.
 *
 * Illuminate\Database\Connection::prepareBindings() rewrites every PHP bool to
 * an int, and neither the Postgres grammar nor the Postgres connection undoes
 * that. PostgreSQL is strictly typed, so an int4 parameter cannot be assigned
 * to a real boolean column (SQLSTATE[42804]). Sending the canonical "true" /
 * "false" literals instead keeps INSERT, UPDATE and WHERE bindings valid.
 */
class PostgresConnection extends BasePostgresConnection
{
    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    public function prepareBindings(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return parent::prepareBindings($bindings);
    }
}
