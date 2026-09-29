<?php

declare(strict_types=1);

namespace App\Services\Publishing;

/**
 * Strips credential material out of anything destined for a log line or a
 * `publishing_attempts` payload column.
 *
 * Provider responses are echoed into the database for support, so redaction is
 * the only thing standing between an upstream body and a stored access token.
 * Redaction is deliberately conservative: an unrecognised key whose *value*
 * looks like a bearer credential is redacted too, and long strings are
 * truncated so a caption echo cannot become a data dump.
 */
final class PayloadRedactor
{
    private const REDACTED_KEYS = [
        'access_token',
        'refresh_token',
        'id_token',
        'client_secret',
        'app_secret',
        'client_assertion',
        'authorization',
        'code_verifier',
        'code_challenge',
        'password',
        'secret',
        'token',
        'session',
        'cookie',
        'signature',
    ];

    private const REDACTED = '[redacted]';

    private const MAX_STRING_LENGTH = 512;

    private const MAX_DEPTH = 8;

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload, int $depth = 0): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (self::isSecretKey($key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = self::redactValue($value, $depth);
        }

        return $redacted;
    }

    public static function redactValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[truncated]';
        }

        if (is_array($value)) {
            return self::redact($value, $depth + 1);
        }

        if ($value instanceof \JsonSerializable) {
            return self::redactValue($value->jsonSerialize(), $depth);
        }

        if (is_object($value)) {
            return self::redact(get_object_vars($value), $depth + 1);
        }

        if (is_string($value)) {
            return self::looksLikeCredential($value)
                ? self::REDACTED
                : self::truncate($value);
        }

        return $value;
    }

    private static function isSecretKey(mixed $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $normalised = strtolower(str_replace(['-', ' '], '_', $key));

        if (in_array($normalised, self::REDACTED_KEYS, true)) {
            return true;
        }

        return str_ends_with($normalised, '_token')
            || str_ends_with($normalised, '_secret')
            || str_ends_with($normalised, '_password');
    }

    private static function looksLikeCredential(string $value): bool
    {
        $trimmed = trim($value);

        if (strlen($trimmed) < 20) {
            return false;
        }

        if (preg_match('/^(bearer|basic|token)\s+\S+/i', $trimmed) === 1) {
            return true;
        }

        return preg_match('/^(EA|sk-|gho_|ghp_|EAA)[A-Za-z0-9_-]{10,}$/', $trimmed) === 1;
    }

    private static function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING_LENGTH).'… [truncated]';
    }
}
