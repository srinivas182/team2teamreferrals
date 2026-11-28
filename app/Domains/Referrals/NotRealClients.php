<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * "Not a real client". Accepting a referral counts toward the receiver's closing rate, so receivers only accept leads
 * they can work. When an accepted client turns out not to be real (wrong number, never asked for help, a duplicate,
 * spam), the receiver marks it so, with what they tried: it then doesn't count against the receiver's scores, it
 * counts against the sender, and it earns no sharing reward. The sender can ask the group owner to review; the owner
 * reinstates it (it counts against the receiver after all) or upholds it. A sender with 3 in 90 days in a group earns
 * no reward there until that clears or the owner lifts it; repeat pauses escalate to the owners and super admins.
 */
final class NotRealClients
{
    public const REASONS = ['wrong_contact' => 'Wrong or dead phone and email', 'never_asked' => 'Never asked for help', 'duplicate' => 'Already a client or a duplicate', 'spam' => 'Fake or spam'];

    public const WITHIN_DAYS = 30;

    public function __construct(private readonly Notifier $notifier) {}

    public static function canMark(Referral $ref, User $user): bool
    {
        $team = Team::of($user);

        return $team !== null && $team->id === $ref->receiver_team_id && $ref->status === 'accepted' && $ref->invalid_at === null && $ref->closed_at === null
            && $ref->accepted_at !== null && $ref->accepted_at->gte(now()->subDays(self::WITHIN_DAYS)) && Visibility::canView($ref, $user);
    }

    public function mark(Referral $ref, User $user, string $reason, string $note): void
    {
        abort_unless(self::canMark($ref, $user), 403, 'You can only mark a client you accepted in the last 30 days, before it’s closed.');
        $ref->forceFill(['invalid_at' => now(), 'invalid_reason' => $reason, 'invalid_note' => $note, 'invalid_by' => $user->id, 'stage' => 'lost', 'closed_at' => now()])->save();
        $this->event($ref, 'marked_not_real', (int) $ref->receiver_team_id, $user, ['reason' => $reason]);
        $ref->loadMissing(['sender.leader', 'receiver', 'group.industry']);
        $person = User::query()->find((int) $ref->sender_user_id);
        $receiverName = $ref->receiver !== null ? $ref->receiver->name : 'The receiving team';
        foreach (collect([$person, $ref->sender->leader])->filter()->unique('id') as $person) {
            $this->notifier->send($person, 'referral.message', ['headline' => "{$receiverName} couldn’t confirm {$ref->shortClientName()} as a client",
                'body' => 'Reason: '.self::REASONS[$reason].'. Their note: '.$note.' This referral won’t count toward your sharing reward. If the client is real, you can ask the group owner to review it.'],
                url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        }
        $this->checkQuality($ref);
    }

    /**
     * After a referral's not-real count changes: a friendly heads-up at 2, and at 3 the reward pauses in this group,
     * with the end date, and the owners are told. A second pause within a year asks the owners to review the team;
     * a third also tells the super admins. Nothing here affects the team's seat or the referrals it receives.
     */
    public function checkQuality(Referral $ref): void
    {
        $ref->loadMissing(['sender.leader', 'group.owners', 'group.industry']);
        $teamId = (int) $ref->sender_team_id;
        $group = $ref->group;
        $count = SharingReward::invalidSent($teamId, $group->id);
        $leader = $ref->sender->leader;
        $limit = SharingReward::INVALID_LIMIT;
        if ($count === $limit - 1) {
            $this->notifier->send($leader, 'network.score', ['headline' => 'A quick check before you send your next referral',
                'body' => "Two clients you sent in {$group->name} recently couldn’t be reached or weren’t looking for help. Please check the client’s details and that they asked for help before you send. One more in the next ".SharingReward::INVALID_WINDOW_DAYS.' days pauses your sharing reward in this group.'],
                url('/app/performance'));

            return;
        }
        if ($count !== $limit) {
            return;
        }
        $open = DB::table('sharing_reward_pauses')->where(['team_id' => $teamId, 'group_id' => $group->id])->where(fn ($q) => $q->whereNull('resolution')->orWhere('resolution', 'kept'))
            ->where('paused_at', '>=', now()->subDays(SharingReward::INVALID_WINDOW_DAYS))->exists();
        if ($open) {
            return; // the same pause, counted again after a review
        }
        DB::table('sharing_reward_pauses')->insert(['team_id' => $teamId, 'group_id' => $group->id, 'paused_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $until = (string) SharingReward::pausedUntil($teamId, $group->id)?->toFormattedDateString();
        $this->notifier->send($leader, 'network.score', ['headline' => "Your sharing reward in {$group->name} is paused until {$until}",
            'body' => "{$limit} referrals your team sent in the last ".SharingReward::INVALID_WINDOW_DAYS.' days were marked not a real client, so free months from sending are paused in this group until '.$until.'. Your seat and the referrals you receive are not affected. If a call was wrong, you can ask the group owner to review it from the referral.'],
            url('/app/performance'));
        $pauses = SharingReward::pausesThisYear($teamId, $group->id);
        $name = $ref->sender->name;
        [$headline, $body] = $pauses >= 2
            ? ["Please review {$name} in {$group->name}", "This is {$name}’s {$this->ordinal($pauses)} sharing reward pause in 12 months: {$limit} of their referrals in ".SharingReward::INVALID_WINDOW_DAYS.' days were marked not a real client. On the group’s Referrals page you can lift the pause, keep it, or remove the team from the group.']
            : ["{$name}’s sharing reward is paused in {$group->name}", "{$limit} of their referrals in ".SharingReward::INVALID_WINDOW_DAYS." days were marked not a real client, so their reward is paused until {$until}. No action is needed. You can lift the pause from the group’s Referrals page if you think it’s unfair."];
        $people = $group->owners;
        if ($pauses >= 3) {
            $people = $people->merge(User::role('super_admin')->get())->unique('id');
        }
        foreach ($people as $person) {
            $this->notifier->send($person, 'network.score', ['headline' => $headline, 'body' => $body], url("/app/group/{$group->slug}/referrals"));
        }
    }

    /** The group owner (or a super admin) lifts a pause: rewards resume now and the count starts again. */
    public function lift(Group $group, Team $team, User $by, string $reason): void
    {
        abort_unless(SharingReward::paused($team->id, $group->id), 422, 'This team’s sharing reward isn’t paused.');
        $row = DB::table('sharing_reward_pauses')->where(['team_id' => $team->id, 'group_id' => $group->id])->latest('paused_at')->first();
        $values = ['resolution' => 'lifted', 'resolved_at' => now(), 'resolved_by' => $by->id, 'reason' => $reason, 'updated_at' => now()];
        $row === null ? DB::table('sharing_reward_pauses')->insert([...$values, 'team_id' => $team->id, 'group_id' => $group->id, 'paused_at' => now(), 'created_at' => now()])
            : DB::table('sharing_reward_pauses')->where('id', $row->id)->update($values);
        activity('groups')->causedBy($by)->performedOn($group)->event('sharing-pause-lifted')->withProperties(['team' => $team->id, 'reason' => $reason])->log("Lifted {$team->name}’s sharing reward pause");
        $team->loadMissing('leader');
        $this->notifier->send($team->leader, 'network.score', ['headline' => "Your sharing reward in {$group->name} is back on",
            'body' => 'The group owner lifted the pause. Send a referral that the other team accepts and contacts this month, and next month is free.'], url('/app'));
    }

    /** The owner looked at a pause and keeps it: it ends by itself on its date. */
    public function keep(Group $group, Team $team, User $by): void
    {
        abort_unless(SharingReward::paused($team->id, $group->id), 422, 'This team’s sharing reward isn’t paused.');
        DB::table('sharing_reward_pauses')->where(['team_id' => $team->id, 'group_id' => $group->id])->whereNull('resolution')
            ->update(['resolution' => 'kept', 'resolved_at' => now(), 'resolved_by' => $by->id, 'updated_at' => now()]);
        activity('groups')->causedBy($by)->performedOn($group)->event('sharing-pause-kept')->withProperties(['team' => $team->id])->log("Kept {$team->name}’s sharing reward pause");
    }

    /** The owner removes the team from the group: each seat it holds there is released and offered to the next team. */
    public function remove(Group $group, Team $team, User $by, string $reason): int
    {
        $seats = \App\Domains\Network\Seats\Models\Seat::query()->where(['group_id' => $group->id, 'primary_team_id' => $team->id])->get();
        foreach ($seats as $seat) {
            app(\App\Domains\Network\Seats\Seats::class)->release($seat, 'removed by the group owner: '.$reason, $by);
        }
        activity('groups')->causedBy($by)->performedOn($group)->event('team-removed')->withProperties(['team' => $team->id, 'reason' => $reason, 'seats' => $seats->count()])->log("Removed {$team->name} from {$group->name}");

        return $seats->count();
    }

    private function ordinal(int $n): string
    {
        return match ($n) {
            1 => 'first', 2 => 'second', 3 => 'third', default => "{$n}th"
        };
    }

    /** The sender asks the group owner to review. Until decided, it doesn't count against them. */
    public function dispute(Referral $ref, User $user, string $why): void
    {
        $team = Team::of($user);
        abort_unless($team !== null && $team->id === $ref->sender_team_id && $ref->invalid_at !== null && $ref->invalid_disputed_at === null && Visibility::canView($ref, $user), 403);
        // One review per call: once the owner upholds it, it stands.
        abort_if(self::decided($ref), 422, 'The group owner already reviewed this call.');
        $ref->forceFill(['invalid_disputed_at' => now()])->save();
        $this->event($ref, 'not_real_disputed', $team->id, $user, ['why' => $why]);
        $ref->loadMissing(['group.owners', 'sender', 'group.industry']);
        foreach ($ref->group->owners as $owner) {
            $this->notifier->send($owner, 'network.score', ['headline' => "{$ref->sender->name} asks you to review a “not a real client” call",
                'body' => "On referral {$ref->ulid}: {$why}"], url("/app/group/{$ref->group->slug}/referrals"));
        }
    }

    /** The group owner (or staff) decides: reinstate (it was a real client) or uphold. */
    public function decide(Referral $ref, Group $group, User $by, bool $reinstate): void
    {
        abort_unless($ref->group_id === $group->id && $ref->invalid_at !== null && $ref->invalid_disputed_at !== null, 422, 'There’s nothing to review on this referral.');
        if ($reinstate) {
            $ref->forceFill(['invalid_at' => null, 'invalid_reason' => null, 'invalid_note' => null, 'invalid_by' => null, 'invalid_disputed_at' => null])->save();
        } else {
            $ref->forceFill(['invalid_disputed_at' => null])->save(); // upheld: counts against the sender again
        }
        $this->event($ref, $reinstate ? 'not_real_reinstated' : 'not_real_upheld', null, $by, []);
        if (! $reinstate) {
            $this->checkQuality($ref);
        }
    }

    /** Whether the group owner already upheld a "not a real client" call on this referral. */
    public static function decided(Referral $ref): bool
    {
        return DB::table('referral_events')->where('referral_id', $ref->id)->where('event', 'not_real_upheld')->exists();
    }

    /**
     * Disputed calls waiting for a decision in a group.
     *
     * @return list<array{id: string, date: string, sender: string, receiver: string, reason: string, note: string}>
     */
    public static function pending(Group $group): array
    {
        return array_values(DB::table('referrals as r')->join('teams as s', 's.id', '=', 'r.sender_team_id')->leftJoin('teams as t', 't.id', '=', 'r.receiver_team_id')
            ->where('r.group_id', $group->id)->whereNotNull('r.invalid_at')->whereNotNull('r.invalid_disputed_at')->orderBy('r.invalid_disputed_at')
            ->get(['r.ulid', 'r.invalid_at', 'r.invalid_reason', 'r.invalid_note', 's.name as sender', 't.name as receiver'])
            ->map(fn ($r) => ['id' => (string) $r->ulid, 'date' => substr((string) $r->invalid_at, 0, 10), 'sender' => (string) $r->sender, 'receiver' => (string) $r->receiver,
                'reason' => self::REASONS[$r->invalid_reason] ?? (string) $r->invalid_reason, 'note' => (string) $r->invalid_note])->all());
    }

    /** @param array<string, mixed> $data */
    private function event(Referral $ref, string $event, ?int $teamId, ?User $user, array $data): void
    {
        DB::table('referral_events')->insert(['referral_id' => $ref->id, 'team_id' => $teamId, 'actor_id' => $user?->id, 'event' => $event, 'meta' => json_encode($data), 'created_at' => now()]);
    }
}
