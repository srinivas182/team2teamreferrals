<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use App\Domains\Referrals\Referrals;
use App\Domains\Seo\SeoData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ReferralController
{
    public function __construct(private readonly Referrals $referrals, private readonly IndustryTemplates $templates) {}

    public function create(Request $request): Response
    {
        $user = $this->user($request);
        $groups = $this->referrals->groupsFor($user);
        $firstTime = ! DB::table('onboarding_nudges')->where(['user_id' => $user->id, 'kind' => 'refresher'])->exists();

        return Inertia::render('app/referrals/Send', [
            'groups' => array_map(fn (Group $g) => [
                'slug' => $g->slug, 'name' => $g->name, 'industry' => $g->industry->name, 'terms' => $g->industry->terms(),
                'form' => $this->templates->resolve('form', $g->industry, $g->id)['body'], 'consent' => Referrals::consentText($g),
                'blocked' => app(\App\Domains\Network\Training\Training::class)->blocks($user, $g), 'trainingUrl' => '/app/training/'.$g->industry->slug,
            ], $groups),
            'refresher' => $firstTime && $groups !== [] ? $groups[0]->industry->slug : null,
            'seo' => SeoData::private('Send a referral')->toArray(),
        ]);
    }

    /** Smart routing preview: who will receive it, before sending. */
    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate(['group' => ['required', 'exists:groups,slug'], 'city_id' => ['required', 'integer', 'exists:cities,id']]);
        $r = $this->referrals->route(Group::query()->where('slug', $d['group'])->firstOrFail(), City::query()->findOrFail((int) $d['city_id']));
        $team = $r['team'];
        // Average time to accept over the last 50 accepted referrals (computed in PHP so it works on any database).
        $times = $team ? Referral::query()->where('receiver_team_id', $team->id)->whereNotNull('accepted_at')->latest('accepted_at')->limit(50)->get(['created_at', 'accepted_at'])
            ->map(fn (Referral $x) => $x->created_at->diffInMinutes($x->accepted_at)) : collect();
        $avg = $times->isEmpty() ? null : $times->avg();

        return response()->json(['territory' => $r['territory'], 'team' => $team?->name, 'role' => $team ? $r['role'] : null, 'backup' => $r['role'] === 'holder' ? $r['seat']->backup?->name : null,
            'avgAcceptMinutes' => is_numeric($avg) ? (int) round((float) $avg) : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'group' => ['required', 'exists:groups,slug'], 'city_id' => ['required', 'integer', 'exists:cities,id'], 'answers' => ['required', 'array'],
            'consent' => ['accepted'], 'notes' => ['nullable', 'string', 'max:2000'], 'confirm_duplicate' => ['boolean'],
        ], ['consent.accepted' => 'Your client must agree to be contacted before you send a referral.']);
        $user = $this->user($request);
        $r = $this->referrals->send($user, Group::query()->where('slug', $d['group'])->firstOrFail(), City::query()->findOrFail((int) $d['city_id']), (array) $d['answers'], true, $d['notes'] ?? null, (bool) ($d['confirm_duplicate'] ?? false));
        if ($r['duplicate']) {
            return back()->with('duplicate', true);
        }
        DB::table('onboarding_nudges')->insertOrIgnore(['user_id' => $user->id, 'kind' => 'refresher', 'sent_at' => now()]);

        return redirect()->to('/app/referrals/'.$r['referral']->ulid)->with('status', 'referral-sent');
    }

    public function inbox(Request $request): Response
    {
        return $this->list($request, 'receiver_team_id', 'app/referrals/Inbox', 'Inbox');
    }

    public function sent(Request $request): Response
    {
        return $this->list($request, 'sender_team_id', 'app/referrals/Sent', 'Sent');
    }

    public function show(Request $request, Referral $referral): Response
    {
        $user = $this->user($request);
        abort_unless($this->referrals->canView($referral, $user), 404);
        $referral->load(['group.industry', 'seat', 'sender', 'receiver']);
        $team = Team::of($user);
        $isReceiver = $team?->id === $referral->receiver_team_id;
        $form = collect($this->templates->resolve('form', $referral->group->industry, $referral->group_id)['body'])->keyBy('key');
        // The receiver sees sensitive details (contact info) only after accepting.
        $hide = $isReceiver && $referral->status !== 'accepted';

        return Inertia::render('app/referrals/Show', [
            'referral' => [
                'id' => $referral->ulid, 'status' => $referral->status, 'territory' => $referral->seat->territory_name, 'group' => $referral->group->name,
                'sender' => $referral->sender->name, 'receiver' => $referral->receiver?->name, 'routedTo' => $referral->routed_to,
                'respondBy' => $referral->respond_by->toIso8601String(), 'client' => $hide ? $referral->shortClientName() : $referral->client_name, 'notes' => $referral->notes,
                'consentText' => $referral->consent_text, 'consentAt' => $referral->consent_at->toIso8601String(), 'declineReason' => $referral->decline_reason,
                'answers' => collect($referral->answers)->map(fn ($v, $k) => ['label' => (string) ($form[$k]['label'] ?? $k), 'value' => $hide && ($form[$k]['sensitive'] ?? false) ? null : $v])->values(),
            ],
            'isReceiver' => $isReceiver,
            'terms' => $referral->group->industry->terms(),
            'timeline' => DB::table('referral_events')->leftJoin('teams', 'teams.id', '=', 'referral_events.team_id')->where('referral_id', $referral->id)->orderBy('referral_events.id')
                ->get(['referral_events.event', 'referral_events.created_at', 'referral_events.meta', 'teams.name as team'])
                ->map(fn ($e) => ['event' => (string) $e->event, 'when' => (string) $e->created_at, 'team' => $e->team, 'meta' => $e->meta ? json_decode((string) $e->meta, true) : null]),
            'status' => session('status'),
            'seo' => SeoData::private('Referral')->toArray(),
        ]);
    }

    public function accept(Request $request, Referral $referral): RedirectResponse
    {
        $this->referrals->accept($referral, $this->user($request));

        return back()->with('status', 'referral-accepted');
    }

    public function decline(Request $request, Referral $referral): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tell the sender why, so they can help their client.'])['reason'];
        $this->referrals->decline($referral, $this->user($request), $reason);

        return redirect()->to('/app/inbox')->with('status', 'referral-declined');
    }

    private function list(Request $request, string $col, string $page, string $title): Response
    {
        $team = Team::of($this->user($request));

        return Inertia::render($page, [
            'items' => $team === null ? [] : Referral::query()->with(['seat:id,territory_name', 'sender:id,name', 'receiver:id,name', 'group:id,name'])->where($col, $team->id)->latest()->limit(200)->get()
                ->map(fn (Referral $r) => ['id' => $r->ulid, 'status' => $r->status, 'client' => $col === 'receiver_team_id' && $r->status !== 'accepted' ? $r->shortClientName() : $r->client_name, 'territory' => $r->seat->territory_name, 'group' => $r->group->name,
                    'sender' => $r->sender->name, 'receiver' => $r->receiver?->name, 'respondBy' => $r->respond_by->toIso8601String(), 'when' => $r->created_at->toIso8601String()]),
            'status' => session('status'),
            'seo' => SeoData::private($title)->toArray(),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
