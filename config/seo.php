<?php

/*
| SEO and AEO configuration. Every public page gets server-rendered metadata,
| structured data, sitemap entries and search-engine notifications from these
| defaults; pages override only what differs.
*/

return [
    'site_name' => env('SEO_SITE_NAME', 'Team to Team Referrals'),
    'short_name' => 't2t',
    'default_description' => 'The referral network for licensed teams. Hand out-of-area leads to verified teams you trust, track every step, and get paid when they close.',
    'default_image' => '/brand/og-image.png',
    'locale' => 'en_US',

    // Canonical origin. Requests on any other host or scheme are 301-redirected here in production.
    'canonical_url' => env('SEO_CANONICAL_URL', env('APP_URL', 'http://localhost')),
    'force_canonical' => (bool) env('SEO_FORCE_CANONICAL', false),

    // Non-production environments are never indexed.
    'indexable' => env('SEO_INDEXABLE', env('APP_ENV') === 'production'),

    'organization' => [
        'name' => 'Team to Team Referrals',
        'url' => env('SEO_CANONICAL_URL', env('APP_URL', 'http://localhost')),
        'logo' => '/brand/icon-512.png',
        'same_as' => [],
    ],

    // AI answer-engine crawlers: allowed by default so content can be cited; switchable by the super admin later.
    'ai_crawlers' => [
        'GPTBot' => true,
        'OAI-SearchBot' => true,
        'ChatGPT-User' => true,
        'ClaudeBot' => true,
        'Claude-SearchBot' => true,
        'PerplexityBot' => true,
        'Google-Extended' => true,
        'Applebot-Extended' => true,
    ],

    // Paths no crawler should fetch (portal, admin, auth, private client links).
    'disallow' => ['/app', '/admin', '/login', '/register', '/forgot-password', '/reset-password', '/two-factor-challenge', '/verify-phone', '/email/', '/user/', '/passkeys/', '/c/', '/api/'],

    'sitemap' => [
        'cache_seconds' => 3600,
        'max_urls_per_file' => 45000,
    ],

    'indexnow' => [
        'enabled' => (bool) env('INDEXNOW_ENABLED', false),
        'key' => env('INDEXNOW_KEY'),
        'endpoint' => 'https://api.indexnow.org/indexnow',
    ],

    'llms' => [
        'summary' => 'Team to Team Referrals is a US referral network for licensed professionals in 46 industries. Each industry network has one verified pro per territory; pros send out-of-area clients to the verified pro there, with the client’s consent, and track every step to closing. Seats cost $10 per city per network per month after a free trial, and months with no referral offered are free. Where an industry allows referral fees, they are paid business to business; Team to Team never holds them.',
    ],
];
