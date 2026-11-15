<?php

declare(strict_types=1);

namespace App\Domains\Network\Onboarding;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Daily: a friendly reminder 2 and 7 days after sign-up to anyone who hasn't applied for a seat yet. Each is sent once. */
final class SendOnboardingNudges extends Command
{
    protected $signature = 't2t:onboarding:nudges';

    protected $description = 'Remind new members who have not applied for a seat 2 and 7 days after sign-up';

    public function handle(Notifier $notifier): int
    {
        foreach (['day2' => 2, 'day7' => 7] as $kind => $days) {
            $users = User::query()->where('created_at', '<=', now()->subDays($days))->where('created_at', '>', now()->subDays($days + 3))
                ->where('is_active', true)->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['staff', 'super_admin']))
                ->whereNotExists(fn ($q) => $q->from('onboarding_nudges')->whereColumn('onboarding_nudges.user_id', 'users.id')->where('kind', $kind))
                ->whereNotExists(fn ($q) => $q->from('seat_applications')->join('team_members', 'team_members.team_id', '=', 'seat_applications.team_id')->whereColumn('team_members.user_id', 'users.id'))
                // Not while the "finish joining" email sequence is looking after them (Sprint 46b): one set of reminders is enough.
                ->when(\App\Domains\Growth\GrowthMailer::sendingOn(), fn ($q) => $q->whereNotExists(fn ($w) => $w->from('campaign_enrollments as e')->join('prospects as p', 'p.id', '=', 'e.prospect_id')
                    ->join('campaigns as c', 'c.id', '=', 'e.campaign_id')->where(['c.kind' => 'abandoned', 'c.status' => 'active'])->whereIn('e.status', ['active', 'completed'])->whereColumn('p.user_id', 'users.id')))
                ->limit(500)->get();
            foreach ($users as $u) {
                $notifier->send($u, 'onboarding.nudge', [
                    'headline' => $days === 2 ? 'Your city could still be open' : 'Don’t miss your city',
                    'body' => $days === 2 ? 'You’re one step from claiming your territory: pick your group and city, and the first months are free.' : 'Teams are claiming seats every day. Apply for yours now; if it’s taken, you can still back it up or join the waitlist.',
                ], url('/app'));
                DB::table('onboarding_nudges')->insert(['user_id' => $u->id, 'kind' => $kind, 'sent_at' => now()]);
            }
        }

        return self::SUCCESS;
    }
}
