<?php

declare(strict_types=1);

namespace App\Domains\Network\Invites;

use App\Domains\Billing\BillingAccounts;
use App\Domains\Billing\PricingRules;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Tier;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Invite rewards. An inviter earns a free month (account credit at their seat price) once the invitee's licence is
 * verified and they've accepted their first referral, so fake sign-ups earn nothing; capped at 12 a year. In
 * restricted (Tier B) industries, where anything of value for referrals is prohibited, the inviter gets the
 * "Network builder" badge instead. Group owners earn a free month for every 5 new paid seats in their network.
 * Every amount is an admin setting.
 */
final class InviteRewards
{
    public function __construct(private readonly Notifier $notifier) {}

    public static function setting(string $key): int
    {
        $defaults = ['reward_months' => '1', 'cap' => '12', 'owner_per_seats' => '5'];

        return (int) app(Settings::class)->get("invites.{$key}", $defaults[$key]);
    }

    /** At sign-up: remember who invited this person (never their own team). */
    public static function attach(User $user, ?string $code): void
    {
        $inviter = $code ? Team::query()->where('invite_code', strtolower($code))->first() : null;
        if ($inviter !== null && $inviter->leader_id !== $user->id) {
            DB::table('invite_signups')->insertOrIgnore(['inviter_team_id' => $inviter->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** When the invitee's team qualifies (licence verified and a first referral accepted), reward the inviter once. */
    public function check(Team $invitee): void
    {
        $signup = DB::table('invite_signups')->where(['user_id' => $invitee->leader_id, 'status' => 'joined'])->first();
        if ($signup === null) {
            return;
        }
        $verified = DB::table('team_licenses')->where(['team_id' => $invitee->id, 'status' => 'verified'])->exists();
        $accepted = DB::table('referrals')->where('receiver_team_id', $invitee->id)->whereNotNull('accepted_at')->exists();
        if (! $verified || ! $accepted) {
            return;
        }
        $inviter = Team::query()->with('leader')->find((int) $signup->inviter_team_id);
        if ($inviter === null) {
            return;
        }
        $seats = Seat::query()->with('group.industry')->where('primary_team_id', $inviter->id)->get();
        $restricted = $seats->contains(fn (Seat $s) => $s->group->industry->tier === Tier::Restricted);
        $thisYear = DB::table('invite_signups')->where(['inviter_team_id' => $inviter->id, 'status' => 'rewarded'])->where('qualified_at', '>=', now()->startOfYear())->count();
        $seat = $seats->first();
        [$status, $cents] = match (true) {
            $restricted || $seat === null => ['badge', 0],
            $thisYear >= self::setting('cap') => ['capped', 0],
            default => ['rewarded', (int) round(100 * self::setting('reward_months') * (float) data_get($seat->group->pricing, $seat->founding ? 'founding_price' : 'seat_price', 10))],
        };
        DB::table('invite_signups')->where('id', $signup->id)->update(['status' => $status, 'reward_cents' => $cents, 'qualified_at' => now(), 'updated_at' => now()]);
        if ($status === 'rewarded' && $seat !== null && ($payer = BillingAccounts::payerFor($seat)) !== null) {
            PricingRules::addCredit($payer['type'], $payer['id'], $cents, "Free month: {$invitee->name} joined through your invite");
        }
        $body = match ($status) {
            'rewarded' => 'A free month has been added to your account. Thank you for growing the network!',
            'badge' => 'Thank you for growing the network! It counts towards your Network builder badge.',
            default => 'Thank you! You’ve reached this year’s limit for free-month rewards, so this one counts towards your badge.',
        };
        $this->notifier->send($inviter->leader, 'network.invite', ['headline' => "{$invitee->name} is up and running, thanks to your invite", 'body' => $body], url('/app/invites'));
    }

    /** Daily: owners earn a free month for every N seats in their network that became paid. */
    public function ownerRewards(): void
    {
        $per = max(1, self::setting('owner_per_seats'));
        $paid = DB::table('seat_events')->where('event', 'activated')->join('seats', 'seats.id', '=', 'seat_events.seat_id')
            ->whereNotIn('seat_events.seat_id', DB::table('owner_reward_seats')->select('seat_id'))->get(['seats.id', 'seats.group_id'])->unique('id');
        foreach ($paid->groupBy('group_id') as $groupId => $seats) {
            $group = Group::query()->with('owners')->find((int) $groupId);
            if ($group === null) {
                continue;
            }
            foreach ($seats as $s) {
                DB::table('owner_reward_seats')->insertOrIgnore(['group_id' => $groupId, 'seat_id' => $s->id, 'created_at' => now()]);
            }
            $total = DB::table('owner_reward_seats')->where('group_id', $groupId)->count();
            $earned = intdiv($total, $per);
            foreach ($group->owners as $owner) {
                $team = Team::forLeader($owner);
                for ($n = 1; $n <= $earned; $n++) {
                    // Keyed on the milestone, so each one is credited once.
                    PricingRules::addCredit('team', $team->id, (int) round(100 * (float) data_get($group->pricing, 'seat_price', 10)), "Free month: {$group->name} reached ".($n * $per).' new paid seats');
                }
            }
        }
    }
}
