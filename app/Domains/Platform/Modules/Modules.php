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
        'prelaunch' => ['label' => 'Pre-launch reservations', 'affects' => 'The "Reserve your city" page, founding members and the countdown', 'default' => true],
        'training' => ['label' => 'Network training', 'affects' => 'The training members complete before sending referrals (all tiers), quiz, certificates and refreshers. Off: members send straight away; certificates are kept', 'default' => true],
        'community' => ['label' => 'Community', 'affects' => 'Group feed, member directory, badges, leaderboards, monthly recap and feed digests', 'default' => true],
        'events' => ['label' => 'Events and meetups', 'affects' => 'Event pages, RSVPs, calendar invites and reminders', 'default' => true],
        'cross_industry' => ['label' => 'Cross-industry introductions', 'affects' => 'Introducing clients to pros in other industries', 'default' => true],
        'ai_summaries' => ['label' => 'AI lead summaries', 'affects' => 'The AI summary shown before accepting a referral', 'default' => false],
        'ai_drafts' => ['label' => 'AI message drafts', 'affects' => 'AI-written follow-up drafts in the referral CRM', 'default' => false],
        'ai_intake' => ['label' => 'AI intake', 'affects' => 'Forwarded emails, voice notes and card photos becoming draft referrals', 'default' => false],
        'chat_bot' => ['label' => 'WhatsApp and SMS bot', 'affects' => 'Sending referrals by chat, and status replies by WhatsApp or SMS', 'default' => false],
        'ai_insights' => ['label' => 'AI owner insights', 'affects' => 'The AI-written weekly summary for network owners (the figures are sent either way)', 'default' => false],
        'ai_videos' => ['label' => 'AI training videos', 'affects' => 'Creating training videos with an AI presenter; needs Network training on. Published videos keep playing', 'default' => false],
        'consumer_requests' => ['label' => 'Consumer requests', 'affects' => '"Request a pro" forms on city pages and the consumer portal', 'default' => true, 'available' => false],
        'partner_directory' => ['label' => 'Partner directory', 'affects' => 'Paid partner listings and the directory page', 'default' => true, 'available' => false],
    ];

    public function __construct(private readonly Settings $settings) {}

    public function enabled(string $key): bool
    {
        $def = self::ALL[$key] ?? null;
        if ($def === null) {
            return false;
        }
        // AI training videos attach to network training: with training off, there's nothing to make videos for.
        if ($key === 'ai_videos' && ! $this->enabled('training')) {
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
