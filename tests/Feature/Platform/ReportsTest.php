<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Platform\Reports\ReportMail;
use App\Domains\Referrals\Referrals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Mail::fake();
    fakeSms();
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $cities = City::where('is_enabled', true)->orderBy('id')->get();
    $sv = app(Seats::class);
    $mk = function (string $slug, int $c, string $name) use ($sv, $cities) {
        $t = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name]);
        $sv->approve($sv->apply(Group::where('slug', $slug)->firstOrFail(), $t, $cities[$c], 'L', null), null);

        return $t;
    };
    $this->solar = Group::where('slug', 'solar-installers-network')->firstOrFail();
    [$a, $b] = [$mk('solar-installers-network', 2, 'Lone Star Solar'), $mk('solar-installers-network', 1, 'Hurricane Sun')];
    [$c, $d] = [$mk('movers-network', 2, 'Austin Movers'), $mk('movers-network', 1, 'Hurricane Moving')];
    foreach ([[$a, $this->solar], [$c, Group::where('slug', 'movers-network')->firstOrFail()]] as $i => [$from, $g]) {
        $ans = collect(app(IndustryTemplates::class)->resolve('form', $g->industry, $g->id)['body'])
            ->mapWithKeys(fn ($f) => [$f['key'] => match ($f['type']) {
                'choice' => $f['options'][0], 'multi_choice' => [$f['options'][0]], 'boolean' => true, 'number' => '5', 'date' => now()->addMonth()->toDateString(), default => 'Text'
            }])->all();
        app(Referrals::class)->send($from->leader, $g, $cities[1], [...$ans, 'client_name' => "Maria Lopez{$i}", 'client_phone' => '+1435555019'.$i, 'client_email' => "maria{$i}@clients.test"], true, null, true);
    }
    DB::table('invoices')->insert(['number' => 'INV-1', 'owner_type' => 'team', 'owner_id' => $a->id, 'period' => '2026-11', 'status' => 'paid', 'paid_at' => now()->subDay(), 'total_cents' => 2900,
        'lines' => json_encode([['type' => 'seat', 'seat_id' => 1, 'description' => 'Seat', 'amount_cents' => 1000], ['type' => 'premium', 'description' => 'Premium membership', 'amount_cents' => 1900]]), 'created_at' => now(), 'updated_at' => now()]);
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now(), 'timezone' => 'America/Chicago']);
    $this->admin->assignRole(Permissions::SUPER_ADMIN);
    $this->owner = User::factory()->create(['timezone' => 'America/Chicago']);
    $this->solar->owners()->attach($this->owner->id);
    $this->ulid = fn () => (string) DB::table('saved_reports')->latest('id')->value('ulid');
});

it('DONE WHEN: the super admin schedules a weekly revenue report and receives it by email', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'America/Chicago')); // a Thursday
    $this->actingAs($this->admin)->post('/admin/reports', ['name' => 'Weekly revenue', 'dataset' => 'revenue', 'preset' => 'last_7'])->assertSessionHas('status', 'report-saved');
    $this->post('/admin/reports/'.($this->ulid)().'/schedules', ['frequency' => 'weekly', 'weekday' => 1, 'hour' => 8, 'timezone' => 'America/Chicago', 'recipients' => [$this->admin->id]])->assertSessionHas('status', 'report-scheduled');
    $next = CarbonImmutable::parse((string) DB::table('report_schedules')->value('next_run_at'), 'UTC');
    expect($next->setTimezone('America/Chicago')->format('D Y-m-d H:i'))->toBe('Mon 2026-11-16 08:00');
    Artisan::call('t2t:reports');
    Mail::assertNothingSent(); // not yet due
    $this->travelTo($next->addMinutes(5));
    DB::table('invoices')->update(['paid_at' => now()->subDay()]);
    Artisan::call('t2t:reports');
    Mail::assertSent(ReportMail::class, function (ReportMail $m) {
        $csv = (string) $m->csv;

        return $m->hasTo($this->admin->email) && $m->name === 'Weekly revenue' && str_contains($csv, '"Seat subscriptions",10.00') && str_contains($csv, '"Net revenue",29.00') && count($m->attachments()) === 1;
    });
    expect(DB::table('report_deliveries')->value('status'))->toBe('sent')
        ->and(CarbonImmutable::parse((string) DB::table('report_schedules')->value('next_run_at'), 'UTC')->setTimezone('America/Chicago')->format('D Y-m-d H:i'))->toBe('Mon 2026-11-23 08:00');
    Artisan::call('t2t:reports');
    Mail::assertSentCount(1); // not sent twice
    // The page shows the delivery, with the recipient's name (built from first and last name).
    $this->get('/admin/reports')->assertInertia(fn ($p) => $p->where('deliveries.0.to', $this->admin->name)->where('deliveries.0.status', 'sent')->where('previewUrl', '/admin/reports/preview'));
});

it('[3] previews, groups, saves and exports reports with short client names', function () {
    $this->actingAs($this->admin)->post('/admin/reports/preview', ['dataset' => 'referrals', 'preset' => 'last_30', 'group_by' => 'network'])
        ->assertSessionHas('report_preview', fn ($p) => $p['columns'] === ['Network', 'Count'] && $p['total'] === 2);
    $this->post('/admin/reports/preview', ['dataset' => 'referrals', 'preset' => 'last_30', 'columns' => ['network', 'client']])
        ->assertSessionHas('report_preview', fn ($p) => $p['columns'] === ['Network', 'Client'] && str_starts_with((string) $p['rows'][0][1], 'Maria L'));
    $this->post('/admin/reports', ['name' => 'All referrals', 'dataset' => 'referrals', 'preset' => 'last_30']);
    $csv = $this->get('/admin/reports/'.($this->ulid)().'/export.csv')->streamedContent();
    expect($csv)->toContain('Solar Installers Network')->not->toContain('Lopez')->not->toContain('555-019')->not->toContain('clients.test');
});

it('[8] keeps owner reports to their own network, never revenue, and stops them when ownership ends', function () {
    $this->actingAs($this->owner)->post('/app/group/solar-installers-network/reports/preview', ['dataset' => 'referrals', 'preset' => 'last_30'])
        ->assertSessionHas('report_preview', fn ($p) => $p['total'] === 1 && ! str_contains(json_encode($p['rows']), 'Movers'));
    $this->post('/app/group/solar-installers-network/reports/preview', ['dataset' => 'revenue', 'preset' => 'last_30'])->assertForbidden();
    $this->get('/admin/reports')->assertForbidden();
    $this->post('/app/group/solar-installers-network/reports', ['name' => 'Mine', 'dataset' => 'referrals', 'preset' => 'last_30']);
    $this->post('/app/group/solar-installers-network/reports/'.($this->ulid)().'/schedules', ['frequency' => 'weekly', 'weekday' => 1, 'hour' => 8, 'timezone' => 'America/Chicago', 'recipients' => [$this->admin->id, $this->owner->id]]);
    expect(json_decode((string) DB::table('report_schedules')->value('recipients'), true))->toBe([$this->owner->id]); // owners send to themselves only
    // Every owner action on a report or schedule (owner addresses start with the network).
    $base = '/app/group/solar-installers-network/reports';
    expect($this->get($base.'/'.($this->ulid)().'/export.csv')->streamedContent())->toContain('Solar Installers Network');
    $id = (int) DB::table('report_schedules')->value('id');
    $this->post("{$base}/schedules/{$id}/pause")->assertSessionHas('status', 'schedule-pause');
    $this->post("{$base}/schedules/{$id}/resume")->assertSessionHas('status', 'schedule-resume');
    $this->get('/app/group/movers-network/reports')->assertForbidden(); // not their network
    $this->solar->owners()->detach($this->owner->id);
    DB::table('report_schedules')->update(['next_run_at' => now()->subMinute()]);
    Artisan::call('t2t:reports');
    Mail::assertNothingSent();
    expect(DB::table('report_deliveries')->first())->status->toBe('skipped')->error->toBe('No access to this report');
});

it('[9] sends emails without contact details', function () {
    $this->actingAs($this->admin)->post('/admin/reports', ['name' => 'Referrals', 'dataset' => 'referrals', 'preset' => 'last_30']);
    $s = (object) ['id' => 0, 'report_id' => DB::table('saved_reports')->value('id'), 'frequency' => 'weekly'];
    DB::table('report_schedules')->insert(['report_id' => $s->report_id, 'frequency' => 'weekly', 'weekday' => 1, 'hour' => 8, 'timezone' => 'UTC', 'recipients' => json_encode([$this->admin->id]), 'next_run_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
    Artisan::call('t2t:reports');
    Mail::assertSent(ReportMail::class, function (ReportMail $m) {
        $html = $m->render();

        return ! str_contains($html.$m->csv, '555-019') && ! str_contains($html.$m->csv, 'clients.test') && ! str_contains($html.$m->csv, 'Lopez') && str_contains($html, 'Maria L');
    });
});

it('[10] retries failed sends, gives up after 3 attempts, and respects pause', function () {
    $this->actingAs($this->admin)->post('/admin/reports', ['name' => 'Seats', 'dataset' => 'seats', 'preset' => 'last_30']);
    $this->post('/admin/reports/'.($this->ulid)().'/schedules', ['frequency' => 'monthly', 'day' => 1, 'hour' => 7, 'timezone' => 'UTC', 'recipients' => [$this->admin->id]]);
    $id = (int) DB::table('report_schedules')->value('id');
    $this->post("/admin/reports/schedules/{$id}/pause");
    DB::table('report_schedules')->update(['next_run_at' => now()->subMinute()]);
    Artisan::call('t2t:reports');
    Mail::assertNothingSent();
    $this->post("/admin/reports/schedules/{$id}/resume");
    expect(CarbonImmutable::parse((string) DB::table('report_schedules')->value('next_run_at'))->isFuture())->toBeTrue();
    DB::table('report_schedules')->update(['next_run_at' => now()->subMinute()]);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
    Artisan::call('t2t:reports');
    expect(DB::table('report_deliveries')->value('status'))->toBe('retrying');
    Artisan::call('t2t:reports');
    Artisan::call('t2t:reports');
    $d = DB::table('report_deliveries')->first();
    expect($d->status)->toBe('failed')->and((int) $d->attempts)->toBe(3)->and($d->error)->toContain('SMTP down');
});

it('[11] sends large reports as a private link that only its recipient can open', function () {
    config(['reports.attach_rows' => 1]);
    $this->actingAs($this->admin)->post('/admin/reports', ['name' => 'Big', 'dataset' => 'seats', 'preset' => 'last_30']);
    $this->get('/admin/reports/'.($this->ulid)().'/export.csv')->assertSessionHas('status', 'report-building');
    $link = null;
    Mail::assertSent(ReportMail::class, function (ReportMail $m) use (&$link) {
        $link = $m->link;

        return $m->csv === null && $m->link !== null && $m->attachments() === [];
    });
    $this->get((string) $link)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $this->actingAs($this->owner)->get((string) $link)->assertForbidden();
    $this->actingAs($this->admin)->get(str_replace('signature=', 'signature=x', (string) $link))->assertForbidden();
});
