<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How a team shares the referrals it is offered. One mode at a time, set by the leader:
 * - everyone: every member is told; the first to accept gets it. If nobody has in the members' time, the leader hears.
 * - turns: one member at a time, in order (round robin). Each has the members' time to accept, then it moves on.
 *   After everyone has had a turn it is open to the whole team and the leader hears.
 * - leader: only the leader (and assistants) are told; they accept or assign.
 */
final class LeadSharing
{
    public const MODES = ['everyone', 'turns', 'leader'];

    public static function mode(Team $team): string
    {
        $m = (string) DB::table('teams')->where('id', $team->id)->value('lead_sharing');

        return in_array($m, self::MODES, true) ? $m : 'everyone';
    }

    /** Minutes members have to accept: the team's setting, or a quarter of the network's response time (at least 15). */
    public static function memberMinutes(Team $team, Group $group): int
    {
        $set = DB::table('teams')->where('id', $team->id)->value('member_accept_minutes');
        $network = (int) $group->rule('accept_hours') * 60;

        return min($network, max(15, $set !== null ? (int) $set : intdiv($network, 4)));
    }

    /**
     * Leader and members in a stable order (assistants run the office and aren't in the rotation).
     *
     * @return Collection<int, User>
     */
    public static function rotation(Team $team): Collection
    {
        $ids = DB::table('team_members')->where('team_id', $team->id)->where('role', '!=', 'assistant')->orderBy('id')->pluck('user_id')->map(fn ($id) => (int) $id);
        if (! $ids->contains($team->leader_id)) {
            $ids->prepend((int) $team->leader_id);
        }
        $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn (int $id) => $users->get($id))->filter()->values();
    }

    /**
     * The next person after $after in the rotation, skipping anyone in $skip.
     *
     * @param  list<int>  $skip
     */
    public static function next(Team $team, ?int $after, array $skip = []): ?User
    {
        $people = self::rotation($team);
        if ($people->isEmpty()) {
            return null;
        }
        $start = $after === null ? -1 : (int) $people->search(fn (User $u) => $u->id === $after);
        for ($i = 1; $i <= $people->count(); $i++) {
            $u = $people->get(($start + $i) % $people->count());
            if ($u !== null && ! in_array($u->id, $skip, true)) {
                return $u;
            }
        }

        return null;
    }

    /** Who may accept this waiting referral: leaders and assistants always; members as the team's mode allows. */
    public static function mayAccept(Referral $ref, Team $team, User $user): bool
    {
        if (Visibility::seesAll($team, $user)) {
            return true;
        }

        return match (self::mode($team)) {
            'leader' => false,
            'turns' => $ref->offered_user_id === null || $ref->offered_user_id === $user->id,
            default => true,
        };
    }

    /**
     * The leader and assistants: told about every new referral when the leader assigns.
     *
     * @return Collection<int, User>
     */
    public static function leadersAndAssistants(Team $team): Collection
    {
        $ids = DB::table('team_members')->where('team_id', $team->id)->whereIn('role', ['leader', 'assistant'])->pluck('user_id')->push($team->leader_id)->unique();

        return User::query()->whereIn('id', $ids)->get();
    }
}
