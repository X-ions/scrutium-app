<?php

namespace App\Enums;

enum MetricType: string
{
    case Views = 'views';
    case Impressions = 'impressions';
    case Reach = 'reach';
    case Likes = 'likes';
    case Comments = 'comments';
    case Shares = 'shares';
    case Saves = 'saves';
    case Clicks = 'clicks';
    case Mentions = 'mentions';
    case WatchTime = 'watch_time';
    case VideoViews = 'video_views';
    case Followers = 'followers';
    case FollowerGains = 'follower_gains';
    case FollowerLosses = 'follower_losses';
    case ProfileVisits = 'profile_visits';
    case Engagement = 'engagement';
    case Reactions = 'reactions';
    case AvgViewDuration = 'avg_view_duration_seconds';
    case CompletionRate = 'completion_rate';

    public function label(): string
    {
        return match ($this) {
            self::Views => 'Views',
            self::Impressions => 'Impressions',
            self::Reach => 'Reach',
            self::Likes => 'Likes',
            self::Comments => 'Comments',
            self::Shares => 'Shares',
            self::Saves => 'Saves',
            self::Clicks => 'Clicks',
            self::Mentions => 'Mentions',
            self::WatchTime => 'Watch time',
            self::VideoViews => 'Video views',
            self::Followers => 'Followers',
            self::FollowerGains => 'Follower gains',
            self::FollowerLosses => 'Follower losses',
            self::ProfileVisits => 'Profile visits',
            self::Engagement => 'Engagement',
            self::Reactions => 'Reactions',
            self::AvgViewDuration => 'Avg. view duration',
            self::CompletionRate => 'Completion rate',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Views, self::Impressions, self::Reach, self::VideoViews => 'bg-blue-light-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
            self::Likes, self::Saves => 'bg-theme-pink-500/10 text-theme-pink-500 dark:bg-theme-pink-500/15 dark:text-theme-pink-500',
            self::Comments, self::Mentions => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
            self::Shares, self::Clicks => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Followers, self::FollowerGains, self::FollowerLosses => 'bg-theme-purple-500/10 text-theme-purple-500 dark:bg-theme-purple-500/15 dark:text-theme-purple-500',
            self::WatchTime, self::ProfileVisits, self::Engagement, self::AvgViewDuration, self::CompletionRate => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
            self::Reactions => 'bg-theme-pink-500/10 text-theme-pink-500 dark:bg-theme-pink-500/15 dark:text-theme-pink-500',
        };
    }

    /**
     * Cumulative counters reported by the provider rather than deltas.
     */
    public function isCumulative(): bool
    {
        return in_array($this, [self::Followers, self::FollowerGains, self::FollowerLosses], true);
    }

    /**
     * Counters that make up the engagement rate numerator.
     *
     * Reactions are deliberately excluded: on Meta a reaction count already
     * contains every like, so adding both would double count. Use
     * {@see self::overlapsLikes()} to decide which of the two to trust.
     *
     * @return list<self>
     */
    public function isEngagementMetric(): bool
    {
        return in_array($this, [self::Likes, self::Comments, self::Shares, self::Saves, self::Clicks], true);
    }

    /**
     * True when this metric is a superset of another rather than independent of it.
     *
     * @return list<self>
     */
    public function overlaps(): array
    {
        return match ($this) {
            self::Reactions => [self::Likes],
            self::Views, self::VideoViews, self::Impressions, self::Reach => [self::Engagement],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
