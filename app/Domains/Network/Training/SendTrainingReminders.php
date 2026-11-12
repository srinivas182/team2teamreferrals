<?php

declare(strict_types=1);

namespace App\Domains\Network\Training;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily: a gentle reminder 3 and 7 days after a team member's first application if their training isn't finished.
 * Each is sent once; non-urgent, so it waits for the end of quiet hours.
 */
final class SendTrainingReminders extends Command
{
    protected $signature = 't2t:training:reminders';

    protected $description = 'Remind members 3 and 7 days after applying if their network training is unfinished';

    public function handle(Training $training, Notifier $notifier): int
    {
        if (! app(\App\Domains\Platform\Modules\Modules::class)->enabled('training')) {
            return self::SUCCESS; // Network training switched off
        }
        foreach (['train3' => 3, 'train7' => 7] as $kind => $days) {
            $rows = DB::table('seat_applications')->join('team_members', 'team_members.team_id', '=', 'seat_applications.team_id')
                ->selectRaw('team_members.user_id, min(seat_applications.created_at) as first_at, min(seat_applications.group_id) as group_id')
                ->groupBy('team_members.user_id')->havingRaw('min(seat_applications.created_at) <= ?', [now()->subDays($days)])
                ->havingRaw('min(seat_applications.created_at) > ?', [now()->subDays($days + 3)])->get();
            foreach ($rows as $r) {
                if (DB::table('onboarding_nudges')->where(['user_id' => $r->user_id, 'kind' => $kind])->exists()) {
                    continue;
                }
                $user = User::query()->find((int) $r->user_id);
                $group = Group::query()->find((int) $r->group_id);
                if (! $user instanceof User || ! $group instanceof Group || $training->passed($user, $group->industry)) {
                    continue;
                }
                $notifier->send($user, 'training.reminder', ['headline' => 'Finish your network training', 'body' => 'It takes about 15 minutes in short lessons, and you can stop and resume any time. Once you pass, referrals can flow to you.'], url('/app/training/'.$group->industry->slug), $group->industry);
                DB::table('onboarding_nudges')->insert(['user_id' => $user->id, 'kind' => $kind, 'sent_at' => now()]);
            }
        }

        return self::SUCCESS;
    }
}
