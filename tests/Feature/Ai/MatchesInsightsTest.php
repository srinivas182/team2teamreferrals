<?php

use App\Domains\Community\Insights\GroupInsights;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    modulesOn('ai_insights');
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
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get(); // 0 Saint George, 1 Hurricane (~17 mi), 2 Austin, 3 Houston, 4 Fort Worth
    $sv = app(Seats::class);
    $this->mk = function (string $n, int $c) use ($sv) {
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
        $sv->approve($sv->apply($this->g, $t, $this->cities[$c], 'L', null), null);

        return $t;
    };
    $this->a = ($this->mk)('Lone Star Solar', 2);   // the sender, in Austin
    $this->b = ($this->mk)('Hurricane Sun', 1);
    $this->c = ($this->mk)('Bayou Power', 3);
    $this->owner = User::factory()->create();
    $this->g->owners()->attach($this->owner->id);
    $this->n = 0;
    $this->refer = function (Team $from, int $city) {
        $ans = collect(app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'])
            ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
            }])->all();
        $this->n++;

        return app(Referrals::class)->send($from->leader, $this->g, $this->cities[$city], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+143555502'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
    };
});

it('[3] suggests nearby seat holders on the send screen when no one covers the city, and none when they are far away', function () {
    $this->actingAs($this->a->leader);
    $near = $this->getJson('/app/send/preview?group=solar-installers-network&city_id='.$this->cities[0]->id)->assertOk()->json();
    expect($near['team'])->toBeNull()->and($near['matches'])->toHaveCount(1)
        ->and($near['matches'][0])->toMatchArray(['team' => 'Hurricane Sun', 'cityId' => $this->cities[1]->id])->and($near['matches'][0]['miles'])->toBeLessThan(25);
    expect($this->getJson('/app/send/preview?group=solar-installers-network&city_id='.$this->cities[4]->id)->json('matches'))->toBe([]); // Fort Worth: nothing within 60 miles
    expect($this->getJson('/app/send/preview?group=solar-installers-network&city_id='.$this->cities[1]->id)->json('matches'))->toBe([]); // covered: no matches needed
});

it('[3] lets the sender send an unplaced referral on to a nearby match, with the client’s agreement to that business', function () {
    $d = ($this->mk)('Red Cliffs Solar', 0);
    $r = ($this->refer)($this->a, 0);
    app(Referrals::class)->decline($r->fresh(), $d->leader, 'Fully booked');
    expect($r->fresh()->status)->toBe('declined');
    $this->actingAs($this->a->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('matches.0.team', 'Hurricane Sun'));
    $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/resend", ['city_id' => $this->cities[1]->id, 'consent' => true])->assertForbidden();
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r->ulid}/resend", ['city_id' => $this->cities[1]->id])->assertSessionHasErrors('consent');
    $this->post("/app/referrals/{$r->ulid}/resend", ['city_id' => $this->cities[1]->id, 'consent' => true])->assertRedirect();
    $copy = Referral::latest('id')->first();
    expect($copy->receiver_team_id)->toBe($this->b->id)->and($copy->client_name)->toBe('Tom Brennan')->and($copy->id)->not->toBe($r->id)
        ->and(DB::table('ai_suggestions')->where(['kind' => 'match', 'key' => 'nearby', 'status' => 'used'])->count())->toBe(1);
    $this->post("/app/referrals/{$r->ulid}/resend", ['city_id' => $this->cities[3]->id, 'consent' => true])->assertStatus(422); // Houston isn't a match
});

it('[5] sends owners weekly insights: weak cities, slow teams and rising members, in plain words when AI is off', function () {
    $d = ($this->mk)('Red Cliffs Solar', 0);
    app(Referrals::class)->decline(($this->refer)($this->a, 0)->fresh(), $d->leader, 'Busy');         // Saint George: unplaced
    DB::table('seat_waitlist')->insert(['seat_id' => DB::table('seats')->where(['group_id' => $this->g->id, 'territory_id' => $this->cities[3]->id])->value('id'), 'team_id' => $d->id, 'score' => 50, 'joined_at' => now()]);
    foreach ([1, 2] as $i) {                                                                         // Hurricane Sun: slow to accept, but busier than before
        $r = ($this->refer)($this->a, 1);
        $this->travel(20)->hours();
        app(Referrals::class)->accept($r->fresh(), $this->b->leader);
    }
    $this->travelTo(now()->next('Monday')->setTime(8, 0));
    Artisan::call('t2t:performance');
    $row = DB::table('group_insights')->where('group_id', $this->g->id)->first();
    $data = json_decode($row->data, true);
    expect($data['weakCities'][0]['city'])->toBe('Saint George, UT')->and(collect($data['weakCities'])->firstWhere('city', 'Houston, TX')['waiting'])->toBe(1)
        ->and($data['slowTeams'][0]['team'])->toBe('Hurricane Sun')->and(collect($data['rising'])->pluck('team'))->toContain('Hurricane Sun')
        ->and((bool) $row->ai_written)->toBeFalse()->and($row->summary)->toContain('Cities to strengthen: Saint George, UT (1 unplaced)');
    Notification::assertSentTo($this->owner, InAppNotification::class, fn ($n) => $n->type === 'network.insights' && str_contains($n->body, 'Saint George'));
    Artisan::call('t2t:performance'); // once per week
    expect(DB::table('group_insights')->count())->toBe(1);
    $this->actingAs($this->owner)->get('/app/group/solar-installers-network')->assertInertia(fn ($p) => $p->where('insights.summary', $row->summary));
});

it('[5] has AI write the summary from the figures, free for owners, when AI is on', function () {
    $s = app(Settings::class);
    $s->set('ai.driver', 'anthropic');
    $s->set('ai.anthropic.api_key', 'k', secret: true);
    foreach (['model' => 'm', 'input_price' => '1', 'output_price' => '1', 'monthly_cap' => '100'] as $k => $v) {
        $s->set("ai.anthropic.{$k}", $v);
    }
    $sent = [];
    Http::fake(['api.anthropic.com/*' => function ($req) use (&$sent) {
        $sent[] = $req->data();

        return Http::response(['content' => [['type' => 'text', 'text' => 'Look at Saint George first.']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    }]);
    app(GroupInsights::class)->run();
    expect(DB::table('group_insights')->value('summary'))->toBe('Look at Saint George first.')->and($sent[0]['messages'][0]['content'])->toContain('<figures>')
        ->and(DB::table('ai_usage_logs')->count())->toBe(0); // not charged to any team
});

it('[11][12] shows what members do with suggestions, why they dismiss them, and the with/without impact', function () {
    $r1 = ($this->refer)($this->a, 1);
    app(Referrals::class)->accept($r1->fresh(), $this->b->leader);
    $r2 = ($this->refer)($this->a, 1);
    app(Referrals::class)->accept($r2->fresh(), $this->b->leader);
    $this->actingAs($this->b->leader)->post("/app/referrals/{$r1->ulid}/assist/first_contact/use");
    $this->post("/app/referrals/{$r2->ulid}/assist/first_contact/dismiss", ['reason' => 'already_done']);
    app(Pipeline::class)->logCall($r1->fresh(), $this->b->leader, 'Called', null);
    $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin)->get('/admin/ai')->assertInertia(fn ($p) => $p->where('suggestions.0.key', 'first_contact')->where('suggestions.0.used', 1)->where('suggestions.0.dismissed', 1)
        ->where('suggestions.0.reasons.already_done', 1)->where('impact.withSuggestions.referrals', 1)->where('impact.without.referrals', 1)
        ->where('impact.withSuggestions.contactHours', fn ($h) => $h !== null)->where('impact.without.contactHours', null));
});
