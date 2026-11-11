<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Introductions;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Introduce a client to other industries' seat holders in the same city: one tracked introduction per network. */
final class IntroductionController
{
    public function __construct(private readonly Referrals $referrals, private readonly IndustryTemplates $templates) {}

    public function create(Request $request): Response
    {
        $user = $this->user($request);
        $team = Team::of($user) ?? Team::forLeader($user);
        $mine = Group::query()->with('industry')->whereIn('id', DB::table('seats')->where('primary_team_id', $team->id)->pluck('group_id'))->orderBy('name')->get();
        $client = null;
        $from = $mine->firstWhere('slug', (string) $request->query('from')) ?? $mine->first();
        $cityId = (int) $request->query('city');
        // From a referral: its network, city and client (when this team may see the client's details).
        if ($request->query('referral') && ($ref = Referral::query()->with('seat')->where('ulid', (string) $request->query('referral'))->first()) !== null) {
            abort_unless($this->referrals->canView($ref, $user), 403);
            $from = $mine->firstWhere('id', $ref->group_id) ?? $from;
            $cityId = $ref->seat->territory_id;
            $sees = $team->id === $ref->sender_team_id || ($team->id === $ref->receiver_team_id && $ref->status === 'accepted');
            $client = $sees ? ['name' => $ref->client_name, 'phone' => $ref->client_phone, 'email' => $ref->client_email] : null;
        }
        $city = $cityId ? City::query()->with('state')->find($cityId) : null;

        return Inertia::render('app/Introduce', [
            'from' => $from ? ['slug' => $from->slug, 'name' => $from->name] : null,
            'mine' => $mine->map(fn (Group $g) => ['slug' => $g->slug, 'name' => $g->name])->values(),
            'city' => $city ? ['id' => $city->id, 'name' => $city->name.', '.$city->state->code] : null,
            'client' => $client,
            'suggestions' => $from && $city ? array_map(fn ($s) => [...$s, 'form' => $this->fields($s['slug'])], Introductions::suggestions($from, $city->id, array_values(array_map('intval', $mine->pluck('id')->all())))) : [],
            'seo' => SeoData::private('Introduce a client')->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $d = $request->validate([
            'from' => ['required', 'string'], 'city_id' => ['required', 'integer', 'exists:cities,id'],
            'client_name' => ['required', 'string', 'max:120'], 'client_phone' => ['nullable', 'required_without:client_email', 'string', 'max:32'], 'client_email' => ['nullable', 'email:rfc', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'intros' => ['required', 'array', 'min:1', 'max:6'], 'intros.*.group' => ['required', 'string'], 'intros.*.answers' => ['nullable', 'array'],
            // Consent names each business: one confirmation per introduction.
            'intros.*.consent' => ['accepted'],
        ], ['intros.*.consent.accepted' => 'Confirm your client agreed to be introduced to each business.', 'intros.required' => 'Choose at least one network to introduce your client to.']);
        $from = Group::query()->with('industry')->where('slug', $d['from'])->firstOrFail();
        $city = City::query()->findOrFail((int) $d['city_id']);
        $sent = 0;
        $errors = [];
        foreach ($d['intros'] as $i => $intro) {
            $group = Group::query()->with('industry')->where('slug', (string) $intro['group'])->first();
            if ($group === null) {
                continue;
            }
            $answers = [...(array) ($intro['answers'] ?? []), 'client_name' => $d['client_name'], 'client_phone' => $d['client_phone'] ?? null, 'client_email' => $d['client_email'] ?? null];
            try {
                $r = $this->referrals->send($user, $group, $city, $answers, true, $d['notes'] ?? null, true, false, $from);
                $sent += $r['referral'] !== null ? 1 : 0;
            } catch (ValidationException $e) {
                foreach ($e->errors() as $k => $msgs) {
                    $errors["intros.{$i}.".$k] = $group->name.': '.$msgs[0];
                }
            }
        }
        if ($errors !== [] && $sent === 0) {
            throw ValidationException::withMessages($errors);
        }

        return redirect('/app/sent')->with('status', $sent === 1 ? 'introduction-sent' : 'introductions-sent');
    }

    /** Admins: allow or block an industry pair, with a counsel note. */
    public function pairings(Request $request): Response
    {
        return Inertia::render('admin/Pairings', [
            'industries' => Industry::query()->orderBy('name')->get(['id', 'name', 'tier'])->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'tier' => $i->tier->value]),
            'rules' => DB::table('industry_pairings as p')->join('industries as a', 'a.id', '=', 'p.from_industry_id')->join('industries as b', 'b.id', '=', 'p.to_industry_id')
                ->orderBy('a.name')->get(['p.id', 'a.name as from', 'b.name as to', 'p.allowed', 'p.note'])->map(fn ($r) => ['id' => (int) $r->id, 'from' => (string) $r->from, 'to' => (string) $r->to, 'allowed' => (bool) $r->allowed, 'note' => (string) $r->note]),
            'status' => session('status'),
            'seo' => SeoData::private('Introduction pairings')->toArray(),
        ]);
    }

    public function savePairing(Request $request): RedirectResponse
    {
        $d = $request->validate(['from' => ['required', 'integer', 'exists:industries,id', 'different:to'], 'to' => ['required', 'integer', 'exists:industries,id'], 'allowed' => ['required', 'boolean'], 'note' => ['required', 'string', 'min:5', 'max:500']],
            ['note.required' => 'Add the counsel note behind this decision.']);
        DB::table('industry_pairings')->updateOrInsert(['from_industry_id' => $d['from'], 'to_industry_id' => $d['to']], ['allowed' => (bool) $d['allowed'], 'note' => $d['note'], 'updated_by' => $this->user($request)->id, 'updated_at' => now(), 'created_at' => now()]);
        activity('admin')->causedBy($request->user())->event('pairing')->withProperties($d)->log('Introduction pairing '.($d['allowed'] ? 'allowed' : 'blocked'));

        return back()->with('status', 'pairing-saved');
    }

    /** @return list<array<string, mixed>> the receiving network's form, minus the client fields asked once */
    private function fields(string $slug): array
    {
        $g = Group::query()->with('industry')->where('slug', $slug)->firstOrFail();

        return array_values(array_filter($this->templates->resolve('form', $g->industry, $g->id)['body'], fn ($f) => ! in_array($f['key'], ['client_name', 'client_phone', 'client_email'], true)));
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
