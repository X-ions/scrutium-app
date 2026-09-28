<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Device Fingerprinting
    |--------------------------------------------------------------------------
    |
    | Browser characteristics are used to build a stable per-device fingerprint
    | so repeated sign-ins from the same machine can be recognised. The
    | fingerprint is a SHA-256 hash of the normalised components; nothing in
    | it is reversible and it never leaves the platform.
    |
    */

    'fingerprint' => [
        // Components that survive browser/OS updates. Changing this list
        // invalidates every existing fingerprint, so treat it as stable.
        'stable_components' => [
            'accept_language',
            'sec_ch_ua_mobile',
            'sec_ch_ua_platform',
            'screen_width',
            'screen_height',
            'color_depth',
            'timezone_offset',
            'webgl_renderer',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Device Workflow
    |--------------------------------------------------------------------------
    |
    | A sign-in from a device that has never been seen before produces one
    | alert. The alert carries a "This was me" link; confirming it marks the
    | device as trusted and silences all future alerts for that device.
    |
    */

    'device_token_days' => 7,

    // Repeat sign-ins from the same new device inside this window are
    // collapsed into the first alert rather than generating new email.
    'new_device_alert_window_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Risk Signals
    |--------------------------------------------------------------------------
    |
    | Locations considered high risk. Populate with ISO-3166 alpha-2 codes
    | separated by commas. Impossible-travel detection compares the implied
    | speed between consecutive sign-ins against the threshold below.
    |
    */

    'high_risk_countries' => env('SECURITY_HIGH_RISK_COUNTRIES', ''),

    'impossible_travel_speed_kmh' => 900,

    /*
    |--------------------------------------------------------------------------
    | Failed Sign-in Alerts
    |--------------------------------------------------------------------------
    |
    | The user is emailed once a sign-in attempt from a single address has
    | failed this many times inside the password-reset throttle window. The
    | alert is still subject to the per-user email rate limits below.
    |
    */

    'failed_login_threshold' => (int) env('SECURITY_FAILED_LOGIN_THRESHOLD', 5),

    /*
    |--------------------------------------------------------------------------
    | Audit Log Retention
    |--------------------------------------------------------------------------
    |
    | Security events are written once and never updated apart from the
    | acknowledgement columns. A retention window in days bounds the table;
    | null keeps every event indefinitely.
    |
    */

    'retention_days' => (int) env('SECURITY_RETENTION_DAYS', 0),

];
