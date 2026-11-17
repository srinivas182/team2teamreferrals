<?php

declare(strict_types=1);

namespace App\Domains\Partners\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Directory\Directory;
use App\Domains\Network\Directory\DirectoryUrls;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Partners\Partner;
use App\Domains\Partners\Partners;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Portal → Local partners: advertisers in the member's own cities, to pass on to clients. */
final class MemberPartnersController
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $team = Team::of($user);
        $cityIds = [];
        if ($team !== null) {
            foreach (DB::table('seats')->where('primary_team_id', $team->id)->whereIn('status', Directory::FILLED)->get(['territory_type', 'territory_id']) as $s) {
                $c = DirectoryUrls::mainCity((string) $s->territory_type, (int) $s->territory_id);
                if ($c !== null) {
                    $cityIds[] = (int) $c->id;
                }
            }
        }
        $chosen = $request->integer('city') ?: null;
        if ($chosen !== null) {
            $cityIds = [$chosen];
        }
        $cities = City::query()->with('state')->whereIn('id', array_unique($cityIds))->orderByDesc('population')->get();

        return Inertia::render('app/Partners', [
            'cities' => $cities->map(fn (City $c) => [
                'id' => $c->id, 'name' => $c->name.', '.$c->state->code,
                'groups' => Partners::inCity($c)->map(fn ($g) => ['category' => $g['category']->name, 'partners' => array_map(fn (Partner $p) => Partners::card($p), $g['partners'])])->values()->all(),
            ])->values(),
            'note' => PartnersController::HOW_IT_WORKS,
            'status' => session('status'),
            'seo' => SeoData::private('Local partners')->toArray(),
        ]);
    }

    public function share(Request $request, string $slug): RedirectResponse
    {
        $user = $this->user($request);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190'], 'note' => ['nullable', 'string', 'max:500']]);
        $partner = Partner::query()->with('category')->where('slug', $slug)->live()->firstOrFail();
        /** @var array{name: string, email: string, note?: ?string} $d */
        Partners::share($partner, $user, $d);

        return back()->with('status', 'partner-shared');
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
