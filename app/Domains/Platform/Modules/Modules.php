<?php

declare(strict_types=1);

namespace App\Domains\Platform\Modules;

use App\Domains\Platform\Settings\Settings;

/** Whole features switched on or off from the admin, no deploy. Off means hidden from navigation and unreachable. */
final class Modules
{
    /** @var array<string, array{label: string, affects: string, default: bool}> */
    public const ALL = [
        'push' => ['label' => 'Push notifications', 'affects' => 'Browser and app push, the push setting for members', 'default' => true],
        'consumer_requests' => ['label' => 'Consumer requests', 'affects' => '"Request a pro" forms on city pages and the consumer portal', 'default' => true],
        'prelaunch' => ['label' => 'Pre-launch reservations', 'affects' => 'The "Reserve your city" page, founding members and the countdown', 'default' => true],
        'community' => ['label' => 'Community', 'affects' => 'Group feed, member directory, badges and leaderboards', 'default' => true],
        'events' => ['label' => 'Events and meetups', 'affects' => 'Event pages, RSVPs and calendar invites', 'default' => true],
        'cross_industry' => ['label' => 'Cross-industry introductions', 'affects' => 'Sending clients to pros in other industries', 'default' => true],
        'partner_directory' => ['label' => 'Partner directory', 'affects' => 'Paid partner listings and the directory page', 'default' => true],
        'ai' => ['label' => 'AI features', 'affects' => 'Lead summaries, nudges, drafts and AI intake; also needs an AI provider', 'default' => false],
    ];

    public function __construct(private readonly Settings $settings) {}

    public function enabled(string $key): bool
    {
        $def = self::ALL[$key] ?? null;
        if ($def === null) {
            return false;
        }
        $v = $this->settings->get('modules.'.$key);

        return $v === null ? $def['default'] : $v === '1';
    }

    /** @return array<string, bool> */
    public function map(): array
    {
        $out = [];
        foreach (array_keys(self::ALL) as $k) {
            $out[$k] = $this->enabled($k);
        }

        return $out;
    }
}
