<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Assist\CloseLikelihood;
use App\Domains\Referrals\Assist\NextActions;
use App\Domains\Referrals\Pipeline;
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
    foreach (['model' => 'claude-test', 'input_price' => '1', 'output_price' => '5', 'monthly_cap' => '100'] as $k => $v) {
        $s->set("ai.anthropic.{$k}", $v);
    }
    $this->reply = 'Hi! Quick update on Tom Brennan: we met on Tuesday and sent the quote. Approval is guaranteed. Call me on 435-555-0100. The referral fee will follow at closing. Thanks again!';
    $this->sent = [];
    Http::fake(['api.anthropic.com/*' => function ($req) {
        $this->sent[] = $req->data();

        return Http::response(['content' => [['type' => 'text', 'text' => $this->reply]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 50]]);
    }]);
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
    $sv = app(Seats::class);
    $this->mk = function (string $n, string $slug, int $c) use ($sv) {
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
        $g = Group::where('slug', $slug)->firstOrFail();
        $sv->approve($sv->apply($g, $t, $this->cities[$c], 'L', null), null);
        DB::table('team_licenses')->insert(['team_id' => $t->id, 'industry_id' => $g->industry_id, 'state_id' => DB::table('states')->value('id'), 'number' => 'L'.$t->id, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);

        return $t;
    };
    $this->a = ($this->mk)('Red Rock Solar', 'solar-installers-network', 0);
    $this->b = ($this->mk)('Hurricane Sun', 'solar-installers-network', 1);
    $this->n = 0;
    $this->refer = function (Team $from, string $slug, int $city, string $timing = 'Within 30 days') {
        $g = Group::where('slug', $slug)->firstOrFail();
        $ans = collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
            ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
                'choice' => in_array($timing, $f['options'], true) ? $timing : $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
            }])->all();
        $this->n++;

        return app(Referrals::class)->send($from->leader, $g, $this->cities[$city], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+143555501'.str_pad((string) $this->n, 2, '0', STR_PAD_LEFT)], true, null, true)['referral'];
    };
});

it('[1] suggests the next action from the referral’s deadlines, most urgent first', function () {
    $r = ($this->refer)($this->a, 'solar-installers-network', 1);
    expect(array_column(NextActions::for($r->fresh(), $this->b), 'key'))->toBe(['respond'])->and(NextActions::for($r->fresh(), $this->a))->toBe([]);
    app(Referrals::class)->accept($r->fresh(), $this->b->leader);
    expect(array_column(NextActions::for($r->fresh(), $this->b), 'key'))->toContain('first_contact');
    $this->travel(5)->days(); // contact overdue, update due
    $keys = array_column(NextActions::for($r->fresh(), $this->b), 'key');
    expect($keys[0])->toBe('first_contact')->and(NextActions::for($r->fresh(), $this->b)[0]['title'])->toBe('First contact is overdue');
    app(Pipeline::class)->logCall($r->fresh(), $this->b->leader, 'Spoke with Tom', null);
    $this->travel(15)->days();
    expect(array_column(NextActions::for($r->fresh(), $this->b), 'key'))->toContain('stalled');
});

it('[4][7] scores close likelihood with stated reasons that move it the right way', function () {
    $r = ($this->refer)($this->a, 'solar-installers-network', 1);
    expect(CloseLikelihood::for($r->fresh()))->toBeNull(); // not accepted yet
    app(Referrals::class)->accept($r->fresh(), $this->b->leader);
    $before = CloseLikelihood::for($r->fresh());
    expect($before['reasons'][0]['text'])->toContain('close about 30%');
    $this->travel(3)->days();
    $late = CloseLikelihood::for($r->fresh());
    expect($late['score'])->toBeLessThan($before['score'])->and(collect($late['reasons'])->pluck('text'))->toContain('The client hasn’t been contacted yet');
    app(Pipeline::class)->logCall($r->fresh(), $this->b->leader, 'Spoke', null);
    $stages = array_column(app(Pipeline::class)->stages($r->fresh()), 'key');
    app(Pipeline::class)->move($r->fresh(), $this->b->leader, $stages[count($stages) - 3], null, []);
    expect(CloseLikelihood::for($r->fresh())['score'])->toBeGreaterThan($late['score']);
    expect(collect(CloseLikelihood::for($r->fresh())['reasons'])->every(fn ($x) => is_string($x['text']) && is_int($x['effect'])))->toBeTrue();
});

it('[9] shows the score to the receiving team only and keeps it out of everything else', function () {
    $r = ($this->refer)($this->a, 'solar-installers-network', 1);
    app(Referrals::class)->accept($r->fresh(), $this->b->leader);
    $this->actingAs($this->b->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('assist.score.score', fn ($s) => is_int($s)));
    $this->actingAs($this->a->leader)->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('assist.score', null)->where('assist.actions', []));
    // Nothing that ranks or challenges teams reads the score.
    foreach (['app/Domains/Community', 'app/Domains/Network/Seats', 'app/Domains/Referrals/Performance'] as $dir) {
        expect(collect(Symfony\Component\Finder\Finder::create()->files()->in(base_path($dir))->name('*.php'))->contains(fn ($f) => str_contains((string) file_get_contents($f->getRealPath()), 'CloseLikelihood') || str_contains((string) file_get_contents($f->getRealPath()), 'referral_scores')))->toBeFalse();
    }
});

it('[6][10] uses, dismisses (with a reason) or snoozes a suggestion in one tap, with a daily cap', function () {
    $r = ($this->refer)($this->a, 'solar-installers-network', 1);
    app(Referrals::class)->accept($r->fresh(), $this->b->leader);
    $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/assist/first_contact/snooze")->assertSessionHas('status', 'suggestion-snooze');
    expect(array_column(NextActions::for($r->fresh(), $this->b), 'key'))->not->toContain('first_contact');
    $this->travel(25)->hours();
    expect(array_column(NextActions::for($r->fresh(), $this->b), 'key'))->toContain('first_contact');
    $this->post("/app/referrals/{$r->ulid}/assist/first_contact/dismiss", ['reason' => 'already_done'])->assertSessionHas('status', 'suggestion-dismiss');
    $this->post("/app/referrals/{$r->ulid}/assist/update/dismiss", ['reason' => 'nonsense'])->assertSessionHasErrors('reason');
    expect(array_column(NextActions::for($r->fresh(), $this->b), 'key'))->not->toContain('first_contact')
        ->and(DB::table('ai_suggestions')->where('status', 'dismissed')->value('reason'))->toBe('already_done');
    $this->actingAs(User::factory()->create())->post("/app/referrals/{$r->ulid}/assist/update/use")->assertNotFound();
    $this->actingAs($this->b->leader)->get('/app/pipeline')->assertInertia(fn ($p) => $p->where('cards.0.score', fn ($s) => is_int($s)));
    foreach (range(1, NextActions::DAILY_CAP + 3) as $i) {
        ($this->refer)($this->a, 'solar-installers-network', 1);
    }
    expect(NextActions::forTeam($this->b))->toHaveCount(NextActions::DAILY_CAP);
});

it('[2][8] drafts follow-ups for one credit, never sent automatically, without contacts, promises or (in Tier B) fees', function () {
    $r = ($this->refer)($this->a, 'solar-installers-network', 1);
    $this->actingAs($this->b->leader)->post("/app/referrals/{$r->ulid}/draft", ['to' => 'client', 'tone' => 'friendly'])->assertForbidden(); // not accepted yet
    app(Referrals::class)->accept($r->fresh(), $this->b->leader);
    $messages = DB::table('referral_messages')->count();
    $this->post("/app/referrals/{$r->ulid}/draft", ['to' => 'partner', 'tone' => 'brief'])->assertSessionHas('draft', fn ($d) => str_contains($d['text'], 'sent the quote')
        && ! str_contains($d['text'], 'Brennan') && ! str_contains($d['text'], '555-0100') && ! str_contains($d['text'], 'guaranteed') && $d['to'] === 'partner');
    expect(DB::table('referral_messages')->count())->toBe($messages)->and($this->sent[0]['system'])->toContain('Tone: brief')
        ->and((int) DB::table('ai_usage_logs')->where('feature', 'follow_up_draft')->value('credits'))->toBe(1);
    $this->actingAs($this->a->leader)->post("/app/referrals/{$r->ulid}/draft", ['to' => 'client', 'tone' => 'brief'])->assertForbidden(); // only the receiver writes to the client
    $this->post("/app/referrals/{$r->ulid}/draft", ['to' => 'partner', 'tone' => 'shouty'])->assertSessionHasErrors('tone');
    // Tier B: fee sentences removed even when the model writes them.
    $m = Group::where('slug', 'mortgage-lenders-circle')->firstOrFail();
    $x = ($this->mk)('Lakeside Lending', 'mortgage-lenders-circle', 0);
    $y = ($this->mk)('Summit Loans', 'mortgage-lenders-circle', 1);
    $rb = ($this->refer)($x, 'mortgage-lenders-circle', 1);
    app(Referrals::class)->accept($rb->fresh(), $y->leader);
    $this->actingAs($y->leader)->post("/app/referrals/{$rb->ulid}/draft", ['to' => 'partner', 'tone' => 'professional'])->assertSessionHas('draft', fn ($d) => ! str_contains($d['text'], 'fee') && str_contains($d['text'], 'Thanks again'));
});
