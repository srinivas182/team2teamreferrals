<?php

use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\InAppNotification;
use App\Domains\Messaging\Push\PushSender;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use App\Domains\Partners\Partner;
use App\Domains\Partners\PartnerCategory;
use App\Domains\Partners\PartnerMail;
use App\Domains\Partners\Partners;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\Events\PublicUrlsChanged;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['seo.indexable' => true]);
    Artisan::call('t2t:locations:import', ['--path' => base_path('tests/fixtures/locations')]);
    Artisan::call('t2t:catalogue:sync');
    Notification::fake();
    Storage::fake('public');
    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(User $user, string $title, string $body, ?string $url): int
        {
            return 0;
        }
    });
    $this->solar = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
    $this->houston = City::where('slug', 'houston')->firstOrFail();
    $this->austin = City::where('slug', 'austin')->firstOrFail();
    // A member team holds the Houston solar seat, so the Houston solar city page exists.
    $this->member = User::factory()->create();
    $this->team = tap(Team::forLeader($this->member))->update(['name' => 'Hurricane Sun']);
    app(Seats::class)->approve(app(Seats::class)->apply($this->solar, $this->team, $this->houston, 'L-1', null), null);
    $this->reviewer = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->reviewer->assignRole(Permissions::STAFF);
    $this->reviewer->givePermissionTo('partners.manage');
    $this->token = fn () => encrypt(now()->subSeconds(30)->getTimestamp());
    // Each application comes from its own address here (the route allows 5 an hour from one).
    $this->apply = fn (array $over = []) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.'.random_int(0, 255).'.'.random_int(1, 254)])->post('/partners/advertise', [
        'category' => 'mortgage-lenders', 'business_name' => 'Bayou Home Loans', 'contact_name' => 'Maya Lender', 'email' => 'maya@bayouloans.example',
        'phone' => '+1 713 555 0101', 'website' => 'https://bayouloans.example', 'description' => 'Local mortgage lender closing purchase loans in Houston in 21 days on average.',
        'license_number' => '123456', 'offer_title' => '$500 off closing costs', 'offer_details' => 'For buyers who mention this site.',
        'cities' => [$this->houston->id, $this->austin->id], 'consent' => '1', 'started' => ($this->token)(), ...$over,
    ]);
    Cache::flush();
});

function approvePartner(Partner $p, User $by): void
{
    Partners::approve($p->fresh() ?? $p, $by, true);
}

it('takes an application with a checked logo, licence rule and spam guard, and keeps it private until approved', function () {
    // Licensed categories need the license number; the hidden field and the consent box are enforced.
    ($this->apply)(['license_number' => ''])->assertSessionHasErrors('license_number');
    ($this->apply)(['website_url' => 'http://spam.example'])->assertSessionHasErrors('business_name');
    ($this->apply)(['consent' => '0'])->assertSessionHasErrors('consent');
    ($this->apply)(['cities' => []])->assertSessionHasErrors('cities');
    ($this->apply)(['website' => 'http://insecure.example'])->assertSessionHasErrors('website');
    // A file named .png that isn't an image is refused by its bytes.
    ($this->apply)(['logo' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;')])->assertSessionHasErrors('logo');
    expect(Partner::count())->toBe(0);
    // The route is rate limited per address.
    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->post('/partners/advertise', []);
    }
    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->post('/partners/advertise', [])->assertStatus(429);

    ($this->apply)(['logo' => UploadedFile::fake()->image('logo.png', 120, 120)])->assertSessionHasNoErrors()->assertSessionHas('status', 'partner-applied');
    $p = Partner::with('listings')->sole();
    expect($p->status)->toBe('pending')->and($p->slug)->toBe('bayou-home-loans')->and($p->listings)->toHaveCount(2)
        ->and($p->logo_path)->toStartWith('partners/'.$p->ulid)->toEndWith('.png');
    Storage::disk('public')->assertExists((string) $p->logo_path);
    Notification::assertSentTo($this->reviewer, InAppNotification::class, fn ($n) => $n->type === 'admin.partners');
    Notification::assertSentOnDemand(PartnerMail::class, fn ($n, $ch, AnonymousNotifiable $to) => $to->routes['mail'] === 'maya@bayouloans.example' && $n->subject === 'We got your application');

    // Not public yet: no page, nothing on the city page, nothing in the sitemap.
    $this->get('/partner/bayou-home-loans')->assertNotFound();
    expect($this->get('/industries/solar/tx/houston')->viewData('page')['props']['partners'])->toBe([]);
    expect((string) $this->get('/sitemaps/partners.xml')->getContent())->not->toContain('bayou-home-loans');
    // A second business with the same name gets its own address.
    ($this->apply)(['email' => 'other@example.org']);
    expect(Partner::query()->pluck('slug')->all())->toContain('bayou-home-loans-2');
});

it('lets only partner reviewers approve, and needs the license checked first for licensed fields', function () {
    ($this->apply)();
    $p = Partner::sole();
    $this->actingAs($this->member)->get('/admin/partners')->assertForbidden();
    $this->actingAs($this->member)->post('/admin/partners/'.$p->ulid.'/approve')->assertForbidden();

    $this->actingAs($this->reviewer)->get('/admin/partners')->assertOk()->assertInertia(fn ($pg) => $pg->component('admin/Partners')
        ->where('counts.pending', 1)->where('rows.0.licenseLookup', 'https://www.nmlsconsumeraccess.org/EntityDetails.aspx/COMPANY/123456')->where('rows.0.cities', fn ($c) => count($c) === 2));
    $this->post('/admin/partners/'.$p->ulid.'/approve')->assertSessionHasErrors('license');
    expect($p->fresh()?->status)->toBe('pending');
    $this->post('/admin/partners/'.$p->ulid.'/reject', ['reason' => ''])->assertSessionHasErrors('reason');

    Event::fake([PublicUrlsChanged::class]);
    $this->post('/admin/partners/'.$p->ulid.'/approve', ['license_checked' => true])->assertSessionHas('status', 'partner-approved');
    $p->refresh();
    expect($p->status)->toBe('approved')->and($p->license_verified_at)->not->toBeNull()->and($p->reviewed_by)->toBe($this->reviewer->id);
    Notification::assertSentOnDemand(PartnerMail::class, fn ($n) => $n->subject === 'Your listing is live');
    Event::assertDispatched(PublicUrlsChanged::class, fn ($e) => in_array('/partner/bayou-home-loans', $e->paths, true)
        && in_array('/partners/mortgage-lenders/tx/houston', $e->paths, true) && in_array('/industries/solar/tx/houston', $e->paths, true));
    expect(DB::table('activity_log')->where('event', 'partner-approved')->exists())->toBeTrue();

    // Suspend (with a reason the business sees), then reinstate.
    $this->post('/admin/partners/'.$p->ulid.'/suspend', ['reason' => 'Payment issue under review'])->assertSessionHas('status', 'partner-suspended');
    $this->post('/logout');
    $this->get('/partner/bayou-home-loans')->assertNotFound();
    $this->actingAs($this->reviewer)->post('/admin/partners/'.$p->ulid.'/reinstate')->assertSessionHas('status', 'partner-reinstated');
    expect($p->fresh()?->status)->toBe('approved');
    // An unlicensed category can be approved without a license number.
    ($this->apply)(['category' => 'home-stagers', 'business_name' => 'Stage Right', 'license_number' => '']);
    $stager = Partner::where('slug', 'stage-right')->sole();
    $this->actingAs($this->reviewer)->post('/admin/partners/'.$stager->ulid.'/approve')->assertSessionHasNoErrors();
    expect($stager->fresh()?->status)->toBe('approved')->and($stager->fresh()?->license_verified_at)->toBeNull();
    // Rejecting tells the business why.
    ($this->apply)(['business_name' => 'Fly By Night Loans', 'email' => 'x@example.org']);
    $bad = Partner::where('slug', 'fly-by-night-loans')->sole();
    $this->actingAs($this->reviewer)->post('/admin/partners/'.$bad->ulid.'/reject', ['reason' => 'NMLS ID not found'])->assertSessionHas('status', 'partner-rejected');
    Notification::assertSentOnDemand(PartnerMail::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'x@example.org' && in_array('Reason: NMLS ID not found', $n->lines, true));
});

it('shows approved partners on city pages as labelled adverts, with full SEO and AEO pages', function () {
    ($this->apply)(['logo' => UploadedFile::fake()->image('logo.png', 120, 120)]);
    approvePartner(Partner::sole(), $this->reviewer);

    // The city page block: labelled, explained, linking to the full list.
    $props = $this->get('/industries/solar/tx/houston')->assertOk()->viewData('page')['props'];
    expect($props['partners'])->toHaveCount(1)
        ->and($props['partners'][0]['category']['name'])->toBe('Mortgage lenders')
        ->and($props['partners'][0]['partners'][0])->toMatchArray(['name' => 'Bayou Home Loans', 'phone' => '+1 713 555 0101'])
        ->and($props['partners'][0]['partners'][0]['license'])->toMatchArray(['label' => 'NMLS ID', 'number' => '123456', 'verified' => now()->toDateString()])
        ->and($props['partners'][0]['partners'][0]['offer']['title'])->toBe('$500 off closing costs')
        ->and($props['partners'][0]['href'])->toBe('/partners/mortgage-lenders/tx/houston')
        ->and($props['partnersNote'])->toContain('flat monthly fee')->toContain('never pay per lead');
    // Partner details never include the contact's email.
    expect(json_encode($props['partners']))->not->toContain('maya@');

    // Category in a city: indexed, ItemList + FAQPage + breadcrumbs, FAQ on the page.
    $city = $this->get('/partners/mortgage-lenders/tx/houston')->assertOk()->viewData('page')['props'];
    expect($city['seo']['robots'])->not->toContain('noindex')
        ->and(collect($city['seo']['schema'])->pluck('@type')->all())->toContain('ItemList', 'FAQPage', 'BreadcrumbList')
        ->and($city['answer'])->toContain('1 mortgage lender advertises in Houston, TX')
        ->and(collect($city['faq'])->pluck('q')->all())->toContain('How do I choose a good mortgage lender in Houston?', 'How is this list ordered?');
    // A category with no one listed is shown but not indexed.
    expect($this->get('/partners/movers/tx/houston')->assertOk()->viewData('page')['props']['seo']['robots'])->toContain('noindex');
    $this->get('/partners/no-such-thing/tx/houston')->assertNotFound();

    // The partner page: LocalBusiness subtype with credential, offer and areas served.
    $show = $this->get('/partner/bayou-home-loans')->assertOk()->viewData('page')['props'];
    $biz = collect($show['seo']['schema'])->firstWhere('@type', 'FinancialService');
    expect($biz['name'])->toBe('Bayou Home Loans')->and($biz['hasCredential']['name'])->toBe('NMLS ID 123456')
        ->and($biz['makesOffer']['name'])->toBe('$500 off closing costs')->and(collect($biz['areaServed'])->pluck('name')->all())->toContain('Houston, TX', 'Austin, TX')
        ->and($show['answer'])->toContain('NMLS ID was checked on')
        ->and($show['seo']['robots'])->not->toContain('noindex');

    // Hub and sitemap.
    expect($this->get('/partners')->assertOk()->viewData('page')['props']['places'][0]['href'])->toStartWith('/partners/mortgage-lenders/tx/');
    $xml = (string) $this->get('/sitemaps/partners.xml')->assertOk()->getContent();
    expect($xml)->toContain('/partners/mortgage-lenders/tx/houston')->toContain('/partner/bayou-home-loans')->toContain('/partners<');
    $this->get('/partners/advertise')->assertOk()->assertInertia(fn ($pg) => $pg->component('partners/Advertise')->where('price', 49));

    // Expired offers disappear on their own.
    Partner::sole()->forceFill(['offer_expires_on' => now()->subDay()->toDateString()])->save();
    expect($this->get('/partner/bayou-home-loans')->viewData('page')['props']['partner']['offer'])->toBeNull();
});

it('rotates the top spot fairly: everyone leads once over as many days as there are partners', function () {
    $items = ['a', 'b', 'c', 'd'];
    $leaders = [];
    for ($day = 100; $day < 104; $day++) {
        $order = Partners::rotate($items, '5:1', $day);
        expect($order)->toHaveCount(4)->and(collect($order)->sort()->values()->all())->toBe($items);
        $leaders[] = $order[0];
    }
    expect(collect($leaders)->sort()->values()->all())->toBe($items);
    // Same order all day.
    expect(Partners::rotate($items, '5:1', 200))->toBe(Partners::rotate($items, '5:1', 200));
});

it('sends an enquiry to that partner alone, and lets members endorse without changing the order', function () {
    ($this->apply)();
    ($this->apply)(['business_name' => 'Gulf Coast Mortgage', 'email' => 'hello@gulfcoast.example', 'license_number' => '777']);
    foreach (Partner::all() as $p) {
        approvePartner($p, $this->reviewer);
    }
    $bayou = Partner::where('slug', 'bayou-home-loans')->sole();
    $msg = ['name' => 'Ana Buyer', 'email' => 'ana@example.org', 'phone' => '', 'message' => 'Can you pre-approve me this week?', 'city' => $this->houston->id, 'consent' => '1'];
    $this->post('/partner/bayou-home-loans/enquire', [...$msg, 'started' => ($this->token)(), 'website_url' => 'x'])->assertSessionHasErrors('message');
    $this->post('/partner/bayou-home-loans/enquire', [...$msg, 'started' => ($this->token)(), 'consent' => '0'])->assertSessionHasErrors('consent');
    $this->post('/partner/bayou-home-loans/enquire', [...$msg, 'started' => ($this->token)()])->assertSessionHas('status', 'partner-enquiry-sent');
    $row = DB::table('partner_enquiries')->sole();
    expect($row->partner_id)->toBe($bayou->id)->and($row->city_id)->toBe($this->houston->id);
    Notification::assertSentOnDemand(PartnerMail::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'maya@bayouloans.example' && $n->replyTo === 'ana@example.org'
        && in_array('Can you pre-approve me this week?', $n->lines, true));
    Notification::assertSentOnDemand(PartnerMail::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'ana@example.org');
    // Exclusive: the other lender never hears about it.
    $gulf = [];
    Notification::assertSentOnDemand(PartnerMail::class, function ($n, $ch, $to) use (&$gulf) {
        if ($to->routes['mail'] === 'hello@gulfcoast.example') {
            $gulf[] = $n->subject;
        }

        return true;
    });
    expect($gulf)->toBe(['We got your application', 'Your listing is live']);

    // Endorsements: members holding a seat only, one per team, shown on the page, never re-ordering.
    $before = array_map(fn (Partner $p) => $p->slug, Partners::inCity($this->houston)->first()['partners']);
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->post('/partner/gulf-coast-mortgage/endorse')->assertForbidden();
    $this->actingAs($this->member)->get('/partner/gulf-coast-mortgage')->assertInertia(fn ($pg) => $pg->where('member', true)->where('endorsed', false));
    $this->actingAs($this->member)->post('/partner/gulf-coast-mortgage/endorse', ['note' => 'Closed three loans for our buyers on time.'])->assertSessionHas('status', 'partner-endorsed');
    $this->actingAs($this->member)->post('/partner/gulf-coast-mortgage/endorse', ['note' => 'Still great.']);
    expect(DB::table('partner_endorsements')->count())->toBe(1);
    $page = $this->actingAs($this->member)->get('/partner/gulf-coast-mortgage')->viewData('page')['props'];
    expect($page['endorsed'])->toBeTrue()->and($page['endorsements'][0])->toMatchArray(['team' => 'Hurricane Sun', 'note' => 'Still great.'])->and($page['partner']['endorsements'])->toBe(1);
    expect(array_map(fn (Partner $p) => $p->slug, Partners::inCity($this->houston)->first()['partners']))->toBe($before);
    $this->actingAs($this->member)->delete('/partner/gulf-coast-mortgage/endorse')->assertSessionHas('status', 'partner-endorsement-withdrawn');
    expect(DB::table('partner_endorsements')->count())->toBe(0);
});

it('hides everything when the module is off or the category is switched off, and lets staff edit categories', function () {
    ($this->apply)();
    approvePartner(Partner::sole(), $this->reviewer);
    app(Settings::class)->set('modules.partner_directory', '0');
    $this->get('/partners')->assertNotFound();
    $this->get('/partner/bayou-home-loans')->assertNotFound();
    expect($this->get('/industries/solar/tx/houston')->viewData('page')['props']['partners'])->toBe([]);
    expect((string) $this->get('/sitemaps/partners.xml')->getContent())->not->toContain('bayou');
    // The footer links to the directory only while it is on.
    App\Domains\Content\Menus::forget();
    expect(json_encode(App\Domains\Content\Menus::forPublic()))->not->toContain('partners');
    app(Settings::class)->set('modules.partner_directory', '1');
    App\Domains\Content\Menus::forget();
    expect(json_encode(App\Domains\Content\Menus::forPublic()))->toContain('\/partners\/advertise');

    $mortgage = PartnerCategory::where('slug', 'mortgage-lenders')->sole();
    $this->actingAs($this->reviewer)->get('/admin/partners/categories')->assertOk()->assertInertia(fn ($pg) => $pg->component('admin/PartnerCategories')->where('categories.0.slug', 'mortgage-lenders'));
    $base = ['name' => 'Mortgage lenders', 'singular' => 'mortgage lender', 'licensed' => true, 'license_label' => 'NMLS ID', 'schema_type' => 'FinancialService', 'sort' => 1, 'active' => true];
    $this->put('/admin/partners/categories/'.$mortgage->id, [...$base, 'license_lookup_url' => 'http://bad.example'])->assertSessionHasErrors('license_lookup_url');
    $this->put('/admin/partners/categories/'.$mortgage->id, [...$base, 'faq' => [['q' => 'Do I need pre-approval?', 'a' => 'Yes, most sellers expect one.']]])->assertSessionHas('status', 'partner-category-saved');
    expect($mortgage->fresh()?->faq)->toBe([['q' => 'Do I need pre-approval?', 'a' => 'Yes, most sellers expect one.']]);
    $this->post('/admin/partners/categories', ['name' => 'Pest control', 'singular' => 'pest control company', 'schema_type' => 'LocalBusiness', 'active' => true])->assertSessionHasNoErrors();
    expect(PartnerCategory::where('slug', 'pest-control')->exists())->toBeTrue();
    $this->actingAs($this->member)->post('/admin/partners/categories', ['name' => 'X', 'singular' => 'x', 'schema_type' => 'LocalBusiness'])->assertForbidden();

    // Switching a category off hides its partners everywhere.
    $this->actingAs($this->reviewer)->put('/admin/partners/categories/'.$mortgage->id, [...$base, 'active' => false]);
    $this->post('/logout');
    $this->get('/partner/bayou-home-loans')->assertNotFound();
    $this->get('/partners/mortgage-lenders/tx/houston')->assertNotFound();
    expect($this->get('/industries/solar/tx/houston')->viewData('page')['props']['partners'])->toBe([]);
});
