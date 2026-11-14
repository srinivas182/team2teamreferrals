<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Directory\Directory;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    Cache::flush();
    $this->g = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->city = fn (string $slug) => City::where('slug', $slug)->firstOrFail();
    $sv = app(Seats::class);
    $this->mk = function (string $name, string $city) use ($sv) {
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name]);
        $sv->approve($sv->apply($this->g, $t, ($this->city)($city), 'L', null), null);

        return $t;
    };
    $this->houston = ($this->mk)('Hurricane Sun', 'houston');
    $this->austin = ($this->mk)('Lone Star Solar', 'austin');
    $this->n = 0;
    // A referral from Lone Star Solar to Hurricane Sun, accepted after $hours hours.
    $this->refer = function (float $hours) {
        $ans = collect(app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'])
            ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
            }])->all();
        $this->n++;
        $r = app(Referrals::class)->send($this->austin->leader, $this->g, ($this->city)('houston'), [...$ans, 'client_name' => 'Client '.$this->n, 'client_phone' => '+143555509'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
        app(Referrals::class)->accept($r->fresh(), $this->houston->leader);
        DB::table('referrals')->where('id', $r->id)->update(['accepted_at' => $r->fresh()->created_at->copy()->addMinutes((int) ($hours * 60))]);

        return $r;
    };
});

it('shows response time, the last 12 months, verified credentials and rated reviews on a team’s profile', function () {
    $refs = [($this->refer)(1), ($this->refer)(3), ($this->refer)(8)];
    DB::table('team_licenses')->insert(['team_id' => $this->houston->id, 'industry_id' => $this->g->industry_id, 'state_id' => ($this->city)('houston')->state_id, 'number' => 'TX-4471',
        'status' => 'verified', 'reviewed_at' => '2026-10-02 10:00:00', 'expires_on' => '2027-06-30', 'created_at' => now(), 'updated_at' => now()]);
    $rate = fn ($r, string $rater, int $stars, ?string $comment) => DB::table('referral_ratings')->insert(['referral_id' => $r->id, 'rater' => $rater,
        'rater_team_id' => $rater === 'client' ? null : $this->austin->id, 'rated_team_id' => $this->houston->id, 'stars' => $stars, 'comment' => $comment, 'created_at' => now(), 'updated_at' => now()]);
    $rate($refs[0], 'sender', 5, 'Fast and kept us posted.');
    $rate($refs[1], 'client', 4, 'Great install. Call me on 435-555-0199 or tom@example.com.');
    $rate($refs[2], 'client', 2, null);
    $props = $this->get('/t/'.$this->houston->fresh()->slug)->assertOk()->viewData('page')['props'];
    expect($props['response'])->toBe(['hours' => 3, 'count' => 3]) // the median, once there are 3 or more
        ->and($props['lastYear'])->toBe(['received' => 3, 'closed' => 0])
        ->and($props['credentials'])->toBe([['state' => 'TX', 'stateName' => 'Texas', 'industry' => 'Solar', 'number' => 'TX-4471', 'verifiedOn' => '2026-10-02', 'expiresOn' => '2027-06-30']])
        ->and($props['reviews']['breakdown'])->toBe([5 => 1, 4 => 1, 3 => 0, 2 => 1, 1 => 0])
        ->and($props['reviews']['clients'])->toBe(2)->and($props['reviews']['peers'])->toBe(1);
    $client = collect($props['reviews']['items'])->firstWhere('stars', 4);
    expect($client['by'])->toBe('client')->and($client['from'])->toBeNull()
        ->and($client['comment'])->toBe('Great install. Call me on [contact removed] or [contact removed].');
    expect(collect($props['reviews']['items'])->firstWhere('stars', 5)['from'])->toBe('Lone Star Solar');
    // Stars and reviews in the page data, from verified referrals only.
    $business = collect($props['seo']['schema'])->firstWhere('@type', 'LocalBusiness');
    expect($business['aggregateRating']['ratingCount'])->toBe(3)->and(collect($business['review'])->pluck('author.name')->all())->toContain('Lone Star Solar', 'Verified client');
    // A referring team with a private profile isn't named.
    $this->austin->forceFill(['profile_public' => false])->save();
    expect(collect($this->get('/t/'.$this->houston->fresh()->slug)->viewData('page')['props']['reviews']['items'])->firstWhere('stars', 5)['from'])->toBeNull();
});

it('hides the response time until there are enough referrals', function () {
    ($this->refer)(2);
    expect($this->get('/t/'.$this->houston->fresh()->slug)->viewData('page')['props']['response'])->toBe(['hours' => null, 'count' => 1]);
});

it('maps a group publicly without naming backups or private teams', function () {
    $this->houston->forceFill(['profile_public' => false])->save();
    $map = $this->get('/groups/'.$this->g->slug)->assertOk()->viewData('page')['props']['map']['points'];
    expect(collect($map)->where('status', 'held')->pluck('name')->all())->toBe(['Austin, TX'])
        ->and(collect($map)->where('status', 'held')->pluck('name'))->not->toContain('Houston, TX')
        ->and(collect($map)->firstWhere('name', 'Austin, TX')['team']['name'])->toBe('Lone Star Solar') // public holders are named, as on the page
        ->and(json_encode($map))->not->toContain('Hurricane Sun');
});

it('links each city page to nearby seats and to other industries in the same city', function () {
    $mortgage = tap(Group::query()->whereHas('industry', fn ($q) => $q->where('slug', 'mortgage'))->firstOrFail())->update(['status' => 'live']);
    $lender = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Bayou Home Loans']);
    app(Seats::class)->approve(app(Seats::class)->apply($mortgage, $lender, ($this->city)('houston'), 'NMLS-1', null), null);
    $props = $this->get('/industries/solar/tx/houston')->assertOk()->viewData('page')['props'];
    expect($props['nearby'])->toBe([['name' => 'Austin', 'slug' => 'austin', 'team' => 'Lone Star Solar']])
        ->and($props['otherIndustries'])->toBe([['slug' => 'mortgage', 'name' => 'Mortgage lending', 'team' => 'Bayou Home Loans']]);
    expect(Directory::otherIndustries(($this->city)('austin'), $this->g->industry_id))->toBe([]);
});

it('lists states with a verified pro on the industry hub, and adds indexable directory pages to llms.txt', function () {
    Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
    $editor = User::factory()->create();
    $i = $this->g->industry;
    App\Domains\Content\Industry\IndustryContent::save($i, App\Domains\Content\Industry\IndustryContent::content($i), 'Reviewer', $editor);
    App\Domains\Content\Industry\IndustryContent::publish($i, $editor);
    expect($this->get('/industries/solar')->assertOk()->viewData('page')['props']['states'])->toBe([['code' => 'tx', 'name' => 'Texas']]);
    $llms = (string) $this->get('/llms.txt')->getContent();
    expect($llms)->toContain('## Directory')->toContain('/groups/'.$this->g->slug)->toContain('/industries/solar/tx');
});
