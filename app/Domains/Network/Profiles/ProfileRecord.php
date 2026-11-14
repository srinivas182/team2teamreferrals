<?php

declare(strict_types=1);

namespace App\Domains\Network\Profiles;

use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Illuminate\Support\Facades\DB;

/**
 * The facts a public profile shows beyond the basics, each from real referral records: how quickly the team
 * responds, what it completed in the last 12 months, its ratings (with a breakdown and the reviews behind them),
 * and what was verified. Nothing here names a client or shows contact details.
 */
final class ProfileRecord
{
    /** A figure is shown only once there are enough cases to mean something. */
    public const MIN_CASES = 3;

    /** @return array{hours: ?int, count: int} median hours from a referral arriving to the team accepting it, last 12 months */
    public static function response(Team $team): array
    {
        $rows = DB::table('referrals')->where('receiver_team_id', $team->id)->whereNotNull('accepted_at')->where('created_at', '>=', now()->subYear())
            ->get(['created_at', 'accepted_at']);
        $hours = $rows->map(fn ($r): float => max(0, (strtotime((string) $r->accepted_at) - strtotime((string) $r->created_at)) / 3600))->sort()->values()->all();
        $n = count($hours);
        if ($n < self::MIN_CASES) {
            return ['hours' => null, 'count' => $n];
        }
        $median = $n % 2 === 1 ? $hours[intdiv($n, 2)] : ($hours[intdiv($n, 2) - 1] + $hours[intdiv($n, 2)]) / 2;

        return ['hours' => max(1, (int) round($median)), 'count' => $n];
    }

    /** @return array{received: int, closed: int} referrals received and confirmed closed in the last 12 months */
    public static function lastYear(Team $team): array
    {
        $since = now()->subYear();

        return [
            'received' => DB::table('referrals')->where('receiver_team_id', $team->id)->where('created_at', '>=', $since)->count(),
            'closed' => DB::table('referrals')->where('receiver_team_id', $team->id)->whereIn('close_status', ['confirmed', 'auto_confirmed'])->where('close_confirmed_at', '>=', $since)->count(),
        ];
    }

    /**
     * Ratings from verified referrals only: a 5-to-1 breakdown, counts from clients and from referring teams, and the
     * reviews (newest first). Comments have any email or phone masked; clients are never named.
     *
     * @return array{breakdown: array<int, int>, clients: int, peers: int, items: array<int, array{stars: int, by: string, from: ?string, comment: ?string, date: string}>}
     */
    public static function reviews(Team $team, int $limit = 50): array
    {
        $rows = DB::table('referral_ratings')->leftJoin('teams', 'teams.id', '=', 'referral_ratings.rater_team_id')->where('rated_team_id', $team->id)
            ->orderByDesc('referral_ratings.created_at')->get(['referral_ratings.stars', 'referral_ratings.rater', 'referral_ratings.comment', 'referral_ratings.created_at', 'teams.name as from_team', 'teams.profile_public']);
        $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($rows as $r) {
            $breakdown[max(1, min(5, (int) $r->stars))]++;
        }

        return [
            'breakdown' => $breakdown,
            'clients' => $rows->where('rater', 'client')->count(),
            'peers' => $rows->where('rater', '!=', 'client')->count(),
            'items' => $rows->take($limit)->map(fn ($r) => [
                'stars' => (int) $r->stars, 'by' => $r->rater === 'client' ? 'client' : 'team',
                // A referring team is named only if its own profile is public.
                'from' => $r->rater !== 'client' && $r->profile_public ? (string) $r->from_team : null,
                'comment' => $r->comment !== null && trim((string) $r->comment) !== '' ? str_replace(['[email shown after accepting]', '[phone shown after accepting]'], '[contact removed]', Referral::maskContacts((string) $r->comment)) : null,
                'date' => substr((string) $r->created_at, 0, 7),
            ])->values()->all(),
        ];
    }

    /** @return array<int, array{state: string, stateName: string, industry: string, number: string, verifiedOn: ?string, expiresOn: ?string}> licenses we checked */
    public static function credentials(Team $team): array
    {
        return DB::table('team_licenses')->join('states', 'states.id', '=', 'team_licenses.state_id')->join('industries', 'industries.id', '=', 'team_licenses.industry_id')
            ->where('team_licenses.team_id', $team->id)->where('team_licenses.status', 'verified')->orderBy('states.code')
            ->get(['states.code', 'states.name', 'industries.name as industry', 'team_licenses.number', 'team_licenses.reviewed_at', 'team_licenses.expires_on'])
            ->map(fn ($l) => ['state' => (string) $l->code, 'stateName' => (string) $l->name, 'industry' => (string) $l->industry, 'number' => (string) $l->number,
                'verifiedOn' => $l->reviewed_at ? substr((string) $l->reviewed_at, 0, 10) : null, 'expiresOn' => $l->expires_on ? (string) $l->expires_on : null])->values()->all();
    }
}
