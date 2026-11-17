<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Network\Directory\Directory;
use App\Domains\Network\Directory\NetworkMap;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Platform\Modules\Modules;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\Aeo;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page (Sprint 52a, the approved design): every figure on it is real, examples are labelled as examples,
 * and the "send a referral" demo is a clearly marked simulation that needs no sign-up.
 */
final class HomeController
{
    public function __invoke(): Response
    {
        $data = Cache::remember('home:data', 300, function (): array {
            $groups = Directory::groups();
            $live = $groups->keyBy('industry_id');
            $hubs = DB::table('industry_content')->where('status', 'published')->pluck('industry_id')->flip();
            $industries = Industry::query()->orderBy('name')->get()->map(function (Industry $i) use ($live, $hubs) {
                $g = $live[$i->id] ?? null;
                $t = $i->terms();

                return ['slug' => $i->slug, 'name' => $i->name, 'family' => Industry::FAMILIES[$i->family] ?? $i->family, 'pro' => $t['pro'], 'client' => $t['client'],
                    'href' => isset($hubs[$i->id]) ? '/industries/'.$i->slug : ($g !== null ? '/groups/'.$g->slug : '/groups?industry='.$i->slug), 'live' => $g !== null];
            })->values()->all();
            // One query for the seat figures: filled seats per network, and the states they're in.
            $perGroup = DB::table('seats')->whereIn('status', Directory::FILLED)->whereNotNull('primary_team_id')->groupBy('group_id')->selectRaw('group_id, count(*) as n')->pluck('n', 'group_id');
            $states = DB::table('seats')->whereIn('status', Directory::FILLED)->whereNotNull('primary_team_id')->whereNotNull('state_code')->distinct()->count('state_code');
            // Median minutes to accept, from the last 90 days (shown only once there's enough to be meaningful).
            $minutes = DB::table('referrals')->whereNotNull('accepted_at')->where('created_at', '>=', now()->subDays(90))->limit(2000)->get(['created_at', 'accepted_at'])
                ->map(fn ($r) => (int) round((strtotime((string) $r->accepted_at) - strtotime((string) $r->created_at)) / 60))->filter(fn ($m) => $m >= 0)->sort()->values();
            $mapGroup = $groups->sortByDesc(fn ($g) => (int) ($perGroup[$g->id] ?? 0))->first();

            return [
                'industries' => $industries,
                'stats' => ['seats' => (int) $perGroup->sum(), 'networks' => $groups->count(), 'industries' => count($industries),
                    'states' => $states, 'acceptMinutes' => $minutes->count() >= 20 ? (int) $minutes[intdiv($minutes->count(), 2)] : null],
                'map' => $mapGroup !== null ? ['groups' => $groups->map(fn ($g) => ['slug' => $g->slug, 'name' => $g->industry->name])->values()->all(), 'slug' => $mapGroup->slug, 'initial' => NetworkMap::forGroup($mapGroup)] : null,
            ];
        });
        $s = app(Settings::class);
        $demo = config('app.demo_install') ? '/demo' : ((string) $s->get('demo.url', '') ?: null);

        return Inertia::render('Home', [
            ...$data,
            'joinHref' => app(Modules::class)->enabled('prelaunch') ? '/reserve' : '/register',
            'demoUrl' => $demo,
            'faq' => Aeo::faq(),
            'seo' => (new SeoData(
                title: 'Team to Team Referrals — hand out-of-area leads to teams you trust',
                description: (string) config('seo.default_description'),
                path: '/',
                schema: [Schema::organization(), Schema::website(), Schema::faq(array_map(fn ($x) => [$x['q'], $x['a']], Aeo::faq()))],
            ))->toArray(),
        ]);
    }
}
