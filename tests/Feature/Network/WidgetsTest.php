<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Profiles\ProfileLeadVerify;
use App\Domains\Network\Profiles\PublicProfiles;
use App\Domains\Network\Profiles\Widgets\Widgets;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Illuminate\Support\Facades\Cache::flush(); // rate limits from earlier tests
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $sv = app(Seats::class);
    $this->team = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun', 'website' => 'https://www.hurricanesun.com/about']);
    $this->seat = $sv->approve($sv->apply($this->g, $this->team, City::where('slug', 'houston')->firstOrFail(), 'L', null), null);
    PublicProfiles::ensureSlug($this->team);
    $this->team->forceFill(['profile_public' => true])->save();
    $this->slug = $this->team->fresh()->slug;
    $this->form = fn (array $over = []) => [...['referrer_name' => 'Pat Agent', 'referrer_email' => 'pat@example.com', 'client_name' => 'Jo Client', 'client_phone' => '713 555 0101',
        'need' => 'Wants quotes for a 10kW rooftop system.', 'consent' => true, 'started' => encrypt(now()->subSeconds(20)->timestamp)], ...$over];
});

it('serves each widget only on the team’s own websites and keeps cookies off it', function () {
    Widgets::setDomains($this->team, ['agency-partner.com', 'https://www.Hurricanesun.com/']);
    expect($this->team->fresh()->widget_domains)->toBe(['agency-partner.com', 'hurricanesun.com']);
    expect(fn () => Widgets::setDomains($this->team, ['not a site']))->toThrow(Illuminate\Validation\ValidationException::class);

    foreach (Widgets::TYPES as $w) {
        $r = $this->get("/embed/{$this->slug}/{$w}")->assertOk()->assertSee('Hurricane Sun');
        $csp = (string) $r->headers->get('Content-Security-Policy');
        expect($csp)->toContain("frame-ancestors 'self' https://agency-partner.com https://www.agency-partner.com https://hurricanesun.com https://www.hurricanesun.com")
            ->and($r->headers->has('X-Frame-Options'))->toBeFalse()
            ->and($r->headers->getCookies())->toBe([]);
    }
    $this->get("/embed/{$this->slug}/badge")->assertSee('Verified')->assertSee('Houston');
    $this->get("/embed/{$this->slug}/button")->assertSee('Refer a client to Hurricane Sun');
    // Ordinary pages still refuse framing.
    expect($this->get('/')->headers->get('X-Frame-Options'))->toBe('DENY');
    // The loader script is cacheable and small.
    $js = $this->get('/embed/v1.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    expect(strlen((string) $js->getContent()))->toBeLessThan(3000);
});

it('hides the widget as soon as the profile goes private or the seat lapses', function () {
    $this->get("/embed/{$this->slug}/badge")->assertSee('Hurricane Sun');
    $this->team->forceFill(['profile_public' => false])->save();
    $this->get("/embed/{$this->slug}/badge")->assertOk()->assertDontSee('Hurricane Sun')->assertSee('h: 0', false);
    $this->team->forceFill(['profile_public' => true])->save();
    DB::table('seats')->where('primary_team_id', $this->team->id)->update(['status' => 'lapsed']);
    $this->get("/embed/{$this->slug}/reviews")->assertDontSee('Hurricane Sun');
    $this->get('/embed/no-such-team/badge')->assertOk()->assertDontSee('Hurricane Sun');
});

it('counts views and clicks from the team’s websites only, and referrals sent through a widget', function () {
    $this->get("/embed/{$this->slug}/badge", ['Referer' => 'https://hurricanesun.com/'])->assertOk()->assertSee('from=hurricanesun.com', false);
    $this->get("/embed/{$this->slug}/badge", ['Referer' => 'https://copycat.example/'])->assertOk(); // not the team's site: not counted
    $this->get("/embed/{$this->slug}/badge"); // our own preview: not counted
    $this->get("/embed/{$this->slug}/go/button?to=refer&from=hurricanesun.com")->assertRedirect('/t/'.$this->slug.'?via=button#refer');
    $this->get("/embed/{$this->slug}/go/badge?from=copycat.example")->assertRedirect('/t/'.$this->slug.'?via=badge');

    $this->post("/t/{$this->slug}/refer", ($this->form)(['via' => 'button']))->assertSessionHasNoErrors()->assertSessionHas('status', 'profile-lead-check-email');
    expect(DB::table('profile_leads')->value('source'))->toBe('button');
    $this->post("/t/{$this->slug}/refer", ($this->form)(['via' => 'evil', 'client_phone' => '713 555 0199']))->assertSessionHasErrors('via');
    $link = Notification::sent(new AnonymousNotifiable, ProfileLeadVerify::class)->first()->link;
    $this->get($link)->assertRedirect('/t/'.$this->slug);

    $s = Widgets::stats($this->team->fresh());
    expect($s['badge'])->toBe(['view' => 1, 'click' => 0, 'referral' => 0])
        ->and($s['button'])->toBe(['view' => 0, 'click' => 1, 'referral' => 1]);

    $this->actingAs($this->team->leader)->get('/app/share')->assertInertia(fn ($p) => $p->where('widgets.live', true)->where('widgets.site', 'hurricanesun.com')
        ->where('widgets.stats.button.referral', 1));
    $this->actingAs($this->team->leader)->get('/app/profile-leads')->assertInertia(fn ($p) => $p->where('leads.0.source', 'button'));
});

it('lets the leader list the websites, and keeps the no-script link pointing at the profile', function () {
    $this->actingAs($this->team->leader)->post('/app/share/widget-domains', ['domains' => ['partner.org', '', 'www.partner.org']])
        ->assertSessionHas('status', 'widget-domains-saved');
    expect($this->team->fresh()->widget_domains)->toBe(['partner.org']);
    $this->actingAs($this->team->leader)->post('/app/share/widget-domains', ['domains' => ['bad site']])->assertSessionHasErrors('domains');
    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $this->team->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member)->post('/app/share/widget-domains', ['domains' => ['x.com']])->assertForbidden();
    // The fallback link inside the placeholder is the canonical profile address.
    $this->actingAs($this->team->leader)->get('/app/share')->assertInertia(fn ($p) => $p->where('widgets.slug', $this->slug)->where('widgets.base', url('/')));
    $this->get('/t/'.$this->slug)->assertOk();
});

it('keeps each rate limit to its own route, so browsing doesn’t use up the referral limit', function () {
    foreach (range(1, 8) as $i) {
        $this->get("/embed/{$this->slug}/badge")->assertOk();
        $this->get("/t/{$this->slug}")->assertOk();
    }
    $this->post("/t/{$this->slug}/refer", ($this->form)())->assertSessionHas('status', 'profile-lead-check-email');
    foreach (range(2, 5) as $i) {
        $this->post("/t/{$this->slug}/refer", ($this->form)(['client_phone' => '713 555 01'.$i.'0']));
    }
    $this->post("/t/{$this->slug}/refer", ($this->form)(['client_phone' => '713 555 0170']))->assertStatus(429); // the referral limit itself still holds
});
