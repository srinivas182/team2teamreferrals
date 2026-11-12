<?php

declare(strict_types=1);

namespace App\Domains\Community\Http;

use App\Domains\Community\Badges\Badges;
use App\Domains\Community\Badges\Leaderboards;
use App\Domains\Community\Directory\Specialties;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Member directory, badge rules, and network leaderboards. */
final class RecognitionController
{
    /** Teams in the networks this member belongs to, searchable by city, specialty, language and badge. */
    public function directory(Request $request): Response
    {
        $groups = $this->groups($this->user($request));
        $f = $request->validate(['city' => ['nullable', 'string', 'max:80'], 'group' => ['nullable', 'string'], 'specialty' => ['nullable', 'string', 'max:40'], 'language' => ['nullable', 'string', 'max:40'], 'badge' => ['nullable', 'string']]);
        $groupIds = $groups->when($f['group'] ?? null, fn ($c, $slug) => $c->where('slug', $slug))->pluck('id');
        $seats = DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->whereIn('seats.group_id', $groupIds)->whereNotNull('seats.primary_team_id')
            ->when($f['city'] ?? null, fn ($q, $c) => $q->where('seats.territory_name', 'like', '%'.$c.'%'))->get(['seats.primary_team_id', 'seats.territory_name', 'groups.name']);
        $teams = Team::query()->whereIn('id', $seats->pluck('primary_team_id')->unique())->orderBy('name')->limit(200)->get()
            ->map(fn (Team $t) => ['name' => $t->name, 'slug' => $t->slug, 'cities' => $seats->where('primary_team_id', $t->id)->pluck('territory_name')->unique()->values(),
                'groups' => $seats->where('primary_team_id', $t->id)->pluck('name')->unique()->values(),
                'specialties' => json_decode((string) DB::table('teams')->where('id', $t->id)->value('specialties'), true) ?: [],
                'languages' => json_decode((string) DB::table('teams')->where('id', $t->id)->value('languages'), true) ?: [], 'badges' => Badges::of($t), 'trust' => \App\Domains\Referrals\Trust\TrustScore::stored($t->id)['score'] ?? null])
            ->filter(fn ($t) => empty($f['specialty']) || in_array($f['specialty'], $t['specialties'], true))
            ->filter(fn ($t) => empty($f['language']) || in_array($f['language'], $t['languages'], true))
            ->filter(fn ($t) => empty($f['badge']) || collect($t['badges'])->contains('key', $f['badge']))->values();

        return Inertia::render('app/Directory', [
            'teams' => $teams, 'filters' => $f, 'groups' => $groups->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name])->values(),
            'specialties' => array_values(array_unique(array_merge(...array_map(fn ($fam) => Specialties::forFamily((string) $fam), $groups->map(fn (Group $g) => $g->industry->family)->unique()->all() ?: [null])))),
            'languages' => Specialties::LANGUAGES, 'badges' => collect(Badges::ALL)->map(fn ($b, $k) => ['key' => $k, 'name' => $b['name']])->values(),
            'seo' => SeoData::private('Directory')->toArray(),
        ]);
    }

    /** How every badge is earned and kept, and the ones this team holds now. */
    public function badges(Request $request): Response
    {
        $team = Team::of($this->user($request)) ?? Team::forLeader($this->user($request));

        return Inertia::render('app/Badges', [
            'badges' => collect(Badges::ALL)->map(fn ($b, $k) => ['key' => $k, ...$b])->values(), 'mine' => Badges::of($team), 'windowDays' => Badges::WINDOW_DAYS,
            'seo' => SeoData::private('Badges')->toArray(),
        ]);
    }

    public function leaderboards(Request $request): Response
    {
        $groups = $this->groups($this->user($request));
        $group = $groups->firstWhere('slug', (string) $request->query('group')) ?? $groups->first();
        $snap = $group ? DB::table('leaderboard_snapshots')->where('group_id', $group->id)->orderByDesc('period')->first() : null;

        return Inertia::render('app/Leaderboards', [
            'groups' => $groups->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name])->values(),
            'group' => $group ? ['slug' => $group->slug, 'name' => $group->name, 'serviceOnly' => Leaderboards::serviceOnly($group)] : null,
            'monthly' => $snap ? ['period' => (string) $snap->period, 'rows' => json_decode((string) $snap->rows, true)] : null,
            'allTime' => $group ? Leaderboards::rows($group, null, CarbonImmutable::now()) : [],
            'seo' => SeoData::private('Leaderboards')->toArray(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Group> */
    private function groups(User $user): \Illuminate\Support\Collection
    {
        $team = Team::of($user);
        $ids = DB::table('seats')->where('primary_team_id', $team !== null ? $team->id : 0)->pluck('group_id')->merge(DB::table('group_owners')->where('user_id', $user->id)->pluck('group_id'))->unique();

        return Group::query()->with('industry')->whereIn('id', $ids)->orderBy('name')->get();
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
