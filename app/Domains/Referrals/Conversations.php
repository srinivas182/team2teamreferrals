<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Events\ReferralChanged;
use Illuminate\Support\Facades\DB;

/** Reading, unread counts, muting and quick replies for referral conversations. */
final class Conversations
{
    public const DEFAULT_QUICK_REPLIES = ['Called them, will update you soon.', 'Can you share their best time to talk?', 'Thanks for the referral!', 'Appointment booked; I’ll keep you posted.'];

    /** Private channels: a person may listen to their own team, and to referrals their team is on. */
    public static function canListenToTeam(User $user, string $teamUlid): bool
    {
        return Team::of($user)?->ulid === $teamUlid;
    }

    public static function canListenToReferral(User $user, string $referralUlid): bool
    {
        $team = Team::of($user);
        $ref = Referral::query()->where('ulid', $referralUlid)->first(['id', 'sender_team_id', 'receiver_team_id']);

        return $team !== null && $ref !== null && in_array($team->id, [$ref->sender_team_id, $ref->receiver_team_id], true);
    }

    /** Record that a team has read up to the latest message; tells the other side ("Seen") when it moves on. */
    public static function markRead(Referral $ref, Team $team): void
    {
        $latest = (int) DB::table('referral_messages')->where('referral_id', $ref->id)->where('team_id', '!=', $team->id)->max('id');
        $current = (int) DB::table('referral_reads')->where(['referral_id' => $ref->id, 'team_id' => $team->id])->value('last_message_id');
        if ($latest > $current || ! DB::table('referral_reads')->where(['referral_id' => $ref->id, 'team_id' => $team->id])->exists()) {
            DB::table('referral_reads')->updateOrInsert(['referral_id' => $ref->id, 'team_id' => $team->id], ['last_message_id' => max($latest, $current), 'read_at' => now()]);
            if ($latest > $current) {
                ReferralChanged::dispatch($ref, 'seen');
            }
        }
    }

    /** The other team's read position, for "Seen" on my messages. */
    public static function otherRead(Referral $ref, Team $team): int
    {
        $other = $team->id === $ref->sender_team_id ? $ref->receiver_team_id : $ref->sender_team_id;

        return (int) DB::table('referral_reads')->where(['referral_id' => $ref->id, 'team_id' => $other])->value('last_message_id');
    }

    /** @return array{inbox: int, sent: int} unread messages from the other team, by direction */
    public static function unread(Team $team): array
    {
        $count = fn (string $col) => (int) DB::table('referral_messages as m')->join('referrals as r', 'r.id', '=', 'm.referral_id')
            ->leftJoin('referral_reads as rr', fn ($j) => $j->on('rr.referral_id', '=', 'r.id')->where('rr.team_id', '=', $team->id))
            ->where("r.{$col}", $team->id)->where('m.team_id', '!=', $team->id)
            ->whereRaw('m.id > coalesce(rr.last_message_id, 0)')->count();

        return ['inbox' => $count('receiver_team_id'), 'sent' => $count('sender_team_id')];
    }

    public static function muted(Referral $ref, User $user): bool
    {
        return DB::table('referral_mutes')->where(['referral_id' => $ref->id, 'user_id' => $user->id])->exists();
    }

    public static function toggleMute(Referral $ref, User $user): bool
    {
        if (self::muted($ref, $user)) {
            DB::table('referral_mutes')->where(['referral_id' => $ref->id, 'user_id' => $user->id])->delete();

            return false;
        }
        DB::table('referral_mutes')->insert(['referral_id' => $ref->id, 'user_id' => $user->id, 'created_at' => now()]);

        return true;
    }

    /** @return list<string> */
    public static function quickReplies(Team $team): array
    {
        $saved = json_decode((string) DB::table('teams')->where('id', $team->id)->value('quick_replies'), true);

        return is_array($saved) && $saved !== [] ? array_values(array_map('strval', $saved)) : self::DEFAULT_QUICK_REPLIES;
    }

    /** @return array<int, array{referral: string, client: string, territory: string, who: string, excerpt: string, when: string}> */
    public static function search(Team $team, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $rows = DB::table('referral_messages as m')->join('referrals as r', 'r.id', '=', 'm.referral_id')->join('teams as t', 't.id', '=', 'm.team_id')
            ->join('seats as s', 's.id', '=', 'r.seat_id')
            ->where(fn ($w) => $w->where('r.sender_team_id', $team->id)->orWhere('r.receiver_team_id', $team->id))
            ->where('m.body', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%')->orderByDesc('m.id')->limit(50)
            ->get(['r.id as rid', 'r.ulid', 'r.client_name', 'r.status', 'r.receiver_team_id', 's.territory_name', 't.name as who', 'm.body', 'm.created_at']);

        return $rows->map(function ($m) use ($team) {
            // The receiver sees the same privacy rules in search as on the referral before accepting.
            $early = (int) $m->receiver_team_id === $team->id && $m->status !== 'accepted';
            $body = $early ? Referral::maskContacts((string) $m->body) : (string) $m->body;
            $ref = new Referral(['client_name' => (string) $m->client_name]);

            return ['referral' => (string) $m->ulid, 'client' => $early ? $ref->shortClientName() : (string) $m->client_name, 'territory' => (string) $m->territory_name,
                'who' => (string) $m->who, 'excerpt' => mb_strimwidth($body, 0, 160, '…'), 'when' => (string) $m->created_at];
        })->values()->all();
    }
}
