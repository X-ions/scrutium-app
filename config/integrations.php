<?php

return [
    'categories' => [
        'social' => 'Social & Creator',
        'commerce' => 'E-Commerce & Sales',
        'audience' => 'Audience Intelligence',
        'operations' => 'Workflow & Ops',
    ],

    'providers' => [
        'instagram' => [
            'name' => 'Instagram / Meta API',
            'category' => 'social',
            'badge' => 'IG',
            'description' => 'Sync Reel views, reach, impressions, and audience metrics.',
        ],
        'tiktok' => [
            'name' => 'TikTok Creator Marketplace',
            'category' => 'social',
            'badge' => 'TT',
            'description' => 'Bring creator analytics and campaign performance into Scrutium.',
        ],
        'youtube' => [
            'name' => 'YouTube',
            'category' => 'social',
            'badge' => 'YT',
            'description' => 'Sync video views, watch time, and channel performance.',
        ],
        'twitch' => [
            'name' => 'Twitch',
            'category' => 'social',
            'badge' => 'TW',
            'description' => 'Track live-stream reach and creator channel metrics.',
        ],
        'x' => [
            'name' => 'X',
            'category' => 'social',
            'badge' => 'X',
            'description' => 'Sync post engagement and audience performance.',
        ],
        'shopify' => [
            'name' => 'Shopify',
            'category' => 'commerce',
            'badge' => 'SH',
            'description' => 'Attribute orders and promo-code sales to campaigns.',
        ],
        'woocommerce' => [
            'name' => 'WooCommerce',
            'category' => 'commerce',
            'badge' => 'WC',
            'description' => 'Connect store orders and creator referral performance.',
        ],
        'amazon_brand_registry' => [
            'name' => 'Amazon Brand Registry',
            'category' => 'commerce',
            'badge' => 'AM',
            'description' => 'Bring marketplace attribution and sales reporting together.',
        ],
        'stripe' => [
            'name' => 'Stripe',
            'category' => 'commerce',
            'badge' => 'ST',
            'description' => 'Reconcile tracked campaign referrals with payment events.',
        ],
        'hypeauditor' => [
            'name' => 'HypeAuditor',
            'category' => 'audience',
            'badge' => 'HA',
            'description' => 'Add audience quality and authenticity signals to vetting.',
        ],
        'modash' => [
            'name' => 'Modash',
            'category' => 'audience',
            'badge' => 'MD',
            'description' => 'Enrich creator profiles with audience intelligence.',
        ],
        'custom_webhook' => [
            'name' => 'Custom Webhook',
            'category' => 'audience',
            'badge' => 'WH',
            'description' => 'Connect a custom verification or integrity data source.',
        ],
        'slack' => [
            'name' => 'Slack',
            'category' => 'operations',
            'badge' => 'SL',
            'description' => 'Send deliverable, approval, and integrity alerts to Slack.',
        ],
        'microsoft_teams' => [
            'name' => 'Microsoft Teams',
            'category' => 'operations',
            'badge' => 'MT',
            'description' => 'Route campaign updates and workflow alerts to Teams.',
        ],
        'gmail' => [
            'name' => 'Gmail',
            'category' => 'operations',
            'badge' => 'GM',
            'description' => 'Coordinate communications and workflow notifications.',
        ],
        'hubspot' => [
            'name' => 'HubSpot',
            'category' => 'operations',
            'badge' => 'HS',
            'description' => 'Connect creator campaign workflows with CRM activity.',
        ],
        'zapier' => [
            'name' => 'Zapier',
            'category' => 'operations',
            'badge' => 'ZP',
            'description' => 'Automate handoffs between Scrutium and your tools.',
        ],
    ],
];