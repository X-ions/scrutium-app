<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One traceable line per publishing lifecycle event.
 *
 * Every line carries a short `job_id` (e.g. `job_83hd82`) that is also stored on
 * the ScheduledPost row, so an operator reading a support ticket can grep the
 * whole history of one variant. Context is passed through
 * {@see PayloadRedactor} first: a token that reaches this class is never
 * written to disk.
 */
final class StructuredLog
{
    public static function jobId(): string
    {
        return 'job_'.Str::lower(Str::random(6, 16));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function write(string $event, array $context = []): void
    {
        Log::info('socialhub.publishing', self::line($event, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $event, array $context = []): void
    {
        Log::warning('socialhub.publishing', self::line($event, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $event, array $context = []): void
    {
        Log::error('socialhub.publishing', self::line($event, $context));
    }

    /**
     * The canonical key order, so a log line is greppable and diffable.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function line(string $event, array $context): array
    {
        $safe = PayloadRedactor::redact($context);

        $ordered = [
            'event' => $event,
            'job_id' => self::stringOrNull($safe, 'job_id'),
            'organization_id' => self::stringOrNull($safe, 'organization_id'),
            'post_id' => self::stringOrNull($safe, 'post_id'),
            'variant_id' => self::stringOrNull($safe, 'variant_id'),
            'provider' => self::stringOrNull($safe, 'provider'),
            'status' => self::stringOrNull($safe, 'status'),
            'attempt' => self::stringOrNull($safe, 'attempt'),
            'error_code' => self::stringOrNull($safe, 'error_code'),
            'retry_in_seconds' => self::stringOrNull($safe, 'retry_in_seconds'),
        ];

        $extra = array_diff_key($safe, $ordered);

        if ($extra !== []) {
            $ordered['context'] = $extra;
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function stringOrNull(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
