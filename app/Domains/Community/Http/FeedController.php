<?php

declare(strict_types=1);

namespace App\Domains\Community\Http;

use App\Domains\Community\Feed;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use App\Domains\Seo\SeoData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class FeedController
{
    public const PAGE = 20;

    public function __construct(private readonly Feed $feed) {}

    public function index(Request $request, ?Group $group = null): Response|RedirectResponse
    {
        $user = $this->user($request);
        $groups = $this->groups($user);
        if ($group === null) {
            return $groups->isEmpty() ? Inertia::render('app/Feed', ['groups' => [], 'group' => null, 'posts' => [], 'seo' => SeoData::private('Feed')->toArray()])
                : redirect('/app/feed/'.$groups->first()->slug);
        }
        abort_unless(Feed::member($user, $group), 403);

        return Inertia::render('app/Feed', [
            'groups' => $groups->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name])->values(),
            'group' => ['slug' => $group->slug, 'name' => $group->name, 'isOwner' => $group->isOwnedBy($user) || $user->isStaff()],
            'posts' => $this->page($group, $user, null),
            'digest' => $user->feed_digest,
            'realtime' => config('broadcasting.default') === 'mercure',
            'status' => session('status'),
            'seo' => SeoData::private('Feed · '.$group->name)->toArray(),
        ]);
    }

    /** Infinite scroll: the next page of older posts. */
    public function more(Request $request, Group $group): JsonResponse
    {
        abort_unless(Feed::member($this->user($request), $group), 403);

        return response()->json(['posts' => $this->page($group, $this->user($request), (int) $request->query('before'))]);
    }

    public function store(Request $request, Group $group): RedirectResponse
    {
        $d = $request->validate(['kind' => ['required', 'in:post,ask,announcement'], 'body' => ['required', 'string', 'min:2', 'max:3000'], 'city_id' => ['nullable', 'integer', 'exists:cities,id']]);
        $this->feed->post($group, $this->user($request), (string) $d['kind'], (string) $d['body'], isset($d['city_id']) ? (int) $d['city_id'] : null);

        return back()->with('status', 'posted');
    }

    public function comment(Request $request, int $post): RedirectResponse
    {
        $this->feed->comment($post, $this->user($request), (string) $request->validate(['body' => ['required', 'string', 'max:2000']])['body']);

        return back();
    }

    public function react(Request $request, int $post): RedirectResponse
    {
        $this->feed->react($post, $this->user($request), (string) $request->validate(['kind' => ['required', 'in:like,celebrate,thanks']])['kind']);

        return back();
    }

    public function pin(Request $request, int $post): RedirectResponse
    {
        $this->feed->pin($post, $this->user($request));

        return back();
    }

    public function report(Request $request, string $target, int $id): RedirectResponse
    {
        $this->feed->report($target, $id, $this->user($request), $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null);

        return back()->with('status', 'reported');
    }

    public function digest(Request $request): RedirectResponse
    {
        $this->user($request)->forceFill(['feed_digest' => (string) $request->validate(['digest' => ['required', 'in:daily,weekly,off']])['digest']])->save();

        return back()->with('status', 'digest-saved');
    }

    public function shareWin(Request $request, Referral $referral): RedirectResponse
    {
        $posted = $this->feed->agreeToShareWin($referral, $this->user($request));

        return back()->with('status', $posted ? 'win-posted' : 'win-waiting');
    }

    /** Owners: hidden and reported posts and comments, to restore or remove. */
    public function moderation(Request $request, Group $group): Response
    {
        $user = $this->user($request);
        abort_unless($group->isOwnedBy($user) || $user->isStaff(), 403);
        $postIds = DB::table('feed_posts')->where('group_id', $group->id)->pluck('id');
        $row = fn (string $target) => fn ($r) => ['target' => $target, 'id' => (int) $r->id, 'body' => (string) $r->body, 'reports' => (int) $r->reports, 'hidden' => $r->hidden_at !== null,
            'reasons' => DB::table('feed_reports')->where(['target' => $target, 'target_id' => $r->id])->whereNotNull('reason')->pluck('reason')];

        return Inertia::render('app/group/Moderation', [
            'group' => ['slug' => $group->slug, 'name' => $group->name],
            'items' => DB::table('feed_posts')->where('group_id', $group->id)->whereNull('removed_at')->where('reports', '>', 0)->orderByDesc('reports')->get()->map($row('post'))
                ->merge(DB::table('feed_comments')->whereIn('post_id', $postIds)->whereNull('removed_at')->where('reports', '>', 0)->orderByDesc('reports')->get()->map($row('comment')))->values(),
            'seo' => SeoData::private('Moderation · '.$group->name)->toArray(),
        ]);
    }

    public function moderate(Request $request, string $target, int $id): RedirectResponse
    {
        $this->feed->moderate($target, $id, $this->user($request), (bool) $request->validate(['restore' => ['required', 'boolean']])['restore']);

        return back()->with('status', 'moderated');
    }

    /** @return list<array<string, mixed>> */
    private function page(Group $group, User $user, ?int $before): array
    {
        $me = Team::of($user);
        $posts = DB::table('feed_posts as p')->leftJoin('teams as t', 't.id', '=', 'p.team_id')->leftJoin('cities as c', 'c.id', '=', 'p.city_id')->leftJoin('states as s', 's.id', '=', 'c.state_id')
            ->where('p.group_id', $group->id)->whereNull('p.removed_at')->whereNull('p.hidden_at')->when($before, fn ($q) => $q->where('p.id', '<', $before))
            // Pinned posts lead the first page only.
            ->when(! $before, fn ($q) => $q->orderByRaw('p.pinned_at is null'))->orderByDesc('p.id')->limit(self::PAGE)
            ->get(['p.*', 't.name as team', 't.slug as team_slug', 'c.name as city', 's.code as state']);
        $ids = $posts->pluck('id');
        $reactions = DB::table('feed_reactions')->whereIn('post_id', $ids)->get()->groupBy('post_id');
        $comments = DB::table('feed_comments as m')->leftJoin('teams as t', 't.id', '=', 'm.team_id')->whereIn('m.post_id', $ids)->whereNull('m.removed_at')->whereNull('m.hidden_at')
            ->orderBy('m.id')->get(['m.*', 't.name as team', 't.slug as team_slug'])->groupBy('post_id');

        return array_values($posts->map(function ($p) use ($reactions, $comments, $user, $group) {
            $r = $reactions[$p->id] ?? collect();
            $mine = (int) $p->user_id === $user->id;

            return [
                'id' => (int) $p->id, 'kind' => (string) $p->kind, 'eventId' => $p->event_id !== null ? (int) $p->event_id : null, 'body' => (string) $p->body, 'team' => (string) ($p->team ?? 'Network owner'), 'teamSlug' => $p->team_slug,
                'at' => substr((string) $p->created_at, 0, 16), 'pinned' => $p->pinned_at !== null, 'mine' => $mine, 'city' => $p->city ? $p->city.', '.$p->state : null,
                'reactions' => ['like' => $r->where('kind', 'like')->count(), 'celebrate' => $r->where('kind', 'celebrate')->count(), 'thanks' => $r->where('kind', 'thanks')->count()],
                'myReaction' => $r->firstWhere('user_id', $user->id)?->kind,
                'comments' => ($comments[$p->id] ?? collect())->map(fn ($c) => [
                    'id' => (int) $c->id, 'team' => (string) ($c->team ?? 'Member'), 'body' => (string) $c->body, 'at' => substr((string) $c->created_at, 0, 16), 'offer' => (bool) $c->offer,
                    // The asker gets one tap to send the referral to the team that covers that city.
                    'sendUrl' => $mine && $c->offer && $p->city_id ? '/app/send?group='.$group->slug.'&city='.$p->city_id.'&from='.$c->team_slug : null,
                ])->values()->all(),
            ];
        })->all());
    }

    /** @return \Illuminate\Support\Collection<int, Group> */
    private function groups(User $user): \Illuminate\Support\Collection
    {
        $team = Team::of($user);
        $ids = DB::table('seats')->where('primary_team_id', $team !== null ? $team->id : 0)->pluck('group_id')->merge(DB::table('group_owners')->where('user_id', $user->id)->pluck('group_id'))->unique();

        return Group::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
