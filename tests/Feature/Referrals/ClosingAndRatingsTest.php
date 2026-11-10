<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Companies\Company;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\ClientRatingRequest;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referrals;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    [$this->a, $this->b] = [$mk('Red Rock Solar'), $mk('Hurricane Sun')];
    $s->approve($s->apply($this->g, $this->a, $cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $cities[1], 'L', null), null);
    $this->owner = User::factory()->create();
    $this->g->owners()->attach($this->owner->id);
    $ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $this->g->industry, $this->g->id)['body'] as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $this->r = app(Referrals::class)->send($this->a->leader, $this->g, $cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100', 'client_email' => 'tom@example.com'], true, null)['referral'];
    app(Referrals::class)->accept($this->r->fresh(), $this->b->leader);
    $this->url = "/app/referrals/{$this->r->ulid}";
    $this->won = fn () => app(Pipeline::class)->move($this->r->fresh(), $this->b->leader, 'won', null, ['closing_date' => '2026-12-20', 'closing_value' => 31500]);
});

it('asks the sender to confirm a Won close, then asks the client one question', function () {
    ($this->won)();
    expect($this->r->fresh()->close_status)->toBe('awaiting');
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'please confirm') && str_contains($n->title, '$31,500.00'));
    $this->actingAs($this->b->leader)->post("{$this->url}/close/confirm")->assertForbidden();
    $this->actingAs($this->a->leader)->post("{$this->url}/close/confirm")->assertSessionHas('status', 'close-confirmed');
    expect($this->r->fresh()->close_status)->toBe('confirmed');
    $link = Notification::sent(new AnonymousNotifiable, ClientRatingRequest::class)->first()->link;
    $path = parse_url($link, PHP_URL_PATH);

    auth()->logout();
    $this->get($path)->assertInertia(fn ($p) => $p->component('ClientRate')->where('pro', 'Hurricane Sun'));
    $this->post($path, ['stars' => 5, 'comment' => 'Fantastic install'])->assertSessionHas('status', 'client-rated');
    expect(DB::table('referral_ratings')->where(['rater' => 'client', 'rated_team_id' => $this->b->id, 'stars' => 5])->exists())->toBeTrue();
    $this->get($path)->assertInertia(fn ($p) => $p->where('token', null));
});

it('lets the sender dispute a close, and confirms undisputed closes after seven days', function () {
    ($this->won)();
    $this->actingAs($this->a->leader)->post("{$this->url}/close/dispute", ['reason' => 'Tom says the job was cancelled'])->assertSessionHas('status', 'close-disputed');
    expect($this->r->fresh()->close_status)->toBe('disputed');
    Notification::assertSentTo($this->owner, InAppNotification::class, fn ($n) => str_contains($n->title, 'Close disputed'));

    app(Pipeline::class)->move($this->r->fresh(), $this->b->leader, 'proposal', null);
    expect($this->r->fresh()->close_status)->toBeNull();
    ($this->won)();
    $this->travel(8)->days();
    Artisan::call('t2t:performance');
    expect($this->r->fresh()->close_status)->toBe('auto_confirmed');
});

it('rates both ways after closing, editable for seven days, never within one company', function () {
    $this->actingAs($this->a->leader)->post("{$this->url}/rate", ['stars' => 5])->assertStatus(422);
    ($this->won)();
    $this->post("{$this->url}/rate", ['stars' => 4, 'aspects' => ['communication' => 5, 'speed' => 4, 'outcome' => 4, 'bogus' => 1], 'comment' => 'Quick and clear'])->assertSessionHas('status', 'rated');
    $this->post("{$this->url}/rate", ['stars' => 5])->assertSessionHas('status', 'rated');
    $row = DB::table('referral_ratings')->where('rater', 'sender')->first();
    expect((int) $row->stars)->toBe(5)->and((int) $row->rated_team_id)->toBe($this->b->id)->and(json_decode((string) $row->aspects, true))->not->toHaveKey('bogus');
    $this->travel(8)->days();
    $this->post("{$this->url}/rate", ['stars' => 1])->assertSessionHasErrors('stars');

    $this->actingAs($this->b->leader)->post("{$this->url}/rate", ['stars' => 5, 'aspects' => ['quality' => 5]])->assertSessionHas('status', 'rated');
    $this->actingAs(User::factory()->create())->post("{$this->url}/rate", ['stars' => 5])->assertForbidden();
});

it('stops teams in the same company rating each other', function () {
    $co = Company::create(['name' => 'Sun Holdings', 'admin_id' => $this->a->leader_id]);
    $this->a->forceFill(['company_id' => $co->id])->save();
    $this->b->forceFill(['company_id' => $co->id])->save();
    ($this->won)();
    $this->actingAs($this->a->leader)->post("{$this->url}/rate", ['stars' => 5])->assertSessionHasErrors('stars');
});

it('lets the receiver say thank you, shown publicly only if the sender agrees', function () {
    ($this->won)();
    $this->actingAs($this->a->leader)->post("{$this->url}/thanks", ['note' => 'x'])->assertForbidden();
    $this->actingAs($this->b->leader)->post("{$this->url}/thanks", ['note' => 'Thanks for trusting us with Tom!'])->assertSessionHas('status', 'thanked');
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => str_contains($n->title, 'says thank you'));
    expect($this->r->fresh()->thanks_public)->toBeFalse();
    $this->actingAs($this->a->leader)->post("{$this->url}/thanks/public", ['public' => true])->assertSessionHas('status', 'thanks-public');
    expect($this->r->fresh()->thanks_public)->toBeTrue();
});
