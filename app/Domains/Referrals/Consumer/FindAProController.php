<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Consumer;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Phone\PhoneNumber;
use App\Domains\Network\Directory\Directory;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use App\Support\FormGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/** The public side of "Find a trusted pro": the landing page, the request, the phone check and the confirmation; and the open request board for members. */
final class FindAProController
{
    public function __construct(private readonly ConsumerRequests $requests) {}

    public function index(): Response
    {
        $site = (string) config('seo.site_name');
        $answer = "To find a trusted pro on {$site}, pick what you need and your city. Each city has one verified pro per industry: licensed where required, held to response deadlines, and rated by clients and other pros. Your request goes to that one pro, never sold to several.";
        $industries = Industry::query()->whereIn('id', Directory::groups()->pluck('industry_id'))->orderBy('name')->get()
            ->filter(fn (Industry $i) => $i->tier->allowsConsumerRequests())->map(fn ($i) => ['slug' => $i->slug, 'name' => $i->name])->values();

        return Inertia::render('FindAPro', ['answer' => $answer, 'industries' => $industries,
            'seo' => (new SeoData(title: 'Find a trusted pro', description: $answer, path: '/find-a-pro',
                schema: [Schema::organization(), Schema::faq([['How do I find a trusted pro near me?', $answer]]), Schema::breadcrumbs([['Home', '/'], ['Find a pro', '/find-a-pro']])]))->toArray()]);
    }

    /** From the landing page: go to the city page for that industry, where the request form is. */
    public function go(Request $request): RedirectResponse
    {
        $d = $request->validate(['industry' => ['required', 'string', 'exists:industries,slug'], 'city_id' => ['required', 'integer', 'exists:cities,id']]);
        $city = City::query()->findOrFail((int) $d['city_id']);
        $state = strtolower((string) DB::table('states')->where('id', $city->state_id)->value('code'));

        return redirect('/industries/'.$d['industry'].'/'.$state.'/'.$city->slug.'#request');
    }

    /**
     * The request form's start token, fetched when the form opens: the page itself is cached at the edge without a
     * session, so this (uncached) call starts one and sets the cookie the form needs to post.
     */
    public function token(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['started' => FormGuard::token()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $groupSlug, int $cityId): RedirectResponse
    {
        $group = Group::query()->with('industry')->where('slug', $groupSlug)->firstOrFail();
        $city = City::query()->findOrFail($cityId);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:32'], 'email' => ['nullable', 'email:rfc', 'max:190'],
            'contact_method' => ['required', 'in:'.implode(',', array_keys(ConsumerRequests::CONTACT))], 'best_time' => ['nullable', 'in:'.implode(',', array_keys(ConsumerRequests::BEST_TIME))],
            'timing' => ['required', 'in:'.implode(',', array_keys(ConsumerRequests::TIMING))], 'answers' => ['nullable', 'array'], 'consent' => ['accepted'], 'started' => ['required', 'string'],
        ], ['consent.accepted' => 'Please agree to be contacted so a pro can call you.']);
        FormGuard::check($request, 'name');
        $r = $this->requests->start($group, $city, $d, parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: null, FormGuard::ipHash($request));
        $request->session()->put('consumer_request', $r['ulid']); // only this browser can see the request's pages

        return redirect('/request/'.$r['ulid'].($r['duplicate'] ? '/done' : '/verify'));
    }

    public function verifyForm(Request $request, string $ulid): Response|RedirectResponse
    {
        $req = $this->mine($request, $ulid);
        if ($req->status !== 'unverified') {
            return redirect('/request/'.$ulid.'/done');
        }

        return Inertia::render('RequestVerify', ['id' => $ulid, 'phone' => PhoneNumber::masked((string) $req->phone), 'status' => session('status'),
            'seo' => (new SeoData(title: 'Check your phone', description: (string) config('seo.site_name'), index: false))->toArray()]);
    }

    public function verify(Request $request, string $ulid): RedirectResponse
    {
        $this->mine($request, $ulid);
        $d = $request->validate(['code' => ['required', 'digits:6']], ['code.digits' => 'Enter the 6-digit code from the text message.']);
        $this->requests->verify($ulid, (string) $d['code']);

        return redirect('/request/'.$ulid.'/done');
    }

    public function resend(Request $request, string $ulid): RedirectResponse
    {
        $req = $this->mine($request, $ulid);
        abort_unless($req->status === 'unverified', 404);
        $this->requests->sendCode($ulid);

        return back()->with('status', 'code-sent');
    }

    public function done(Request $request, string $ulid): Response
    {
        $req = $this->mine($request, $ulid);
        $ref = $req->referral_id ? DB::table('referrals')->where('id', $req->referral_id)->first(['client_token', 'receiver_team_id', 'respond_by']) : null;
        $team = $ref?->receiver_team_id ? Team::query()->find((int) $ref->receiver_team_id) : null;

        return Inertia::render('RequestDone', [
            'status' => (string) $req->status, 'first' => (string) strtok((string) $req->name, ' '),
            'pro' => $team !== null ? ['name' => $team->name, 'slug' => $team->profile_public ? $team->slug : null] : null,
            'respondBy' => $ref?->respond_by, 'portal' => $ref?->client_token ? url('/r/'.$ref->client_token) : null,
            'seo' => (new SeoData(title: 'Request sent', description: (string) config('seo.site_name'), index: false))->toArray(),
        ]);
    }

    /** Members: the open request board (requests no seat holder took), for networks and states they serve. */
    public function board(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $team = Team::of($user) ?? Team::forLeader($user);

        return Inertia::render('app/RequestBoard', ['requests' => $this->requests->boardFor($team), 'status' => session('status'), 'seo' => SeoData::private('Open request board')->toArray()]);
    }

    public function take(Request $request, string $ulid): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $ref = $this->requests->take($ulid, $user);

        return redirect('/app/referrals/'.$ref->ulid)->with('status', 'request-taken');
    }

    private function mine(Request $request, string $ulid): stdClass
    {
        abort_unless($request->session()->get('consumer_request') === $ulid, 404);

        return $this->requests->find($ulid) ?? abort(404);
    }
}
