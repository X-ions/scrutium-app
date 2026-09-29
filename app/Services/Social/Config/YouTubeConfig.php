<?php

declare(strict_types=1);

namespace App\Services\Social\Config;

use App\Services\Social\Data\UserFacingError;

/**
 * YouTube Data API v3 and YouTube Analytics API endpoints.
 *
 * Analytics for views, comments, and likes live on the video resource; watch
 * time and average view duration only exist in the Analytics API, which is why
 * both hosts appear here.
 */
final class YouTubeConfig
{
    public const API_BASE = 'https://www.googleapis.com/youtube/v3';

    public const UPLOAD_BASE = 'https://www.googleapis.com/upload/youtube/v3';

    public const ANALYTICS_BASE = 'https://youtubeanalytics.googleapis.com/v2/reports';

    public const RESUMABLE_UPLOAD_TYPE = 'resumable';

    /** Chunk size Google recommends for a resumable upload body. */
    public const UPLOAD_CHUNK_BYTES = 8388608;

    public const MAX_UPLOAD_BYTES = 2147483648;

    /** A scheduled publish must be at least this far in the future. */
    public const MIN_SCHEDULE_LEAD_MINUTES = 15;

    public const DEFAULT_PRIVACY = 'private';

    public const ANALYTICS_METRICS = [
        'views' => ['metric' => 'views', 'dimensions' => 'day'],
        'comments' => ['metric' => 'estimatedComments', 'dimensions' => 'day'],
        'engagements' => ['metric' => 'estimatedLikes', 'dimensions' => 'day'],
        'watch_time_minutes' => ['metric' => 'estimatedMinutesWatched', 'dimensions' => 'day'],
        'avg_view_duration_seconds' => ['metric' => 'averageViewDuration', 'dimensions' => 'day'],
        'followers' => ['metric' => 'subscribersGained', 'dimensions' => 'day'],
    ];

    /**
     * video `statistics` field => normalised metric. `viewCount` and
     * `likeCount` are the only lifetime counters the video resource exposes.
     *
     * @var array<string, string>
     */
    public const STATISTICS_METRICS = [
        'viewCount' => 'views',
        'likeCount' => 'likes',
        'commentCount' => 'comments',
    ];

    /**
     * @return array<string, UserFacingError>
     */
    public static function errorMap(): array
    {
        return [
            'accessNotConfigured' => UserFacingError::make(
                'api_not_enabled',
                'The YouTube Data API is not enabled for this Google Cloud project.',
                'Google API error accessNotConfigured: the YouTube Data API v3 is disabled for the project.',
                false,
                'Enable "YouTube Data API v3" in the Google Cloud console for the project these credentials belong to.',
            ),
            'dailyLimitExceeded' => UserFacingError::make(
                'quota_exceeded',
                'This YouTube channel has used its daily upload quota. The upload will be retried tomorrow.',
                'Google API error dailyLimitExceeded: the per-channel daily upload quota is exhausted.',
                true,
                'No action needed; the job is requeued and retried after the quota resets.',
            ),
            'uploadLimitExceeded' => UserFacingError::make(
                'quota_exceeded',
                'This YouTube channel has used its daily upload quota. The upload will be retried tomorrow.',
                'Google API error uploadLimitExceeded: the per-channel daily upload quota is exhausted.',
                true,
                'No action needed; the job is requeued and retried after the quota resets.',
            ),
            'quotaExceeded' => UserFacingError::make(
                'quota_exceeded',
                'The YouTube API project has used its daily request quota. Analytics will resume tomorrow.',
                'Google API error quotaExceeded: the project-wide daily quota is exhausted.',
                true,
                'No action needed; the sync job is requeued and retried after the quota resets.',
            ),
            'forbidden' => UserFacingError::make(
                'permission_missing',
                'This YouTube channel has not granted the app permission to publish or read. Reconnect the channel and approve the requested access.',
                'Google API error forbidden: the authorized channel lacks the scope for this call.',
                false,
                'Reconnect the channel from the Social Accounts page and approve all requested permissions.',
            ),
            'insufficientPermissions' => UserFacingError::make(
                'permission_missing',
                'This YouTube channel has not granted the app permission to publish or read. Reconnect the channel and approve the requested access.',
                'Google API error insufficientPermissions: the OAuth token lacks the required scope.',
                false,
                'Reconnect the channel and approve the YouTube upload, readonly and analytics scopes.',
            ),
            'invalidPublishAt' => UserFacingError::make(
                'invalid_schedule',
                sprintf('YouTube rejected the scheduled publish time. A scheduled upload must be at least %d minutes in the future.', self::MIN_SCHEDULE_LEAD_MINUTES),
                'Google API error invalidPublishAt: status.publishAt is not a valid future RFC 3339 timestamp.',
                false,
                'Schedule the post at least 15 minutes ahead, or publish it immediately instead.',
            ),
            'unauthorized' => UserFacingError::make(
                'token_invalid',
                'This YouTube connection has expired or was revoked. Reconnect the channel to continue.',
                'Google API error unauthorized: the access token is invalid, expired, or lacks the requested scope.',
                false,
                'Reconnect the channel from the Social Accounts page.',
            ),
            'forbiddenCannotRateOwnVideo' => UserFacingError::make(
                'unsupported_operation',
                'YouTube will not let a channel rate its own video. Skip the rating for this upload.',
                'Google API error forbiddenCannotRateOwnVideo: a channel cannot rate its own video.',
                false,
                'Publish the video without a rating, then set it from the channel owner account.',
            ),
        ];
    }
}
