<?php

declare(strict_types=1);

namespace App\Services\Social\Support;

use Illuminate\Http\Client\Response;

/**
 * Parses upstream throttling headers into a retry delay.
 *
 * Handles `Retry-After` as either delta-seconds or an HTTP-date, plus
 * `X-RateLimit-Remaining` and `X-RateLimit-Reset` (epoch seconds or a delta).
 * When nothing usable is present the caller-supplied fallback is used, so a
 * publish is never retried instantly against a throttling API.
 */
final readonly class RateLimitState
{
    public const DEFAULT_FALLBACK_SECONDS = 60;

    public function __construct(
        public int $retryAfter,
        public ?int $remaining = null,
        public ?int $resetAt = null,
        public ?string $source = null,
    ) {}

    /**
     * @param  array<string, mixed>  $headers
     */
    public static function fromHeaders(array $headers, int $fallback = self::DEFAULT_FALLBACK_SECONDS, ?int $now = null): self
    {
        $normalized = self::normalizeKeys($headers);
        $now ??= time();

        $retryAfter = self::parseRetryAfter($normalized['retry-after'] ?? null, $now);

        // X hyphenates the words where Meta does not, so both spellings of the
        // reset and remaining headers have to be honoured.
        $resetRaw = $normalized['x-ratelimit-reset'] ?? $normalized['x-rate-limit-reset'] ?? null;
        $reset = self::parseReset($resetRaw, $now);

        $remaining = self::parseInt(
            $normalized['x-ratelimit-remaining'] ?? $normalized['x-rate-limit-remaining'] ?? null
        );

        if ($retryAfter !== null) {
            $source = 'retry-after';
        } elseif ($reset !== null) {
            $source = 'x-ratelimit-reset';
        } else {
            $source = null;
        }

        if ($source === null) {
            // No usable header: the caller-supplied fallback applies and
            // `source` stays null because no header is responsible for it.
            return new self(max(1, $fallback), $remaining, $reset, null);
        }

        $delay = $source === 'retry-after' ? $retryAfter : max(1, $reset - $now);

        return new self(max(1, $delay), $remaining, $reset, $source);
    }

    public static function fromResponse(Response $response, int $fallback = self::DEFAULT_FALLBACK_SECONDS, ?int $now = null): self
    {
        return self::fromHeaders($response->headers(), $fallback, $now);
    }

    public static function exhausted(int $retryAfter, ?int $remaining = null, ?int $resetAt = null): self
    {
        return new self(max(1, $retryAfter), $remaining, $resetAt, 'local-bucket');
    }

    public function isExhausted(): bool
    {
        return $this->remaining === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'retry_after' => $this->retryAfter,
            'remaining' => $this->remaining,
            'reset_at' => $this->resetAt,
            'source' => $this->source,
        ];
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private static function normalizeKeys(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            $normalized[strtolower((string) $key)] = $value;
        }

        return $normalized;
    }

    private static function parseRetryAfter(mixed $value, int $now): ?int
    {
        $value = self::cleanHeader($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return max(0, (int) $value);
        }

        $timestamp = self::parseHttpDate($value);

        return $timestamp === null ? null : max(0, $timestamp - $now);
    }

    private static function parseReset(mixed $value, int $now): ?int
    {
        $value = self::cleanHeader($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            $number = (int) $value;

            // Epoch seconds if it is plausibly a timestamp, otherwise a delta.
            return $number > 1_000_000_000 ? $number : $now + $number;
        }

        return self::parseHttpDate($value);
    }

    /**
     * Reduce a header value to a trimmed string, or null when unusable.
     */
    private static function cleanHeader(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Parse an HTTP-date only. A bare relative string such as "-5" must be
     * rejected rather than fed to strtotime, which would silently resolve it
     * against the current time and produce a plausible-looking but wrong delay.
     */
    private static function parseHttpDate(string $value): ?int
    {
        if (preg_match('/^[A-Za-z]{3},\s/', $value) !== 1) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private static function parseInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }
}
