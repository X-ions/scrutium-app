<?php

declare(strict_types=1);

namespace App\Services\Social\Config;

use App\Services\Social\Data\UserFacingError;

/**
 * TikTok Content Posting API endpoints, scopes, and error codes.
 *
 * Publishing here is gated on TikTok's own approval process: the client must be
 * audited before its posts are publicly visible, and a creator must complete the
 * consent screen before any init call is accepted. Both are enforced by the
 * provider rather than assumed.
 */
final class TikTokConfig
{
    public const API_BASE = 'https://open.tiktokapis.com/v2';

    /** Direct Post: publishes straight to the creator's profile. */
    public const DIRECT_POST_VIDEO = '/post/publish/video/init/';

    /** Upload: hands the video to the creator to finish in the app inbox. */
    public const UPLOAD_VIDEO = '/post/publish/inbox/video/init/';

    /** Photo and photo-carousel posts. */
    public const PHOTO_POST = '/post/publish/content/init/';

    public const CREATOR_INFO = '/post/publish/creator_info/query/';

    public const PUBLISH_STATUS = '/post/publish/status/fetch/';

    public const CANCEL_PUBLISH = '/post/publish/cancel/';

    public const USER_INFO = '/user/info/';

    public const VIDEO_QUERY = '/video/query/';

    public const COMMENT_LIST = '/comment/list/';

    public const COMMENT_REPLY = '/comment/reply/';

    public const USER_INFO_FIELDS = 'open_id,union_id,avatar_url,display_name,username,follower_count,video_count';

    /**
     * The only privacy level every client is guaranteed may be used: TikTok
     * restricts unaudited clients to `SELF_ONLY`.
     */
    public const SAFE_PRIVACY_LEVEL = 'SELF_ONLY';

    public const PRIVACY_LEVELS = [
        'PUBLIC_TO_EVERYONE',
        'MUTUAL_FOLLOW_FRIENDS',
        'FOLLOWER_OF_CREATOR',
        'SELF_ONLY',
    ];

    /** A photo post may carry at most 35 images, which is TikTok's carousel. */
    public const MAX_PHOTO_IMAGES = 35;

    public const CHUNK_SIZE = 10485760;

    public const MAX_VIDEO_BYTES = 4294967296;

    /**
     * @return array<string, UserFacingError>
     */
    public static function errorMap(): array
    {
        return [
            'unaudited_client_can_only_post_to_private_accounts' => UserFacingError::make(
                'app_audit_required',
                'TikTok only allows this app to post privately until it passes TikTok App Audit. The post was not published.',
                'TikTok error unaudited_client_can_only_post_to_private_accounts: the client is unaudited.',
                false,
                'Submit the app for TikTok App Audit. Until it is approved, only private posts are allowed.',
            ),
            'privacy_level_option_mismatch' => UserFacingError::make(
                'privacy_level_mismatch',
                'TikTok rejected the privacy level chosen for this post.',
                'TikTok error privacy_level_option_mismatch: privacy_level is missing or not one of the creator\'s allowed options.',
                false,
                'Query the creator info endpoint and offer the creator exactly the privacy levels TikTok returned for their account.',
            ),
            'access_token_invalid' => UserFacingError::make(
                'token_invalid',
                'This TikTok connection has expired or been revoked. Reconnect the account to continue.',
                'TikTok error access_token_invalid: the access token is invalid or has expired.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
            'scope_not_authorized' => UserFacingError::make(
                'permission_missing',
                'This TikTok account has not granted the app permission to publish or read. Reconnect it and approve the requested access.',
                'TikTok error scope_not_authorized: the access token does not carry the required scope grant.',
                false,
                'Reconnect the account and approve the video.publish and video.upload scopes.',
            ),
            'consent_required' => UserFacingError::make(
                'consent_required',
                'The creator must approve TikTok\'s posting consent screen before this app can publish for them.',
                'TikTok error consent_required: the creator has not completed the Content Posting consent flow.',
                false,
                'Send the creator through the TikTok consent screen again, then retry the publish.',
            ),
            'spam_risk_too_many_posts' => UserFacingError::make(
                'daily_post_cap',
                'This creator has reached TikTok\'s daily API posting limit. The post will be retried tomorrow.',
                'TikTok error spam_risk_too_many_posts: the user daily post cap from the API was reached.',
                true,
                'No action needed; the job is requeued and retried after the cap resets.',
            ),
            'spam_risk_user_banned_from_posting' => UserFacingError::make(
                'user_banned',
                'TikTok will not let this creator post. Nothing was published.',
                'TikTok error spam_risk_user_banned_from_posting: the user is banned from making new posts.',
                false,
                'The creator must resolve this in the TikTok app; the post cannot be published until then.',
            ),
            'reached_active_user_cap' => UserFacingError::make(
                'daily_user_cap',
                'This app reached TikTok\'s daily quota for active publishing creators. The post will be retried tomorrow.',
                'TikTok error reached_active_user_cap: the daily active publishing user quota was reached.',
                true,
                'No action needed; the job is requeued and retried after the quota resets.',
            ),
            'url_ownership_unverified' => UserFacingError::make(
                'url_verification_required',
                'TikTok will only pull media from a URL whose domain this app has verified.',
                'TikTok error url_ownership_unverified: the media URL domain is not verified in the TikTok app settings.',
                false,
                'Add and verify the media domain in the TikTok for Developers console, then retry.',
            ),
            'invalid_params' => UserFacingError::make(
                'invalid_params',
                'TikTok rejected the post details. Nothing was published.',
                'TikTok error invalid_params: a required post_info or source_info field is missing or invalid.',
                false,
                'Review the caption length, privacy level, and media source on this variant, then retry.',
            ),
        ];
    }
}
