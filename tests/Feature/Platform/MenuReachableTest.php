<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Modules\Modules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
 * Every portal menu link must open for a real owner with every module on (the owner menu once linked to pages that
 * didn't exist), and every portal page must be reachable from the menu or the command palette.
 */
beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    modulesOn(...array_keys(array_filter(Modules::ALL, fn ($m) => ($m['available'] ?? true) !== false)));
    $this->nav = (string) file_get_contents(resource_path('js/layouts/nav.ts'));
    $this->layout = (string) file_get_contents(resource_path('js/layouts/PortalLayout.tsx'));
});

it('opens every menu link for a seat-holding owner', function () {
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $user = User::factory()->create();
    $team = Team::forLeader($user);
    app(Seats::class)->approve(app(Seats::class)->apply($g, $team, City::where('is_enabled', true)->first(), 'L', null), null);
    $g->owners()->attach($user->id);
    preg_match_all("/href: '([^']+)'/", $this->nav, $m);
    $broken = [];
    foreach (array_unique($m[1]) as $href) {
        $status = $this->actingAs($user)->get($href)->getStatusCode();
        if (! in_array($status, [200, 302], true)) {
            $broken[] = "{$href} ({$status})";
        }
    }
    expect($broken)->toBe([]);
});

it('has every portal page in the menu or the command palette', function () {
    // Pages reached from inside another page by design (a record, a step of a flow, a filtered list).
    $byDesign = ['app/referrals/inbox', 'app/notifications/settings', 'app/team/preview', 'app/share', 'app/profile-leads', 'app/groups', 'app/settings/notifications', 'app/settings/security', 'app/settings/profile', 'app/billing', 'app/nurture', 'app/earnings', 'app/credits', 'app/invites', 'app/badges', 'app/leaderboards', 'app/directory', 'app/onboarding', 'app/sent', 'app/send', 'app/introduce'];
    $missing = [];
    foreach (Route::getRoutes() as $r) {
        $uri = $r->uri();
        if (! in_array('GET', $r->methods(), true) || ! str_starts_with($uri, 'app/') || str_contains($uri, '{') || preg_match('/\.(csv|pdf|svg|json|ics)$|\/more$|export|search$|preview$/', $uri)) {
            continue;
        }
        if (! str_contains($this->nav, "'/{$uri}'") && ! str_contains($this->layout, "'/{$uri}'") && ! in_array($uri, $byDesign, true)) {
            $missing[] = $uri;
        }
    }
    expect($missing)->toBe([]);
});
