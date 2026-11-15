<?php

declare(strict_types=1);

namespace App\Domains\Network\Prelaunch\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Someone confirmed a pre-launch seat reservation from the email link. */
final class ReservationConfirmed
{
    use Dispatchable;

    public function __construct(public readonly int $reservationId) {}
}
