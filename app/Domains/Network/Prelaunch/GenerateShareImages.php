<?php

declare(strict_types=1);

namespace App\Domains\Network\Prelaunch;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** After a reservation is confirmed: render its share cards once, then send the thank-you email with the card attached. */
final class GenerateShareImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $shareId) {}

    public function handle(Reservations $reservations): void
    {
        $s = $reservations->shared($this->shareId);
        if ($s === null) {
            return;
        }
        $made = ShareImage::generate($this->shareId, $s['business'], $s['territory'], $s['group']);
        $email = (string) DB::table('reservations')->where('share_id', $this->shareId)->value('email');
        Notification::route('mail', $email)->notify(new ReservationThanksNotification(
            $s['territory'], $s['group'], url('/reserve/c/'.$this->shareId),
            $made ? Storage::disk('public')->path(ShareImage::path($this->shareId, 'og')) : null,
        ));
    }
}
