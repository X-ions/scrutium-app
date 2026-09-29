<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\Data\UserFacingError;
use Throwable;

/**
 * A provider API call returned an unsuccessful HTTP response that is not a
 * dedicated subclass (auth, rate limit). The upstream body is summarised into a
 * {@see UserFacingError}; the raw body is never stored on the exception so that
 * upstream error echoes cannot leak into logs.
 */
final class ProviderApiException extends SocialProviderException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $provider,
        public readonly int $status,
        public readonly ?string $providerCode,
        ?UserFacingError $userFacingError = null,
        ?Throwable $previous = null,
        array $context = [],
    ) {
        parent::__construct(
            sprintf(
                'Provider "%s" returned HTTP %d%s.',
                $provider,
                $status,
                $providerCode !== null ? sprintf(' (code %s)', $providerCode) : '',
            ),
            $userFacingError,
            $previous,
            ['provider' => $provider, 'status' => $status, 'provider_code' => $providerCode] + $context,
        );
    }
}
