<?php

declare(strict_types=1);

namespace App\Domains\Network\Seats\Events;

use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Foundation\Events\Dispatchable;

/** A seat's history gained an entry (applied, claimed, backup, waitlisted, released, open…). */
final class SeatEventRecorded
{
    use Dispatchable;

    public function __construct(public readonly Seat $seat, public readonly ?Team $team, public readonly string $event) {}
}
