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
 * reinstates it (it counts against the receiver after all) or upholds it. A sender with 3 in 90 days earns no reward
 * until that clears, and the group owners are told so they can decide whether the team stays in the group.
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
            $this->notifier->send($person, 'referral.message', ['headline' => "{$receiverName} says {$ref->shortClientName()} isn’t a real client",
                'body' => self::REASONS[$reason].': '.$note.' This doesn’t earn a sharing reward. If it’s wrong, ask the group owner to review it.'], url('/app/referrals/'.$ref->ulid), $ref->group->industry, $ref->group_id);
        }
        // Repeatedly sending clients who aren't real: rewards stop, and the group owners decide whether the team stays.
        $count = SharingReward::invalidSent((int) $ref->sender_team_id);
        if ($count === SharingReward::INVALID_LIMIT) {
            $this->notifier->send($ref->sender->leader, 'network.score', ['headline' => 'Your sharing rewards are paused',
                'body' => SharingReward::INVALID_LIMIT.' referrals you sent in the last '.SharingReward::INVALID_WINDOW_DAYS.' days were marked not real clients. Only send clients who asked for help.'], url('/app/performance'));
            foreach ($ref->group->owners as $owner) {
                $this->notifier->send($owner, 'network.score', ['headline' => "{$ref->sender->name} keeps sending clients who aren’t real",
                    'body' => SharingReward::INVALID_LIMIT.' of their referrals in '.SharingReward::INVALID_WINDOW_DAYS.' days were marked not real. Review whether they should stay in '.$ref->group->name.'.'], url("/app/group/{$ref->group->slug}/referrals"));
            }
        }
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
