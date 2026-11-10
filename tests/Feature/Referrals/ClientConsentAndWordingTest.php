<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Referrals\Referral;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    $this->sms = fakeSms();
    // Only texts to clients (the fake also captures pros' own notification texts).
    $this->toClients = fn () => array_values(array_filter($this->sms->sent, fn ($m) => in_array($m['to'], ['+14355550100', '+14355550199'], true)));
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->g = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $this->city = $cities[1];
    $s = app(Seats::class);
    $mk = fn (string $n) => tap(Team::forLeader(User::factory()->create()))->update(['name' => $n]);
    [$this->a, $this->b] = [$mk('Red Rock Solar'), $mk('Hurricane Sun')];
    $s->approve($s->apply($this->g, $this->a, $cities[0], 'L', null), null);
    $s->approve($s->apply($this->g, $this->b, $cities[1], 'L', null), null);
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('super_admin');
});

function send2($test, array $over = [])
{
    $ans = [];
    foreach (app(IndustryTemplates::class)->resolve('form', $test->g->industry, $test->g->id)['body'] as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }

    return $test->actingAs($test->a->leader)->post('/app/send', ['group' => 'solar-installers-network', 'city_id' => $test->city->id, 'consent' => true, 'answers' => [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100'], ...$over]);
}

it('keeps client texting off until approved, with a note for the record', function () {
    send2($this, ['text_client' => true]);
    expect(($this->toClients)())->toBe([]);
    $this->actingAs($this->admin)->post('/admin/industries/solar/client-texting', ['approved' => true])->assertSessionHasErrors('note');
    $this->post('/admin/industries/solar/client-texting', ['approved' => true, 'note' => 'Approved by J. Counsel, 11/10/2026'])->assertSessionHas('status', 'texting-approved');
    expect(DB::table('activity_log')->where('event', 'client-texting')->exists())->toBeTrue();
});

it('texts the client once to confirm, never to opted-out numbers', function () {
    $this->g->industry->forceFill(['client_texting_approved' => true])->save();
    send2($this, ['text_client' => true]);
    expect(($this->toClients)())->toHaveCount(1)->and(($this->toClients)()[0]['to'])->toBe('+14355550100')
        ->and(($this->toClients)()[0]['message'])->toContain('Red Rock Solar')->toContain('/c/')->toContain('Reply STOP');
    $r = Referral::firstOrFail();
    $this->post("/app/referrals/{$r->ulid}/ask-client")->assertStatus(422);
    expect(($this->toClients)())->toHaveCount(1);

    DB::table('sms_opt_outs')->insert(['phone' => '+14355550199', 'opted_out_at' => now()]);
    send2($this, ['text_client' => true, 'confirm_duplicate' => true, 'answers' => [...Referral::firstOrFail()->answers, 'client_name' => 'Ana Ruiz', 'client_phone' => '+14355550199']]);
    expect(($this->toClients)())->toHaveCount(1);
});

it('records client-confirmed consent by link, then texts the status link and tells the sender', function () {
    $this->g->industry->forceFill(['client_texting_approved' => true])->save();
    send2($this, ['text_client' => true]);
    $path = parse_url(preg_replace('/.*(https?:\/\/\S+\/c\/\S+).*/', '$1', ($this->toClients)()[0]['message']), PHP_URL_PATH);
    auth()->logout();

    $this->get($path)->assertOk()->assertInertia(fn ($p) => $p->component('ClientConsent')->where('request.firstName', 'Tom'));
    $r = Referral::firstOrFail();
    expect($r->client_confirmed_at)->toBeNull();
    $this->post($path)->assertRedirect('/r/'.$r->client_token);
    $r->refresh();
    $texts = ($this->toClients)();
    expect($r->consent_method)->toBe('client_confirmed')->and($r->client_confirmed_at)->not->toBeNull()
        ->and(end($texts)['message'])->toContain('/r/'.$r->client_token);
    Notification::assertSentTo($this->a->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.message' && str_contains($n->title, 'confirmed by text') && ! preg_match('/\{[a-z_]+\}/', $n->title.$n->body));
    $this->get($path)->assertInertia(fn ($p) => $p->where('request', null));
});

it('records client-confirmed consent when the client replies YES', function () {
    $this->g->industry->forceFill(['client_texting_approved' => true])->save();
    send2($this, ['text_client' => true]);
    app(Settings::class)->set('sms.twilio.token', 'tok', secret: true);
    $url = route('webhooks.twilio.inbound');
    $params = ['Body' => 'yes', 'From' => '+14355550100'];
    ksort($params);
    $data = $url.implode('', array_map(fn ($k, $v) => $k.$v, array_keys($params), $params));
    $this->withHeader('X-Twilio-Signature', base64_encode(hash_hmac('sha1', $data, 'tok', true)))->post($url, $params)->assertOk();
    expect(Referral::firstOrFail()->consent_method)->toBe('client_confirmed')
        ->and(DB::table('referral_events')->where('event', 'client_confirmed')->value('meta'))->toContain('reply');
});

it('lets group owners reword referral messages, refusing placeholders that can’t be filled', function () {
    $owner = User::factory()->create();
    $this->g->owners()->attach($owner->id);
    $this->actingAs($owner)->post('/app/group/solar-installers-network/messages', ['type' => 'referral.waiting', 'subject' => 'Solar lead for you, {first_name}: {client}', 'body' => 'Price is {price}'])->assertSessionHasErrors('body');
    $this->post('/app/group/solar-installers-network/messages', ['type' => 'referral.waiting', 'subject' => 'Solar lead for you, {first_name}: {client}', 'body' => '{team} sent you {client} in {city}. Respond within {hours} hours.'])->assertSessionHas('status', 'wording-saved');
    $this->actingAs(User::factory()->create())->get('/app/group/solar-installers-network/messages')->assertForbidden();

    send2($this);
    Notification::assertSentTo($this->b->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.waiting' && str_starts_with($n->title, 'Solar lead for you') && str_contains($n->title, 'Tom B.') && str_contains($n->body, 'Respond within 24 hours'));

    $this->actingAs($owner)->delete('/app/group/solar-installers-network/messages/referral.waiting')->assertSessionHas('status', 'wording-reset');
    $this->get('/app/group/solar-installers-network/messages')->assertInertia(fn ($p) => $p->where('messages.0.custom', false));
});
