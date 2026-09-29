<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use JsonException;

/**
 * Strips credentials and oversized noise out of a provider payload before it is
 * written to `analytics_metrics.raw_response`.
 *
 * Provider responses legitimately echo the page access token (Meta returns
 * `access_token` on page edges), and insight payloads can run to hundreds of
 * kilobytes of per-post breakdowns. Storing either would put a live credential
 * in the database or bloat the partition, so both are removed here rather than
 * being trusted to be small and harmless.
 */
final class RawPayloadSanitizer
{
    public const MAX_BYTES = 32_768;

    private const REDACTED = '[redacted]';

    /**
     * Key names that carry credential material. Matched case-insensitively
     * against the flattened key path, so `page_access_token`,
     * `meta_page_access_token` and `tokenValue` are all caught.
     *
     * @var list<string>
     */
    private const SECRET_KEY_PATTERN = '/(access_?token|refresh_?token|id_?token|bearer|authorization|client_?secret|app_?secret|api_?key|code_?verifier|password|private_?key|session_?key|cookie|signature)/i';

    public const MAX_DEPTH = 6;

    public const MAX_ITEMS = 200;

    public const MAX_STRING_BYTES = 2_048;

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function sanitize(?array $payload, string $provider = 'unknown'): ?array
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $clean = $this->walk($payload, '', 0);

        $encoded = $this->encode($clean);

        if ($encoded === null) {
            return ['_truncated' => true, 'reason' => 'unencodable'];
        }

        if (strlen($encoded) <= self::MAX_BYTES) {
            return $clean;
        }

        return [
            '_truncated' => true,
            '_original_bytes' => strlen($encoded),
            '_provider' => $provider,
            '_preview' => $this->preview($clean),
        ];
    }

    /**
     * Recursively redact, depth-limit and shrink a decoded payload.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function walk(array $payload, string $path, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_omitted' => 'max_depth_reached'];
        }

        $result = [];
        $count = 0;

        foreach ($payload as $key => $value) {
            if (++$count > self::MAX_ITEMS) {
                $result['_omitted_items'] = 'max_items_reached';

                break;
            }

            $childPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = $this->value($value, $childPath, $depth);
        }

        return $result;
    }

    private function value(mixed $value, string $path, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->walk($value, $path, $depth + 1);
        }

        if (is_string($value)) {
            if (preg_match(self::SECRET_KEY_PATTERN, $path) === 1) {
                return self::REDACTED;
            }

            return strlen($value) > self::MAX_STRING_BYTES
                ? substr($value, 0, self::MAX_STRING_BYTES).'…[truncated]'
                : $value;
        }

        if (is_object($value)) {
            $decoded = json_decode((string) $this->encode($value), true);

            return $this->walk(is_array($decoded) ? $decoded : [], $path, $depth + 1);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(array $payload): array
    {
        return array_slice(
            array_map(
                static fn (mixed $value): string => is_scalar($value) ? substr((string) $value, 0, 120) : get_debug_type($value),
                $payload,
            ),
            0,
            20,
            true,
        );
    }

    private function encode(mixed $value): ?string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return null;
        }
    }
}
