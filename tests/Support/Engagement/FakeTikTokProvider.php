<?php

declare(strict_types=1);

namespace Tests\Support\Engagement;

/**
 * $k stand-in.
 *
 * SocialProviderRegistry instantiates by class name with no constructor
 * arguments, so the platform a fake reports has to come from the class itself.
 * Naming it per platform is also what keeps getSupportedFeatures() honest:
 * the "cannot reply" test needs Pinterest's real no-comment-API matrix, not a
 * blanket fake that supports everything.
 */
final class FakeTikTokProvider extends FakeEngagementProvider
{
    public function __construct()
    {
        parent::__construct('tiktok');
    }
}
