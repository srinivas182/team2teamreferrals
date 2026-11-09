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
