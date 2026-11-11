<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Profiles\ProfileLeadVerify;
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
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $this->team = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun', 'bio' => 'Family-run solar installers since 2009.', 'phone' => '+14355550199']);
    app(Seats::class)->approve(app(Seats::class)->apply($this->g, $this->team, $this->cities[1], 'L', null), null);
    $this->url = '/t/'.$this->team->fresh()->slug;
    // A non-member's form, filled in a few seconds after the page loaded.
    $this->form = fn (array $over = []) => [...['referrer_name' => 'Ana Ruiz', 'referrer_email' => 'ana@example.com', 'client_name' => 'Tom Brennan', 'client_phone' => '(435) 555-0100',
        'need' => 'Wants quotes for a 10kW rooftop system.', 'consent' => true, 'started' => encrypt(now()->subSeconds(20)->timestamp)], ...$over];
});

it('gives every team a stable web address and a search-ready public page', function () {
    expect($this->team->fresh()->slug)->toBe('hurricane-sun'); // follows the business name set after sign-up
    $this->get($this->url); // once visited (or shared), the address is fixed
    $this->team->update(['name' => 'Hurricane Sun Energy']);
    expect($this->team->fresh()->slug)->toBe('hurricane-sun'); // printed QR cards keep working
    config(['seo.indexable' => true]);
    $this->get($this->url)->assertOk()->assertInertia(function ($p) {
        $seo = $p->toArray()['props']['seo'];
        expect(mb_strlen($seo['description']))->toBeGreaterThanOrEqual(50)->and($seo['canonical'])->toEndWith($this->url)
            ->and($seo['robots'])->toContain('index, follow')->and(collect($seo['schema'])->firstWhere('@type', 'LocalBusiness'))->not->toBeNull();

        return $p->component('TeamPublic')->where('team.name', 'Hurricane Sun Energy')->where('viewer', 'guest');
    });
    expect((string) $this->get('/sitemap.xml')->getContent())->toContain('/sitemaps/profiles.xml');
    expect((string) $this->get('/sitemaps/profiles.xml')->getContent())->toContain($this->url);
});

it('hides profiles that are switched off or hold no seat', function () {
    $this->team->forceFill(['profile_public' => false])->save();
    $this->get($this->url)->assertNotFound();
    $noSeat = Team::forLeader(User::factory()->create());
    $this->get('/t/'.$noSeat->fresh()->slug)->assertNotFound();
});

it('counts a visit once per visitor per day, and QR scans separately, with a valid QR code', function () {
    $this->get($this->url.'?src=qr');
    $this->get($this->url.'?src=qr');
    $stats = DB::table('profile_stats')->where('team_id', $this->team->id)->pluck('count', 'kind');
    expect((int) $stats['view'])->toBe(1)->and((int) $stats['qr'])->toBe(1);
    $svg = $this->get($this->url.'/qr.svg')->assertOk()->assertHeader('content-type', 'image/svg+xml');
    expect((string) $svg->getContent())->toStartWith('<?xml')->toContain('<svg');
});

it('sends members to the network form, already set to this team’s network and city', function () {
    $member = Team::forLeader(User::factory()->create());
    app(Seats::class)->approve(app(Seats::class)->apply($this->g, $member, $this->cities[0], 'L', null), null);
    $this->actingAs($member->leader)->get($this->url)->assertInertia(fn ($p) => $p->where('viewer', 'member')->where('sendUrl', '/app/send?group=solar-installers-network&city='.$this->cities[1]->id));
    $this->get('/app/send?group=solar-installers-network&city='.$this->cities[1]->id)->assertInertia(fn ($p) => $p->where('prefill.cityId', $this->cities[1]->id)->where('prefill.group', 'solar-installers-network'));
});

it('takes non-member referrals only after spam checks and email confirmation', function () {
    $this->post($this->url.'/refer', ($this->form)(['website_url' => 'http://spam']))->assertSessionHasErrors('need');
    $this->post($this->url.'/refer', ($this->form)(['started' => encrypt(now()->timestamp)]))->assertSessionHasErrors('need');
    $this->post($this->url.'/refer', ($this->form)())->assertSessionHas('status', 'profile-lead-check-email');
    $link = Notification::sent(new AnonymousNotifiable, ProfileLeadVerify::class)->first()->link;
    $this->actingAs($this->team->leader)->get('/app/profile-leads')->assertInertia(fn ($p) => $p->has('leads', 0)); // unconfirmed stays invisible

    auth()->logout();
    $this->get(parse_url($link, PHP_URL_PATH))->assertRedirect($this->url);
    Notification::assertSentTo($this->team->leader, InAppNotification::class, fn ($n) => $n->type === 'profile.lead' && str_contains($n->title, 'Tom Brennan'));
    $this->actingAs($this->team->leader)->get('/app/profile-leads')->assertInertia(fn ($p) => $p->has('leads', 1)->where('leads.0.phone', '4355550100'));
    expect((int) DB::table('profile_stats')->where(['team_id' => $this->team->id, 'kind' => 'referral'])->value('count'))->toBe(1);

    auth()->logout();
    $this->post($this->url.'/refer', ($this->form)(['referrer_email' => 'other@example.com']))->assertSessionHas('status', 'profile-lead-duplicate');
});

it('gives the team its link, counts, email signature, printable card and a show/hide switch', function () {
    $this->actingAs($this->team->leader)->get('/app/share')->assertInertia(fn ($p) => $p->component('app/ShareProfile')
        ->where('link', url($this->url).'?src=link')->where('signature', fn ($s) => str_contains($s, url($this->url).'?src=link') && str_contains($s, 'Refer a client to us')));
    $this->get('/app/share/card.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->post('/app/share/public', ['public' => false])->assertSessionHas('status', 'profile-hidden');
    expect($this->team->fresh()->profile_public)->toBeFalse();
});
