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
                    'territory' => $g->industry->territory_unit, 'price' => (int) ($g->pricing['seat_price'] ?? 10), 'trial' => (int) ($g->pricing['trial_months'] ?? 3),
                    'inviteOnly' => $g->visibility === 'invite_only', 'live' => $g->status === 'live', 'mine' => $mine[$g->id] ?? null]),
            'seo' => SeoData::private('Groups')->toArray(),
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
