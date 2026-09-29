<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\SocialAccountStatus;
use App\Models\PostVariant;
use App\Services\Social\Contracts\ProviderCapabilities;
use App\Services\Social\Data\UserFacingError;

/**
 * Decides whether one variant may be published to its network right now.
 *
 * Returns a {@see UserFacingError} instead of throwing, because the caller is
 * almost always a loop over variants: one variant that cannot be published must
 * be failed with a readable message while the rest continue.
 */
class VariantCapabilityGate
{
    /**
     * @return UserFacingError|null null when the variant is clear to publish
     */
    public function evaluate(
        PostVariant $variant,
        ProviderCapabilities $capabilities,
        string $provider,
        string $platformName,
    ): ?UserFacingError {
        $contentType = VariantContentType::fromVariant($variant);
        $capability = VariantContentType::capabilityFor($contentType);

        if (! $capabilities->publishing) {
            return $capabilities->errorFor('publishing');
        }

        if (! $capabilities->supports($capability)) {
            $error = $capabilities->errorFor($capability);

            return new UserFacingError(
                code: $error->code,
                userMessage: sprintf(
                    '%s does not support %s on this post. Nothing was published.',
                    $platformName,
                    self::describeContentType($contentType),
                ),
                technicalMessage: $error->technicalMessage,
                retryable: false,
                remediation: $error->remediation,
                context: $error->context + [
                    'content_type' => $contentType,
                    'capability' => $capability,
                    'provider' => $provider,
                ],
            );
        }

        $account = $variant->relationLoaded('socialAccount')
            ? $variant->getRelation('socialAccount')
            : $variant->socialAccount()->first();

        if ($account === null) {
            return UserFacingError::make(
                'account_missing',
                sprintf('The %s account this variant targets is no longer connected.', $platformName),
                sprintf('Post variant %d references a missing social account.', $variant->getKey()),
                false,
                'Reconnect the account, or remove this network from the post.',
                ['provider' => $provider],
            );
        }

        $status = $account->status instanceof SocialAccountStatus
            ? $account->status
            : SocialAccountStatus::Disconnected;

        if ($status->requiresReauthorization() || $status->isTerminal()) {
            return UserFacingError::make(
                'account_reauthorization_required',
                sprintf('%s needs to be reconnected before this post can go out. Nothing was published.', $platformName),
                sprintf('Social account %d is %s.', $account->getKey(), $status->value),
                false,
                'Reconnect the account from the Social Accounts page, then retry this variant.',
                ['provider' => $provider, 'account_id' => $account->getKey(), 'account_status' => $status->value],
            );
        }

        if (! $account->hasUsableToken()) {
            return UserFacingError::make(
                'account_token_expired',
                sprintf('The %s account has an expired access token. Nothing was published.', $platformName),
                sprintf('Social account %d has a token past its expiry.', $account->getKey()),
                false,
                'Reconnect the account so we can obtain a fresh access token.',
                ['provider' => $provider, 'account_id' => $account->getKey()],
            );
        }

        return null;
    }

    /**
     * The capability matrix speaks in feature flags; the composer shows the
     * user what they put on the post, so the gap is reported in those terms.
     */
    private static function describeContentType(string $contentType): string
    {
        return match ($contentType) {
            'image' => 'image posts',
            'video' => 'video posts',
            'carousel' => 'carousel posts',
            'link' => 'link posts',
            'story' => 'stories',
            'reel' => 'reels',
            'text' => 'text-only posts',
            default => sprintf('%s posts', $contentType),
        };
    }
}
