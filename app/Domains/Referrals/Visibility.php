<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Who in a team sees which referrals. Leaders and assistants see all of the team's referrals. Members see the ones
 * they sent, the ones they accepted, and offers still waiting for the team (so they can accept them); never a
 * teammate's clients. Every list, page, search, earnings view and API call goes through here.
 */
final class Visibility
{
    public static function seesAll(Team $team, User $user): bool
    {
        $role = $team->roleOf($user);

        return $role === null ? $team->leader_id === $user->id : in_array($role, ['leader', 'assistant'], true);
    }

    /**
     * Narrows a referrals query to what this person may see. $side: 'sent', 'received' or null for both;
     * $p is the referrals table alias prefix ("r.") when the query joins other tables.
     */
    public static function apply(Builder $q, Team $team, User $user, ?string $side = null, string $p = ''): Builder
    {
        $all = self::seesAll($team, $user);
        $sent = fn ($w) => $w->where("{$p}sender_team_id", $team->id)->when(! $all, fn ($w) => $w->where("{$p}sender_user_id", $user->id));
        $received = fn ($w) => $w->where("{$p}receiver_team_id", $team->id)
            ->when(! $all, fn ($w) => $w->where(fn ($m) => $m->where("{$p}receiver_user_id", $user->id)->orWhere("{$p}status", 'sent')));

        return match ($side) {
            'sent' => $q->where($sent),
            'received' => $q->where($received),
            default => $q->where(fn ($w) => $w->where($sent)->orWhere($received)),
        };
    }

    public static function canView(Referral $ref, User $user): bool
    {
        $team = Team::of($user);
        if ($team === null || ! in_array($team->id, [$ref->sender_team_id, $ref->receiver_team_id], true)) {
            return false;
        }
        if (self::seesAll($team, $user)) {
            return true;
        }

        return ($team->id === $ref->sender_team_id && $ref->sender_user_id === $user->id)
            || ($team->id === $ref->receiver_team_id && ($ref->receiver_user_id === $user->id || $ref->status === 'sent'));
    }
}
