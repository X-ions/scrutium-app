<?php

declare(strict_types=1);

namespace App\Services\Social\Config;

use App\Services\Social\Data\UserFacingError;

/**
 * X API v2 endpoints, limits, and error codes.
 *
 * X meters by endpoint, not by a global window, so the `x-rate-limit-*` headers
 * name the bucket the response came from. The per-endpoint cost matters as much
 * as the window: a Post read costs more than a write.
 */
final class XConfig
{
    public const API_BASE = 'https://api.x.com';

    public const UPLOAD_BASE = 'https://upload.x.com/1.1/media/upload.json';

    public const AUTHORIZE_URL = 'https://x.com/i/oauth2/authorize';

    public const TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    public const REVOKE_URL = 'https://api.x.com/2/oauth2/revoke';

    public const POSTS = '/2/tweets';

    public const MEDIA_UPLOAD = '/2/media/upload';

    public const MEDIA_INITIALIZE = '/2/media/upload/initialize';

    public const MEDIA_STATUS = '/2/media/upload';

    /** A Post may carry up to four images, or one GIF, or one video. */
    public const MAX_IMAGES_PER_POST = 4;

    public const CHUNK_SIZE = 5242880;

    public const MAX_IMAGE_BYTES = 5242880;

    public const MAX_GIF_BYTES = 15728640;

    public const MAX_VIDEO_BYTES = 5368709120;

    /** A media id is only valid for 24 hours after upload. */
    public const MEDIA_ID_TTL_SECONDS = 86400;

    public const DEFAULT_CHUNK_CHECK_AFTER_SECONDS = 5;

    /**
     * `public_metrics` field => normalised metric. `impression_count` is only
     * present for posts the authenticated user authored.
     *
     * @var array<string, string>
     */
    public const PUBLIC_METRICS = [
        'like_count' => 'likes',
        'reply_count' => 'comments',
        'retweet_count' => 'shares',
        'quote_count' => 'shares',
        'bookmark_count' => 'saves',
        'impression_count' => 'impressions',
    ];

    /**
     * @return array<string, UserFacingError>
     */
    public static function errorMap(): array
    {
        return [
            'invalid_grant' => UserFacingError::make(
                'token_revoked',
                'This X connection has expired or been revoked. Reconnect the account to continue.',
                'X returned invalid_grant: the refresh token is no longer valid.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
            'unsupported_grant_type' => UserFacingError::make(
                'token_revoked',
                'This X connection can no longer be refreshed. Reconnect the account to continue.',
                'X returned unsupported_grant_type for the refresh request.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
            'insufficient_scope' => UserFacingError::make(
                'permission_missing',
                'This X connection does not grant the app permission for this action. Reconnect it and approve the requested access.',
                'X returned insufficient_scope: the user token lacks the required scope.',
                false,
                'Reconnect the account and approve the tweet.write, media.write, and offline.access scopes.',
            ),
            'not_authorized_for_resource' => UserFacingError::make(
                'permission_missing',
                'This X account has not granted the app permission for this action. Reconnect it and approve the requested access.',
                'X returned not_authorized_for_resource: the authenticated user cannot act on this object.',
                false,
                'Reconnect the account and approve all requested permissions.',
            ),
            'duplicate' => UserFacingError::make(
                'duplicate_post',
                'X rejected this post because an identical one was already published.',
                'X returned a duplicate error: the same Post text was posted too recently.',
                false,
                'Change the wording of this post, or publish it again later.',
            ),
            'media_not_found' => UserFacingError::make(
                'media_expired',
                'The uploaded media could not be attached. X discards media ids after 24 hours.',
                'X returned media_not_found: the media_id is unknown or expired.',
                false,
                'Upload the media again immediately before publishing.',
            ),
            'processing_not_yet_finished' => UserFacingError::make(
                'media_processing',
                'X is still processing the media. The post will be retried shortly.',
                'X returned processing_not_yet_finished: the media is not ready to attach.',
                true,
                'No action needed; the job is requeued and retried once processing finishes.',
            ),
            'media_upload_not_found' => UserFacingError::make(
                'media_expired',
                'The media upload session is no longer available on X.',
                'X returned media_upload_not_found for the upload session.',
                false,
                'Start the media upload again.',
            ),
            'too_many_requests' => UserFacingError::make(
                'rate_limited',
                'X is asking us to slow down. The post will be retried automatically.',
                'X returned a rate limit response for this endpoint.',
                true,
                'No action needed; the job is requeued with a delay.',
            ),
        ];
    }
}
