<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Daily: tell the group 30 days before a scheduled pricing or rule change, then apply it on the day. */
final class ApplyScheduledChanges extends Command
{
    protected $signature = 't2t:groups:scheduled';

    protected $description = 'Send 30-day notices and apply scheduled group pricing and rule changes';

    public function handle(Notifier $notifier): int
    {
        $pending = DB::table('group_scheduled_changes')->whereNull('applied_at')->whereNull('cancelled_at')->get();
        foreach ($pending as $c) {
            $group = Group::query()->find($c->group_id);
            if (! $group instanceof Group) {
                continue;
            }
            $effective = \Carbon\CarbonImmutable::parse((string) $c->effective_on);
            if ($c->notified_at === null && $effective->subDays(30)->lessThanOrEqualTo(now())) {
                // Owners and every seat holder in the group are told 30 days ahead.
                $holders = User::query()->whereIn('id', DB::table('seats')->join('teams', 'teams.id', '=', 'seats.primary_team_id')->where('seats.group_id', $group->id)->pluck('teams.leader_id'))->get();
                foreach ($group->owners()->get()->merge($holders)->unique('id') as $owner) {
                    /** @var User $owner */
                    $notifier->send($owner, 'network.announcement', ['body' => "{$group->name}: ".($c->section === 'pricing' ? 'pricing' : 'network rules').' change on '.$effective->toFormattedDateString().'. See the details in your group overview.'], url('/app/group/'.$group->slug), $group->industry);
                }
                DB::table('group_scheduled_changes')->where('id', $c->id)->update(['notified_at' => now()]);
            }
            if ($effective->lessThanOrEqualTo(now()->startOfDay())) {
                $group->forceFill([(string) $c->section => json_decode((string) $c->values, true)])->save();
                DB::table('group_scheduled_changes')->where('id', $c->id)->update(['applied_at' => now()]);
                activity('admin')->performedOn($group)->event('group-change-applied')->withProperties(['section' => $c->section, 'effective_on' => $c->effective_on])->log("Scheduled {$c->section} change applied to {$group->name}");
            }
        }

        return self::SUCCESS;
    }
}
