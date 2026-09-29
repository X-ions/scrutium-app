<?php

declare(strict_types=1);

namespace App\Services\Social\Config;

use App\Services\Social\Data\UserFacingError;

/**
 * Instagram Graph API endpoints and the error codes the Content Publishing API
 * returns.
 *
 * Kept apart from the provider so the same facts are readable — and testable —
 * without paging through the publish flow.
 */
final class InstagramConfig
{
    public const HOST = 'https://graph.facebook.com';

    /** Resumable video sessions are pushed to a different, unversioned host. */
    public const R_UPLOAD_HOST = 'https://rupload.facebook.com';

    public const RUPLOAD_PATH = '/ig-api-upload';

    /** A container expires this long after creation, so it cannot be published later. */
    public const CONTAINER_TTL_SECONDS = 1800;

    /** Bounded polling: an unfinished container becomes a retryable error, never a hang. */
    public const CONTAINER_POLL_ATTEMPTS = 8;

    public const CONTAINER_POLL_INTERVAL_SECONDS = 5;

    /** Instagram's rolling 24-hour API publish cap, per professional account. */
    public const DAILY_PUBLISH_CAP = 50;

    public const MAX_CAROUSEL_CHILDREN = 10;

    public const MAX_CAPTION_LENGTH = 2200;

    public const MAX_REEL_BYTES = 1073741824;

    public const MAX_IMAGE_BYTES = 8388608;

    /**
     * Insight metric name => normalised metric. Anything absent from this map
     * is not a metric we know how to normalise and is left out of a batch.
     *
     * @var array<string, string>
     */
    public const INSIGHT_METRICS = [
        'views' => 'views',
        'reach' => 'reach',
        'impressions' => 'impressions',
        'saved' => 'saves',
        'shares' => 'shares',
        'comments' => 'comments',
        'likes' => 'likes',
    ];

    /**
     * @return array<string, UserFacingError>
     */
    public static function errorMap(): array
    {
        return [
            '190' => UserFacingError::make(
                'token_invalid',
                'This Instagram connection has expired or was revoked. Reconnect the account to continue.',
                'Graph API error 190: the access token is invalid or has expired.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
            '102' => UserFacingError::make(
                'session_expired',
                'The Facebook session behind this Instagram connection expired. Reconnect the account to continue.',
                'Graph API error 102: the session has expired.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
            '200' => UserFacingError::make(
                'permission_missing',
                'This Instagram account is missing a permission needed to publish. Reconnect it and approve the requested access.',
                'Graph API error 200: the app does not have the required permission.',
                false,
                'Reconnect the account and approve the instagram_content_publish permission.',
            ),
            '10' => UserFacingError::make(
                'permission_missing',
                'The Facebook Page behind this Instagram account does not grant the app the access needed to publish.',
                'Graph API error 10: the permission is not granted for this object.',
                false,
                'Grant the app a Content Creator or Full Control role on the Page in Business Manager, then reconnect.',
            ),
            '9' => UserFacingError::make(
                'instagram_account_issue',
                'The Facebook Page linked to this Instagram account is not configured for API publishing.',
                'Graph API error 9: the Instagram professional account is not linked to the Page the token was issued for.',
                false,
                'Link the Instagram professional account to the Facebook Page in the Instagram app settings, then reconnect.',
            ),
            '36003' => UserFacingError::make(
                'api_post_cap',
                'This Instagram account has reached its API publishing limit. The post will be retried tomorrow.',
                'Graph API error 36003: the rolling 24-hour API post cap for the account was reached.',
                true,
                'No action needed; the job is requeued and retried after the cap rolls over.',
            ),
            '2' => UserFacingError::make(
                'service_temporarily_down',
                'Instagram is asking us to slow down. The post will be retried automatically.',
                'Graph API error 2: the service is temporarily down.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
            '4' => UserFacingError::make(
                'rate_limited',
                'Instagram is asking us to slow down. The post will be retried automatically.',
                'Graph API error 4: the application has reached its rate limit.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
            '17' => UserFacingError::make(
                'rate_limited',
                'Instagram is asking us to slow down. The post will be retried automatically.',
                'Graph API error 17: the call rate limit was reached.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
            '368' => UserFacingError::make(
                'temporarily_blocked',
                'Instagram temporarily blocked publishing from this account. The post will be retried later.',
                'Graph API error 368: the action was temporarily blocked.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
            '9007' => UserFacingError::make(
                'container_unfinished',
                'Instagram had not finished processing the media before the post was published. The post will be retried.',
                'Graph API error 9007: the media container was not in the FINISHED state.',
                true,
                'No action needed; the job is requeued and the container is polled again.',
            ),
        ];
    }
}
