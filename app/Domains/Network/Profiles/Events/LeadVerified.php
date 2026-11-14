<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A lead sent through a team's public profile (or widget) was confirmed by the referrer and is now in the team's list. */
final class LeadVerified
{
    use Dispatchable;

    public function __construct(public readonly int $leadId, public readonly int $teamId) {}
}
