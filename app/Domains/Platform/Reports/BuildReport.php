<?php

declare(strict_types=1);

namespace App\Domains\Platform\Reports;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Referrals\Dashboard\Period;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** A large report requested from the screen: built in the background and sent as a private download link. */
final class BuildReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $reportId, public readonly int $userId) {}

    public function handle(): void
    {
        $r = DB::table('saved_reports')->where('id', $this->reportId)->first();
        $user = User::query()->find($this->userId);
        $group = $r?->group_id ? Group::query()->find((int) $r->group_id) : null;
        if ($r === null || $user === null || ! Datasets::allowed($user, (string) $r->dataset, $group)) {
            return;
        }
        $p = Period::from((string) $r->preset);
        $result = Datasets::runSaved($r, $group, $p);
        Mail::to($user->email)->send(new ReportMail((string) $r->name, $p->toArray()['label'], $result, null, Schedules::store(Datasets::csv($result), $user, (string) $r->ulid)));
    }
}
