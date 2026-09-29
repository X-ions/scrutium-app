<?php

/*
|--------------------------------------------------------------------------
| SocialHub CMS — social provider configuration
|--------------------------------------------------------------------------
|
| Credentials are read from the environment ONLY. Never commit a real value and
| never inline a secret here: every credential below resolves to an empty string
| until the corresponding key is set in `.env`. See `.env.example` for the
| documented key names and `php artisan socialhub:doctor` to report which
| providers are configured (it prints `set`/`missing` and never a value).
|
| Adding a provider is a config entry plus one class — no other file changes.
|
*/

return [

    /*
    |---------------------------------------------------------------------------
    | Providers
    |---------------------------------------------------------------------------
    |
    | `class` points at an App\Services\Social\Contracts\SocialProviderInterface
    | implementation. A class listed here but not yet written is skipped by the
    | registry and reported by `socialhub:doctor`; it never causes a fatal error.
    |
    | `capabilities_override` narrows the verified matrix in
    | App\Services\Social\Capabilities\PlatformCapabilities for this deployment.
    | It can only turn capabilities OFF or restate them — the platform truth
    | lives in the matrix.
    |
    */

    'providers' => [

        'facebook' => [
            'class' => App\Services\Social\Providers\FacebookProvider::class,
            'display_name' => 'Facebook',
            'badge' => 'FB',
            'docs_url' => 'https://developers.facebook.com/docs/graph-api',
            'requires_app_review' => true,
            'env_prefix' => 'META_',
            'capabilities_override' => [],
            'credentials' => [
                'client_id' => env('META_APP_ID'),
                'client_secret' => env('META_APP_SECRET'),
                'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
            ],
            'oauth' => [
                'client_id' => env('META_APP_ID'),
                'client_secret' => env('META_APP_SECRET'),
                'authorize_url' => 'https://www.facebook.com/v23.0/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/v23.0/oauth/access_token',
                'revoke_url' => 'https://graph.facebook.com/v23.0/me/permissions',
                'graph_version' => 'v23.0',
                'pkce' => true,
                'scopes' => [
                    'pages_show_list',
                    'pages_read_engagement',
                    'pages_manage_posts',
                    'pages_manage_engagement',
                    'read_insights',
                ],
                'default_scopes' => [
                    'pages_show_list',
                    'pages_read_engagement',
                    'pages_manage_posts',
                    'pages_manage_engagement',
                    'read_insights',
                ],
                'webhook_secret' => env('META_APP_SECRET'),
            ],
        ],

        'instagram' => [
            'class' => App\Services\Social\Providers\InstagramProvider::class,
            'display_name' => 'Instagram',
            'badge' => 'IG',
            'docs_url' => 'https://developers.facebook.com/docs/instagram-platform',
            'requires_app_review' => true,
            'env_prefix' => 'META_',
            'capabilities_override' => [],
            'credentials' => [
                'client_id' => env('META_APP_ID'),
                'client_secret' => env('META_APP_SECRET'),
            ],
            'oauth' => [
                'client_id' => env('META_APP_ID'),
                'client_secret' => env('META_APP_SECRET'),
                'authorize_url' => 'https://www.facebook.com/v23.0/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/v23.0/oauth/access_token',
                'graph_version' => 'v23.0',
                'pkce' => true,
                'scopes' => [
                    'instagram_basic',
                    'instagram_content_publish',
                    'pages_show_list',
                    'pages_read_engagement',
                    'instagram_manage_insights',
                ],
                'default_scopes' => [
                    'instagram_basic',
                    'instagram_content_publish',
                    'pages_show_list',
                    'pages_read_engagement',
                    'instagram_manage_insights',
                ],
                'webhook_secret' => env('META_APP_SECRET'),
            ],
        ],

        'youtube' => [
            'class' => App\Services\Social\Providers\YouTubeProvider::class,
            'display_name' => 'YouTube',
            'badge' => 'YT',
            'docs_url' => 'https://developers.google.com/youtube/v3',
            'requires_app_review' => true,
            'env_prefix' => 'GOOGLE_',
            'capabilities_override' => [],
            'credentials' => [
                'client_id' => env('YOUTUBE_CLIENT_ID'),
                'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
                'redirect_uri' => env('YOUTUBE_REDIRECT_URI'),
            ],
            'oauth' => [
                'client_id' => env('YOUTUBE_CLIENT_ID'),
                'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'revoke_url' => 'https://oauth2.googleapis.com/revoke',
                'redirect_uri' => env('YOUTUBE_REDIRECT_URI'),
                // Offline access is mandatory: without access_type=offline
                // Google never issues a refresh token and the connection dies
                // after one hour.
                'access_type' => 'offline',
                'include_granted_scopes' => true,
                'pkce' => true,
                'scopes' => [
                    'https://www.googleapis.com/auth/youtube.upload',
                    'https://www.googleapis.com/auth/youtube.force-ssl',
                    'https://www.googleapis.com/auth/youtube.readonly',
                    'https://www.googleapis.com/auth/youtube-analytics.reports.readonly',
                ],
                'default_scopes' => [
                    'https://www.googleapis.com/auth/youtube.upload',
                    'https://www.googleapis.com/auth/youtube.force-ssl',
                    'https://www.googleapis.com/auth/youtube.readonly',
                    'https://www.googleapis.com/auth/youtube-analytics.reports.readonly',
                ],
            ],
        ],

        'tiktok' => [
            'class' => App\Services\Social\Providers\TikTokProvider::class,
            'display_name' => 'TikTok',
            'badge' => 'TT',
            'docs_url' => 'https://developers.tiktok.com/doc/content-posting-api-get-started',
            'requires_app_review' => true,
            'env_prefix' => 'TIKTOK_',
            'capabilities_override' => [],
            'credentials' => [
                'client_key' => env('TIKTOK_CLIENT_KEY'),
                'client_secret' => env('TIKTOK_CLIENT_SECRET'),
                'webhook_secret' => env('TIKTOK_WEBHOOK_SECRET'),
            ],
            'oauth' => [
                'client_id' => env('TIKTOK_CLIENT_KEY'),
                'client_secret' => env('TIKTOK_CLIENT_SECRET'),
                'authorize_url' => 'https://www.tiktok.com/v2/auth/authorize/',
                'token_url' => 'https://open.tiktokapis.com/v2/oauth/token/',
                // The creator must be sent through the consent screen before
                // any Content Posting call is accepted. The web app is
                // registered in the developer console, not in .env.
                'consent_url' => 'https://www.tiktok.com/v2/auth/authorize/',
                'consent_base' => 'https://www.tiktok.com/consent/',
                'pkce' => true,
                'scopes' => [
                    'user.info.basic',
                    'video.publish',
                    'video.upload',
                ],
                'default_scopes' => [
                    'user.info.basic',
                    'video.publish',
                    'video.upload',
                ],
                'webhook_secret' => env('TIKTOK_WEBHOOK_SECRET'),
            ],
        ],

        'x' => [
            'class' => App\Services\Social\Providers\XProvider::class,
            'display_name' => 'X',
            'badge' => 'X',
            'docs_url' => 'https://docs.x.com/x-api',
            'requires_app_review' => true,
            'env_prefix' => 'X_',
            'capabilities_override' => [],
            'credentials' => [
                'client_id' => env('X_CLIENT_ID'),
                'client_secret' => env('X_CLIENT_SECRET'),
            ],
            'oauth' => [
                'client_id' => env('X_CLIENT_ID'),
                'client_secret' => env('X_CLIENT_SECRET'),
                'authorize_url' => 'https://x.com/i/oauth2/authorize',
                'token_url' => 'https://api.x.com/2/oauth2/token',
                'revoke_url' => 'https://api.x.com/2/oauth2/revoke',
                'api_base' => 'https://api.x.com',
                // X rotates the refresh token on every use: the new one must be
                // persisted or the connection breaks on the next refresh.
                'rotating_refresh_token' => true,
                'pkce' => true,
                'scopes' => [
                    'tweet.read',
                    'tweet.write',
                    'users.read',
                    'follows.read',
                    'media.write',
                    'offline.access',
                    'space.read',
                ],
                'default_scopes' => [
                    'tweet.read',
                    'tweet.write',
                    'users.read',
                    'media.write',
                    'offline.access',
                ],
            ],
        ],

        'linkedin' => [
            'class' => App\Services\Social\Providers\LinkedInProvider::class,
            'display_name' => 'LinkedIn',
            'badge' => 'LI',
            'docs_url' => 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/community-management/shares/posts-api',
            'requires_app_review' => true,
            'env_prefix' => 'LINKEDIN_',
            'capabilities_override' => [],
            'credentials' => [
                'client_id' => env('LINKEDIN_CLIENT_ID'),
                'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
            ],
            'oauth' => [
                'client_id' => env('LINKEDIN_CLIENT_ID'),
                'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
                'authorize_url' => 'https://www.linkedin.com/oauth/v2/authorization',
                'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
                'revoke_url' => 'https://www.linkedin.com/oauth/v2/revoke',
                'api_base' => 'https://api.linkedin.com/rest',
                // Every Marketing API call needs a dated version header; the
                // docs pin the supported major version.
                'api_version' => '202601',
                'restli_protocol_version' => '2.0.0',
                'pkce' => true,
                'scopes' => [
                    'openid',
                    'profile',
                    'email',
                    'w_member_social',
                    'w_organization_social',
                    'r_organization_social',
                ],
                'default_scopes' => [
                    'openid',
                    'profile',
                    'email',
                    'w_member_social',
                    'w_organization_social',
                ],
            ],
        ],

        'pinterest' => [
            'class' => App\Services\Social\Providers\PinterestProvider::class,
            'display_name' => 'Pinterest',
            'badge' => 'PI',
            'docs_url' => 'https://developers.pinterest.com/docs/api/v5/',
            'requires_app_review' => true,
            'env_prefix' => 'PINTEREST_',
            'capabilities_override' => [],
            'credentials' => [
                'client_id' => env('PINTEREST_CLIENT_ID'),
                'client_secret' => env('PINTEREST_CLIENT_SECRET'),
            ],
            'oauth' => [
                'client_id' => env('PINTEREST_CLIENT_ID'),
                'client_secret' => env('PINTEREST_CLIENT_SECRET'),
                'authorize_url' => 'https://www.pinterest.com/oauth/',
                'token_url' => 'https://api.pinterest.com/v5/oauth/token',
                'api_base' => 'https://api.pinterest.com/v5',
                'pkce' => true,
                'scopes' => [
                    'pins:read',
                    'pins:write',
                    'boards:read',
                    'user_accounts:read',
                ],
                'default_scopes' => [
                    'pins:read',
                    'pins:write',
                    'boards:read',
                    'user_accounts:read',
                ],
            ],
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | Rate limits
    |---------------------------------------------------------------------------
    |
    | Redis token buckets keyed `ratelimit:{provider}:{tenant}`. `capacity` is the
    | burst size, `refill_per_second` the sustained rate, and `cost` the number
    | of tokens one call consumes (use a higher cost for expensive endpoints
    | such as insights). These are conservative starting points — raise them
    | only against your own quota documentation.
    |
    | `fallback_retry_after` is used when a 429 carries no usable Retry-After
    | or X-RateLimit-Reset header. It is never zero: an unthrottled immediate
    | retry against a throttling API is never correct.
    |
    */

    'rate_limits' => [

        'default' => [
            'capacity' => 60,
            'refill_per_second' => 1.0,
            'cost' => 1,
            'fallback_retry_after' => 60,
        ],

        'facebook' => [
            'capacity' => 200,
            'refill_per_second' => 5.0,
            'cost' => 1,
            'fallback_retry_after' => 60,
        ],

        'instagram' => [
            'capacity' => 200,
            'refill_per_second' => 5.0,
            'cost' => 1,
            'fallback_retry_after' => 60,
        ],

        'youtube' => [
            'capacity' => 100,
            'refill_per_second' => 2.0,
            'cost' => 1,
            'fallback_retry_after' => 60,
        ],

        'tiktok' => [
            'capacity' => 100,
            'refill_per_second' => 2.0,
            'cost' => 1,
            'fallback_retry_after' => 60,
        ],

        'x' => [
            'capacity' => 300,
            'refill_per_second' => 10.0,
            'cost' => 1,
            'fallback_retry_after' => 900,
        ],

        'linkedin' => [
            'capacity' => 100,
            'refill_per_second' => 1.0,
            'cost' => 1,
            'fallback_retry_after' => 60,
        ],

        'pinterest' => [
            'capacity' => 100,
            'refill_per_second' => 1.0,
            'cost' => 1,
            'fallback_retry_after' => 60,
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | Publishing defaults
    |---------------------------------------------------------------------------
    |
    | Applied by the publishing engine. A 429 releases the job for
    | `retry_after` seconds rather than retrying inside the request.
    |
    */

    'publishing' => [
        'max_attempts' => 5,
        'backoff' => [30, 120, 600, 1800],
        'connect_timeout' => 10,
        'timeout' => 30,
        'insights_timeout' => 60,
        'release_on_rate_limit' => true,
    ],

    /*
    |---------------------------------------------------------------------------
    | Media limits
    |---------------------------------------------------------------------------
    |
    | Enforced before any upload: the MIME type is sniffed server-side with
    | finfo, never trusted from the client, and the extension must be on the
    | allow-list. These are our own guard rails — a platform may impose tighter
    | limits, and the provider must still reject what the platform rejects.
    |
    */

    'media' => [
        'disk' => env('SOCIALHUB_MEDIA_DISK', 'local'),
        'max_size_kb' => 2048,
        'allowed_mime' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'video/mp4',
            'video/quicktime',
            'video/webm',
        ],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'webm'],
    ],

    /*
    |---------------------------------------------------------------------------
    | OAuth
    |---------------------------------------------------------------------------
    */

    'oauth' => [
        'state_ttl_minutes' => 10,
        'state_bytes' => 32,
    ],

    /*
    |---------------------------------------------------------------------------
    | Webhooks
    |---------------------------------------------------------------------------
    |
    | Signature verification and replay rejection. `tolerance_seconds` rejects
    | a request whose timestamp is further out than this from server time.
    |
    */

    'webhooks' => [
        'tolerance_seconds' => 300,
        'queue' => env('SOCIALHUB_QUEUE'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Pagination
    |---------------------------------------------------------------------------
    */

    'pagination' => [
        'max_pages' => 25,
        'default_page_size' => 50,
    ],

    /*
    |---------------------------------------------------------------------------
    | Queue
    |---------------------------------------------------------------------------
    |
    | Falls back to the application's default connection when unset.
    |
    */

    'queue' => env('SOCIALHUB_QUEUE'),

    /*
    |---------------------------------------------------------------------------
    | Token encryption
    |---------------------------------------------------------------------------
    |
    | When set, social tokens are encrypted with this dedicated key rather than
    | the application key, so a leaked application key does not also expose
    | every connected social account. Must be a base64-encoded 32-byte key.
    |
    */

    'token_key' => env('SOCIALHUB_TOKEN_KEY'),

];
