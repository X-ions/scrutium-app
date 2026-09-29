<?php

declare(strict_types=1);

namespace App\Services\Social\Config;

use App\Services\Social\Data\UserFacingError;

/**
 * Pinterest API v5 endpoints, limits, and error codes.
 *
 * A Pin always carries a board and a media_source. Images may be sent inline as
 * base64; a video must be registered through /media first to obtain a media_id.
 * There is no comment API and no scheduled publish field, so both are reported
 * as absent rather than approximated.
 */
final class PinterestConfig
{
    public const API_BASE = 'https://api.pinterest.com/v5';

    public const PINS = '/pins';

    public const BOARDS = '/boards';

    public const MEDIA = '/media';

    public const USER_ACCOUNT = '/user_account';

    public const USER_ACCOUNT_ANALYTICS = '/user_account/analytics';

    public const ACCOUNT = '/user_account';

    public const AUTHORIZE_URL = 'https://www.pinterest.com/oauth/';

    public const TOKEN_URL = 'https://api.pinterest.com/v5/oauth/token';

    public const MAX_TITLE_LENGTH = 100;

    public const MAX_DESCRIPTION_LENGTH = 800;

    public const MAX_ALT_TEXT_LENGTH = 500;

    /** image_base64 accepts JPEG, PNG and GIF under this size. */
    public const MAX_IMAGE_BYTES = 20971520;

    public const MAX_VIDEO_BYTES = 1073741824;

    public const MAX_BOARD_PINS_PAGE = 100;

    /** Analytics queries are limited to a 90-day window. */
    public const MAX_ANALYTICS_WINDOW_DAYS = 90;

    /**
     * Pin and account analytics metric types. There is no reach metric: Pinterest
     * reports impressions only.
     *
     * @var array<string, string>
     */
    public const ANALYTICS_METRICS = [
        'IMPRESSION' => 'impressions',
        'OUTBOUND_CLICK' => 'clicks',
        'PIN_CLICK' => 'clicks',
        'SAVE' => 'saves',
        'VIDEO_MRC_VIEW' => 'views',
        'VIDEO_10S_VIEW' => 'views',
        'TOTAL_REACTIONS' => 'likes',
    ];

    /**
     * @return array<string, UserFacingError>
     */
    public static function errorMap(): array
    {
        return [
            'invalid_scope' => UserFacingError::make(
                'permission_missing',
                'This Pinterest account has not granted the app permission for this action. Reconnect it and approve the requested access.',
                'Pinterest error invalid_scope: the access token lacks the required scope.',
                false,
                'Reconnect the account and approve the pins:read, pins:write, and boards:read scopes.',
            ),
            'invalid_token' => UserFacingError::make(
                'token_invalid',
                'This Pinterest connection has expired or been revoked. Reconnect the account to continue.',
                'Pinterest error invalid_token: the access token is invalid or has expired.',
                false,
                'Reconnect the account from the Social Accounts page.',
            ),
        ];
    }
}
