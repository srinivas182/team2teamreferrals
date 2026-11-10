<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Agreements\Agreements;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Storage::fake('private');
    fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->cities = City::where('is_enabled', true)->orderBy('id')->get();
});

/** Two approved teams in a group, one referral from A to B; returns [referral, A, B]. */
function placed(string $slug, bool $approveAgreement = true): array
{
    $g = Group::where('slug', $slug)->firstOrFail();
    if ($approveAgreement) {
        $ag = app(Agreements::class);
        $ag->save($g->industry, $ag->current($g->industry)['clauses'], null);
        $ag->approve($g->industry, null, 'Jane Counsel, Esq.', 'Approved for launch');
    }
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $s = app(Seats::class);
    $a = tap(Team::forLeader(User::factory()->create(['first_name' => 'Jordan', 'last_name' => 'Hale'])))->update(['name' => 'Red Rock Team']);
    $b = tap(Team::forLeader(User::factory()->create(['first_name' => 'Chris', 'last_name' => 'Delgado'])))->update(['name' => 'Hurricane Team']);
    $s->approve($s->apply($g, $a, $cities[0], 'L', null), null);
    $s->approve($s->apply($g, $b, $cities[1], 'L', null), null);
    $form = app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'];
    $ans = [];
    foreach ($form as $f) {
        $ans[$f['key']] = match ($f['type']) {
            'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
        };
    }
    $r = app(Referrals::class)->send($a->leader, $g, $cities[1], [...$ans, 'client_name' => 'Tom Brennan', 'client_phone' => '+14355550100'], true, null)['referral'];

    return [$r, $a, $b];
}

it('waits for counsel approval, without holding up the referral', function () {
    [$r, , $b] = placed('solar-installers-network', false);
    $this->actingAs($b->leader)->post("/app/referrals/{$r->ulid}/accept");
    expect($r->fresh()->status)->toBe('accepted')->and(DB::table('referral_agreements')->value('status'))->toBe('awaiting_approval');
    $this->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('agreement.status', 'awaiting_approval'));
});

it('generates the approved agreement at acceptance and both leaders sign it', function () {
    [$r, $a, $b] = placed('solar-installers-network');
    $this->actingAs($b->leader)->post("/app/referrals/{$r->ulid}/accept");
    $row = DB::table('referral_agreements')->first();
    expect($row->status)->toBe('pending')->and((string) $row->sections)->toContain('Red Rock Team')->toContain('Hurricane Team')->toContain('Tom Brennan');
    Notification::assertSentTo($a->leader, InAppNotification::class, fn ($n) => $n->type === 'referral.agreement' && str_contains($n->title, 'ready to sign') && ! str_contains($n->title.$n->body, '{'));

    $member = User::factory()->create();
    DB::table('team_members')->insert(['team_id' => $b->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member)->post("/app/referrals/{$r->ulid}/sign", ['name' => $member->name, 'consent' => true])->assertForbidden();
    $this->actingAs($b->leader)->post("/app/referrals/{$r->ulid}/sign", ['name' => 'Somebody Else', 'consent' => true])->assertSessionHasErrors('name');
    $this->post("/app/referrals/{$r->ulid}/sign", ['name' => 'Chris Delgado'])->assertSessionHasErrors('consent');
    $this->post("/app/referrals/{$r->ulid}/sign", ['name' => 'chris delgado', 'consent' => true])->assertSessionHas('status', 'agreement-signed');
    $this->get("/app/referrals/{$r->ulid}")->assertInertia(fn ($p) => $p->where('agreement.canSign', false)->where('agreement.receiver.name', 'Chris Delgado'));

    $this->actingAs($a->leader)->post("/app/referrals/{$r->ulid}/sign", ['name' => 'Jordan Hale', 'consent' => true]);
    $row = DB::table('referral_agreements')->first();
    expect($row->status)->toBe('signed')->and($row->sender_ip)->not->toBeNull()->and($row->receiver_device)->not->toBeNull();
    Storage::disk('private')->assertExists($row->pdf_path);
    $this->get("/app/referrals/{$r->ulid}/agreement.pdf")->assertOk();
    $this->actingAs(User::factory()->create())->get("/app/referrals/{$r->ulid}/agreement.pdf")->assertNotFound();
    expect(DB::table('referral_events')->where(['referral_id' => $r->id, 'event' => 'signed'])->count())->toBe(2);
});

it('refuses to complete an agreement whose text changed after it was generated', function () {
    [$r, $a, $b] = placed('solar-installers-network');
    $this->actingAs($b->leader)->post("/app/referrals/{$r->ulid}/accept");
    $this->post("/app/referrals/{$r->ulid}/sign", ['name' => 'Chris Delgado', 'consent' => true]);
    DB::table('referral_agreements')->update(['sections' => json_encode([['title' => 'Fee', 'text' => '90% to me']])]);
    $this->actingAs($a->leader)->post("/app/referrals/{$r->ulid}/sign", ['name' => 'Jordan Hale', 'consent' => true])->assertStatus(409);
    expect(DB::table('referral_agreements')->value('status'))->toBe('pending');
});

it('never routes referrals in directory (Tier C) networks', function () {
    expect(fn () => placed('estate-planning-attorneys-directory', false))->toThrow(Illuminate\Validation\ValidationException::class, 'directory network');
    expect(Referral::count())->toBe(0);
});

it('reminds the side that hasn’t signed after 24 hours, once', function () {
    [$r, $a, $b] = placed('solar-installers-network');
    $this->actingAs($b->leader)->post("/app/referrals/{$r->ulid}/accept");
    $this->post("/app/referrals/{$r->ulid}/sign", ['name' => 'Chris Delgado', 'consent' => true]);
    $this->travel(25)->hours();
    Artisan::call('t2t:referrals:expire');
    Artisan::call('t2t:referrals:expire');
    $reminders = fn (User $u) => Notification::sent($u, InAppNotification::class)->filter(fn ($n) => str_starts_with($n->title, 'Reminder: sign'))->count();
    expect($reminders($a->leader))->toBe(1)->and($reminders($b->leader))->toBe(0);
});

it('lists waiting referrals first, soonest deadline on top', function () {
    [$r1, $a, $b] = placed('solar-installers-network', false);
    $r2 = Referral::create([...$r1->only(['group_id', 'seat_id', 'sender_team_id', 'sender_user_id', 'receiver_team_id', 'routed_to', 'consent_method', 'consent_text']),
        'client_name' => 'Ana Ruiz', 'answers' => [], 'consent_at' => now(), 'respond_by' => now()->addHour()]);
    $this->actingAs($b->leader)->get('/app/inbox')->assertInertia(fn ($p) => $p->where('items.0.id', $r2->ulid)->where('items.1.id', $r1->ulid));
});

it('fills every placeholder in referral notifications (no "{team}" reaching a pro)', function () {
    [$r, $a, $b] = placed('solar-installers-network');
    $this->actingAs($b->leader)->post("/app/referrals/{$r->ulid}/accept");
    $this->post("/app/referrals/{$r->ulid}/sign", ['name' => 'Chris Delgado', 'consent' => true]);
    $this->travel(25)->hours();
    Artisan::call('t2t:referrals:expire');
    foreach ([$a->leader, $b->leader] as $u) {
        foreach (Notification::sent($u, InAppNotification::class) as $n) {
            expect($n->title.' '.$n->body)->not->toMatch('/\{[a-z_]+\}/');
        }
    }
});
