<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Network\Training\Training;
use App\Domains\Platform\Modules\Modules;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Intake\IntakeEmails;
use App\Domains\Referrals\LeadSummary;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
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
    $s = app(Settings::class);
    $s->set('ai.driver', 'anthropic');
    $s->set('ai.anthropic.api_key', 'k', secret: true);
    foreach (['model' => 'm', 'input_price' => '1', 'output_price' => '5', 'monthly_cap' => '100'] as $k => $v) {
        $s->set("ai.anthropic.{$k}", $v);
    }
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'A summary.']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);
    $this->off = fn (string $k) => $s->set('modules.'.$k, '0');
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $this->sv = app(Seats::class);
    $this->member = function (string $slug, int $city) {
        $t = Team::forLeader(User::factory()->create());
        $g = Group::where('slug', $slug)->firstOrFail();
        $this->sv->approve($this->sv->apply($g, $t, $this->cities[$city], 'L', null), null);
        DB::table('team_licenses')->insert(['team_id' => $t->id, 'industry_id' => $g->industry_id, 'state_id' => DB::table('states')->value('id'), 'number' => 'L'.$t->id, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);

        return $t;
    };
    $this->a = ($this->member)('solar-installers-network', 2);
});

it('has every available switch checked somewhere in the code', function () {
    $code = collect(Symfony\Component\Finder\Finder::create()->files()->in([app_path(), base_path('routes')])->name('*.php'))
        ->reject(fn ($f) => str_ends_with($f->getRealPath(), 'Modules.php'))->map(fn ($f) => (string) file_get_contents($f->getRealPath()))->implode("\n");
    $unchecked = collect(Modules::ALL)->reject(fn ($m) => ($m['available'] ?? true) === false)
        ->keys()->reject(fn ($k) => str_contains($code, "enabled('{$k}')") || str_contains($code, "module:{$k}"))->values()->all();
    expect($unchecked)->toBe([]);
});

it('community, events and cross-industry: pages answer 404 and their jobs do nothing when off', function () {
    $this->actingAs($this->a->leader);
    $this->get('/app/feed')->assertRedirect();
    foreach (['community', 'events', 'cross_industry'] as $k) {
        ($this->off)($k);
    }
    foreach (['/app/feed', '/app/directory', '/app/badges', '/app/leaderboards', '/app/events', '/app/introduce'] as $url) {
        $this->get($url)->assertNotFound();
    }
    $this->get('/app')->assertInertia(fn ($p) => $p->where('modules.community', false)->where('modules.events', false));
    $this->a->leader->forceFill(['feed_digest' => 'daily'])->save();
    Artisan::call('t2t:feed:digest');
    Notification::assertNotSentTo($this->a->leader, App\Domains\Messaging\InAppNotification::class, fn ($n) => $n->type === 'feed.digest');
});

it('training off: no training requirement anywhere, Tier B included, and its pages are gone', function () {
    $lender = ($this->member)('mortgage-lenders-circle', 0);
    $mortgage = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail();
    $this->travel(30)->days(); // past the 14-day grace
    expect(app(Training::class)->blocks($lender->leader, $mortgage))->toBeTrue();
    ($this->off)('training');
    expect(app(Training::class)->blocks($lender->leader, $mortgage))->toBeFalse()->and(app(Modules::class)->enabled('ai_videos'))->toBeFalse();
    $this->actingAs($lender->leader)->get('/app/training')->assertNotFound();
});

it('each AI module switches off on its own, leaving the others working', function () {
    modulesOn('ai_summaries', 'ai_drafts', 'ai_intake', 'chat_bot');
    $b = ($this->member)('solar-installers-network', 1);
    $g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $ans = collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
        ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        }])->all();
    $r = app(Referrals::class)->send($this->a->leader, $g, $this->cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100'], true, null, true)['referral'];
    ($this->off)('ai_summaries');
    expect(app(LeadSummary::class)->for($r->fresh(), $b->leader))->toBeNull();
    app(Referrals::class)->accept($r->fresh(), $b->leader);
    $this->actingAs($b->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('assist.drafts', true)); // drafts still on
    ($this->off)('ai_drafts');
    $this->post("/app/referrals/{$r->ulid}/draft", ['to' => 'partner', 'tone' => 'brief'])->assertNotFound();
    $this->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('assist.drafts', false));
    $this->get('/app/drafts')->assertOk(); // intake still on
    ($this->off)('ai_intake');
    $this->get('/app/drafts')->assertNotFound();
    expect(app(IntakeEmails::class)->handle('intake+1.x@in.example', 'a@b.c', 's', 't', 'm-1'))->toBe('disabled');
    ($this->off)('chat_bot');
    expect(app(Modules::class)->enabled('chat_bot'))->toBeFalse()->and(app(Modules::class)->enabled('community'))->toBeTrue();
});

it('carries the old single AI switch over to each AI module on upgrade', function () {
    DB::table('settings')->where('key', 'like', 'modules.%')->delete();
    DB::table('settings')->insert(['key' => 'modules.ai', 'value' => '1', 'is_secret' => false, 'created_at' => now(), 'updated_at' => now()]);
    (require database_path('migrations/2027_01_03_000000_split_ai_module.php'))->up();
    $keys = ['modules.ai_summaries', 'modules.ai_drafts', 'modules.ai_intake', 'modules.chat_bot', 'modules.ai_insights'];
    expect(DB::table('settings')->whereIn('key', $keys)->pluck('value', 'key')->all())
        ->toEqualCanonicalizing(['modules.ai_summaries' => '1', 'modules.ai_drafts' => '1', 'modules.ai_intake' => '1', 'modules.chat_bot' => '1', 'modules.ai_insights' => '1'])
        ->and(DB::table('settings')->where('key', 'modules.ai')->exists())->toBeFalse();
});
