<?php

declare(strict_types=1);

namespace App\Domains\Platform\Providers;

/**
 * Which providers exist, and which settings each needs. Drives validation, the admin
 * forms and runtime configuration, so adding a provider is one entry here plus a driver.
 */
final class ProviderCatalogue
{
    /**
     * @var array<string, array<string, array{label: string, fields: array<string, array{label: string, secret?: bool, required?: bool, hint?: string}>}>>
     */
    public const PROVIDERS = [
        'mail' => [
            'log' => ['label' => 'Development log (no email sent)', 'fields' => []],
            'smtp' => ['label' => 'SMTP', 'fields' => [
                'host' => ['label' => 'Host', 'required' => true],
                'port' => ['label' => 'Port', 'required' => true, 'hint' => '587 for STARTTLS, 465 for SSL'],
                'username' => ['label' => 'Username'],
                'password' => ['label' => 'Password', 'secret' => true],
            ]],
            'ses' => ['label' => 'Amazon SES', 'fields' => [
                'key' => ['label' => 'Access key ID', 'required' => true],
                'secret' => ['label' => 'Secret access key', 'secret' => true, 'required' => true],
                'region' => ['label' => 'Region', 'required' => true, 'hint' => 'e.g. us-east-1'],
            ]],
            'postmark' => ['label' => 'Postmark', 'fields' => [
                'token' => ['label' => 'Server API token', 'secret' => true, 'required' => true],
            ]],
            'mailgun' => ['label' => 'Mailgun', 'fields' => [
                'domain' => ['label' => 'Sending domain', 'required' => true],
                'secret' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'endpoint' => ['label' => 'API endpoint', 'hint' => 'api.mailgun.net, or api.eu.mailgun.net for EU'],
            ]],
        ],
        'sms' => [
            'log' => ['label' => 'Development log (no text sent)', 'fields' => []],
            'twilio' => ['label' => 'Twilio', 'fields' => [
                'sid' => ['label' => 'Account SID', 'required' => true],
                'token' => ['label' => 'Auth token', 'secret' => true, 'required' => true],
                'from' => ['label' => 'From number or Messaging Service SID', 'required' => true, 'hint' => '+1… number, or MG… for a Messaging Service (recommended for A2P 10DLC)'],
                'whatsapp_from' => ['label' => 'WhatsApp number (optional)', 'hint' => '+1… number enabled for WhatsApp Business after Meta verification'],
                'template_accepted' => ['label' => 'WhatsApp template: referral accepted (optional)', 'hint' => 'Content SID (HX…) of the Meta-approved template; variables 1 client, 2 city, 3 team'],
                'template_declined' => ['label' => 'WhatsApp template: referral declined (optional)', 'hint' => 'Content SID (HX…) of the Meta-approved template; variables 1 client, 2 city, 3 team'],
                'cost_per_message' => ['label' => 'Average cost per message (USD, optional)', 'hint' => 'Used to measure spend against the cap; defaults to 0.01'],
                'monthly_cap' => ['label' => 'Monthly messaging cap (USD, optional)', 'hint' => 'Status replies pause for the month when reached; 0 means no cap'],
            ]],
        ],
        'payments' => [
            'none' => ['label' => 'Not connected', 'fields' => []],
            'stripe' => ['label' => 'Stripe', 'fields' => [
                'publishable_key' => ['label' => 'Publishable key', 'required' => true, 'hint' => 'pk_test_… or pk_live_…'],
                'secret_key' => ['label' => 'Secret key', 'secret' => true, 'required' => true, 'hint' => 'sk_test_… or sk_live_… (a restricted key works too)'],
                'webhook_secret' => ['label' => 'Webhook signing secret', 'secret' => true, 'hint' => 'whsec_…, from the webhook endpoint you create in Stripe'],
            ]],
        ],
        'storage' => [
            'local' => ['label' => 'This server (development)', 'fields' => []],
            's3' => ['label' => 'S3-compatible (AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2)', 'fields' => [
                'key' => ['label' => 'Access key ID', 'required' => true],
                'secret' => ['label' => 'Secret access key', 'secret' => true, 'required' => true],
                'bucket' => ['label' => 'Bucket', 'required' => true],
                'region' => ['label' => 'Region', 'required' => true, 'hint' => 'us-east-1, or auto for Cloudflare R2'],
                'endpoint' => ['label' => 'Endpoint', 'hint' => 'Leave blank for AWS. R2: https://<account>.r2.cloudflarestorage.com'],
            ]],
        ],
        'cdn' => [
            'none' => ['label' => 'No CDN purge', 'fields' => []],
            'cloudflare' => ['label' => 'Cloudflare', 'fields' => [
                'zone_id' => ['label' => 'Zone ID', 'required' => true],
                'api_token' => ['label' => 'API token (Cache Purge permission only)', 'secret' => true, 'required' => true],
            ]],
        ],
        'ai' => [
            'none' => ['label' => 'Off', 'fields' => []],
            'anthropic' => ['label' => 'Anthropic (Claude)', 'fields' => [
                'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'model' => ['label' => 'Model', 'required' => true, 'hint' => 'e.g. claude-haiku-5-5 for fast, low-cost tasks'],
                'input_price' => ['label' => 'Input price (USD per million tokens)', 'required' => true, 'hint' => 'From your Anthropic pricing page; used for the spend cap'],
                'output_price' => ['label' => 'Output price (USD per million tokens)', 'required' => true],
                'monthly_cap' => ['label' => 'Monthly spend cap (USD)', 'required' => true, 'hint' => 'AI features pause for the month when reached'],
            ]],
            'openai' => ['label' => 'OpenAI', 'fields' => [
                'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'model' => ['label' => 'Model', 'required' => true, 'hint' => 'A fast, low-cost model for summaries and drafts'],
                'input_price' => ['label' => 'Input price (USD per million tokens)', 'required' => true, 'hint' => 'From your OpenAI pricing page; used for the spend cap'],
                'output_price' => ['label' => 'Output price (USD per million tokens)', 'required' => true],
                'monthly_cap' => ['label' => 'Monthly spend cap (USD)', 'required' => true, 'hint' => 'This provider pauses for the month when reached'],
            ]],
        ],
        // Where reporting data lives (Admin → Settings → Analytics). MySQL by default; ClickHouse for large volumes.
        'analytics' => [
            'mysql' => ['label' => 'MySQL (a separate database is recommended)', 'fields' => []],
            'clickhouse' => ['label' => 'ClickHouse', 'fields' => [
                'url' => ['label' => 'HTTP address', 'required' => true, 'hint' => 'For example https://clickhouse.internal:8443 (a Droplet or ClickHouse Cloud)'],
                'user' => ['label' => 'User', 'required' => true],
                'password' => ['label' => 'Password', 'secret' => true, 'required' => true],
                'database' => ['label' => 'Database', 'required' => true, 'hint' => 'Created on first use'],
            ]],
        ],
        // AI presenter videos for training (Admin → Settings → Video).
        'video' => [
            'none' => ['label' => 'Not connected', 'fields' => []],
            'heygen' => ['label' => 'HeyGen', 'fields' => [
                'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'price_per_minute' => ['label' => 'Price per video minute (USD)', 'required' => true, 'hint' => 'From your HeyGen plan; used for the platform’s cost reporting'],
                'webhook_secret' => ['label' => 'Webhook secret (optional)', 'secret' => true, 'hint' => 'Lets HeyGen tell us when a video is ready; without it we check every 5 minutes'],
            ]],
        ],
        // Used automatically when the main AI provider fails or reaches its spend cap.
        'ai_fallback' => [
            'none' => ['label' => 'No fallback', 'fields' => []],
            'openai' => ['label' => 'OpenAI', 'fields' => [
                'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'model' => ['label' => 'Model', 'required' => true, 'hint' => 'A fast, low-cost model for summaries and drafts'],
                'input_price' => ['label' => 'Input price (USD per million tokens)', 'required' => true, 'hint' => 'From your OpenAI pricing page; used for the spend cap'],
                'output_price' => ['label' => 'Output price (USD per million tokens)', 'required' => true],
                'monthly_cap' => ['label' => 'Monthly spend cap (USD)', 'required' => true, 'hint' => 'This provider pauses for the month when reached'],
            ]],
            'anthropic' => ['label' => 'Anthropic (Claude)', 'fields' => [
                'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
                'model' => ['label' => 'Model', 'required' => true, 'hint' => 'e.g. claude-haiku-5-5 for fast, low-cost tasks'],
                'input_price' => ['label' => 'Input price (USD per million tokens)', 'required' => true, 'hint' => 'From your Anthropic pricing page; used for the spend cap'],
                'output_price' => ['label' => 'Output price (USD per million tokens)', 'required' => true],
                'monthly_cap' => ['label' => 'Monthly spend cap (USD)', 'required' => true, 'hint' => 'AI features pause for the month when reached'],
            ]],
        ],
    ];

    /** @return array<string, array{label: string, secret?: bool, required?: bool, hint?: string}> */
    public static function fields(string $channel, string $driver): array
    {
        return self::PROVIDERS[$channel][$driver]['fields'] ?? [];
    }

    /** @return array<string, array{label: string, fields: array<string, array{label: string, secret?: bool, required?: bool, hint?: string}>}> */
    public static function forChannel(string $channel): array
    {
        return self::PROVIDERS[$channel] ?? [];
    }

    /** @return list<string> */
    public static function drivers(string $channel): array
    {
        return array_keys(self::PROVIDERS[$channel] ?? []);
    }
}
