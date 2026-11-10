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
                'canText' => $g->industry->client_texting_approved,
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
            'consent' => ['accepted'], 'notes' => ['nullable', 'string', 'max:2000'], 'confirm_duplicate' => ['boolean'], 'text_client' => ['boolean'],
        ], ['consent.accepted' => 'Your client must agree to be contacted before you send a referral.']);
        $user = $this->user($request);
        $r = $this->referrals->send($user, Group::query()->where('slug', $d['group'])->firstOrFail(), City::query()->findOrFail((int) $d['city_id']), (array) $d['answers'], true, $d['notes'] ?? null, (bool) ($d['confirm_duplicate'] ?? false), (bool) ($d['text_client'] ?? false));
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
                'clientConfirmed' => $referral->client_confirmed_at?->toIso8601String(), 'consentRequested' => $referral->consent_requested_at !== null,
                'canAskClient' => $team?->id === $referral->sender_team_id && Referrals::canTextClient($referral),
                'answers' => collect($referral->answers)->map(fn ($v, $k) => ['label' => (string) ($form[$k]['label'] ?? $k), 'value' => $hide && ($form[$k]['sensitive'] ?? false) ? null : $v])->values(),
            ],
            'isReceiver' => $isReceiver,
            'agreement' => $this->agreementFor($referral, $user),
            'messages' => DB::table('referral_messages')->join('users', 'users.id', '=', 'referral_messages.user_id')->join('teams', 'teams.id', '=', 'referral_messages.team_id')
                ->where('referral_id', $referral->id)->orderBy('referral_messages.id')->get(['referral_messages.id', 'referral_messages.body', 'referral_messages.created_at', 'referral_messages.team_id', 'users.first_name', 'teams.name as team'])
                ->map(fn ($m) => ['id' => (int) $m->id, 'mine' => (int) $m->team_id === $team?->id, 'who' => "{$m->first_name} · {$m->team}", 'when' => (string) $m->created_at,
                    'body' => $hide ? Referral::maskContacts((string) $m->body) : (string) $m->body]),
            'clientLink' => $team?->id === $referral->sender_team_id && $referral->client_token ? url('/r/'.$referral->client_token) : null,
            'terms' => $referral->group->industry->terms(),
            'timeline' => DB::table('referral_events')->leftJoin('teams', 'teams.id', '=', 'referral_events.team_id')->where('referral_id', $referral->id)->orderBy('referral_events.id')
                ->get(['referral_events.event', 'referral_events.created_at', 'referral_events.meta', 'teams.name as team'])
                ->map(fn ($e) => ['event' => (string) $e->event, 'when' => (string) $e->created_at, 'team' => $e->team, 'meta' => $e->meta ? json_decode((string) $e->meta, true) : null]),
            'status' => session('status'),
            'seo' => SeoData::private('Referral')->toArray(),
        ]);
    }

    public function askClient(Request $request, Referral $referral): RedirectResponse
    {
        abort_unless(Team::of($this->user($request))?->id === $referral->sender_team_id, 403);
        $this->referrals->requestClientConsent($referral) || abort(422, 'This client can’t be texted (not approved for this industry, no mobile, already asked, or opted out).');

        return back()->with('status', 'client-asked');
    }

    /** The client's confirm screen from the text (a button, so link previews can't confirm on their behalf). */
    public function clientConsent(string $token): Response
    {
        $r = Referral::query()->with(['sender', 'seat', 'group.industry'])->where('consent_token_hash', hash('sha256', $token))->first();

        return Inertia::render('ClientConsent', [
            'request' => $r ? ['sender' => $r->sender->name, 'territory' => $r->seat->territory_name, 'pro' => $r->group->industry->terms()['pro'], 'firstName' => strtok($r->client_name, ' ') ?: 'there', 'token' => $token] : null,
            'seo' => (new SeoData(title: 'Confirm your introduction', description: 'Team to Team Referrals', index: false))->toArray(),
        ]);
    }

    public function clientConsentConfirm(string $token): RedirectResponse
    {
        $r = Referral::query()->where('consent_token_hash', hash('sha256', $token))->firstOrFail();
        $this->referrals->confirmClient($r, 'link');

        return redirect()->to('/r/'.$r->client_token);
    }

    public function message(Request $request, Referral $referral): RedirectResponse
    {
        abort_unless($this->referrals->canView($referral, $this->user($request)), 404);
        $body = (string) $request->validate(['body' => ['required', 'string', 'max:4000']])['body'];
        $this->referrals->message($referral, $this->user($request), $body);

        return back();
    }

    /** One-tap confirm screen from the email or text (the signature proves it's the receiver's link). */
    public function quick(Request $request, Referral $referral, int $team): Response
    {
        $referral->load(['seat', 'sender', 'group']);
        $valid = $referral->status === 'sent' && $referral->receiver_team_id === $team;

        return Inertia::render('ReferralQuickAccept', [
            'referral' => $valid ? ['client' => $referral->shortClientName(), 'territory' => $referral->seat->territory_name, 'sender' => $referral->sender->name, 'group' => $referral->group->name,
                'respondBy' => $referral->respond_by->toIso8601String(), 'action' => $request->fullUrl(), 'view' => url('/app/referrals/'.$referral->ulid)] : null,
            'seo' => (new SeoData(title: 'Accept referral', description: 'Team to Team Referrals', index: false))->toArray(),
        ]);
    }

    public function quickAccept(Request $request, Referral $referral, int $team): RedirectResponse
    {
        abort_unless($referral->status === 'sent' && $referral->receiver_team_id === $team, 410, 'This referral is no longer waiting for you.');
        $leader = Team::query()->findOrFail($team)->leader;
        $this->referrals->accept($referral, $leader);

        return redirect()->to('/app/referrals/'.$referral->ulid)->with('status', 'referral-accepted');
    }

    /** The client's private status page: who will contact them, their verified badges, and where things stand. */
    public function client(string $token): Response
    {
        $r = Referral::query()->with(['seat', 'receiver', 'sender', 'group.industry'])->where('client_token', $token)->firstOrFail();
        $t = $r->group->industry->terms();
        $receiver = $r->receiver;

        return Inertia::render('ClientStatus', [
            'status' => $r->status, 'firstName' => strtok($r->client_name, ' ') ?: 'there', 'territory' => $r->seat->territory_name, 'sender' => $r->sender->name, 'pro' => $t['pro'],
            'receiver' => $receiver && $r->status === 'accepted' ? [
                'name' => $receiver->name,
                'licenses' => DB::table('team_licenses')->join('states', 'states.id', '=', 'team_licenses.state_id')->where(['team_id' => $receiver->id, 'status' => 'verified'])->pluck('states.code')->unique()->values(),
                'trained' => app(\App\Domains\Network\Training\Training::class)->teamTrained($receiver),
            ] : null,
            'contactHours' => (int) $r->group->rule('first_contact_hours'),
            'seo' => (new SeoData(title: 'Your introduction', description: 'Team to Team Referrals', index: false))->toArray(),
        ]);
    }

    public function sign(Request $request, Referral $referral, \App\Domains\Referrals\ReferralAgreements $agreements): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'consent' => ['accepted']], ['consent.accepted' => 'Please agree to sign electronically.']);
        $agreements->sign($referral, $this->user($request), (string) $d['name'], (string) $request->ip(), (string) $request->userAgent());

        return back()->with('status', 'agreement-signed');
    }

    public function agreementPdf(Request $request, Referral $referral): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($this->referrals->canView($referral, $this->user($request)), 404);
        $path = DB::table('referral_agreements')->where(['referral_id' => $referral->id, 'status' => 'signed'])->value('pdf_path');
        abort_if(! is_string($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('private')->download($path, 'referral-agreement-'.$referral->ulid.'.pdf');
    }

    /** @return array<string, mixed>|null */
    private function agreementFor(Referral $referral, User $user): ?array
    {
        $a = DB::table('referral_agreements')->where('referral_id', $referral->id)->first();
        if ($a === null) {
            return null;
        }
        $team = Team::of($user);
        $side = $team?->id === $referral->sender_team_id ? 'sender' : ($team?->id === $referral->receiver_team_id ? 'receiver' : null);

        return [
            'status' => (string) $a->status, 'version' => $a->version, 'summary' => $a->summary ? json_decode((string) $a->summary, true) : [], 'sections' => $a->sections ? json_decode((string) $a->sections, true) : [],
            'sender' => ['name' => $a->sender_signed_name, 'at' => $a->sender_signed_at], 'receiver' => ['name' => $a->receiver_signed_name, 'at' => $a->receiver_signed_at],
            'canSign' => $a->status === 'pending' && $side !== null && $team?->leader_id === $user->id && $a->{$side.'_signed_at'} === null, 'signerName' => $user->name,
            'pdf' => $a->status === 'signed' ? '/app/referrals/'.$referral->ulid.'/agreement.pdf' : null,
        ];
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
            // Waiting referrals first, soonest deadline on top, so nothing urgent is missed.
            'items' => $team === null ? [] : Referral::query()->with(['seat:id,territory_name', 'sender:id,name', 'receiver:id,name', 'group:id,name'])->where($col, $team->id)
                ->orderByRaw("case when status = 'sent' then 0 else 1 end")->orderByRaw("case when status = 'sent' then respond_by end")->latest()->limit(200)->get()
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
