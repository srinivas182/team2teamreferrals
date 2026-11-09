<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Models\IndustryLicensing;
use App\Domains\Network\Licenses\Licenses;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Locations\Models\State;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

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
    $this->tx = State::where('code', 'TX')->firstOrFail();
    $this->solar = Industry::where('slug', 'solar')->firstOrFail();
    $this->staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->staff->assignRole('staff');
    $this->staff->givePermissionTo('licenses.verify');
});

it('adds licenses with a format check and keeps documents private', function () {
    Storage::fake('private');
    IndustryLicensing::create(['industry_id' => $this->solar->id, 'state_id' => $this->tx->id, 'required' => true, 'number_pattern' => '^TECL[0-9]{5}$']);
    $u = User::factory()->create();
    $this->actingAs($u)->post('/app/licenses', ['industry_id' => $this->solar->id, 'state_id' => $this->tx->id, 'number' => '12345'])->assertSessionHasErrors('number');
    $this->post('/app/licenses', ['industry_id' => $this->solar->id, 'state_id' => $this->tx->id, 'number' => 'tecl12345', 'expires_on' => now()->addYear()->toDateString(),
        'document' => UploadedFile::fake()->create('lic.pdf', 50, 'application/pdf')])->assertSessionHas('status', 'license-added');
    $l = DB::table('team_licenses')->first();
    expect($l->number)->toBe('TECL12345')->and($l->status)->toBe('pending');
    Storage::disk('private')->assertExists($l->document_path);
    $this->post('/app/licenses', ['industry_id' => $this->solar->id, 'state_id' => $this->tx->id, 'number' => 'TECL12345'])->assertSessionHasErrors('number');
    $this->get('/app/profile')->assertOk()->assertInertia(fn ($p) => $p->component('app/Licenses')->has('licenses', 1));
});

it('lets staff verify or reject with a reason, tells the team, and flags duplicates', function () {
    $a = Team::forLeader(User::factory()->create());
    $b = Team::forLeader(User::factory()->create());
    $lic = app(Licenses::class);
    $id = $lic->add($a, $this->solar, $this->tx->id, 'ABC123', null, null, null);
    $lic->add($b, $this->solar, $this->tx->id, 'ABC123', null, null, null);

    $this->actingAs($this->staff)->get('/admin/licenses')->assertOk()->assertInertia(fn ($p) => $p->component('admin/Licenses')->has('rows', 2)->where('rows.0.duplicates', 1));
    $this->post("/admin/licenses/{$id}", ['decision' => 'reject'])->assertSessionHasErrors('note');
    $this->post("/admin/licenses/{$id}", ['decision' => 'verify'])->assertSessionHas('status', 'license-verified');
    expect(DB::table('team_licenses')->where('id', $id)->value('status'))->toBe('verified');
    Notification::assertSentTo($a->leader, InAppNotification::class, fn ($n) => $n->type === 'license.update' && str_contains($n->title, 'verified'));
});

it('keeps the queue for staff with the license permission', function () {
    $other = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $other->assignRole('staff');
    $this->actingAs($other)->get('/admin/licenses')->assertForbidden();
    $this->actingAs(User::factory()->create())->post('/admin/licenses/1', ['decision' => 'verify'])->assertForbidden();
});

it('moves teams with verified licenses up the waitlist', function () {
    $group = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $city = City::where('is_enabled', true)->orderBy('id')->firstOrFail();
    $seats = app(Seats::class);
    $mk = fn () => Team::forLeader(User::factory()->create());
    [$h, $bk, $late, $early] = [$mk(), $mk(), $mk(), $mk()];
    foreach ([$h, $bk, $early, $late] as $t) {
        $seats->approve($seats->apply($group, $t, $city, 'L', null), null);
    }
    $id = app(Licenses::class)->add($late, $this->solar, $this->tx->id, 'V1', null, null, null);
    app(Licenses::class)->decide($id, $this->staff, true, null);
    $order = DB::table('seat_waitlist')->orderByDesc('score')->orderBy('joined_at')->pluck('team_id')->all();
    expect($order[0])->toBe($late->id);
});

it('reminds 30 and 7 days before expiry, then marks the license expired', function () {
    $t = Team::forLeader(User::factory()->create());
    $id = app(Licenses::class)->add($t, $this->solar, $this->tx->id, 'EXP1', now()->addDays(29)->toDateString(), null, null);
    DB::table('team_licenses')->where('id', $id)->update(['status' => 'verified']);
    Artisan::call('t2t:licenses:reminders');
    Artisan::call('t2t:licenses:reminders');
    expect(Notification::sent($t->leader, InAppNotification::class)->filter(fn ($n) => str_contains($n->title, '30 days'))->count())->toBe(1);
    $this->travel(23)->days();
    Artisan::call('t2t:licenses:reminders');
    expect(Notification::sent($t->leader, InAppNotification::class)->contains(fn ($n) => str_contains($n->title, '7 days')))->toBeTrue();
    $this->travel(7)->days();
    Artisan::call('t2t:licenses:reminders');
    expect(DB::table('team_licenses')->where('id', $id)->value('status'))->toBe('expired');
});
