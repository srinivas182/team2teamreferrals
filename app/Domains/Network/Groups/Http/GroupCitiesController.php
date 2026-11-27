<?php

declare(strict_types=1);

namespace App\Domains\Network\Groups\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\GroupCities;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Seo\SeoData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** A group's Cities setting, for its owners and staff: all cities can join, or only the ones listed, state by state. */
final class GroupCitiesController
{
    public function show(Request $request, Group $group): Response
    {
        $this->authorize($request, $group);

        return Inertia::render('admin/groups/Cities', [
            'group' => ['slug' => $group->slug, 'name' => $group->name],
            'basePath' => $request->is('admin/*') ? "/admin/groups/{$group->slug}" : "/app/group/{$group->slug}",
            'limited' => GroupCities::limited($group),
            'cities' => GroupCities::byState($group),
            'states' => DB::table('states')->orderBy('name')->get(['code', 'name']),
            'heldOutside' => GroupCities::limited($group) ? GroupCities::heldOutside($group) : 0,
            'status' => session('status'),
            'seo' => SeoData::private('Cities · '.$group->name)->toArray(),
        ]);
    }

    /** The cities in one state, biggest first, to tick. */
    public function state(Request $request, Group $group, string $code): JsonResponse
    {
        $this->authorize($request, $group);
        $state = DB::table('states')->where('code', strtoupper($code))->first(['id']);
        abort_if($state === null, 404);

        return response()->json(DB::table('cities')->where(['state_id' => $state->id, 'is_enabled' => true])->orderByDesc('population')->orderBy('name')->limit(2000)
            ->get(['id', 'name', 'population'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->values());
    }

    public function save(Request $request, Group $group): RedirectResponse
    {
        $this->authorize($request, $group);
        $d = $request->validate(['limited' => ['required', 'boolean'], 'cities' => ['array', 'max:5000'], 'cities.*' => ['integer']]);
        $limited = (bool) $d['limited'];
        if ($limited && empty($d['cities'])) {
            return back()->withErrors(['cities' => 'Pick at least one city, or choose “All cities”.']);
        }
        GroupCities::save($group, $limited, array_map('intval', array_values($d['cities'] ?? [])));
        activity('groups')->causedBy($request->user())->performedOn($group)->event('cities-updated')->withProperties(['limited' => $limited, 'cities' => count($d['cities'] ?? [])])->log('Group cities updated');

        return back()->with('status', 'group-cities-saved');
    }

    /** Owners of the group, or staff who manage groups (with two-factor when required). */
    private function authorize(Request $request, Group $group): void
    {
        $u = $request->user();
        abort_unless($u instanceof User && ($group->isOwnedBy($u) || ($u->isStaff() && $u->can('groups.manage') && (! \App\Domains\Identity\StaffTwoFactor::required() || $u->hasTwoFactorEnabled()))), 403);
    }
}
