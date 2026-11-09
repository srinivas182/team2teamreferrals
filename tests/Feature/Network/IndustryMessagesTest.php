<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Models\NotificationTemplate;
use App\Domains\Messaging\Notifier;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Messaging\Templates;
use App\Domains\Network\Industries\Http\AdminIndustryMessagesController;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use Illuminate\Support\Facades\Notification;

function msgIndustry(string $tier = 'A', array $terms = []): Industry
{
    return Industry::create(['slug' => $tier === 'A' ? 'solar' : 'mortgage', 'name' => $tier === 'A' ? 'Solar' : 'Mortgage lending', 'family' => $tier === 'A' ? 'home' : 'money', 'tier' => Tier::from($tier), 'territory_unit' => 'city',
        'terminology' => ['pro' => $tier === 'A' ? 'installer' : 'loan officer', 'pros' => $tier === 'A' ? 'installers' : 'loan officers', 'client' => $tier === 'A' ? 'homeowner' : 'borrower', 'clients' => $tier === 'A' ? 'homeowners' : 'borrowers', ...$terms]]);
}

function msgAdmin(): User
{
    $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $u->assignRole('staff');
    $u->givePermissionTo('industries.manage');

    return $u;
}

beforeEach(function () {
    $this->sms = fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    Notification::fake();
});

$data = ['client' => 'Tom Brennan', 'city' => 'Austin', 'team' => 'SunPath Solar', 'hours' => 24];

it('gives restricted industries tier-aware terms', function () {
    $b = msgIndustry('B');
    expect(msgIndustry('A')->terms())->toMatchArray(['referral' => 'referral', 'fee' => 'referral fee'])
        ->and($b->terms())->toMatchArray(['referral' => 'introduction', 'pro' => 'loan officer'])
        ->and($b->mergeTerms())->toMatchArray(['client_term' => 'borrower', 'referral_term' => 'introduction']);
});

it('fills every message with the industry’s own words', function () use ($data) {
    $user = User::factory()->create();
    app(Notifier::class)->send($user, 'referral.waiting', $data, null, msgIndustry('B'));
    expect($this->sms->sent[0]['message'])->toBe('New t2t introduction: Tom Brennan in Austin. Respond within 24h: ');
    Notification::assertSentTo($user, App\Domains\Messaging\Mail\PlatformMessage::class, fn ($n) => $n->items[0]['title'] === 'New introduction: Tom Brennan in Austin' && str_contains($n->items[0]['body'], 'sent you a borrower'));
});

it('keeps the platform wording when no industry is given', function () use ($data) {
    app(Notifier::class)->send(User::factory()->create(), 'referral.waiting', $data);
    expect($this->sms->sent[0]['message'])->toStartWith('New t2t referral: Tom Brennan in Austin');
});

it('resolves industry wording first, then platform, then the default', function () use ($data) {
    $ind = msgIndustry('A');
    $t = app(Templates::class);
    expect($t->wording('referral.waiting', 'sms', $ind->id)['source'])->toBe('default');
    $t->save('referral.waiting', 'sms', null, 'Platform: {client}', null);
    expect($t->wording('referral.waiting', 'sms', $ind->id))->toMatchArray(['source' => 'platform', 'body' => 'Platform: {client}']);
    $t->save('referral.waiting', 'sms', null, 'Solar: {client} needs an {pro}', null, $ind->id);
    expect($t->wording('referral.waiting', 'sms', $ind->id))->toMatchArray(['source' => 'industry'])
        ->and($t->wording('referral.waiting', 'sms')['body'])->toBe('Platform: {client}');

    app(Notifier::class)->send(User::factory()->create(), 'referral.waiting', $data, null, $ind);
    expect(end($this->sms->sent)['message'])->toBe('Solar: Tom Brennan needs an installer');

    $t->reset('referral.waiting', 'sms', $ind->id);
    expect($t->wording('referral.waiting', 'sms', $ind->id)['source'])->toBe('platform');
});

it('keeps industry and platform version numbers separate', function () {
    $ind = msgIndustry('A');
    $t = app(Templates::class);
    $t->save('referral.accepted', 'mail', 'P', 'p1', null);
    $t->save('referral.accepted', 'mail', 'P', 'p2', null);
    expect($t->save('referral.accepted', 'mail', 'S', 's1', null, $ind->id)->version)->toBe(1);
    $v1 = NotificationTemplate::where(['industry_id' => $ind->id, 'version' => 1])->firstOrFail();
    $t->save('referral.accepted', 'mail', 'S', 's2', null, $ind->id);
    $t->activate($v1);
    expect($t->wording('referral.accepted', 'mail', $ind->id)['body'])->toBe('s1')->and($t->wording('referral.accepted', 'mail')['body'])->toBe('p2');
});

it('edits, restores and resets an industry’s messages from the builder', function () {
    $ind = msgIndustry('A');
    $this->actingAs(msgAdmin())->get("/admin/industries/{$ind->slug}/messages?type=referral.waiting")->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/industries/Messages')->has('channels', 3)->where('channels.0.current.source', 'default')->where('industry.terms.pro', 'installer'));
    $this->post("/admin/industries/{$ind->slug}/messages", ['type' => 'referral.waiting', 'channel' => 'sms', 'body' => str_repeat('x', 321)])->assertSessionHasErrors('body');
    $this->post("/admin/industries/{$ind->slug}/messages", ['type' => 'referral.waiting', 'channel' => 'sms', 'body' => 'Solar lead {client}'])->assertSessionHas('status', 'template-saved');
    $this->post("/admin/industries/{$ind->slug}/messages", ['type' => 'referral.waiting', 'channel' => 'push', 'body' => 'x'])->assertSessionHasErrors('channel');
    $this->get("/admin/industries/{$ind->slug}/messages")->assertInertia(fn ($p) => $p->where('types.0.overridden', true));

    $other = msgIndustry('B');
    $tpl = NotificationTemplate::where('industry_id', $ind->id)->firstOrFail();
    $this->post("/admin/industries/{$other->slug}/messages/{$tpl->id}/activate")->assertNotFound();

    $this->post("/admin/industries/{$ind->slug}/messages/reset", ['type' => 'referral.waiting', 'channel' => 'sms'])->assertSessionHas('status', 'industry-message-reset');
    expect(app(Templates::class)->wording('referral.waiting', 'sms', $ind->id)['source'])->toBe('default');
});

it('saves the new referral and fee terms', function () {
    $ind = msgIndustry('A');
    $this->actingAs(msgAdmin())->put("/admin/industries/{$ind->slug}", [
        'name' => 'Solar', 'family' => 'home', 'tier' => 'A', 'territory_unit' => 'city', 'icon' => 'Sun', 'description' => null, 'license_required' => true,
        'terminology' => ['pro' => 'installer', 'pros' => 'installers', 'client' => 'homeowner', 'clients' => 'homeowners', 'referral' => 'lead', 'fee' => 'finder’s fee'],
    ])->assertSessionHasNoErrors();
    expect($ind->fresh()->terms())->toMatchArray(['referral' => 'lead', 'fee' => 'finder’s fee']);
});

it('puts every message, as members read it, into the counsel review pack', function () {
    $ind = msgIndustry('B');
    $pack = AdminIndustryMessagesController::pack($ind, app(Templates::class));
    $waiting = collect($pack)->first(fn ($m) => $m['label'] === 'New referral waiting for you' && $m['channel'] === 'Text');
    expect($waiting['body'])->toContain('introduction')->and($waiting['source'])->toBe('default')
        ->and(collect($pack)->pluck('channel')->unique()->sort()->values()->all())->toBe(['Email', 'In-app', 'Text']);
});
