<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Api\ApiAccess;
use App\Domains\Api\Resources;
use App\Domains\Community\Directory\Specialties;
use App\Domains\Network\Directory\Directory;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use stdClass;

/** /api/v1/me, groups, cities, seats, profile and leads. */
final class AccountController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $token = $this->token($request);
        $team = $this->team($request);

        return response()->json(['object' => 'token', 'name' => $token->name, 'mode' => $token->mode, 'scopes' => $token->scopes,
            'team' => ['id' => $team->ulid, 'name' => $team->name], 'acting_as' => $this->user($request)->name,
            'rate_limit_per_minute' => ApiAccess::perMinute($token), 'expires_at' => $token->expires_at?->toIso8601String()]);
    }

    /** The networks the team can send referrals in (the sandbox network for a test token). */
    public function groups(Request $request): JsonResponse
    {
        $groups = app(Referrals::class)->groupsFor($this->user($request));

        return response()->json(['object' => 'list', 'data' => array_map(fn (Group $g) => $this->group($g, false), $groups), 'has_more' => false, 'next_cursor' => null]);
    }

    public function showGroup(Request $request, string $slug): JsonResponse
    {
        $group = collect(app(Referrals::class)->groupsFor($this->user($request)))->firstWhere('slug', $slug) ?? abort(404);

        return response()->json($this->group($group, true));
    }

    /** Who would receive a referral for this city in this group, before sending one. */
    public function route(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate(['city_id' => ['required', 'integer', 'exists:cities,id']]);
        $group = collect(app(Referrals::class)->groupsFor($this->user($request)))->firstWhere('slug', $slug) ?? abort(404);
        $r = app(Referrals::class)->route($group, \App\Domains\Network\Locations\Models\City::query()->findOrFail((int) $d['city_id']));

        return response()->json(['object' => 'route', 'territory' => $r['territory'], 'available' => $r['team'] !== null && $r['team']->id !== $this->team($request)->id,
            'own_territory' => $r['team']?->id === $this->team($request)->id, 'receiver' => $r['team'] !== null ? Resources::teamRef($r['team']) : null, 'role' => $r['role']]);
    }

    /** Find city ids by name (and state). */
    public function cities(Request $request): JsonResponse
    {
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80'], 'state' => ['nullable', 'string', 'size:2'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $rows = DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')->where('cities.is_enabled', true)->where('cities.name', 'like', addcslashes((string) $d['q'], '%_').'%')
            ->when(isset($d['state']), fn ($q) => $q->where('states.code', strtoupper((string) $d['state'])))
            ->orderByDesc('cities.population')->limit((int) ($d['limit'] ?? 10))->get(['cities.id', 'cities.name', 'states.code']);

        return response()->json(['object' => 'list', 'data' => $rows->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name, 'state' => (string) $c->code])->values()->all(), 'has_more' => false, 'next_cursor' => null]);
    }

    public function seats(Request $request): JsonResponse
    {
        $team = $this->team($request);
        $rows = DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->where(fn ($q) => $q->where('seats.primary_team_id', $team->id)->orWhere('seats.backup_team_id', $team->id))
            ->orderBy('seats.id')->get(['seats.id', 'seats.territory_name', 'seats.territory_type', 'seats.status', 'seats.primary_team_id', 'seats.trial_ends_at', 'groups.slug', 'groups.name']);

        return response()->json(['object' => 'list', 'data' => $rows->map(fn ($s) => $this->seat($s, $team))->values()->all(), 'has_more' => false, 'next_cursor' => null]);
    }

    public function showSeat(Request $request, int $id): JsonResponse
    {
        $team = $this->team($request);
        $s = DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->where('seats.id', $id)->where(fn ($q) => $q->where('seats.primary_team_id', $team->id)->orWhere('seats.backup_team_id', $team->id))
            ->first(['seats.id', 'seats.territory_name', 'seats.territory_type', 'seats.status', 'seats.primary_team_id', 'seats.trial_ends_at', 'groups.slug', 'groups.name']) ?? abort(404);

        return response()->json($this->seat($s, $team));
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json($this->profileOf($this->team($request)->fresh() ?? $this->team($request)));
    }

    /** Update the team profile: only the fields sent change. */
    public function updateProfile(Request $request): JsonResponse
    {
        $team = $this->team($request);
        abort_unless(in_array($team->roleOf($this->user($request)), ['leader', 'assistant'], true), 403, 'Only the team leader or an assistant can change the profile.');
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'], 'bio' => ['sometimes', 'nullable', 'string', 'max:2000'], 'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'], 'service_area' => ['sometimes', 'nullable', 'string', 'max:500'],
            'founded_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:'.date('Y')],
            'specialties' => ['sometimes', 'array', 'max:12'], 'specialties.*' => ['string', 'max:40'],
            'languages' => ['sometimes', 'array', 'max:12'], 'languages.*' => ['string', Rule::in(Specialties::LANGUAGES)],
        ]);
        foreach (['specialties', 'languages'] as $k) {
            if (array_key_exists($k, $d)) {
                DB::table('teams')->where('id', $team->id)->update([$k => json_encode(array_values(array_unique((array) $d[$k])))]);
                unset($d[$k]);
            }
        }
        $team->fill($d)->save();
        activity('team')->causedBy($request->user())->performedOn($team)->event('team-updated')->withProperties(['via' => 'api'])->log("Updated team profile {$team->name} (API)");

        return response()->json($this->profileOf($team->fresh() ?? $team));
    }

    public function leads(Request $request): JsonResponse
    {
        $d = $request->validate(['status' => ['nullable', 'in:new,contacted,closed'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:500']]);
        $page = DB::table('profile_leads')->where('team_id', $this->team($request)->id)->where('status', '!=', 'unverified')
            ->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']))->orderByDesc('id')->cursorPaginate($this->limit($request));

        return response()->json(Resources::page($page, array_values(array_map(fn ($l) => $this->lead($l), $page->items()))));
    }

    public function showLead(Request $request, int $id): JsonResponse
    {
        return response()->json($this->lead($this->findLead($request, $id)));
    }

    public function updateLead(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:new,contacted,closed']]);
        $lead = $this->findLead($request, $id);
        DB::table('profile_leads')->where('id', $lead->id)->update(['status' => $d['status'], 'updated_at' => now()]);

        return response()->json($this->lead($this->findLead($request, $id)));
    }

    private function findLead(Request $request, int $id): stdClass
    {
        return DB::table('profile_leads')->where(['id' => $id, 'team_id' => $this->team($request)->id])->where('status', '!=', 'unverified')->first() ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function lead(stdClass $l): array
    {
        return ['id' => (int) $l->id, 'object' => 'lead', 'status' => (string) $l->status, 'source' => (string) ($l->source ?? 'profile'),
            'referrer' => ['name' => (string) $l->referrer_name, 'email' => (string) $l->referrer_email, 'business' => $l->referrer_business],
            'client' => ['name' => (string) $l->client_name, 'phone' => $l->client_phone, 'email' => $l->client_email], 'need' => (string) $l->need,
            'consent_text' => (string) $l->consent_text, 'verified_at' => $l->verified_at !== null ? now()->parse((string) $l->verified_at)->toIso8601String() : null,
            'created_at' => now()->parse((string) $l->created_at)->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function group(Group $g, bool $full): array
    {
        $out = ['slug' => $g->slug, 'object' => 'group', 'name' => $g->name, 'industry' => ['slug' => $g->industry->slug, 'name' => $g->industry->name],
            'territory_unit' => $g->industry->territory_unit, 'sandbox' => $g->is_sandbox, 'accept_hours' => (int) $g->rule('accept_hours')];
        if ($full) {
            $templates = app(\App\Domains\Network\Industries\Templates\IndustryTemplates::class);
            $out['form'] = array_map(fn ($f) => ['key' => (string) $f['key'], 'label' => (string) ($f['label'] ?? $f['key']), 'type' => (string) ($f['type'] ?? 'text'),
                'required' => (bool) ($f['required'] ?? false), 'options' => $f['options'] ?? null], $templates->resolve('form', $g->industry, $g->id)['body']);
            $out['stages'] = array_map(fn ($s) => ['key' => (string) $s['key'], 'label' => (string) $s['label'], 'kind' => (string) $s['kind']], $templates->resolve('stages', $g->industry, $g->id)['body']);
            if ($g->is_sandbox) {
                $out['sandbox_seats'] = \App\Domains\Api\Sandbox::seats($g);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function seat(stdClass $s, Team $team): array
    {
        return ['id' => (int) $s->id, 'object' => 'seat', 'group' => ['slug' => (string) $s->slug, 'name' => (string) $s->name], 'territory' => (string) $s->territory_name,
            'territory_type' => (string) $s->territory_type, 'role' => (int) $s->primary_team_id === $team->id ? 'primary' : 'backup', 'status' => (string) $s->status,
            'filled' => in_array($s->status, Directory::FILLED, true), 'trial_ends_at' => $s->trial_ends_at !== null ? now()->parse((string) $s->trial_ends_at)->toIso8601String() : null];
    }

    /** @return array<string, mixed> */
    private function profileOf(Team $team): array
    {
        $row = DB::table('teams')->where('id', $team->id)->first(['specialties', 'languages']);

        return ['object' => 'profile', 'name' => $team->name, 'public' => (bool) $team->profile_public, 'url' => $team->profile_public && $team->slug ? url('/t/'.$team->slug) : null,
            'bio' => $team->bio, 'website' => $team->website, 'phone' => $team->phone, 'service_area' => $team->service_area, 'founded_year' => $team->founded_year,
            'specialties' => (array) json_decode((string) ($row->specialties ?? '[]'), true), 'languages' => (array) json_decode((string) ($row->languages ?? '[]'), true)];
    }
}
