<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\MetricType;
use App\Enums\SocialPlatform;

/**
 * Describes how far a set of metrics can honestly be compared and combined
 * across the selected platforms.
 *
 * Two platforms reporting a metric under the same name is not the same as
 * measuring the same thing. YouTube `views` counts every video view including
 * Shorts; Facebook `views` is `post_video_views`, a three-second view
 * threshold; TikTok uses a two-second threshold. Adding them produces a number
 * that looks authoritative and means nothing. This class makes that judgement
 * explicit instead of leaving it to the UI.
 *
 * Each (metric, platform) pair carries a *semantic key*: platforms sharing a key
 * measure the same thing and may be summed. A metric is only comparable when
 * every selected platform reporting it agrees on the key.
 */
final class MetricComparability
{
    /**
     * metric => platform => [semantic key, human definition].
     *
     * Platforms absent from a metric's map do not report it at all; that is
     * recorded as "absent", never as zero.
     *
     * @var array<string, array<string, array{string, string}>>
     */
    private const DEFINITIONS = [
        'views' => [
            'facebook' => ['video_views_3s', 'Video post views, counted after 3 seconds of playback. Text and photo posts contribute no views.'],
            'instagram' => ['content_views', 'Views of feed, reel and story content. Not restricted to video.'],
            'youtube' => ['all_video_views', 'Every video view, including Shorts.'],
            'tiktok' => ['video_views_2s', 'Video views, counted after 2 seconds of playback.'],
            'linkedin' => ['organic_video_views', 'Organic video views only; paid distribution is not included.'],
        ],
        'impressions' => [
            'facebook' => ['total_impressions', 'Total impressions including repeats, organic and paid.'],
            'tiktok' => ['total_impressions', 'Total video impressions including repeats.'],
            'x' => ['tweet_impressions', 'Tweet impressions. Not unique people, and not reach.'],
            'pinterest' => ['total_impressions', 'Total pin impressions including repeats.'],
        ],
        'reach' => [
            'facebook' => ['unique_accounts', 'Unique accounts that saw the post.'],
            'tiktok' => ['unique_viewers', 'Unique viewers of the video.'],
            'pinterest' => ['unique_accounts', 'Unique accounts that saw the pin.'],
        ],
        'impressions_unique' => [],
        'likes' => [
            'facebook' => ['likes', 'The Like reaction only. Other reactions are not counted here.'],
            'instagram' => ['likes', 'Likes. Saves and comments are reported separately.'],
            'youtube' => ['likes', 'Likes on the video.'],
            'tiktok' => ['likes', 'Likes on the video.'],
            'x' => ['likes', 'Likes (favourites) on the post.'],
            'linkedin' => ['reactions', 'All reactions, not only the Like reaction.'],
        ],
        'comments' => [
            'facebook' => ['comments', 'Comments on the post.'],
            'instagram' => ['comments', 'Comments on the media.'],
            'youtube' => ['comments', 'Comments on the video.'],
            'tiktok' => ['comments', 'Comments on the video.'],
            'x' => ['comments', 'Replies and comments on the post.'],
            'linkedin' => ['comments', 'Comments on the post.'],
        ],
        'shares' => [
            'facebook' => ['shares', 'Shares, re-shares and link shares.'],
            'tiktok' => ['shares', 'Shares and sends.'],
            'x' => ['retweets', 'Reposts.'],
            'linkedin' => ['shares', 'Shares and reposts.'],
        ],
        'saves' => [
            'facebook' => ['saves', 'Saves.'],
            'instagram' => ['saves', 'Saves.'],
            'pinterest' => ['saves', 'Saves.'],
        ],
        'clicks' => [
            'facebook' => ['link_clicks', 'Link clicks, including outbound link clicks.'],
            'x' => ['link_clicks', 'Link clicks and URL engagements.'],
            'linkedin' => ['link_clicks', 'Clicks on the post and its links.'],
            'pinterest' => ['pin_clicks', 'Outbound clicks from the pin.'],
        ],
        'engagement' => [
            'facebook' => ['facebook_engagement', 'Reactions, comments, shares and link clicks combined by the platform.'],
            'instagram' => ['instagram_engagement', 'Likes, comments, saves and shares combined by the platform.'],
            'youtube' => ['youtube_engagement', 'Likes and comments only. Shares and saves are not included.'],
            'tiktok' => ['tiktok_engagement', 'Likes, comments, shares and follows combined by the platform.'],
            'x' => ['x_engagement', 'Replies, reposts, likes, follows and link engagements combined by the platform.'],
            'linkedin' => ['linkedin_engagement', 'Reactions, comments and clicks combined by the platform.'],
        ],
        'followers' => [
            'facebook' => ['audience_total', 'People who like or follow the page.'],
            'instagram' => ['audience_total', 'Instagram followers.'],
            'youtube' => ['audience_total', 'Channel subscribers.'],
            'tiktok' => ['audience_total', 'TikTok followers.'],
            'x' => ['audience_total', 'X followers.'],
            'linkedin' => ['audience_total', 'LinkedIn followers.'],
            'pinterest' => ['audience_total', 'Pinterest followers.'],
        ],
        'watch_time' => [
            'youtube' => ['watch_minutes', 'Minutes watched, as estimated by YouTube.'],
        ],
        'profile_visits' => [
            'facebook' => ['profile_visits', 'Visits to the page profile.'],
            'instagram' => ['profile_visits', 'Profile visits.'],
        ],
    ];

    /**
     * The metrics a "total engagement" headline is built from when a platform
     * does not publish its own engagement total.
     *
     * @return list<string>
     */
    public static function engagementComponents(): array
    {
        return array_values(array_map(
            static fn (MetricType $type): string => $type->value,
            array_filter(MetricType::cases(), static fn (MetricType $type): bool => $type->isEngagementMetric()),
        ));
    }

    /**
     * @param  list<SocialPlatform>  $selected
     * @param  array<string, list<string>>  $available  platform => metric types present
     * @param  list<string>  $metrics  metric types the caller intends to report
     * @return array<string, mixed>
     */
    public function describe(array $selected, array $available = [], array $metrics = []): array
    {
        $selectedKeys = array_map(static fn (SocialPlatform $platform): string => $platform->value, $selected);
        $candidates = $metrics !== []
            ? $metrics
            : array_values(array_unique(array_merge(...array_values($available ?: [[]])) ?: []));

        sort($candidates);

        $report = [];
        $warnings = [];

        foreach ($candidates as $metric) {
            $definitions = self::DEFINITIONS[$metric] ?? [];

            $reporting = [];
            $absent = [];
            $semantics = [];

            foreach ($selectedKeys as $platform) {
                if (in_array($metric, $available[$platform] ?? [], true)) {
                    $reporting[] = $platform;
                    $semantics[] = $definitions[$platform][0] ?? 'unknown';
                } else {
                    $absent[] = $platform;
                }
            }

            $distinct = array_values(array_unique($semantics));
            $comparable = $reporting !== [] && count($distinct) === 1;

            $report[$metric] = [
                'label' => $this->label($metric),
                'reported_by' => $reporting,
                'absent_on' => $absent,
                'comparable' => $comparable,
                'status' => $reporting === [] ? 'unavailable' : ($comparable ? 'comparable' : 'not_comparable'),
                'sum_is_meaningful' => $comparable && $reporting !== [],
                'definitions' => array_intersect_key($definitions, array_flip($reporting)),
            ];

            if ($reporting === []) {
                $warnings[] = sprintf('%s was not reported by any selected network in this period.', $this->label($metric));

                continue;
            }

            if (! $comparable) {
                $warnings[] = sprintf(
                    '%s is measured differently on %s. Read the networks separately rather than adding them together.',
                    $this->label($metric),
                    implode(', ', $reporting),
                );
            }
        }

        return [
            'platforms' => $selectedKeys,
            'metrics' => $report,
            'warnings' => $warnings,
            'comparable_metrics' => array_values(array_keys(array_filter(
                $report,
                static fn (array $entry): bool => (bool) $entry['comparable'],
            ))),
            'incomparable_metrics' => array_values(array_keys(array_filter(
                $report,
                static fn (array $entry): bool => ! $entry['comparable'],
            ))),
        ];
    }

    private function label(string $metric): string
    {
        return MetricType::tryFrom($metric)?->label() ?? ucfirst(str_replace('_', ' ', $metric));
    }
}
