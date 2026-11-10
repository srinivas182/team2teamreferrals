<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Events;

use App\Domains\Referrals\Referral;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Something changed on referral X" (a message, a stage, a signature, a read receipt), pushed to both teams in real
 * time. Deliberately carries no client details or message text: the page reloads its data through the normal,
 * permission-checked request, so masking and privacy rules always apply.
 */
final class ReferralChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly Referral $referral, public readonly string $what) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        // Look the team ids up separately: never load partial relations onto the shared referral model.
        $ref = $this->referral;
        $teams = \App\Domains\Network\Seats\Models\Team::query()->whereIn('id', array_filter([$ref->sender_team_id, $ref->receiver_team_id]))->pluck('ulid', 'id');

        return array_filter([
            new PrivateChannel('referrals.'.$ref->ulid),
            new PrivateChannel('teams.'.$teams[$ref->sender_team_id]),
            $ref->receiver_team_id !== null && isset($teams[$ref->receiver_team_id]) ? new PrivateChannel('teams.'.$teams[$ref->receiver_team_id]) : null,
        ]);
    }

    public function broadcastAs(): string
    {
        return 'referral.changed';
    }

    /** @return array{referral: string, what: string} */
    public function broadcastWith(): array
    {
        return ['referral' => $this->referral->ulid, 'what' => $this->what];
    }
}
