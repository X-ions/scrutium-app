<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;

/**
 * Strips anything credential-shaped out of a log record before it is written.
 *
 * Publishing jobs log provider responses and job context, and a provider
 * response or an exception message can quote back a request that carried an
 * Authorization header. This is the backstop for that: it is not a reason to
 * log carelessly, it is the reason a careless line does not become a token in
 * a log file that a wider group of people can read.
 *
 * Matching is on the key, not the value, so a token that arrives as prose
 * inside a message is not redacted here — the services that build messages
 * must not put one there in the first place.
 */
final class RedactsCredentials
{
    /**
     * Keys whose value is replaced wholesale, at any depth.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'access_token',
        'accesstoken',
        'refresh_token',
        'refreshtoken',
        'id_token',
        'idtoken',
        'client_secret',
        'clientsecret',
        'api_key',
        'apikey',
        'authorization',
        'code_verifier',
        'codeverifier',
        'password',
        'secret',
        'token',
        'bearer',
        'set-cookie',
        'cookie',
        'x-hub-signature',
        'x-hub-signature-256',
        'proxy-authorization',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context !== [] ? $this->scrub($record->context) : $record->context;

        // A message is scrubbed too, because an interpolated exception can
        // contain a token even when no context key does.
        $message = (string) preg_replace(
            '/(access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization|code[_-]?verifier)\s*[:=]\s*"?[A-Za-z0-9\-._~+\/=]{8,}"?/i',
            '$1=[redacted]',
            $record->message,
        );

        // Monolog 3 records are immutable, so a changed record is returned
        // rather than mutated in place.
        return $record->with(context: $context, message: $message);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $clean[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->scrub($value, $depth + 1);

                continue;
            }

            if (is_string($value) && preg_match('/^(?:Bearer|Basic)\s+\S+$/i', trim($value)) === 1) {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        $normalised = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            $candidate = str_replace('-', '_', $sensitive);

            if ($normalised === $candidate || str_ends_with($normalised, '_'.$candidate)) {
                return true;
            }
        }

        return false;
    }
}
