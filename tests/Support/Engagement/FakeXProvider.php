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
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Contracts\ProviderCapabilities;

final class FakeXProvider extends FakeEngagementProvider
{
    public function __construct()
    {
        parent::__construct('x');
    }

    /**
     * The real X provider cannot read comments under the access this
     * integration holds, so {@see PlatformCapabilities} reports that honestly.
     *
     * The comment-sync tests use X as a stand-in for "a second platform that
     * works", to prove one account's failure does not disturb another's. For
     * that stand-in role the fake must behave like a comments-capable network,
     * otherwise the test would be asserting against a platform that by design
     * returns nothing.
     *
     * This widens the test double only. The production XProvider still refuses
     * to read comments, and XProviderTest still pins that.
     */
    public function getSupportedFeatures(): ProviderCapabilities
    {
        $real = PlatformCapabilities::x();

        return new ProviderCapabilities(
            publishing: $real->publishing,
            imagePublishing: $real->imagePublishing,
            videoPublishing: $real->videoPublishing,
            carouselPublishing: $real->carouselPublishing,
            textPublishing: $real->textPublishing,
            stories: $real->stories,
            reels: $real->reels,
            shorts: $real->shorts,
            scheduling: $real->scheduling,
            comments: true,
            commentReplies: $real->commentReplies,
            likes: $real->likes,
            shares: $real->shares,
            views: $real->views,
            reach: $real->reach,
            impressions: $real->impressions,
            saves: $real->saves,
            followers: $real->followers,
            analytics: $real->analytics,
            webhooks: $real->webhooks,
            linkPosts: $real->linkPosts,
            firstComment: $real->firstComment,
            deletePost: $real->deletePost,
            metrics: $real->metrics,
            constraints: $real->constraints,
            requiredScopes: $real->requiredScopes,
        );
    }
}
