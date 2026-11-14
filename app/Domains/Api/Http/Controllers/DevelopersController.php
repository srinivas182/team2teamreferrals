<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Api\ApiAccess;
use App\Domains\Api\ApiSpec;
use App\Domains\Api\ApiToken;
use App\Domains\Api\ApiTokens;
use App\Domains\Api\Resources;
use App\Domains\Api\Webhooks\WebhookDelivery;
use App\Domains\Api\Webhooks\WebhookEndpoint;
use App\Domains\Api\Webhooks\WebhookEvents;
use App\Domains\Api\Webhooks\Webhooks;
use App\Domains\Billing\PricingRules;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** /developers (public API docs) and Portal → Developers (the team's API tokens, webhooks, plan and request log). */
final class DevelopersController
{
    public function docs(): Response
    {
        return Inertia::render('Developers', [
            'spec' => ApiSpec::document(),
            'scopes' => ApiTokens::SCOPES,
            'limits' => ['base' => ApiAccess::BASE_PER_MINUTE, 'plan' => ApiAccess::PLAN_PER_MINUTE, 'planPrice' => PricingRules::price('api_plan'),
                'baseEndpoints' => Webhooks::BASE_ENDPOINTS, 'planEndpoints' => Webhooks::PLAN_ENDPOINTS],
            'seo' => (new SeoData(title: 'API for developers', description: 'The Team to Team Referrals REST API: send and track referrals, leads and profiles, with signed webhooks, test tokens, safe retries and clear errors.',
                path: '/developers', schema: [Schema::organization(), Schema::breadcrumbs([['Home', '/'], ['Developers', '/developers']])]))->toArray(),
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? abort(404);

        return Inertia::render('app/Developers', [
            'tokens' => ApiToken::query()->with('user')->where('team_id', $team->id)->whereNull('revoked_at')->latest('id')->get()->map(fn (ApiToken $t) => [
                'id' => $t->ulid, 'name' => $t->name, 'mode' => $t->mode, 'prefix' => $t->prefix, 'scopes' => $t->scopes, 'createdBy' => $t->user->name,
                'lastUsed' => $t->last_used_at?->toIso8601String(), 'expires' => $t->expires_at?->toDateString(), 'created' => $t->created_at?->toIso8601String(),
                // A token stops working when it expires or its owner leaves the team; it stays listed so it can be revoked.
                'state' => $t->expires_at?->isPast() ? 'expired' : ($team->roleOf($t->user) === null || ! $t->user->is_active ? 'owner_left' : 'active'),
            ])->values(),
            'scopes' => ApiTokens::SCOPES,
            'canManage' => $manage = in_array($team->roleOf($user), ApiTokens::MANAGERS, true),
            'limit' => ApiAccess::hasPlan($team) ? ApiAccess::PLAN_PER_MINUTE : ApiAccess::BASE_PER_MINUTE,
            'plan' => ['on' => ApiAccess::hasPlan($team), 'price' => PricingRules::price('api_plan'), 'perMinute' => ApiAccess::PLAN_PER_MINUTE, 'basePerMinute' => ApiAccess::BASE_PER_MINUTE,
                'endpoints' => Webhooks::PLAN_ENDPOINTS, 'baseEndpoints' => Webhooks::BASE_ENDPOINTS, 'endpointLimit' => Webhooks::limit($team)],
            // Webhook payloads and the request log can hold client details: leaders and assistants only.
            'webhooks' => $manage ? $this->webhooks($team) : [],
            'deliveries' => $manage ? WebhookDelivery::query()->with('endpoint:id,ulid,url')->whereIn('webhook_endpoint_id', WebhookEndpoint::query()->where('team_id', $team->id)->select('id'))
                ->orderByDesc('id')->limit(50)->get()->map(fn (WebhookDelivery $d) => [...Resources::webhookDelivery($d), 'endpoint' => $d->endpoint->ulid, 'url' => $d->endpoint->url])->values() : [],
            'eventTypes' => WebhookEvents::TYPES,
            'logs' => $manage && ApiAccess::hasPlan($team) ? DB::table('api_request_logs as l')->leftJoin('api_tokens as t', 't.id', '=', 'l.api_token_id')->where('l.team_id', $team->id)
                ->orderByDesc('l.id')->limit(100)->get(['l.method', 'l.path', 'l.status', 'l.duration_ms', 'l.request_id', 'l.created_at', 't.name as token', 't.mode'])
                ->map(fn ($l) => ['method' => (string) $l->method, 'path' => (string) $l->path, 'status' => (int) $l->status, 'ms' => (int) $l->duration_ms, 'id' => (string) $l->request_id,
                    'at' => now()->parse((string) $l->created_at)->toIso8601String(), 'token' => $l->token !== null ? $l->token.' ('.$l->mode.')' : 'deleted token'])->values() : [],
            'newSecret' => session('new_secret'),
            'newToken' => session('new_token'),
            'status' => session('status'),
            'seo' => SeoData::private('Developers')->toArray(),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'mode' => ['required', 'in:live,test'], 'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', array_keys(ApiTokens::SCOPES))], 'expires_at' => ['nullable', 'date', 'after:today']]);
        $user = $this->user($request);
        $team = Team::of($user) ?? abort(404);
        $r = ApiTokens::create($team, $user, (string) $d['name'], (string) $d['mode'], array_values((array) $d['scopes']), $d['expires_at'] ?? null);

        // Shown once, on the next page only.
        return back()->with('new_token', ['id' => $r['token']->ulid, 'plain' => $r['plain']])->with('status', 'api-token-created');
    }

    public function revoke(Request $request, string $token): RedirectResponse
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? abort(404);
        abort_unless(in_array($team->roleOf($user), ApiTokens::MANAGERS, true), 403);
        ApiTokens::revoke(ApiToken::query()->where(['ulid' => $token, 'team_id' => $team->id])->whereNull('revoked_at')->firstOrFail(), $user);

        return back()->with('status', 'api-token-revoked');
    }

    public function createWebhook(Request $request): RedirectResponse
    {
        [$user, $team] = $this->manager($request);
        $d = $this->webhookInput($request, creating: true);
        $r = Webhooks::create($team, $user, (string) $d['url'], (string) $d['mode'], array_values((array) ($d['events'] ?? ['*'])), $d['description'] ?? null);

        return back()->with('new_secret', ['id' => $r['endpoint']->ulid, 'secret' => $r['secret']])->with('status', 'webhook-created');
    }

    public function updateWebhook(Request $request, string $endpoint): RedirectResponse
    {
        [$user, $team] = $this->manager($request);
        $d = $this->webhookInput($request, creating: false);
        Webhooks::update($this->endpoint($team, $endpoint), $user, array_filter(['url' => $d['url'] ?? null, 'events' => isset($d['events']) ? array_values((array) $d['events']) : null,
            'active' => isset($d['active']) ? (bool) $d['active'] : null], fn ($v) => $v !== null) + (array_key_exists('description', $d) ? ['description' => $d['description']] : []));

        return back()->with('status', 'webhook-updated');
    }

    public function deleteWebhook(Request $request, string $endpoint): RedirectResponse
    {
        [$user, $team] = $this->manager($request);
        Webhooks::delete($this->endpoint($team, $endpoint), $user);

        return back()->with('status', 'webhook-deleted');
    }

    public function testWebhook(Request $request, string $endpoint): RedirectResponse
    {
        [, $team] = $this->manager($request);
        Webhooks::ping($this->endpoint($team, $endpoint));

        return back()->with('status', 'webhook-test-sent');
    }

    public function rotateWebhook(Request $request, string $endpoint): RedirectResponse
    {
        [$user, $team] = $this->manager($request);
        $e = $this->endpoint($team, $endpoint);

        return back()->with('new_secret', ['id' => $e->ulid, 'secret' => Webhooks::rotate($e, $user)])->with('status', 'webhook-rotated');
    }

    public function resendDelivery(Request $request, string $delivery): RedirectResponse
    {
        [, $team] = $this->manager($request);
        Webhooks::resend(WebhookDelivery::query()->where('ulid', $delivery)->whereIn('webhook_endpoint_id', WebhookEndpoint::query()->where('team_id', $team->id)->select('id'))->firstOrFail());

        return back()->with('status', 'webhook-resent');
    }

    /** @return list<array<string, mixed>> */
    private function webhooks(Team $team): array
    {
        $limit = Webhooks::limit($team);
        $out = [];
        foreach (WebhookEndpoint::query()->where('team_id', $team->id)->orderBy('id')->get() as $i => $e) {
            $counts = DB::table('webhook_deliveries')->where('webhook_endpoint_id', $e->id)->where('created_at', '>=', now()->subDay())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
            $out[] = [...Resources::webhookEndpoint($e), 'overLimit' => $i >= $limit, 'lastDay' => ['succeeded' => (int) ($counts['succeeded'] ?? 0), 'failed' => (int) (($counts['failed'] ?? 0) + ($counts['retrying'] ?? 0))]];
        }

        return $out;
    }

    /** @return array{0: User, 1: Team} */
    private function manager(Request $request): array
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? abort(404);
        abort_unless(in_array($team->roleOf($user), ApiTokens::MANAGERS, true), 403);

        return [$user, $team];
    }

    private function endpoint(Team $team, string $id): WebhookEndpoint
    {
        return WebhookEndpoint::query()->where(['ulid' => $id, 'team_id' => $team->id])->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function webhookInput(Request $request, bool $creating): array
    {
        return $request->validate([
            'url' => [$creating ? 'required' : 'sometimes', 'string', 'max:500', 'url:https'], 'mode' => [$creating ? 'required' : 'prohibited', 'in:live,test'],
            'events' => ['sometimes', 'array', 'min:1'], 'events.*' => ['string', 'in:*,'.implode(',', array_keys(WebhookEvents::TYPES))],
            'description' => ['sometimes', 'nullable', 'string', 'max:120'], 'active' => ['sometimes', 'boolean'],
        ], ['url.url' => 'Use a full https:// address.']);
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
