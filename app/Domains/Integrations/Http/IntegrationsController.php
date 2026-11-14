<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Integrations\Connectors;
use App\Domains\Integrations\CrmSync;
use App\Domains\Integrations\Integration;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Portal → Integrations: the team's CRM connections. Leaders and assistants manage them; members see their status. */
final class IntegrationsController
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? abort(404);
        $manage = in_array($team->roleOf($user), CrmSync::MANAGERS, true);
        $connections = Integration::query()->where('team_id', $team->id)->orderBy('id')->get();
        $since = now()->subDay();

        return Inertia::render('app/Integrations', [
            'providers' => collect(Connectors::all())->map(fn ($p, $key) => ['key' => $key, 'label' => $p->label(), 'keyHelp' => $p->keyHelp(), 'available' => Connectors::available($key)])->values(),
            'connections' => $connections->map(fn (Integration $i) => [
                'id' => $i->ulid, 'provider' => $i->provider, 'label' => Connectors::get($i->provider)->label(), 'status' => $i->status, 'account' => $i->account_name,
                'lastError' => $i->last_error, 'lastSynced' => $i->last_synced_at?->toIso8601String(), 'connectedAt' => $i->created_at->toIso8601String(),
                'settings' => ['sync_sent' => (bool) $i->setting('sync_sent', false), 'sync_notes' => (bool) $i->setting('sync_notes', true), 'assign_user_id' => $i->setting('assign_user_id')],
                'users' => $manage ? ($i->remote_users ?? []) : [], 'stages' => $manage ? ($i->remote_stages ?? []) : [], 'map' => $manage ? (object) CrmSync::stageMap($i) : (object) [],
                'lastDay' => DB::table('sync_logs')->where('integration_id', $i->id)->where('created_at', '>=', $since)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            ])->values(),
            'localStages' => $manage ? CrmSync::localStages($team) : [],
            // The log names clients, so only leaders and assistants see it.
            'logs' => $manage ? DB::table('sync_logs as l')->join('integrations as i', 'i.id', '=', 'l.integration_id')->leftJoin('referrals as r', 'r.id', '=', 'l.referral_id')
                ->where('i.team_id', $team->id)->orderByDesc('l.id')->limit(50)->get(['l.id', 'l.direction', 'l.action', 'l.status', 'l.attempts', 'l.message', 'l.next_attempt_at', 'l.created_at', 'i.provider', 'r.ulid', 'r.client_name'])
                ->map(fn ($l) => ['id' => (int) $l->id, 'provider' => Connectors::get((string) $l->provider)->label(), 'direction' => (string) $l->direction, 'action' => (string) $l->action, 'status' => (string) $l->status,
                    'attempts' => (int) $l->attempts, 'message' => $l->message, 'nextAttempt' => $l->next_attempt_at, 'at' => now()->parse((string) $l->created_at)->toIso8601String(),
                    'referral' => $l->ulid, 'client' => $l->client_name !== null ? Referral::shorten((string) $l->client_name) : null])->values() : [],
            'canManage' => $manage,
            'status' => session('status'),
            'seo' => SeoData::private('Integrations')->toArray(),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        [$user, $team] = $this->manager($request);
        $d = $request->validate(['provider' => ['required', 'in:'.implode(',', array_keys(Connectors::PROVIDERS))], 'api_key' => ['required', 'string', 'min:8', 'max:300'],
            'sync_sent' => ['boolean'], 'sync_notes' => ['boolean'], 'backfill' => ['boolean']], ['api_key.required' => 'Paste your API key.', 'api_key.min' => 'That doesn’t look like a full API key.']);
        // A new key for an existing connection keeps its settings: only what was sent changes.
        $settings = [];
        foreach (['sync_sent', 'sync_notes', 'backfill'] as $k) {
            if (array_key_exists($k, $d)) {
                $settings[$k] = (bool) $d[$k];
            }
        }
        CrmSync::connect($team, $user, (string) $d['provider'], (string) $d['api_key'], $settings);

        return back()->with('status', 'integration-connected');
    }

    public function update(Request $request, string $integration): RedirectResponse
    {
        [$user, $team] = $this->manager($request);
        $i = $this->find($team, $integration);
        $d = $request->validate(['sync_sent' => ['sometimes', 'boolean'], 'sync_notes' => ['sometimes', 'boolean'], 'assign_user_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'map' => ['sometimes', 'array', 'max:200'], 'map.*' => ['nullable', 'string', 'max:100']]);
        if (isset($d['assign_user_id']) && ! collect($i->remote_users ?? [])->contains('id', $d['assign_user_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['assign_user_id' => 'Pick an agent from the list.']);
        }
        $keys = collect(CrmSync::localStages($team))->pluck('key')->all();
        $map = isset($d['map']) ? array_map(fn ($v) => in_array($v, $keys, true) ? (string) $v : '', (array) $d['map']) : null;
        CrmSync::update($i, $user, array_intersect_key($d, array_flip(['sync_sent', 'sync_notes', 'assign_user_id'])), $map);

        return back()->with('status', 'integration-updated');
    }

    public function test(Request $request, string $integration): RedirectResponse
    {
        [, $team] = $this->manager($request);
        $error = CrmSync::test($this->find($team, $integration));

        return $error === null ? back()->with('status', 'integration-ok') : back()->withErrors(['integration' => $error]);
    }

    public function pause(Request $request, string $integration): RedirectResponse
    {
        [$user, $team] = $this->manager($request);
        CrmSync::setPaused($this->find($team, $integration), $user, $request->boolean('paused'));

        return back()->with('status', $request->boolean('paused') ? 'integration-paused' : 'integration-resumed');
    }

    public function retry(Request $request, string $integration): RedirectResponse
    {
        [, $team] = $this->manager($request);
        CrmSync::retryFailed($this->find($team, $integration));

        return back()->with('status', 'integration-retrying');
    }

    public function disconnect(Request $request, string $integration): RedirectResponse
    {
        [$user, $team] = $this->manager($request);
        CrmSync::disconnect($this->find($team, $integration), $user);

        return back()->with('status', 'integration-disconnected');
    }

    /** @return array{0: User, 1: Team} */
    private function manager(Request $request): array
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? abort(404);
        abort_unless(in_array($team->roleOf($user), CrmSync::MANAGERS, true), 403);

        return [$user, $team];
    }

    private function find(Team $team, string $id): Integration
    {
        return Integration::query()->where(['ulid' => $id, 'team_id' => $team->id])->firstOrFail();
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
