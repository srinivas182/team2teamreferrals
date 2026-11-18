<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Consumer\ConsumerRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One inbox (the simplification sprint): besides referrals sent to the team, the inbox shows everything else that has
 * come in and is waiting for someone: new leads from the team's public profile, drafts made from forwarded emails, voice
 * notes and photos, and requests from the public that the team can take from the open board.
 */
final class InboxSources
{
    /** Kinds, in the order the inbox's filters show them. */
    public const KINDS = ['lead' => 'Profile leads', 'draft' => 'Drafts', 'request' => 'Open requests'];

    public function __construct(private readonly ConsumerRequests $requests) {}

    /**
     * Everything waiting besides referrals, newest first.
     *
     * @return list<array{kind: string, id: string, title: string, detail: string, at: string, href: string}>
     */
    public function items(Team $team, User $user): array
    {
        $leads = DB::table('profile_leads')->where(['team_id' => $team->id, 'status' => 'new'])->orderByDesc('id')->limit(50)
            ->get(['id', 'client_name', 'need', 'source', 'verified_at'])
            ->map(fn ($l) => ['kind' => 'lead', 'id' => 'lead-'.$l->id, 'title' => (string) $l->client_name,
                'detail' => trim(mb_substr((string) $l->need, 0, 120).' · from your '.(($l->source ?? 'profile') === 'profile' ? 'public profile' : $l->source)), 'at' => (string) $l->verified_at, 'href' => '/app/profile-leads']);
        $drafts = DB::table('referral_drafts as d')->leftJoin('intake_messages as m', 'm.id', '=', 'd.intake_id')->where(['d.user_id' => $user->id, 'd.status' => 'pending'])
            ->orderByDesc('d.id')->limit(50)->get(['d.ulid', 'd.client_name', 'd.created_at', 'm.channel', 'm.subject'])
            ->map(fn ($d) => ['kind' => 'draft', 'id' => 'draft-'.$d->ulid, 'title' => (string) ($d->client_name ?: ($d->subject ?: 'A referral to finish')),
                'detail' => 'Draft from '.match ((string) ($d->channel ?? 'email')) {
                    'voice' => 'a voice note', 'photo' => 'a photo', default => 'a forwarded email'
                }.': check it and send', 'at' => (string) $d->created_at, 'href' => '/app/drafts']);
        $board = collect($this->requests->boardFor($team))->take(50)
            ->map(fn ($r) => ['kind' => 'request', 'id' => 'request-'.$r['id'], 'title' => $r['client'].' in '.$r['city'],
                'detail' => $r['group'].' · '.$r['timing'].' · anyone in your network can take it', 'at' => (string) $r['since'], 'href' => '/app/requests']);

        return array_values($leads->concat($drafts)->concat($board)->sortByDesc('at')->all());
    }

    /**
     * How many are waiting on this member specifically (new profile leads and their own drafts), for the inbox badge.
     * Open requests aren't counted: they're opportunities for the whole network, not addressed to anyone. One query,
     * remembered for a minute.
     */
    public static function waiting(Team $team, User $user): int
    {
        return (int) Cache::remember("inbox-waiting:{$team->id}:{$user->id}", 60, fn () => (int) DB::selectOne(
            "select (select count(*) from profile_leads where team_id = ? and status = 'new') + (select count(*) from referral_drafts where user_id = ? and status = 'pending') as n",
            [$team->id, $user->id],
        )?->n);
    }

    /** Forget the badge count after something changes (a lead handled, a draft sent or discarded). */
    public static function forget(Team $team, User $user): void
    {
        Cache::forget("inbox-waiting:{$team->id}:{$user->id}");
    }
}
