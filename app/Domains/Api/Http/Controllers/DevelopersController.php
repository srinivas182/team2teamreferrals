<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Api\ApiAccess;
use App\Domains\Api\ApiSpec;
use App\Domains\Api\ApiToken;
use App\Domains\Api\ApiTokens;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** /developers (public API docs) and Portal → Developers (the team's API tokens). */
final class DevelopersController
{
    public function docs(): Response
    {
        return Inertia::render('Developers', [
            'spec' => ApiSpec::document(),
            'scopes' => ApiTokens::SCOPES,
            'limits' => ['base' => ApiAccess::BASE_PER_MINUTE, 'plan' => ApiAccess::PLAN_PER_MINUTE],
            'seo' => (new SeoData(title: 'API for developers', description: 'The Team to Team Referrals REST API: send and track referrals, leads, groups, seats and profiles, with test tokens, safe retries and clear errors.',
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
            'canManage' => in_array($team->roleOf($user), ApiTokens::MANAGERS, true),
            'limit' => ApiAccess::hasPlan($team) ? ApiAccess::PLAN_PER_MINUTE : ApiAccess::BASE_PER_MINUTE,
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

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
