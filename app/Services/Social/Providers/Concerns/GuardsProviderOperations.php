<?php

declare(strict_types=1);

namespace App\Services\Social\Providers\Concerns;

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Services\Social\Data\UserFacingError;

/**
 * Capability gates and pre-flight media limits that every provider needs.
 *
 * Split out of {@see UsesMetaGraph} so a non-Meta platform can assert a
 * capability or reject an oversized file without pretending to speak the Graph
 * API.
 */
trait GuardsProviderOperations
{
    /**
     * @throws UnsupportedCapabilityException
     */
    protected function assertUnsupported(string $capability): void
    {
        $this->getSupportedFeatures()->assertSupports($capability, $this->providerKey(), $this->platformName());
    }

    /**
     * Refuses a content format the platform has no endpoint for, keeping the
     * platform-specific instruction in the user-facing message itself.
     *
     * `UnsupportedCapabilityException::for()` puts its guidance in `remediation`
     * behind a generic headline, which is wrong here: the user needs to be told
     * exactly which media to attach before the headline means anything.
     *
     * @throws UnsupportedCapabilityException
     */
    protected function unsupportedContentFormat(string $capability, string $instruction): UnsupportedCapabilityException
    {
        return new UnsupportedCapabilityException(
            $capability,
            $this->providerKey(),
            UserFacingError::make(
                'unsupported_content_format',
                $instruction,
                sprintf(
                    'Content type "%s" has no endpoint on provider "%s" (capability "%s").',
                    $capability,
                    $this->providerKey(),
                    $capability,
                ),
                false,
                $instruction,
                ['provider' => $this->providerKey()],
            ),
        );
    }

    /**
     * A limit the platform would reject anyway. Failing before the upload saves
     * the whole transfer and gives the user a message they can act on.
     *
     * @throws ProviderApiException
     */
    protected function assertWithinByteLimit(int $bytes, int $maxBytes, string $remediation): void
    {
        if ($bytes <= 0 || $bytes <= $maxBytes) {
            return;
        }

        throw new ProviderApiException(
            $this->providerKey(),
            0,
            null,
            UserFacingError::make(
                'media_too_large',
                sprintf('This file is larger than the %d MB limit for %s.', intdiv($maxBytes, 1_048_576), $this->platformName()),
                sprintf('Media asset size %d bytes exceeds the %d byte platform limit.', $bytes, $maxBytes),
                false,
                $remediation,
            ),
        );
    }
}
