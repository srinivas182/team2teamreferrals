<?php

declare(strict_types=1);

namespace App\Domains\Community;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Seats\Models\Team;
use Illuminate\Support\Facades\DB;

/** Feed activity bundled into one digest, daily or weekly (each member's choice), instead of a ping per post. */
final class FeedDigest
{
    public function __construct(private readonly Notifier $notifier) {}

    public function run(): void
    {
        $due = User::query()->where(fn ($q) => $q->where('feed_digest', 'daily')->orWhere(fn ($w) => $w->where('feed_digest', 'weekly')->when(! now()->isMonday(), fn ($x) => $x->whereRaw('1 = 0'))))->get();
        foreach ($due as $user) {
            $team = Team::of($user);
            $groupIds = DB::table('seats')->where('primary_team_id', $team !== null ? $team->id : 0)->pluck('group_id')
                ->merge(DB::table('group_owners')->where('user_id', $user->id)->pluck('group_id'))->unique();
            $since = $user->feed_digest_at ?? now()->subDays($user->feed_digest === 'daily' ? 1 : 7);
            $posts = DB::table('feed_posts as p')->join('groups as g', 'g.id', '=', 'p.group_id')->whereIn('p.group_id', $groupIds)->where('p.created_at', '>', $since)
                ->whereNull('p.removed_at')->whereNull('p.hidden_at')->where('p.user_id', '!=', $user->id)->orderByDesc('p.id')->get(['p.kind', 'p.body', 'g.name', 'g.slug']);
            $user->forceFill(['feed_digest_at' => now()])->save();
            if ($posts->isEmpty()) {
                continue; // nothing new, nothing sent
            }
            $asks = $posts->where('kind', 'ask')->count();
            $lines = $posts->take(3)->map(fn ($p) => '“'.mb_strimwidth((string) $p->body, 0, 90, '…').'”')->implode(' ');
            $this->notifier->send($user, 'feed.digest', ['headline' => $posts->count().' new post'.($posts->count() === 1 ? '' : 's').' in your network'.($asks ? " ({$asks} asking for a pro)" : ''), 'body' => $lines], url('/app/feed/'.$posts->first()->slug));
        }
    }
}
