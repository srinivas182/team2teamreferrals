<?php

declare(strict_types=1);

namespace App\Domains\Partners\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Locations\Models\State;
use App\Domains\Partners\Partner;
use App\Domains\Partners\PartnerCategory;
use App\Domains\Partners\PartnerListing;
use App\Domains\Partners\Partners;
use App\Domains\Partners\PartnerStats;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use App\Support\FormGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public side of the partner directory: the hub, "Advertise with us", a category in a city, and each partner's page
 * with its exclusive enquiry form. Members can endorse partners they work with.
 */
final class PartnersController
{
    /** Shown wherever partners are listed, so nobody mistakes an advert for a recommendation. */
    public const HOW_IT_WORKS = 'These businesses pay a flat monthly fee to advertise here. They never pay per lead or per closing, and network members are not paid for recommending them. Everyone listed in a city takes the top spot in turn, so the order changes every day. A listing is not an endorsement by us: compare providers and choose whoever suits you.';

    public function index(): Response
    {
        $counts = DB::table('partner_listings as l')->join('partners as p', 'p.id', '=', 'l.partner_id')->where('p.status', 'approved')->whereIn('p.billing_status', Partner::LIVE_BILLING)->where('l.status', 'active')
            ->groupBy('l.category_id')->selectRaw('l.category_id, count(distinct p.id) as partners, count(distinct l.city_id) as cities')->get()->keyBy('category_id');
        $categories = PartnerCategory::query()->active()->get()->map(fn (PartnerCategory $c) => ['slug' => $c->slug, 'name' => $c->name,
            'partners' => (int) ($counts[$c->id]->partners ?? 0), 'cities' => (int) ($counts[$c->id]->cities ?? 0)])->values();
        $places = DB::table('partner_listings as l')->join('partners as p', 'p.id', '=', 'l.partner_id')->join('cities as c', 'c.id', '=', 'l.city_id')->join('states as s', 's.id', '=', 'c.state_id')
            ->join('partner_categories as k', 'k.id', '=', 'l.category_id')->where('p.status', 'approved')->whereIn('p.billing_status', Partner::LIVE_BILLING)->where('l.status', 'active')->where('k.is_active', true)
            ->groupBy('k.slug', 'k.name', 'c.name', 'c.slug', 's.code')->selectRaw('k.slug as category, k.name as category_name, c.name as city, c.slug as city_slug, s.code as state, count(*) as n')
            ->orderByDesc('n')->limit(60)->get()
            ->map(fn ($r) => ['label' => $r->category_name.' in '.$r->city.', '.$r->state, 'href' => '/partners/'.$r->category.'/'.strtolower((string) $r->state).'/'.$r->city_slug, 'count' => (int) $r->n])->values();
        $site = (string) config('seo.site_name');
        $answer = "The {$site} partner directory lists local mortgage lenders, title companies, inspectors, insurance agents and other businesses that serve home buyers and sellers. They advertise for a flat monthly fee; enquiries go to the business you choose and nobody else.";
        $faq = [['q' => 'How does the partner directory work?', 'a' => self::HOW_IT_WORKS], ['q' => 'How do I advertise my business here?', 'a' => 'Apply on the Advertise page with your business details, license number where your field needs one, and the cities you serve. We review every application by hand; you pay nothing until it is approved, and there are no per-lead fees.']];

        return Inertia::render('partners/Index', [
            'categories' => $categories, 'places' => $places, 'answer' => $answer, 'howItWorks' => self::HOW_IT_WORKS, 'faq' => $faq,
            'seo' => (new SeoData(title: 'Local partners for home buyers and sellers', description: $answer, path: '/partners', index: $places->isNotEmpty(),
                schema: [Schema::organization(), Schema::faq(array_map(fn ($f) => [$f['q'], $f['a']], $faq)), Schema::breadcrumbs([['Home', '/'], ['Local partners', '/partners']])]))->toArray(),
        ]);
    }

    public function advertise(): Response
    {
        return Inertia::render('partners/Advertise', [
            'categories' => PartnerCategory::query()->active()->get()->map(fn (PartnerCategory $c) => ['slug' => $c->slug, 'name' => $c->name, 'licensed' => $c->licensed, 'licenseLabel' => $c->license_label])->values(),
            'price' => \App\Domains\Partners\PartnerBilling::settings()['price'], 'discount' => \App\Domains\Partners\PartnerBilling::settings()['annualDiscount'],
            'trialDays' => \App\Domains\Partners\PartnerBilling::settings()['trialDays'], 'cap' => Partners::cap(),
            'howItWorks' => self::HOW_IT_WORKS,
            'status' => session('status'),
            'seo' => (new SeoData(title: 'Advertise your business to local home buyers and sellers', description: 'List your mortgage, title, inspection, insurance or moving business on city pages for a flat monthly fee. No per-lead fees; every enquiry is yours alone.',
                path: '/partners/advertise', schema: [Schema::organization(), Schema::breadcrumbs([['Home', '/'], ['Local partners', '/partners'], ['Advertise', '/partners/advertise']])]))->toArray(),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'category' => ['required', 'string', 'max:60'], 'business_name' => ['required', 'string', 'min:2', 'max:120'], 'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'], 'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+().\-\s]{7,32}$/'],
            'website' => ['nullable', 'url:https', 'max:300'], 'description' => ['required', 'string', 'min:40', 'max:2000'],
            'license_number' => ['nullable', 'string', 'max:60'], 'license_state' => ['nullable', 'string', 'size:2'],
            'offer_title' => ['nullable', 'string', 'max:120'], 'offer_details' => ['nullable', 'string', 'max:500', 'required_with:offer_title'], 'offer_expires_on' => ['nullable', 'date', 'after:today'],
            'cities' => ['required', 'array', 'min:1', 'max:25'], 'cities.*' => ['integer'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'], 'consent' => ['accepted'], 'started' => ['required', 'string'],
        ], ['website.url' => 'Use your full https:// address.', 'cities.required' => 'Choose at least one city you serve.', 'consent.accepted' => 'Please agree to the advertising terms.']);
        FormGuard::check($request, 'business_name');
        /** @var array{category: string, business_name: string, contact_name: string, email: string, phone?: ?string, website?: ?string, description: string, license_number?: ?string, license_state?: ?string, offer_title?: ?string, offer_details?: ?string, offer_expires_on?: ?string, cities: list<int>} $d */
        $d['cities'] = array_values(array_unique(array_map('intval', (array) $d['cities'])));
        Partners::apply($d, $request->file('logo'), FormGuard::ipHash($request));

        return back()->with('status', 'partner-applied');
    }

    /** One category in one city: everyone listed, in today's rotation, clearly marked as advertising. */
    public function city(string $categorySlug, string $state, string $citySlug): Response
    {
        $category = PartnerCategory::query()->where(['slug' => $categorySlug, 'is_active' => true])->firstOrFail();
        $st = State::query()->where('code', strtoupper($state))->firstOrFail();
        $city = City::query()->with('state')->where(['state_id' => $st->id, 'slug' => $citySlug, 'is_enabled' => true])->firstOrFail();
        $partners = Partners::inCity($city, $category)->first()['partners'] ?? [];
        $path = '/partners/'.$category->slug.'/'.strtolower($st->code).'/'.$city->slug;
        $n = count($partners);
        $answer = $n > 0
            ? "{$n} ".Str::plural($category->singular, $n)." advertise in {$city->name}, {$st->code}. They are listed in a daily rotation, and a message you send goes only to the business you choose."
            : "No {$category->name} advertise in {$city->name}, {$st->code} yet.";
        $faq = array_map(fn ($f) => [str_replace('?', " in {$city->name}?", (string) $f['q']), (string) $f['a']], array_slice($category->faq ?? [], 0, 1));
        $faq = [...$faq, ...array_map(fn ($f) => [(string) $f['q'], (string) $f['a']], array_slice($category->faq ?? [], 1)), ['How is this list ordered?', self::HOW_IT_WORKS]];
        $others = PartnerCategory::query()->active()->where('id', '!=', $category->id)->whereIn('id', PartnerListing::query()->where(['city_id' => $city->id, 'status' => 'active'])
            ->whereHas('partner', fn ($q) => $q->live())->select('category_id'))->get()
            ->map(fn (PartnerCategory $c) => ['name' => $c->name, 'href' => '/partners/'.$c->slug.'/'.strtolower($st->code).'/'.$city->slug])->values();

        return Inertia::render('partners/City', [
            'category' => ['slug' => $category->slug, 'name' => $category->name, 'singular' => $category->singular],
            'city' => ['id' => $city->id, 'name' => $city->name, 'slug' => $city->slug], 'state' => ['code' => $st->code, 'name' => $st->name],
            'partners' => array_map(fn (Partner $p) => Partners::card($p), $partners), 'answer' => $answer, 'howItWorks' => self::HOW_IT_WORKS,
            'faq' => array_map(fn ($f) => ['q' => $f[0], 'a' => $f[1]], $faq), 'others' => $others,
            'seo' => (new SeoData(title: "{$category->name} in {$city->name}, {$st->code}", description: $answer, path: $path, index: $n > 0,
                schema: [Schema::organization(), Schema::faq($faq),
                    ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => "{$category->name} in {$city->name}, {$st->code}",
                        'itemListElement' => array_map(fn (Partner $p, int $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $p->business_name, 'url' => url($p->path())], $partners, array_keys($partners))],
                    Schema::breadcrumbs([['Home', '/'], ['Local partners', '/partners'], [$category->name.' in '.$city->name, $path]])]))->toArray(),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $p = $this->find($slug);
        $c = $p->category;
        $cities = PartnerListing::query()->with('city.state')->where(['partner_id' => $p->id, 'status' => 'active'])->get()
            ->map(fn (PartnerListing $l) => ['id' => $l->city->id, 'name' => $l->city->name.', '.$l->city->state->code,
                'href' => '/partners/'.$c->slug.'/'.strtolower((string) $l->city->state->code).'/'.$l->city->slug])->sortBy('name')->values();
        $card = Partners::card($p);
        $user = $request->user();
        $member = $user instanceof User ? Partners::memberTeam($user) : null;
        $endorsed = $member !== null && DB::table('partner_endorsements')->where(['partner_id' => $p->id, 'team_id' => $member])->exists();
        $answer = "{$p->business_name} is {$this->article($c->singular)} {$c->singular} serving ".$cities->pluck('name')->take(3)->implode('; ').($cities->count() > 3 ? ' and more' : '')
            .'. It advertises in the '.config('seo.site_name').' partner directory'.($card['license'] !== null ? ", and its {$card['license']['label']} was checked on {$card['license']['verified']}" : '').'.';
        $business = array_filter(['@context' => 'https://schema.org', '@type' => $c->schema_type, 'name' => $p->business_name, 'url' => url($p->path()), 'description' => $p->description,
            'image' => $card['logo'], 'logo' => $card['logo'], 'telephone' => $p->phone, 'sameAs' => $p->website !== null ? [$p->website] : null,
            'areaServed' => $cities->map(fn ($x) => ['@type' => 'City', 'name' => $x['name']])->all(),
            'makesOffer' => $card['offer'] !== null ? array_filter(['@type' => 'Offer', 'name' => $card['offer']['title'], 'description' => $card['offer']['details'], 'validThrough' => $card['offer']['expires']]) : null,
            'hasCredential' => $card['license'] !== null ? ['@type' => 'EducationalOccupationalCredential', 'credentialCategory' => 'license', 'name' => $card['license']['label'].' '.$card['license']['number']] : null]);

        return Inertia::render('partners/Show', [
            'partner' => [...$card, 'description' => $p->description, 'categorySlug' => $c->slug, 'categoryName' => $c->name],
            'cities' => $cities, 'endorsements' => Partners::endorsements($p), 'answer' => $answer, 'howItWorks' => self::HOW_IT_WORKS,
            'member' => $member !== null, 'endorsed' => $endorsed, 'status' => session('status'),
            'seo' => (new SeoData(title: $p->business_name.': '.Str::ucfirst($c->singular), description: $answer, path: $p->path(), index: $cities->isNotEmpty(), image: $card['logo'],
                schema: [Schema::organization(), $business, Schema::breadcrumbs([['Home', '/'], ['Local partners', '/partners'], [$p->business_name, $p->path()]])]))->toArray(),
        ]);
    }

    public function enquire(Request $request, string $slug): RedirectResponse
    {
        $p = $this->find($slug);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190'], 'phone' => ['nullable', 'string', 'max:32'],
            'message' => ['required', 'string', 'min:10', 'max:3000'], 'city' => ['nullable', 'integer'], 'consent' => ['accepted'], 'started' => ['required', 'string']],
            ['consent.accepted' => 'Please confirm we may pass your message to this business.']);
        FormGuard::check($request, 'message');
        $city = isset($d['city']) ? City::query()->whereIn('id', PartnerListing::query()->where('partner_id', $p->id)->select('city_id'))->find((int) $d['city']) : null;
        Partners::enquire($p, $d, $city, FormGuard::ipHash($request));

        return back()->with('status', 'partner-enquiry-sent');
    }

    public function endorse(Request $request, string $slug): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $d = $request->validate(['note' => ['nullable', 'string', 'max:280']]);
        Partners::endorse($this->find($slug), $user, isset($d['note']) ? trim((string) $d['note']) : null);

        return back()->with('status', 'partner-endorsed');
    }

    public function withdraw(Request $request, string $slug): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Partners::withdraw($this->find($slug), $user);

        return back()->with('status', 'partner-endorsement-withdrawn');
    }

    /**
     * Views and clicks, sent by the browser (pages are cached, so they can't be counted on the server). Bots and
     * repeat visits are left out; unknown or hidden partners are ignored.
     */
    public function seen(Request $request): \Illuminate\Http\Response
    {
        $d = $request->validate(['k' => ['required', 'in:view,click'], 's' => ['required', 'array', 'max:30'], 's.*' => ['string', 'max:120'], 'c' => ['nullable', 'integer']]);
        $partners = Partner::query()->with('category')->whereIn('slug', (array) $d['s'])->live()->get();
        $cityId = isset($d['c']) ? (int) $d['c'] : null;
        foreach ($partners as $p) {
            $listed = $cityId !== null && PartnerListing::query()->where(['partner_id' => $p->id, 'city_id' => $cityId, 'status' => 'active'])->exists();
            PartnerStats::record($request, $p, $listed ? $cityId : null, $d['k'] === 'view' ? 'views' : 'clicks');
        }

        return response()->noContent();
    }

    /** Only approved partners have public pages; anything else is not found (so it is never indexed). */
    private function find(string $slug): Partner
    {
        return Partner::query()->with('category')->where('slug', $slug)->live()->firstOrFail();
    }

    private function article(string $word): string
    {
        return in_array(strtolower($word[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }
}
