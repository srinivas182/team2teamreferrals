<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Events;

use App\Domains\Referrals\Referral;
use Illuminate\Foundation\Events\Dispatchable;

/** A new entry on a referral's timeline (sent, accepted, stage moved…), raised by ReferralEvents::record. */
final class ReferralEventRecorded
{
    use Dispatchable;

    /** @param array<string, mixed> $meta */
    public function __construct(public readonly Referral $referral, public readonly string $event, public readonly int $eventId, public readonly array $meta) {}
}
