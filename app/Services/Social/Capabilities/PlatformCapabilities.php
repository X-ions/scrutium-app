<?php

declare(strict_types=1);

namespace App\Services\Social\Capabilities;

use App\Services\Social\Contracts\ProviderCapabilities;
use InvalidArgumentException;

/**
 * The verified per-platform capability matrix from SOCIAL-PROVIDERS.md.
 *
 * This is the single source of truth the UI reads to show or hide features.
 * A capability that is not listed as available is NOT implemented: the flag
 * stays false and the provider throws UnsupportedCapabilityException.
 *
 * Approval gates are documented, never bypassed — see `requiresAppReview()`.
 */
final class PlatformCapabilities
{
    /** Date the matrix below was last checked against official platform docs. */
    public const VERIFIED_ON = '2026-09-29';

    /**
     * @return array<string, string>
     */
    public static function platforms(): array
    {
        return [
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            'x' => 'X',
            'linkedin' => 'LinkedIn',
            'pinterest' => 'Pinterest',
        ];
    }

    public static function for(string $platform): ProviderCapabilities
    {
        return match ($platform) {
            'facebook' => self::facebook(),
            'instagram' => self::instagram(),
            'youtube' => self::youtube(),
            'tiktok' => self::tiktok(),
            'x' => self::x(),
            'linkedin' => self::linkedin(),
            'pinterest' => self::pinterest(),
            default => throw new InvalidArgumentException(
                sprintf('Unknown social platform "%s".', $platform)
            ),
        };
    }

    public static function supports(string $platform): bool
    {
        return array_key_exists($platform, self::platforms());
    }

    public static function displayName(string $platform): string
    {
        return self::platforms()[$platform] ?? ucfirst($platform);
    }

    public static function requiresAppReview(string $platform): bool
    {
        return in_array($platform, ['facebook', 'instagram', 'tiktok', 'youtube', 'linkedin', 'pinterest', 'x'], true);
    }

    public static function facebook(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            publishing: true,
            imagePublishing: true,
            videoPublishing: true,
            carouselPublishing: true,
            textPublishing: true,
            stories: true,
            reels: false,
            shorts: false,
            scheduling: false,
            comments: true,
            commentReplies: true,
            likes: true,
            shares: true,
            views: true,
            reach: true,
            impressions: true,
            saves: true,
            followers: true,
            analytics: true,
            webhooks: true,
            linkPosts: true,
            firstComment: true,
            deletePost: true,
            metrics: ['views', 'reach', 'impressions', 'followers', 'engagements', 'reactions', 'comments', 'shares', 'clicks', 'saves'],
            constraints: [
                'textPublishing:Facebook text-only posts are deprecated for most page types; publish with media or use a link post instead.',
                'scheduling:Facebook has no native scheduling. Schedule on our side and publish when the time arrives.',
            ],
            requiredScopes: [
                'pages_show_list',
                'pages_read_engagement',
                'pages_manage_posts',
                'pages_manage_engagement',
                'read_insights',
            ],
        );
    }

    public static function instagram(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            publishing: true,
            imagePublishing: true,
            videoPublishing: true,
            carouselPublishing: true,
            textPublishing: false,
            stories: true,
            reels: true,
            shorts: false,
            scheduling: false,
            comments: true,
            commentReplies: true,
            likes: true,
            shares: true,
            views: true,
            reach: true,
            impressions: true,
            saves: true,
            followers: true,
            analytics: true,
            webhooks: true,
            linkPosts: false,
            firstComment: true,
            deletePost: true,
            metrics: ['views', 'reach', 'impressions', 'followers', 'engagements', 'reactions', 'comments', 'shares', 'saves'],
            constraints: [
                'textPublishing:Instagram requires a photo, video, reel, carousel, or story. A caption alone cannot be published.',
                'linkPosts:Instagram has no link post type. Put the URL in the caption of a media post.',
                'scheduling:Instagram has no native scheduling. Schedule on our side and publish when the time arrives.',
                'publishing:Publishing is a two-phase container then media_publish flow; the container must reach the FINISHED status before it can be published.',
                'webhooks:Instagram webhooks are limited to account and comment activity, not per-post metrics.',
                'carouselPublishing:A carousel holds up to 10 children and reels cannot appear in a carousel.',
            ],
            requiredScopes: ['instagram_basic', 'instagram_content_publish', 'pages_show_list', 'pages_read_engagement', 'instagram_manage_insights'],
        );
    }

    public static function youtube(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            publishing: true,
            imagePublishing: false,
            videoPublishing: true,
            carouselPublishing: false,
            textPublishing: false,
            stories: false,
            reels: false,
            shorts: true,
            scheduling: true,
            comments: true,
            commentReplies: true,
            likes: true,
            shares: false,
            views: true,
            reach: false,
            impressions: false,
            saves: false,
            followers: true,
            analytics: true,
            webhooks: false,
            linkPosts: true,
            firstComment: false,
            deletePost: true,
            metrics: ['views', 'followers', 'comments', 'engagements', 'watch_time_minutes', 'avg_view_duration_seconds'],
            constraints: [
                'textPublishing:YouTube publishes video. Use the description for text.',
                'imagePublishing:YouTube publishes video only. A thumbnail is set with thumbnails.set and is not a post.',
                'carouselPublishing:YouTube has no carousel post type.',
                'webhooks:YouTube uses Pub/SubHubbub, which carries no request signature, so an incoming notification cannot be authenticated.',
                'reach:YouTube reports views and watch time, not reach or impressions.',
                'scheduling:Scheduling uses status.publishAt with a RFC 3339 timestamp, and requires status.privacyStatus to be private.',
                'publishing:An unverified Google API project has its uploads forced to private until the project passes audit.',
            ],
            requiredScopes: [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.force-ssl',
                'https://www.googleapis.com/auth/youtube.readonly',
                'https://www.googleapis.com/auth/youtube-analytics.reports.readonly',
            ],
        );
    }

    public static function tiktok(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            publishing: true,
            imagePublishing: true,
            videoPublishing: true,
            carouselPublishing: true,
            textPublishing: false,
            stories: false,
            reels: false,
            shorts: true,
            scheduling: false,
            comments: true,
            commentReplies: true,
            likes: true,
            shares: true,
            views: true,
            reach: false,
            impressions: false,
            saves: false,
            followers: true,
            analytics: true,
            webhooks: true,
            linkPosts: false,
            firstComment: false,
            deletePost: false,
            metrics: ['views', 'followers', 'comments', 'engagements', 'shares'],
            constraints: [
                'textPublishing:TikTok publishes video or photo posts only.',
                'linkPosts:TikTok has no link post type; put links in the caption.',
                'carouselPublishing:A photo post sends up to 35 images in one PULL_FROM_URL request, which is a TikTok photo carousel.',
                'scheduling:The Content Posting API has no scheduled publish parameter. video.upload only creates an inbox draft the creator finishes on the app. Schedule on our side and publish when the time arrives.',
                'deletePost:TikTok does not allow deleting published videos through the API. A publish that has not been uploaded yet can be cancelled with /v2/post/publish/cancel/ using its publish_id.',
                'reach:TikTok reports view, like, comment and share counts only. Reach and impressions are not exposed by the Open Platform.',
                'publishing:Publishing needs the video.publish scope approved through App Audit. Content posted by an unaudited client is restricted to private viewing and TikTok rejects public posts with unaudited_client_can_only_post_to_private_accounts.',
                'publishing:Publishing is consent-gated. The creator must complete the TikTok consent screen before any init call is accepted.',
            ],
            requiredScopes: ['user.info.basic', 'video.publish', 'video.upload'],
        );
    }

    public static function x(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            publishing: true,
            imagePublishing: true,
            videoPublishing: true,
            carouselPublishing: false,
            textPublishing: true,
            stories: false,
            reels: false,
            shorts: false,
            scheduling: false,
            // X exposes no endpoint to read a post's comments under the
            // access this integration holds, so the inbox has nothing to
            // collect from X even though a reply can be posted.
            comments: false,
            commentReplies: true,
            likes: true,
            shares: true,
            views: false,
            reach: false,
            impressions: true,
            saves: true,
            followers: true,
            analytics: true,
            webhooks: false,
            linkPosts: true,
            firstComment: true,
            deletePost: true,
            metrics: ['followers', 'likes', 'comments', 'shares', 'engagements', 'impressions', 'saves'],
            constraints: [
                'views:X does not report video views or watch time.',
                'comments:The X API v2 exposes no read endpoint for the replies to a Post, so an inbound comment inbox cannot be built from the official API. Replies can still be written.',
                'reach:X has no reach metric. public_metrics is engagement and impression counts only.',
                'impressions:impression_count is only returned for posts authored by the authenticated user, so impressions are missing for anyone else\'s post.',
                'webhooks:X has no general-purpose publish webhook. The Activity (Account Activity) API is restricted to approved use cases only.',
                'carouselPublishing:A Post attaches up to 4 images, or 1 GIF, or 1 video. It cannot mix them.',
                'scheduling:X has no scheduled publish endpoint. Schedule on our side and publish when the time arrives.',
                'publishing:POST /2/tweets needs a user access token with tweet.write on an app in a developer project; an app-only bearer token can never post.',
                'publishing:Media must be uploaded first and the resulting media_id attached, and a media_id expires after 24 hours.',
            ],
            requiredScopes: ['tweet.read', 'tweet.write', 'users.read', 'follows.read', 'media.write', 'offline.access', 'space.read'],
        );
    }

    public static function linkedin(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            publishing: true,
            imagePublishing: true,
            videoPublishing: true,
            carouselPublishing: true,
            textPublishing: true,
            stories: false,
            reels: false,
            shorts: false,
            scheduling: false,
            comments: true,
            commentReplies: true,
            likes: true,
            shares: true,
            views: true,
            reach: false,
            impressions: true,
            saves: false,
            followers: false,
            analytics: true,
            webhooks: false,
            linkPosts: false,
            firstComment: false,
            deletePost: false,
            metrics: ['views', 'comments', 'engagements', 'clicks', 'impressions', 'shares'],
            constraints: [
                'linkPosts:LinkedIn rejects posts whose body is only a URL. Always include commentary text.',
                'scheduling:LinkedIn has no scheduled publishing API. Schedule on our side and publish when the time arrives.',
                'followers:LinkedIn exposes no follower count outside the gated Community Management partner program, so a follower total cannot be reported honestly.',
                'deletePost:LinkedIn does not allow deleting published posts through the API.',
                'carouselPublishing:A multi-image post is published through /rest/multiImage, and a document through /rest/documents.',
                'analytics:organizationalEntityShareStatistics and socialActions only report on organization-authored posts and need r_organization_social. A member profile has no organic analytics API.',
                'commentReplies:Nested comments with parentComment are only accepted on organization posts. A member profile can read no comments and write no replies.',
                'views:Only video views are reported, and only for organization posts.',
                'webhooks:LinkedIn has no signed webhook delivery, so an inbound notification cannot be authenticated.',
            ],
            requiredScopes: ['openid', 'profile', 'email', 'w_member_social', 'w_organization_social'],
        );
    }

    public static function pinterest(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            publishing: true,
            imagePublishing: true,
            videoPublishing: true,
            carouselPublishing: false,
            textPublishing: false,
            stories: false,
            reels: false,
            shorts: false,
            scheduling: false,
            comments: false,
            commentReplies: false,
            likes: false,
            shares: false,
            views: true,
            reach: false,
            impressions: true,
            saves: true,
            followers: true,
            analytics: true,
            webhooks: false,
            linkPosts: false,
            firstComment: false,
            deletePost: true,
            metrics: ['views', 'impressions', 'followers', 'saves', 'clicks'],
            constraints: [
                'textPublishing:Pinterest publishes Pins, which always carry an image or video.',
                'comments:Pinterest has no comment API, so comments and replies are unavailable.',
                'carouselPublishing:Pinterest has no carousel post type.',
                'scheduling:The v5 PinCreate body has no scheduled publish field. Schedule on our side and publish when the time arrives.',
                'reach:Pinterest reports impressions, not unique reach. There is no reach metric.',
                'views:Video views are only reported for Video Pins through VIDEO_MRC_VIEW and the video watch metrics.',
                'webhooks:Pinterest has no signed webhook delivery, so an inbound notification cannot be authenticated.',
                'publishing:media_source image_base64 accepts JPEG, PNG and GIF under 20 MB; a video Pin must register a media_id through /v5/media first.',
            ],
            requiredScopes: ['pins:read', 'pins:write', 'boards:read', 'user_accounts:read'],
        );
    }
}
