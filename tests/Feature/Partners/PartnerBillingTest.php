<?php

use App\Domains\Billing\Invoicing;
use App\Domains\Billing\RevenueTypes;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Partners\Partner;
use App\Domains\Partners\PartnerBilling;
use App\Domains\Partners\PartnerListing;
use App\Domains\Partners\PartnerMail;
use App\Domains\Partners\Partners;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['seo.indexable' => true]);
    $this->travelTo(Carbon::parse('2027-03-03 10:00:00'));
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Storage::fake('public');
    Storage::fake('private');
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $s = app(Settings::class);
    $s->set('payments.driver', 'stripe');
    $s->set('payments.stripe.publishable_key', 'pk_test_123');
    $s->set('payments.stripe.secret_key', 'sk_test_123', secret: true);
    $this->charges = [];
    $this->decline = false;
    Http::fake(['api.stripe.com/*' => function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/v1/payment_intents') => (function () use ($request) {
                $this->charges[] = (int) $request['amount'];

                return $this->decline ? Http::response(['error' => ['message' => 'Your card was declined.']], 402) : Http::response(['id' => 'pi_'.count($this->charges), 'status' => 'succeeded']);
            })(),
            str_ends_with($url, '/v1/customers') => Http::response(['id' => 'cus_P1']),
            str_contains($url, '/v1/setup_intents') => Http::response(['id' => 'seti_1', 'client_secret' => 'seti_1_secret']),
            str_contains($url, '/v1/payment_methods/pm_good') => Http::response(['id' => 'pm_good', 'customer' => 'cus_P1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]]),
            str_contains($url, '/v1/customers/') => Http::response(['id' => 'cus_P1']),
            default => Http::response(['error' => ['message' => 'unexpected']], 400),
        };
    }]);
    $this->solar = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->houston = City::where('slug', 'houston')->firstOrFail();
    $this->austin = City::where('slug', 'austin')->firstOrFail();
    $this->member = User::factory()->create();
    $this->team = tap(Team::forLeader($this->member))->update(['name' => 'Hurricane Sun']);
    app(Seats::class)->approve(app(Seats::class)->apply($this->solar, $this->team, $this->houston, 'L-1', null), null);
    $this->reviewer = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->reviewer->assignRole(Permissions::STAFF);
    $this->reviewer->givePermissionTo('partners.manage');
    // A partner, applied and approved (free trial by default).
    $this->make = function (string $name, array $cities, string $email, string $category = 'home-stagers'): Partner {
        $d = ['category' => $category, 'business_name' => $name, 'contact_name' => 'Owner', 'email' => $email, 'description' => str_repeat('We stage homes so they sell faster. ', 2),
            'cities' => $cities, 'license_number' => null];
        $p = Partners::apply($d, null, null);
        Partners::approve($p, $this->reviewer, false);

        return $p->fresh() ?? $p;
    };
    $this->card = fn (Partner $p) => DB::table('billing_customers')->updateOrInsert(['owner_type' => 'partner', 'owner_id' => $p->id],
        ['stripe_customer_id' => 'cus_P1', 'payment_method_id' => 'pm_1', 'card_brand' => 'visa', 'card_last4' => '4242', 'card_exp_month' => 12, 'card_exp_year' => 2030, 'created_at' => now(), 'updated_at' => now()]);
    $this->signIn = fn (string $email) => $this->get(URL::temporarySignedRoute('partners.portal.enter', now()->addMinutes(30), ['t' => encrypt($email)]));
    Cache::flush();
});

function mailsTo(string $email): array
{
    $subjects = [];
    Notification::assertSentOnDemand(PartnerMail::class, function ($n, $ch, $to) use ($email, &$subjects) {
        if ($to->routes['mail'] === $email) {
            $subjects[] = $n->subject;
        }

        return true;
    });

    return $subjects;
}

it('runs a free trial, then bills a flat price per city, prorated to the month end, and renews on the 1st', function () {
    $p = ($this->make)('Stage Right', [$this->houston->id, $this->austin->id], 'hello@stageright.example');
    expect($p->billing_status)->toBe('trial')->and($p->trial_ends_at?->toDateString())->toBe('2027-03-17')->and($p->isLive())->toBeTrue()
        ->and(PartnerBilling::monthlyCents($p))->toBe(9800)->and(PartnerBilling::annualCents($p))->toBe(105840);
    expect(mailsTo('hello@stageright.example'))->toContain('Your listing is live');
    ($this->card)($p);

    // Three days before the trial ends: no reminder when a card is on file.
    $this->travelTo(Carbon::parse('2027-03-14 11:00'));
    PartnerBilling::run();
    expect($p->fresh()?->trial_reminded_at)->not->toBeNull()->and(mailsTo('hello@stageright.example'))->not->toContain('Your free trial ends Mar 17, 2027');

    // Trial ends on the 17th: 15 of 31 days of March, for two cities.
    $this->travelTo(Carbon::parse('2027-03-17 10:30'));
    PartnerBilling::run();
    $inv = DB::table('invoices')->where('owner_type', 'partner')->sole();
    expect($this->charges)->toBe([(int) round(9800 * 15 / 31)])->and($inv->status)->toBe('paid')->and($inv->period)->toBe('2027-03');
    expect((array) json_decode((string) $inv->lines, true)[0])->toMatchArray(['type' => 'partner_listing', 'until' => '2027-03-31', 'days' => 15])
        ->and((string) json_decode((string) $inv->lines, true)[0]['description'])->toContain('Stage Right, 2 cities · Mar 17 to Mar 31, 2027');
    expect($p->fresh()?->billing_status)->toBe('active')->and($p->fresh()?->paid_until?->toDateString())->toBe('2027-03-31');
    Storage::disk('private')->assertExists((string) $inv->pdf_path);
    PartnerBilling::run(); // nothing more is due
    expect(DB::table('invoices')->where('owner_type', 'partner')->count())->toBe(1);

    // Renewal on the 1st: a full month. Then switch to yearly from the next bill.
    $this->travelTo(Carbon::parse('2027-04-01 06:20'));
    PartnerBilling::run();
    expect($this->charges[1])->toBe(9800)->and($p->fresh()?->paid_until?->toDateString())->toBe('2027-04-30');
    $p->forceFill(['billing_cycle' => 'annual'])->save();
    $this->travelTo(Carbon::parse('2027-05-01 06:20'));
    PartnerBilling::run();
    expect($this->charges[2])->toBe(105840)->and($p->fresh()?->paid_until?->toDateString())->toBe('2028-04-30');

    // Revenue shows partner listings as their own line.
    $sum = RevenueTypes::summary('2027-01-01 00:00:00', '2027-12-31 23:59:59');
    expect($sum['byType']['partner_listing'])->toBe(array_sum($this->charges))->and($sum['gross'])->toBe(array_sum($this->charges));
});

it('keeps a listing up while a failed payment is retried, pauses it after the last try, and brings it back on payment', function () {
    $p = ($this->make)('Stage Right', [$this->houston->id], 'hello@stageright.example');
    // Trial reminder when there's no card.
    $this->travelTo(Carbon::parse('2027-03-14 11:00'));
    PartnerBilling::run();
    expect(mailsTo('hello@stageright.example'))->toContain('Your free trial ends Mar 17, 2027');

    $this->travelTo(Carbon::parse('2027-03-17 10:30'));
    PartnerBilling::run();
    expect(DB::table('invoices')->where('owner_type', 'partner')->value('status'))->toBe('failed')
        ->and($p->fresh()?->billing_status)->toBe('past_due')->and($p->fresh()?->isLive())->toBeTrue()
        ->and(mailsTo('hello@stageright.example'))->toContain('Payment failed for invoice '.DB::table('invoices')->value('number'));
    $this->get('/partner/stage-right')->assertOk();

    // Days 3 and 5: still no card. After the third try the listing is paused and hidden.
    foreach (['2027-03-19 11:00', '2027-03-21 11:00'] as $when) {
        $this->travelTo(Carbon::parse($when));
        app(Invoicing::class)->retryDue();
    }
    expect($p->fresh()?->billing_status)->toBe('paused')->and(mailsTo('hello@stageright.example'))->toContain('Your listing is paused');
    $this->get('/partner/stage-right')->assertNotFound();
    expect($this->get('/industries/solar/tx/houston')->viewData('page')['props']['partners'])->toBe([]);

    // Adding a card in the partner account pays what's owed and the listing comes straight back.
    ($this->signIn)('hello@stageright.example')->assertRedirect('/partner-portal');
    $this->post('/partner-portal/'.$p->ulid.'/card', ['payment_method' => 'pm_good'])->assertSessionHas('status', 'card-saved');
    expect(DB::table('invoices')->where('owner_type', 'partner')->value('status'))->toBe('paid')
        ->and($p->fresh()?->billing_status)->toBe('active')->and($p->fresh()?->paid_until?->toDateString())->toBe('2027-03-31')
        ->and(mailsTo('hello@stageright.example'))->toContain('Your listing is back');
    $this->get('/partner/stage-right')->assertOk();
});

it('lists at most the cap per city and category, waitlists the rest without charging, and fills openings in order', function () {
    app(Settings::class)->set('partners.cap', '1');
    $first = ($this->make)('Stage Right', [$this->houston->id], 'a@example.org');
    $second = ($this->make)('Home Glow', [$this->houston->id, $this->austin->id], 'b@example.org');
    $third = ($this->make)('Room Ready', [$this->houston->id], 'c@example.org');
    $houston = fn (Partner $p) => PartnerListing::query()->where(['partner_id' => $p->id, 'city_id' => $this->houston->id])->sole();
    expect($houston($first)->status)->toBe('active')->and($houston($second)->status)->toBe('waitlist')->and($houston($third)->status)->toBe('waitlist')
        ->and(Partners::waitlistPosition($houston($second)))->toBe(1)->and(Partners::waitlistPosition($houston($third)))->toBe(2);
    // Only listed cities are paid for; a fully waitlisted partner isn't billed at all.
    expect(PartnerBilling::monthlyCents($second))->toBe(4900)->and(PartnerBilling::bill($third))->toBeNull();
    $approval = null;
    Notification::assertSentOnDemand(PartnerMail::class, function ($n, $ch, $to) use (&$approval) {
        if ($to->routes['mail'] === 'b@example.org' && $n->subject === 'Your listing is live') {
            $approval = implode(' ', $n->lines);
        }

        return true;
    });
    expect($approval)->toContain('waitlist')->toContain('Houston, TX');
    expect(collect(Partners::inCity($this->houston)->first()['partners'])->pluck('slug')->all())->toBe(['stage-right']);

    // The listed partner is suspended: the first in line takes the place and is told.
    Partners::suspend($first, $this->reviewer, 'Complaint under review');
    expect($houston($second)->fresh()?->status)->toBe('active')->and($houston($third)->fresh()?->status)->toBe('waitlist')
        ->and(mailsTo('b@example.org'))->toContain('A spot opened in Houston');
    // Raising the cap offers the extra places straight away (billing permission needed).
    $this->actingAs($this->reviewer)->post('/admin/partners/settings', ['price' => 59, 'annual_discount' => 15, 'trial_days' => 0, 'cap' => 3])->assertForbidden();
    $this->reviewer->givePermissionTo('billing.manage');
    $this->actingAs($this->reviewer)->post('/admin/partners/settings', ['price' => 59, 'annual_discount' => 15, 'trial_days' => 0, 'cap' => 3])->assertSessionHas('status', 'partner-settings-saved');
    expect($houston($third)->fresh()?->status)->toBe('active')->and(PartnerBilling::settings())->toBe(['price' => 59.0, 'annualDiscount' => 15, 'trialDays' => 0, 'cap' => 3]);
    // Reinstated partners only get a place if there's room.
    Partners::reinstate($first->fresh() ?? $first, $this->reviewer);
    expect($houston($first)->fresh()?->status)->toBe('active');
    $this->get('/partners/advertise')->assertInertia(fn ($pg) => $pg->where('price', 59)->where('discount', 15)->where('trialDays', 0)->where('cap', 3));
});

it('signs partners in by emailed link and lets them manage cities, plan, card and listing changes', function () {
    $p = ($this->make)('Stage Right', [$this->houston->id], 'hello@stageright.example');
    $other = ($this->make)('Home Glow', [$this->houston->id], 'other@example.org');
    $this->get('/partner-portal')->assertOk()->assertInertia(fn ($pg) => $pg->component('partners/portal/Home')->where('signedIn', false));
    $this->post('/partner-portal/link', ['email' => 'nobody@example.org'])->assertSessionHas('status', 'partner-link-sent');
    $this->post('/partner-portal/link', ['email' => 'HELLO@stageright.example'])->assertSessionHas('status', 'partner-link-sent');
    expect(mailsTo('hello@stageright.example'))->toContain('Your sign-in link')->and(mailsTo('nobody@example.org'))->toBe([]);
    $this->get('/partner-portal/'.$p->ulid)->assertForbidden();
    $this->get('/partner-portal/enter?t='.encrypt('hello@stageright.example'))->assertForbidden(); // unsigned
    ($this->signIn)('hello@stageright.example');
    $this->get('/partner-portal')->assertRedirect('/partner-portal/'.$p->ulid);
    $this->get('/partner-portal/'.$other->ulid)->assertNotFound(); // someone else's listing
    $this->get('/partner-portal/'.$p->ulid)->assertOk()->assertInertia(fn ($pg) => $pg->component('partners/portal/Show')
        ->where('billing.status', 'trial')->where('billing.monthly', 49)->where('listings.0.status', 'active')->where('stripeKey', 'pk_test_123'));

    // Cities: add one (price follows from the next bill), remove one, never the last.
    $this->post('/partner-portal/'.$p->ulid.'/cities', ['cities' => [$this->austin->id]])->assertSessionHas('status', 'partner-cities-saved');
    expect(PartnerBilling::cities($p))->toBe(2);
    $austin = PartnerListing::query()->where(['partner_id' => $p->id, 'city_id' => $this->austin->id])->sole();
    $this->delete('/partner-portal/'.$p->ulid.'/cities/'.$austin->id)->assertSessionHas('status', 'partner-cities-saved');
    $only = PartnerListing::query()->where('partner_id', $p->id)->sole();
    $this->delete('/partner-portal/'.$p->ulid.'/cities/'.$only->id)->assertStatus(422);

    // Listing changes wait for review; the live page keeps the old version.
    $this->post('/partner-portal/'.$p->ulid.'/details', ['business_name' => 'Stage Right Studio', 'description' => str_repeat('Fresh new description of our staging. ', 2),
        'logo' => UploadedFile::fake()->image('new.png', 80, 80)])->assertSessionHas('status', 'partner-changes-submitted');
    expect($p->fresh()?->business_name)->toBe('Stage Right')->and($p->fresh()?->pending_changes['business_name'])->toBe('Stage Right Studio');
    $this->actingAs($this->reviewer)->get('/admin/partners?status=changes')->assertInertia(fn ($pg) => $pg->where('counts.changes', 1)
        ->where('rows.0.changes', fn ($c) => collect($c)->pluck('field')->contains('Name') && collect($c)->pluck('field')->contains('Logo')));
    $this->post('/admin/partners/'.$p->ulid.'/approve-changes')->assertSessionHas('status', 'partner-changes-approved');
    expect($p->fresh()?->business_name)->toBe('Stage Right Studio')->and($p->fresh()?->pending_changes)->toBeNull()->and($p->fresh()?->logo_path)->not->toBeNull();
    $this->post('/logout');

    // Plan: yearly from the next bill; cancel during the trial ends it today; start again later.
    ($this->signIn)('hello@stageright.example');
    $this->post('/partner-portal/'.$p->ulid.'/cycle', ['cycle' => 'annual'])->assertSessionHas('status', 'partner-plan-saved');
    $this->post('/partner-portal/'.$p->ulid.'/plan/cancel')->assertSessionHas('status', 'partner-cancelled');
    expect($p->fresh()?->billing_status)->toBe('cancelled')->and(mailsTo('hello@stageright.example'))->toContain('Your listing has ended');
    $this->get('/partner/stage-right')->assertNotFound();
    ($this->card)($p);
    $this->post('/partner-portal/'.$p->ulid.'/plan/restart')->assertSessionHas('status', 'partner-restarted');
    expect($p->fresh()?->billing_status)->toBe('active')->and($p->fresh()?->paid_until?->toDateString())->toBe('2028-03-02')->and($this->charges)->toBe([(int) round(4900 * 12 * 0.9)]);
    // Cancelling a paid plan keeps it up to the end of the time paid for; "keep" undoes it.
    $this->post('/partner-portal/'.$p->ulid.'/plan/cancel');
    expect($p->fresh()?->cancel_at?->toDateString())->toBe('2028-03-03')->and($p->fresh()?->isLive())->toBeTrue();
    $this->post('/partner-portal/'.$p->ulid.'/plan/resume')->assertSessionHas('status', 'partner-resumed');
    expect($p->fresh()?->cancel_at)->toBeNull();
    // The invoice PDF is theirs alone.
    $inv = (int) DB::table('invoices')->where(['owner_type' => 'partner', 'owner_id' => $p->id])->value('id');
    $this->get('/partner-portal/'.$p->ulid.'/invoices/'.$inv.'.pdf')->assertOk();
    $this->get('/partner-portal/'.$other->ulid.'/invoices/'.$inv.'.pdf')->assertNotFound();
    $this->post('/partner-portal/logout');
    $this->get('/partner-portal/'.$p->ulid)->assertForbidden();
});

it('counts views and clicks once per visitor a day without bots, plus enquiries and shares, with a CSV', function () {
    $p = ($this->make)('Stage Right', [$this->houston->id], 'hello@stageright.example');
    $browser = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15'];
    $seen = fn (array $body, array $server) => $this->withServerVariables($server)->postJson('/partners/seen', $body);
    $seen(['k' => 'view', 's' => ['stage-right', 'no-such-partner'], 'c' => $this->houston->id], $browser)->assertNoContent();
    $seen(['k' => 'view', 's' => ['stage-right'], 'c' => $this->houston->id], $browser); // same visitor, same day
    $seen(['k' => 'view', 's' => ['stage-right'], 'c' => $this->houston->id], ['HTTP_USER_AGENT' => 'Googlebot/2.1']);
    $seen(['k' => 'view', 's' => ['stage-right'], 'c' => $this->houston->id], ['HTTP_USER_AGENT' => '']);
    $seen(['k' => 'view', 's' => ['stage-right'], 'c' => $this->houston->id], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows) Chrome/120', 'REMOTE_ADDR' => '10.1.1.1']);
    $seen(['k' => 'click', 's' => ['stage-right'], 'c' => $this->austin->id], $browser); // not listed there: counted on its own page
    $row = DB::table('partner_stats')->where(['partner_id' => $p->id, 'city_id' => $this->houston->id])->sole();
    expect((int) $row->views)->toBe(2)->and((int) DB::table('partner_stats')->where(['partner_id' => $p->id, 'city_id' => 0])->value('clicks'))->toBe(1);

    // An enquiry and a member's share count too.
    $this->post('/partner/stage-right/enquire', ['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Can you stage my condo?', 'city' => $this->houston->id, 'consent' => '1',
        'started' => encrypt(now()->subSeconds(30)->getTimestamp())])->assertSessionHas('status', 'partner-enquiry-sent');
    $this->actingAs($this->member)->get('/app/partners')->assertOk()->assertInertia(fn ($pg) => $pg->component('app/Partners')
        ->where('cities.0.name', 'Houston, TX')->where('cities.0.groups.0.partners.0.slug', 'stage-right'));
    $this->actingAs($this->member)->post('/app/partners/stage-right/share', ['name' => 'Ben', 'email' => 'ben@example.org', 'note' => 'They did a great job for us.'])
        ->assertSessionHas('status', 'partner-shared');
    Notification::assertSentOnDemand(PartnerMail::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'ben@example.org' && $n->replyTo === $this->member->email
        && str_contains(implode(' ', $n->lines), 'nobody is paid for this introduction'));
    expect(DB::table('partner_shares')->count())->toBe(1);

    $r = App\Domains\Partners\PartnerStats::report($p, '2027-03-01', '2027-03-31');
    expect($r['totals'])->toBe(['views' => 2, 'clicks' => 1, 'enquiries' => 1, 'shares' => 1])->and($r['cities'][0]['city'])->toBe('Houston, TX');
    // Cost per enquiry once something is paid.
    ($this->card)($p);
    $p->forceFill(['trial_ends_at' => now()->subMinute()])->save();
    PartnerBilling::run();
    expect(App\Domains\Partners\PartnerStats::report($p, '2027-03-01', '2027-03-31')['costPerEnquiry'])->toBe(round(round(4900 * 29 / 31) / 100, 2));

    ($this->signIn)('hello@stageright.example');
    $csv = $this->get('/partner-portal/'.$p->ulid.'/stats.csv')->assertOk()->streamedContent();
    expect($csv)->toContain('Date,City,Views,Clicks,Enquiries,"Shares by members"')->toContain('2027-03-03,"Houston, TX",2,0,1,0')->toContain('2027-03-03,"Your page",0,1,0,1');
});
