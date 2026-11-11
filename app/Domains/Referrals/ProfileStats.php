<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;

/** A team's track record for its profile: confirmed closes, value (only if the team shows it), ratings and public thanks. */
final class ProfileStats
{
    public const MIN_RATINGS = 3;

    /** @return array{closed: int, value: ?float, rating: ?float, ratings: int, thanks: array<int, array{from: string, note: string}>, giveGet: array{given: int, received: int, balance: int}, topGiverIn: array<int, string>, builder: bool} */
    public static function for(Team $team): array
    {
        $closed = Referral::query()->where('receiver_team_id', $team->id)->whereIn('close_status', ['confirmed', 'auto_confirmed']);
        $ratings = DB::table('referral_ratings')->where('rated_team_id', $team->id);
        $count = (clone $ratings)->count();

        return [
            'closed' => (clone $closed)->count(),
            'value' => DB::table('teams')->where('id', $team->id)->value('show_deal_value') ? (clone $closed)->sum('closing_value_cents') / 100 : null,
            // An average only once there are enough ratings to mean something.
            'rating' => $count >= self::MIN_RATINGS ? round((float) (clone $ratings)->avg('stars'), 1) : null,
            'ratings' => $count,
            'giveGet' => Credits::giveGet($team),
            // Network builder: three or more invitees who got verified and up and running.
            'builder' => DB::table('invite_signups')->where('inviter_team_id', $team->id)->whereIn('status', ['rewarded', 'badge', 'capped'])->count() >= 3,
            'topGiverIn' => \App\Domains\Network\Groups\Models\Group::query()->whereIn('id', DB::table('credit_ledger')->where('team_id', $team->id)->distinct()->pluck('group_id'))->get()
                ->filter(fn ($g) => in_array($team->id, Credits::topGivers($g), true))->map(fn ($g) => (string) $g->name)->values()->all(),
            'thanks' => Referral::query()->with('receiver:id,name')->where(['sender_team_id' => $team->id, 'thanks_public' => true])->whereNotNull('thanks_note')->latest('thanks_at')->limit(6)->get()
                ->map(fn (Referral $r) => ['from' => (string) $r->receiver?->name, 'note' => (string) $r->thanks_note])->values()->all(),
        ];
    }
}
