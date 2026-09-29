<?php

declare(strict_types=1);

namespace App\Services\Social\Contracts;

use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Data\UserFacingError;
use JsonSerializable;

/**
 * Immutable capability set for one provider (optionally narrowed per account).
 *
 * Every platform behaviour gate in SocialHub reads from here — the publishing
 * engine never branches on a platform name, and a capability the platform does
 * not have is never simulated.
 */
final readonly class ProviderCapabilities implements JsonSerializable
{
    /**
     * @param  list<string>  $metrics  Normalised metric vocabulary this platform reports.
     * @param  list<string>  $constraints  Human-readable limits surfaced to the composer.
     * @param  list<string>  $requiredScopes  Scopes without which the capabilities do not hold.
     */
    public function __construct(
        public bool $publishing = false,
        public bool $imagePublishing = false,
        public bool $videoPublishing = false,
        public bool $carouselPublishing = false,
        public bool $textPublishing = false,
        public bool $stories = false,
        public bool $reels = false,
        public bool $shorts = false,
        public bool $scheduling = false,
        public bool $comments = false,
        public bool $commentReplies = false,
        public bool $likes = false,
        public bool $shares = false,
        public bool $views = false,
        public bool $reach = false,
        public bool $impressions = false,
        public bool $saves = false,
        public bool $followers = false,
        public bool $analytics = false,
        public bool $webhooks = false,
        public bool $linkPosts = false,
        public bool $firstComment = false,
        public bool $deletePost = false,
        public array $metrics = [],
        public array $constraints = [],
        public array $requiredScopes = [],
    ) {}

    /**
     * Capability flag names, in declaration order. Used by `supports()` and by
     * the doctor command's capability summary.
     *
     * @return list<string>
     */
    public static function flags(): array
    {
        return [
            'publishing',
            'imagePublishing',
            'videoPublishing',
            'carouselPublishing',
            'textPublishing',
            'stories',
            'reels',
            'shorts',
            'scheduling',
            'comments',
            'commentReplies',
            'likes',
            'shares',
            'views',
            'reach',
            'impressions',
            'saves',
            'followers',
            'analytics',
            'webhooks',
            'linkPosts',
            'firstComment',
            'deletePost',
        ];
    }

    /**
     * Build a capability set for a platform key, e.g. `named('facebook')`.
     * Delegates to the verified matrix so there is one source of truth.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function named(string $platform, array $overrides = []): self
    {
        return PlatformCapabilities::for($platform)->withOverrides($overrides);
    }

    public function supports(string $capability): bool
    {
        return (bool) ($this->{$capability} ?? false);
    }

    public function supportsMetric(string $metric): bool
    {
        return in_array($metric, $this->metrics, true);
    }

    /**
     * @throws UnsupportedCapabilityException
     */
    public function assertSupports(string $capability, ?string $provider = null, ?string $platformName = null): void
    {
        if ($this->supports($capability)) {
            return;
        }

        throw UnsupportedCapabilityException::for(
            $capability,
            $provider ?? 'unknown',
            $platformName ?? 'This network',
            $this->remediationFor($capability),
        );
    }

    /**
     * @throws UnsupportedCapabilityException
     */
    public function assertSupportsMetric(string $metric, ?string $provider = null, ?string $platformName = null): void
    {
        if ($this->supportsMetric($metric)) {
            return;
        }

        throw UnsupportedCapabilityException::for(
            'metric:'.$metric,
            $provider ?? 'unknown',
            ($platformName ?? 'This network').' does not report this metric, so it is left out of the total rather than counted as zero.',
            $this->remediationFor('metric:'.$metric),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $known = array_fill_keys(self::flags(), true);

        $properties = [];
        foreach ($overrides as $key => $value) {
            if (isset($known[$key])) {
                $properties[$key] = (bool) $value;
            }
        }

        foreach (['metrics', 'constraints', 'requiredScopes'] as $listKey) {
            if (isset($overrides[$listKey]) && is_array($overrides[$listKey])) {
                $properties[$listKey] = array_values($overrides[$listKey]);
            }
        }

        if ($properties === []) {
            return $this;
        }

        return new self(...array_merge($this->toArray(), $properties));
    }

    /**
     * Narrows to the intersection of two capability sets — used to apply
     * account-level scope restrictions on top of platform capabilities.
     */
    public function narrow(self $other): self
    {
        $merged = [];
        foreach (self::flags() as $flag) {
            $merged[$flag] = $this->supports($flag) && $other->supports($flag);
        }

        return new self(
            ...array_merge($merged, [
                'metrics' => array_values(array_intersect($this->metrics, $other->metrics)),
                'constraints' => array_values(array_unique([...$this->constraints, ...$other->constraints])),
                'requiredScopes' => $other->requiredScopes,
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'publishing' => $this->publishing,
            'imagePublishing' => $this->imagePublishing,
            'videoPublishing' => $this->videoPublishing,
            'carouselPublishing' => $this->carouselPublishing,
            'textPublishing' => $this->textPublishing,
            'stories' => $this->stories,
            'reels' => $this->reels,
            'shorts' => $this->shorts,
            'scheduling' => $this->scheduling,
            'comments' => $this->comments,
            'commentReplies' => $this->commentReplies,
            'likes' => $this->likes,
            'shares' => $this->shares,
            'views' => $this->views,
            'reach' => $this->reach,
            'impressions' => $this->impressions,
            'saves' => $this->saves,
            'followers' => $this->followers,
            'analytics' => $this->analytics,
            'webhooks' => $this->webhooks,
            'linkPosts' => $this->linkPosts,
            'firstComment' => $this->firstComment,
            'deletePost' => $this->deletePost,
            'metrics' => $this->metrics,
            'constraints' => $this->constraints,
            'requiredScopes' => $this->requiredScopes,
        ];

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * A constraint registered for this capability as a "capability:remediation"
     * entry, or null when the platform declares no extra guidance.
     */
    public function remediationHintFor(string $capability): ?string
    {
        return $this->remediationFor($capability);
    }

    private function remediationFor(string $capability): ?string
    {
        foreach ($this->constraints as $constraint) {
            if (str_starts_with($constraint, $capability.':')) {
                return trim(substr($constraint, strlen($capability) + 1));
            }
        }

        return null;
    }

    public function errorFor(string $capability): UserFacingError
    {
        return UserFacingError::make(
            'unsupported_capability',
            'This network does not support that content format.',
            sprintf('Capability "%s" is not available.', $capability),
            false,
            $this->remediationFor($capability),
        );
    }
}
