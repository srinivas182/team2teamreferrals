<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Referrals\Events\ReferralEventRecorded;
use Illuminate\Support\Facades\DB;

/**
 * The one place a referral's timeline is written. Every entry also raises ReferralEventRecorded, so other parts of
 * the platform (outgoing webhooks, Sprint 44b) hear about every change without each feature having to remember.
 */
final class ReferralEvents
{
    /** @param array<string, mixed> $meta */
    public static function record(Referral $ref, string $event, ?int $teamId, ?int $actorId, array $meta = []): void
    {
        $id = (int) DB::table('referral_events')->insertGetId(['referral_id' => $ref->id, 'event' => $event, 'team_id' => $teamId, 'actor_id' => $actorId,
            'meta' => $meta === [] ? null : json_encode($meta), 'created_at' => now()]);
        ReferralEventRecorded::dispatch($ref, $event, $id, $meta);
    }
}
