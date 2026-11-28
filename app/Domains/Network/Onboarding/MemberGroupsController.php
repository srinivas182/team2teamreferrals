<?php

declare(strict_types=1);

namespace App\Domains\Network\Onboarding;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\SeoData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/** Members find a network to join, and preview how their team will look to others. */
final class MemberGroupsController
{
    public function index(Request $request): Response
    {
        $team = $this->team($request);
        $mine = DB::table('seat_applications')->where('team_id', $team->id)->pluck('status', 'group_id');

        return Inertia::render('app/groups/Index', [
            'groups' => Group::query()->with('industry:id,name,family,territory_unit,tier')->where('is_private', false)->where('status', '!=', 'closed')->orderBy('name')->get()
                ->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name, 'industry' => $g->industry->name, 'family' => Industry::FAMILIES[$g->industry->family] ?? $g->industry->family,
                    'territory' => $g->industry->territory_unit, 'price' => (int) ($g->pricing['seat_price'] ?? 25), 'trial' => (int) ($g->pricing['trial_months'] ?? 1),
                    'inviteOnly' => $g->visibility === 'invite_only', 'live' => $g->status === 'live', 'mine' => $mine[$g->id] ?? null]),
            'seo' => SeoData::private('Join new groups')->toArray(),
        ]);
    }

    /**
     * My groups: every city this team applied for, holds, backs up or waits for, newest first, so a member can see what
     * happened to each application without asking.
     */
    public function mine(Request $request): Response
    {
        $team = $this->team($request);
        $rows = DB::table('seat_applications as a')->join('groups as g', 'g.id', '=', 'a.group_id')->join('seats as s', 's.id', '=', 'a.seat_id')
            ->where('a.team_id', $team->id)->orderByDesc('a.created_at')
            ->get(['a.id', 'a.status', 'a.outcome', 'a.created_at', 'a.decided_at', 'g.slug', 'g.name', 's.id as seat_id', 's.territory_name', 's.primary_team_id', 's.backup_team_id', 's.trial_ends_at']);
        $waiting = DB::table('seat_waitlist')->where('team_id', $team->id)->pluck('seat_id')->all();

        return Inertia::render('app/groups/Mine', [
            'rows' => $rows->map(function ($r) use ($team, $waiting) {
                // Where the team stands now (an approval can later become a holder when a seat opens, or end).
                $now = match (true) {
                    $r->status === 'pending' => 'pending',
                    $r->status === 'declined' => 'declined',
                    (int) $r->primary_team_id === $team->id => 'holder',
                    (int) $r->backup_team_id === $team->id => 'backup',
                    in_array((int) $r->seat_id, array_map('intval', $waiting), true) => 'waitlist',
                    default => 'ended',
                };

                return ['id' => (int) $r->id, 'group' => (string) $r->name, 'slug' => (string) $r->slug, 'city' => (string) $r->territory_name, 'state' => $now,
                    'appliedOn' => substr((string) $r->created_at, 0, 10), 'decidedOn' => $r->decided_at ? substr((string) $r->decided_at, 0, 10) : null,
                    'trialEnds' => $now === 'holder' && $r->trial_ends_at ? substr((string) $r->trial_ends_at, 0, 10) : null];
            })->values(),
            'status' => $request->session()->get('status'),
            'seo' => SeoData::private('My groups')->toArray(),
        ]);
    }

    public function preview(Request $request): Response
    {
        $team = $this->team($request);

        return Inertia::render('app/TeamPreview', [
            'team' => ['name' => $team->name, 'bio' => $team->bio, 'serviceArea' => $team->service_area, 'website' => $team->website, 'phone' => $team->phone, 'social' => $team->social ?? new stdClass,
                'foundedYear' => $team->founded_year, 'logo' => $team->logo_path ? Storage::disk('public')->url($team->logo_path) : null,
                'members' => DB::table('team_members')->where('team_id', $team->id)->count()],
            'licenses' => DB::table('team_licenses')->join('states', 'states.id', '=', 'team_licenses.state_id')->where(['team_id' => $team->id, 'status' => 'verified'])->pluck('states.code')->unique()->values(),
            'seats' => DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->where('primary_team_id', $team->id)->get(['groups.name', 'seats.territory_name', 'seats.founding'])
                ->map(fn ($s) => ['group' => (string) $s->name, 'territory' => (string) $s->territory_name, 'founding' => (bool) $s->founding]),
            'strength' => ProfileStrength::for($team),
            'record' => \App\Domains\Referrals\ProfileStats::for($team),
            'company' => $team->company_id ? ['name' => (string) DB::table('companies')->where('id', $team->company_id)->value('name'),
                'cities' => DB::table('seats')->join('teams', 'teams.id', '=', 'seats.primary_team_id')->where('teams.company_id', $team->company_id)->count()] : null,
            'seo' => SeoData::private('Profile preview')->toArray(),
        ]);
    }

    private function team(Request $request): Team
    {
        /** @var User $u */
        $u = $request->user();

        return Team::of($u) ?? Team::forLeader($u);
    }
}
