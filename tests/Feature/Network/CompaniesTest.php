<?php

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Companies\Company;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Seat;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\SeatInviteNotification;
use App\Domains\Network\Seats\Seats;
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
    $this->group = Group::where('slug', 'solar-installers-network')->firstOrFail();
    $this->ut = City::whereHas('state', fn ($q) => $q->where('code', 'UT'))->where('is_enabled', true)->orderBy('id')->get();
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin)->post('/app/company', ['name' => 'SunPath Solar Inc.']);
    $this->company = Company::administeredBy($this->admin);
});

function branch(Company $c, string $name): Team
{
    $t = Team::forLeader(User::factory()->create());
    $t->forceFill(['name' => $name, 'company_id' => $c->id])->save();

    return $t;
}

it('creates a company with the admin’s own team as its first branch', function () {
    expect($this->company->name)->toBe('SunPath Solar Inc.')->and(Team::of($this->admin)->company_id)->toBe($this->company->id);
    $this->get('/app/company')->assertOk()->assertInertia(fn ($p) => $p->component('app/Company')->has('branches', 1));
    $this->post('/app/company', ['name' => 'Second'])->assertStatus(422);
});

it('invites city leaders in bulk, optionally with a seat offer, and they join the company', function () {
    $this->post('/app/company/invites', ['rows' => [['email' => 'leader@austin.example', 'group' => 'solar-installers-network', 'city_id' => $this->ut[0]->id], ['email' => 'leader2@example.com']]])->assertSessionHas('companyNote', '2 city leader invitation(s) sent.');
    Notification::assertSentTo(new AnonymousNotifiable, SeatInviteNotification::class);
    $token = DB::table('company_invites')->where('email', 'leader@austin.example')->value('id');
    expect($token)->not->toBeNull();

    // Accept through the stored token: re-create a known one for the test.
    DB::table('company_invites')->where('email', 'leader@austin.example')->update(['token_hash' => hash('sha256', 'known-token')]);
    $leader = User::factory()->create(['email' => 'leader@austin.example']);
    $this->actingAs(User::factory()->create())->post('/company-invites/known-token/accept')->assertSessionHasErrors('invite');
    $this->actingAs($leader)->post('/company-invites/known-token/accept')->assertRedirect('/app/team');
    expect(Team::of($leader)->company_id)->toBe($this->company->id);
});

it('lets only the company admin choose who pays for each seat', function () {
    $b = branch($this->company, 'SunPath St. George');
    $s = app(Seats::class);
    $s->approve($s->apply($this->group, $b, $this->ut[0], 'L', null), null);
    $seat = Seat::firstOrFail();
    $this->post("/app/company/seats/{$seat->id}/payer", ['payer' => 'company'])->assertSessionHas('status', 'payer-changed');
    expect($seat->fresh()->payer)->toBe('company');
    $this->actingAs($b->leader)->post("/app/company/seats/{$seat->id}/payer", ['payer' => 'team'])->assertForbidden();
});

it('always allows one seat per state, then enforces the company cap', function () {
    expect(app(Seats::class)->approve(app(Seats::class)->apply($this->group, branch($this->company, 'First'), $this->ut[0], 'L', null), null))->toBe('primary');
    Seat::query()->delete();
    $this->group->update(['rules' => [...$this->group->rules, 'company_cap_pct' => 50]]);
    $s = app(Seats::class);
    $count = $this->ut->count();
    $branches = collect(range(1, $count))->map(fn ($i) => branch($this->company, "Branch {$i}"));
    $outcomes = $branches->map(fn ($b, $i) => $s->approve($s->apply($this->group->fresh(), $b, $this->ut[$i], 'L', null), null))->all();
    $held = collect($outcomes)->filter(fn ($o) => $o === 'primary')->count();
    expect($held)->toBe(max(1, (int) floor($count * 0.5)))->and(DB::table('seat_events')->where('event', 'cap_limited')->count())->toBeGreaterThan(0);

    $this->actingAs($this->admin)->get('/app/company')->assertInertia(fn ($p) => $p->has('seats', $held));
});

it('tells the company admin when a company-paid seat has a payment problem', function () {
    $b = branch($this->company, 'SunPath St. George');
    $s = app(Seats::class);
    $s->approve($s->apply($this->group, $b, $this->ut[0], 'L', null), null);
    $seat = Seat::firstOrFail();
    $seat->update(['payer' => 'company']);
    $s->paymentFailed($seat->fresh());
    Notification::assertSentTo($this->admin, InAppNotification::class, fn ($n) => $n->type === 'seat.payment' && str_contains($n->title, 'SunPath St. George'));
});

it('shows a cap warning in the applications queue and the company badge on the profile', function () {
    $this->group->update(['rules' => [...$this->group->rules, 'company_cap_pct' => 10]]);
    $s = app(Seats::class);
    $first = branch($this->company, 'SunPath Hurricane');
    $s->approve($s->apply($this->group->fresh(), $first, $this->ut[1], 'L', null), null);
    $b = branch($this->company, 'SunPath St. George');
    $s->apply($this->group->fresh(), $b, $this->ut[0], 'L', null);
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole('super_admin');
    $this->actingAs($staff)->get('/admin/groups/solar-installers-network/applications')->assertInertia(fn ($p) => $p->where('applications', fn ($apps) => collect($apps)->contains(fn ($a) => ($a['cap']['over'] ?? false) === true)));
    $this->actingAs($b->leader)->get('/app/team/preview')->assertInertia(fn ($p) => $p->where('company.name', 'SunPath Solar Inc.'));
});
