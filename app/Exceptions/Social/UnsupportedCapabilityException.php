<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\Data\UserFacingError;
use Throwable;

/**
 * The platform genuinely does not offer the requested capability.
 *
 * This is never a transient condition: retrying the same variant will fail
 * forever, so the publishing engine marks the variant failed rather than
 * releasing the job back onto the queue.
 */
final class UnsupportedCapabilityException extends SocialProviderException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $capability,
        public readonly string $provider,
        ?UserFacingError $userFacingError = null,
        ?Throwable $previous = null,
        array $context = [],
    ) {
        parent::__construct(
            sprintf('Provider "%s" does not support the "%s" capability.', $provider, $capability),
            $userFacingError,
            $previous,
            $context,
        );
    }

    public static function for(
        string $capability,
        string $provider,
        string $platformName,
        ?string $remediation = null,
        array $context = [],
    ): self {
        return new self(
            $capability,
            $provider,
            UserFacingError::make(
                'unsupported_capability',
                sprintf('%s does not support this content format. Choose a different format for this network.', $platformName),
                sprintf('Capability "%s" is not available on provider "%s".', $capability, $provider),
                false,
                $remediation ?? 'Change the content format for this variant, or remove this network from the post.',
                $context,
            ),
            null,
            $context,
        );
    }
}
