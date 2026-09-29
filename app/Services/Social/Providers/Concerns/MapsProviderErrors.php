<?php

declare(strict_types=1);

namespace App\Services\Social\Providers\Concerns;

use App\Exceptions\Social\ProviderApiException;
use App\Services\Social\Data\UserFacingError;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Translates provider error payloads into a {@see UserFacingError}.
 *
 * A provider declares its own code map; anything unmapped falls back to a
 * status-derived message. Upstream bodies are never echoed verbatim, so a
 * provider cannot leak submitted content or tokens into a log line.
 */
trait MapsProviderErrors
{
    /**
     * Provider error code => user-facing error.
     *
     * @return array<string, UserFacingError>
     */
    abstract protected function errorMap(): array;

    /**
     * Resolve the provider-specific error code from a response body.
     */
    protected function extractErrorCode(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;

        if (is_array($error)) {
            $code = $error['code'] ?? $error['type'] ?? $error['error_subcode'] ?? null;

            return is_scalar($code) ? (string) $code : null;
        }

        if (is_string($error) && $error !== '') {
            return $error;
        }

        foreach (['code', 'type', 'error_code', 'err_no'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                return (string) $body[$key];
            }
        }

        return null;
    }

    /**
     * A short, safe technical summary for logs and support tickets.
     */
    protected function extractErrorMessage(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        if (is_string($error) && $error !== '') {
            return $error;
        }

        if (is_string($body['message'] ?? null)) {
            return $body['message'];
        }

        return null;
    }

    /**
     * Build the error for a failed response, preferring the provider's own map.
     */
    protected function mapError(Response $response): UserFacingError
    {
        $code = $this->extractErrorCode($response);
        $map = $this->errorMap();

        if ($code !== null && isset($map[$code])) {
            return $map[$code];
        }

        $status = $response->status();

        if ($status === 403) {
            return UserFacingError::make(
                'permission_denied',
                sprintf(
                    '%s refused this action because the connected account lacks permission. Reconnect the account or change the content format.',
                    $this->platformName(),
                ),
                $this->safeTechnical($response, 'permission_denied'),
                false,
                'Reconnect the account and approve all requested permissions, or remove this network from the post.',
                ['status' => $status, 'provider_code' => $code],
            );
        }

        if ($status === 404) {
            return UserFacingError::make(
                'not_found',
                sprintf('%s could not find the requested content. It may have been deleted.', $this->platformName()),
                $this->safeTechnical($response, 'not_found'),
                false,
                'Check that the content still exists on the network and resync the account.',
                ['status' => $status, 'provider_code' => $code],
            );
        }

        if ($status >= 500) {
            return UserFacingError::make(
                'provider_unavailable',
                sprintf('%s is having trouble right now. The post will be retried.', $this->platformName()),
                $this->safeTechnical($response, 'provider_unavailable'),
                true,
                'No action needed; the job is requeued with a delay.',
                ['status' => $status, 'provider_code' => $code],
            );
        }

        return UserFacingError::make(
            'provider_error',
            sprintf('%s rejected this request. Nothing was published.', $this->platformName()),
            $this->safeTechnical($response, 'provider_error'),
            false,
            'Review the variant content and try again.',
            ['status' => $status, 'provider_code' => $code],
        );
    }

    /**
     * Log an upstream failure with no credential material: the operation, the
     * status, and the provider's own error code only.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logProviderFailure(string $operation, Response $response, array $context = []): void
    {
        Log::warning('SocialHub provider request failed.', array_merge([
            'provider' => $this->providerKey(),
            'operation' => $operation,
            'status' => $response->status(),
            'provider_code' => $this->extractErrorCode($response),
        ], $context));
    }

    /**
     * Build a ProviderApiException for a failed response, carrying the mapped
     * user-facing error. The upstream body is summarised, never attached.
     *
     * @param  array<string, mixed>  $context
     */
    protected function buildApiException(
        Response $response,
        ?UserFacingError $userFacingError = null,
        array $context = [],
    ): ProviderApiException {
        $status = $response->status();
        $code = $this->extractErrorCode($response);

        return new ProviderApiException(
            $this->providerKey(),
            $status,
            $code,
            $userFacingError ?? $this->mapError($response),
            null,
            ['status' => $status, 'provider_code' => $code] + $context,
        );
    }

    /**
     * Human-facing network name used in every error message.
     */
    abstract protected function platformName(): string;

    protected function safeTechnical(Response $response, string $fallback): string
    {
        $message = $this->extractErrorMessage($response);
        $code = $this->extractErrorCode($response);
        $status = $response->status();

        if ($message === null && $code === null) {
            return sprintf('%s (HTTP %d)', $fallback, $status);
        }

        return sprintf(
            '%s: HTTP %d%s%s',
            $fallback,
            $status,
            $code !== null ? sprintf(', code %s', $code) : '',
            $message !== null ? sprintf(', %s', Str::limit($message, 200)) : '',
        );
    }
}
