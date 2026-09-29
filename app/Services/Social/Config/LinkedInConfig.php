<?php

declare(strict_types=1);

namespace App\Services\Social\Config;

use App\Services\Social\Data\UserFacingError;

/**
 * LinkedIn Marketing API (Rest.li 2.0.0) endpoints, headers, and error codes.
 *
 * Every call needs a dated `LinkedIn-Version` header and the Rest.li protocol
 * version. A successful create returns the new entity's URN only in the
 * `x-restli-id` response header, never in the body.
 */
final class LinkedInConfig
{
    public const API_BASE = 'https://api.linkedin.com/rest';

    public const RESTLI_PROTOCOL_VERSION = '2.0.0';

    public const POSTS = '/posts';

    public const MULTI_IMAGE = '/multiImage';

    public const DOCUMENTS = '/documents';

    public const SOCIAL_ACTIONS = '/socialActions';

    public const ORGANIZATION_ACLS = '/organizationAcls';

    public const ORGANIZATION_SHARE_STATISTICS = '/organizationalEntityShareStatistics';

    public const USER_INFO = 'https://api.linkedin.com/v2/userinfo';

    public const ME = 'https://api.linkedin.com/v2/me';

    /** LinkedIn rejects a post body that is only a URL. */
    public const MIN_COMMENTARY_LENGTH = 1;

    public const MAX_COMMENTARY_LENGTH = 3000;

    /** A Rest.li finder that can time out should stay well under this. */
    public const FINDER_PAGE_SIZE = 100;

    /** Share statistics are only available over a rolling 12-month window. */
    public const STATISTICS_LOOKBACK_MONTHS = 12;

    /**
     * @return array<string, UserFacingError>
     */
    public static function errorMap(): array
    {
        return [
            'INVALID_PERMISSION' => UserFacingError::make(
                'permission_missing',
                'This LinkedIn account has not granted the app permission for this action. Reconnect it and approve the requested access.',
                'LinkedIn error INVALID_PERMISSION: the token lacks the scope for this endpoint.',
                false,
                'Reconnect the account and approve the w_member_social and w_organization_social permissions.',
            ),
            'INVALID_OAUTH_TOKEN' => UserFacingError::make(
                'token_invalid',
                'This LinkedIn connection has expired or been revoked. Reconnect the account to continue.',
                'LinkedIn error INVALID_OAUTH_TOKEN: the access token is invalid or expired.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
            'REVOKED_ACCESS_TOKEN' => UserFacingError::make(
                'token_revoked',
                'Access to this LinkedIn account was withdrawn. Reconnect it to continue.',
                'LinkedIn error REVOKED_ACCESS_TOKEN: the member or organization revoked the app.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
            'RATE_LIMITED' => UserFacingError::make(
                'rate_limited',
                'LinkedIn is asking us to slow down. The post will be retried automatically.',
                'LinkedIn error RATE_LIMITED: the per-member daily API call allowance is exhausted.',
                true,
                'No action needed; the job is requeued and retried after the daily reset.',
            ),
            'RESOURCE_NOT_FOUND' => UserFacingError::make(
                'not_found',
                'LinkedIn could not find this content. It may have been deleted or belong to another Page.',
                'LinkedIn error RESOURCE_NOT_FOUND: the requested share, UGC post, or organization does not exist.',
                false,
                'Check the Page is still one this member administers, then resync the account.',
            ),
            'ACCESS_DENIED' => UserFacingError::make(
                'permission_missing',
                'This LinkedIn member does not have the Page role required to post on its behalf.',
                'LinkedIn error ACCESS_DENIED: the member lacks an ADMINISTRATOR, CONTENT_ADMIN, or DIRECT_SPONSORED_CONTENT_POSTER role on the organization.',
                false,
                'Ask a Page administrator to grant the member the required role, then reconnect.',
            ),
            'MEDIA_NOT_FOUND' => UserFacingError::make(
                'media_expired',
                'The uploaded media could not be attached to this LinkedIn post.',
                'LinkedIn error MEDIA_NOT_FOUND: the media URN is unknown or has expired.',
                false,
                'Re-upload the media and post again; LinkedIn media URNs are short-lived.',
            ),
            'UNSUPPORTED_MEDIA_TYPE' => UserFacingError::make(
                'unsupported_media',
                'LinkedIn does not accept this media format.',
                'LinkedIn error UNSUPPORTED_MEDIA_TYPE: the uploaded file format is not supported.',
                false,
                'Upload a file in one of the image or video formats LinkedIn lists for the Posts API.',
            ),
        ];
    }
}
