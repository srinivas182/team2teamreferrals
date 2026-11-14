<?php

declare(strict_types=1);

namespace App\Domains\Network\Directory;

use App\Domains\Content\Industry\IndustryContent;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Platform\Modules\Modules;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Public directory pages: /groups, /groups/{group}, /industries/{industry}/{state} and
 * /industries/{industry}/{state}/{city}, plus the "Claim this seat" entry into sign-up and the application.
 * Each page opens with a one-paragraph answer built from the data, and shows only public facts.
 */
final class DirectoryController
{
    private const UNIT = ['city' => 'city', 'county' => 'county', 'metro' => 'metro area', 'state' => 'state'];

    public function index(Request $request): Response
    {
        $f = $request->validate(['industry' => ['nullable', 'string', 'max:60'], 'state' => ['nullable', 'string', 'size:2'], 'city' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:80']]);
        $rows = Directory::listing($f);
        $site = (string) config('seo.site_name');
        $filled = DB::table('seats')->whereIn('status', Directory::FILLED)->whereNotNull('primary_team_id')->count();
        $answer = "The {$site} directory lists ".count(Directory::groups()).' referral networks across '.Directory::groups()->pluck('industry_id')->unique()->count()
            ." industries. Each network gives one verified team per city or area a seat; {$filled} seats are filled today. Pick a network to see its teams and open seats.";
        $city = ! empty($f['city']) ? City::query()->find((int) $f['city']) : null;
        $filtered = array_filter($f, fn ($v) => $v !== null && $v !== '') !== [];

        return Inertia::render('Directory', [
            'groups' => $rows, 'answer' => $answer, 'filters' => ['industry' => $f['industry'] ?? '', 'state' => strtoupper((string) ($f['state'] ?? '')), 'q' => $f['q'] ?? '',
                'city' => $city !== null ? ['id' => $city->id, 'name' => $city->name.', '.DB::table('states')->where('id', $city->state_id)->value('code')] : null],
            'industries' => Industry::query()->whereIn('id', Directory::groups()->pluck('industry_id'))->orderBy('name')->get(['slug', 'name'])->map(fn ($i) => ['slug' => $i->slug, 'name' => $i->name])->values(),
            'states' => DB::table('states')->where('is_enabled', true)->orderBy('name')->get(['code', 'name']),
            'updatedOn' => substr((string) DB::table('seats')->max('updated_at'), 0, 10) ?: null,
            'seo' => (new SeoData(title: 'Referral network directory', description: $answer, path: '/groups', index: ! $filtered, // filtered views aren't separate pages
                schema: [Schema::organization(), $this->itemList(array_map(fn ($g) => [$g['name'], '/groups/'.$g['slug']], $rows)), Schema::breadcrumbs([['Home', '/'], ['Directory', '/groups']])]))->toArray(),
        ]);
    }

    public function group(Request $request, string $slug): Response
    {
        $group = Group::query()->listed()->with('industry')->where('slug', $slug)->firstOrFail();
        $industry = $group->industry;
        $t = $industry->terms();
        $seats = Directory::groupSeats($group);
        $states = collect($seats)->pluck('state')->filter()->unique()->count();
        $site = (string) config('seo.site_name');
        $unit = self::UNIT[$industry->territory_unit] ?? 'area';
        $answer = "{$group->name} is the {$industry->name} referral network on {$site}: one verified {$t['pro']} holds the seat for each {$unit}. "
            .count($seats).' '.(count($seats) === 1 ? 'seat is' : 'seats are')." filled in {$states} ".($states === 1 ? 'state' : 'states')
            .". Members accept or decline referrals within {$group->rule('accept_hours')} hours and contact clients within {$group->rule('first_contact_hours')} hours.";
        $path = '/groups/'.$group->slug;

        return Inertia::render('GroupPublic', [
            'group' => ['slug' => $group->slug, 'name' => $group->name, 'description' => (string) $group->description, 'industry' => $industry->name, 'industrySlug' => $industry->slug,
                'tier' => $industry->tier->value, 'unit' => $unit, 'terms' => $t, 'visibility' => $group->visibility,
                'owners' => $group->owners()->get()->map(fn (User $u) => $u->name)->values(),
                'rules' => ['accept_hours' => (int) $group->rule('accept_hours'), 'first_contact_hours' => (int) $group->rule('first_contact_hours'), 'update_days' => (int) $group->rule('update_days'),
                    'training' => (bool) $group->rule('training_required') && app(Modules::class)->enabled('training'), 'fees' => IndustryContent::tierFact($industry)],
                'pricing' => ['seat_price' => (int) ($group->pricing['seat_price'] ?? 10), 'trial_months' => (int) ($group->pricing['trial_months'] ?? 3)],
                'hub' => IndustryContent::record($industry)?->status === 'published' ? '/industries/'.$industry->slug : null],
            'answer' => $answer, 'seats' => $seats, 'open' => Directory::openPlaces($group), 'map' => NetworkMap::forGroup($group),
            'disclosure' => ($d = IndustryContent::disclosure($industry)) !== null ? \App\Domains\Content\Tokens::fill($d) : null,
            'viewer' => $request->user() instanceof User ? 'member' : 'guest',
            // Indexed once someone public holds a seat; until then the page is mostly empty.
            'seo' => (new SeoData(title: "{$group->name}: verified {$t['pros']} by {$unit}", description: $answer, path: $path, index: $seats !== [],
                image: \App\Domains\Seo\PageImage::path(['eyebrow' => $industry->name.' referral network', 'title' => $group->name,
                    'line' => count($seats).' '.(count($seats) === 1 ? 'seat' : 'seats').' filled · one verified '.$t['pro'].' per '.$unit, 'badge' => 'Verified pros only']),
                schema: [Schema::organization(), $this->itemList(array_map(fn ($s) => [$s['team']['name'].', '.$s['territory'], '/t/'.$s['team']['slug']], $seats)),
                    Schema::breadcrumbs([['Home', '/'], ['Directory', '/groups'], [$group->name, $path]])]))->toArray(),
        ]);
    }

    public function state(string $industrySlug, string $state): Response
    {
        [$industry, $st] = $this->place($industrySlug, $state);
        $groups = Directory::industryGroups($industry);
        abort_if($groups->isEmpty(), 404);
        $t = $industry->terms();
        $seats = collect($groups)->flatMap(fn (Group $g) => array_map(fn ($s) => [...$s, 'group' => $g->name, 'groupSlug' => $g->slug], Directory::groupSeats($g, $st->code)))->values()->all();
        $launch = $groups->first();
        $hub = IndustryContent::record($industry)?->status === 'published';
        $site = (string) config('seo.site_name');
        $answer = ($hub ? IndustryContent::placeSummary($industry, $st->name).' ' : '')
            .(count($seats) > 0 ? count($seats).' '.(count($seats) === 1 ? 'seat is' : 'seats are')." filled by verified {$t['pros']} in {$st->name} on {$site}."
                : "No {$t['pro']} holds a seat in {$st->name} on {$site} yet: every seat is open to claim.");
        $path = '/industries/'.$industry->slug.'/'.strtolower($st->code);

        return Inertia::render('IndustryState', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name, 'terms' => $t, 'hub' => $hub], 'state' => ['code' => $st->code, 'name' => $st->name],
            'answer' => $answer, 'seats' => $seats, 'open' => Directory::openPlaces($launch, 12, $st->code), 'group' => ['slug' => $launch->slug, 'name' => $launch->name],
            'request' => \App\Domains\Referrals\Consumer\ConsumerRequests::allowed($launch)
                ? ['group' => $launch->slug, 'cityId' => null, 'questions' => app(\App\Domains\Referrals\Consumer\ConsumerRequests::class)->questions($launch), 'pro' => null, 'responseHours' => null] : null,
            'disclosure' => ($d = IndustryContent::disclosure($industry)) !== null ? \App\Domains\Content\Tokens::fill($d) : null,
            'seo' => (new SeoData(title: "{$industry->name} in {$st->name}: verified {$t['pros']}", description: $answer, path: $path, index: count($seats) > 0,
                image: \App\Domains\Seo\PageImage::path(['eyebrow' => $industry->name.' · '.$st->name, 'title' => "Verified {$t['pros']} in {$st->name}",
                    'line' => count($seats).' '.(count($seats) === 1 ? 'seat' : 'seats').' filled · one verified '.$t['pro'].' per area', 'badge' => count($seats) > 0 ? 'See who holds each seat' : 'Every seat is open']),
                schema: [Schema::organization(), $this->itemList(array_map(fn ($s) => [$s['team']['name'].', '.$s['territory'], '/t/'.$s['team']['slug']], $seats)),
                    Schema::breadcrumbs([['Home', '/'], ['Industries', '/industries'], [$industry->name, '/industries/'.$industry->slug], [$st->name, $path]])]))->toArray(),
        ]);
    }

    public function city(string $industrySlug, string $state, string $citySlug): Response
    {
        [$industry, $st] = $this->place($industrySlug, $state);
        $city = City::query()->where(['state_id' => $st->id, 'slug' => $citySlug, 'is_enabled' => true])->firstOrFail();
        $groups = Directory::industryGroups($industry);
        abort_if($groups->isEmpty(), 404);
        $seats = $groups->map(fn (Group $g) => ['group' => $g->name, 'groupSlug' => $g->slug, 'seat' => Directory::seatAt($g, $city)])->filter(fn ($s) => $s['seat'] !== null)->values()->all();
        abort_if($seats === [], 404);
        $t = $industry->terms();
        $site = (string) config('seo.site_name');
        $answer = Directory::cityAnswer($industry, $city, $st->code, $seats);
        $hub = IndustryContent::record($industry)?->status === 'published';
        $held = collect($seats)->first(fn ($s) => $s['seat']['team'] !== null);
        $waiting = collect($seats)->sum(fn ($s) => $s['seat']['waitlist']);
        // Indexed once a seat is filled or has a waitlist, in the largest metro areas first: no thin "nobody here" pages.
        $index = ($held !== null || $waiting > 0) && Directory::inTopMetro($city);
        $path = '/industries/'.$industry->slug.'/'.strtolower($st->code).'/'.$city->slug;
        $schema = [Schema::organization(), Schema::breadcrumbs([['Home', '/'], ['Industries', '/industries'], [$industry->name, '/industries/'.$industry->slug],
            [$st->name, '/industries/'.$industry->slug.'/'.strtolower($st->code)], [$city->name, $path]])];
        $team = $held !== null ? $held['seat']['team'] : null;
        if ($held !== null && is_array($team)) {
            $schema[] = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $team['name'], 'url' => url('/t/'.$team['slug']),
                'areaServed' => $held['seat']['territory'], 'description' => $answer]
                + ($team['logo'] ? ['image' => $team['logo']] : [])
                + ($team['rating'] !== null ? ['aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => $team['rating'], 'ratingCount' => $team['ratings']]] : []);
        }

        // "How do I find a trusted pro here?", answered in the open (nothing hidden behind the form).
        $launch = $groups->first();
        $canRequest = \App\Domains\Referrals\Consumer\ConsumerRequests::allowed($launch);
        $holder = $held !== null ? $held['seat']['team'] : null;
        $howTo = "On {$site}, each {$industry->territory_unit} has one verified {$t['pro']} per network: licensed where the law requires it, held to response deadlines and rated by clients and other pros. "
            .($canRequest ? 'Describe what you need with the form on this page; after a quick text code to check your number, your request goes to '.($holder !== null ? $holder['name'] : 'the verified '.$t['pro'].' for '.$city->name)
                .' alone (or their backup), never sold to several.' : 'This is a directory: contact the listed '.$t['pro'].' directly; '.$site.' does not route requests or take any fee.');
        $schema[] = Schema::faq([["How do I find a trusted {$t['pro']} in {$city->name}, {$st->code}?", $howTo]]);
        $schema[] = array_filter(['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $industry->name.' in '.$city->name.', '.$st->code, 'serviceType' => $industry->name,
            'areaServed' => ['@type' => 'City', 'name' => $city->name.', '.$st->name], 'provider' => $holder !== null ? ['@type' => 'LocalBusiness', 'name' => $holder['name'], 'url' => url('/t/'.$holder['slug']), 'areaServed' => $city->name.', '.$st->code] : null,
            'url' => url($path)]);
        $request = $canRequest ? ['group' => $launch->slug, 'cityId' => $city->id, 'questions' => app(\App\Domains\Referrals\Consumer\ConsumerRequests::class)->questions($launch),
            'pro' => $holder['name'] ?? null, 'responseHours' => $holder !== null ? \App\Domains\Network\Profiles\ProfileRecord::response(\App\Domains\Network\Seats\Models\Team::query()->where('slug', (string) $holder['slug'])->firstOrFail())['hours'] : null] : null;

        return Inertia::render('CityPage', [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name, 'terms' => $t, 'hub' => $hub], 'state' => ['code' => $st->code, 'name' => $st->name],
            'city' => ['id' => $city->id, 'name' => $city->name, 'slug' => $city->slug], 'answer' => $answer,
            'intro' => $hub ? IndustryContent::placeSummary($industry, $st->name, $city->name) : null, 'seats' => $seats,
            'nearby' => Directory::nearby($groups->first(), $city), 'otherIndustries' => Directory::otherIndustries($city, $industry->id),
            'howTo' => $howTo, 'request' => $request,
            'disclosure' => ($d = IndustryContent::disclosure($industry)) !== null ? \App\Domains\Content\Tokens::fill($d) : null,
            'seo' => (new SeoData(title: "{$industry->name} in {$city->name}, {$st->code}: the verified {$t['pro']}", description: $answer, path: $path, index: $index, schema: $schema,
                image: \App\Domains\Seo\PageImage::path(['eyebrow' => $industry->name.' · '.$city->name.', '.$st->code, 'title' => $holder !== null ? (string) $holder['name'] : "{$industry->name} in {$city->name}",
                    'line' => $holder !== null ? "The verified {$t['pro']} for {$city->name}, {$st->code}" : "One verified {$t['pro']} per city: this seat is open", 'badge' => $holder !== null ? 'Seat holder' : 'Open: claim it']),
            ))->toArray(),
        ]);
    }

    /**
     * "Claim this seat": members go straight to the application with the city filled in; visitors sign up (or
     * reserve, before launch) and come back to it afterwards, through license check, application and training.
     */
    public function claim(Request $request, string $groupSlug, int $cityId): RedirectResponse
    {
        $group = Group::query()->listed()->where('slug', $groupSlug)->firstOrFail();
        $city = City::query()->where('is_enabled', true)->findOrFail($cityId);
        $apply = '/app/groups/'.$group->slug.'/apply?city='.$city->id;
        if ($request->user() !== null) {
            return redirect($apply);
        }
        $request->session()->put('url.intended', url($apply));
        if (app(Modules::class)->enabled('prelaunch')) {
            return redirect('/reserve?industry='.$group->industry->slug.'&city='.$city->id);
        }

        return redirect('/register')->with('status', 'claim-sign-up');
    }

    /** @return array{0: Industry, 1: stdClass} */
    private function place(string $industrySlug, string $state): array
    {
        $industry = Industry::query()->where('slug', $industrySlug)->firstOrFail();
        $st = DB::table('states')->where(['code' => strtoupper($state), 'is_enabled' => true])->first(['id', 'code', 'name']);
        abort_if($st === null || strtolower($state) !== $state, 404); // one address per page: lowercase state

        return [$industry, $st];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $items  name and path
     * @return array<string, mixed>
     */
    private function itemList(array $items): array
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');
        $items = array_values($items);

        return ['@context' => 'https://schema.org', '@type' => 'ItemList', 'numberOfItems' => count($items),
            'itemListElement' => array_map(fn ($i, $n) => ['@type' => 'ListItem', 'position' => $n + 1, 'name' => $i[0], 'url' => $base.$i[1]], array_slice($items, 0, 100), array_keys(array_slice($items, 0, 100)))];
    }
}
