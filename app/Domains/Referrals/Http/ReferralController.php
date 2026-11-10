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
        if ($team !== null) {
            \App\Domains\Referrals\Conversations::markRead($referral, $team);
        }
        $seenUpTo = $team !== null ? \App\Domains\Referrals\Conversations::otherRead($referral, $team) : 0;
        // The receiver sees file names only until they accept (like contact details).
        $files = \App\Domains\Referrals\Attachments::forViewer($referral, $hide);

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
            'pipeline' => $referral->status === 'accepted' ? [
                'stages' => app(\App\Domains\Referrals\Pipeline::class)->stages($referral), 'current' => $referral->stage,
                'canWork' => $isReceiver, 'firstContact' => $referral->first_contact_at?->toIso8601String(),
                'firstContactDue' => $referral->accepted_at?->copy()->addHours((int) $referral->group->rule('first_contact_hours'))->toIso8601String(),
                'askEarnings' => app(\App\Domains\Referrals\Pipeline::class)->feeIsPercent($referral), 'earnings' => $referral->earnings_cents !== null ? $referral->earnings_cents / 100 : null,
                'closingDate' => $referral->closing_date?->toDateString(), 'closingValue' => $referral->closing_value_cents !== null ? $referral->closing_value_cents / 100 : null, 'lostReason' => $referral->lost_reason,
                'canNudge' => $team?->id === $referral->sender_team_id && $referral->closed_at === null && ($referral->last_nudged_at === null || $referral->last_nudged_at->lte(now()->subDays(\App\Domains\Referrals\Pipeline::NUDGE_EVERY_DAYS))),
                'nextNudge' => $referral->last_nudged_at?->copy()->addDays(\App\Domains\Referrals\Pipeline::NUDGE_EVERY_DAYS)->toIso8601String(),
            ] : null,
            'messages' => DB::table('referral_messages')->join('users', 'users.id', '=', 'referral_messages.user_id')->join('teams', 'teams.id', '=', 'referral_messages.team_id')
                ->where('referral_id', $referral->id)->orderBy('referral_messages.id')->get(['referral_messages.id', 'referral_messages.body', 'referral_messages.created_at', 'referral_messages.team_id', 'users.first_name', 'teams.name as team'])
                ->map(fn ($m) => ['id' => (int) $m->id, 'mine' => (int) $m->team_id === $team?->id, 'seen' => (int) $m->team_id === $team?->id && (int) $m->id <= $seenUpTo, 'who' => "{$m->first_name} · {$m->team}", 'when' => (string) $m->created_at,
                    'body' => $hide ? Referral::maskContacts((string) $m->body) : (string) $m->body, 'files' => $files[(int) $m->id] ?? []]),
            'closing' => $referral->closed_at === null ? null : (function () use ($referral, $team, $user) {
                $mine = $team?->id === $referral->sender_team_id ? 'sender' : ($team?->id === $referral->receiver_team_id ? 'receiver' : null);
                $ratings = DB::table('referral_ratings')->where('referral_id', $referral->id)->get()->keyBy('rater');
                $role = $team?->roleOf($user);

                return [
                    'status' => $referral->close_status, 'dispute' => $referral->close_dispute,
                    'canConfirm' => $mine === 'sender' && in_array($role, ['leader', 'assistant'], true) && in_array($referral->close_status, ['awaiting', 'disputed'], true),
                    'mySide' => $mine,
                    'myRating' => $mine && isset($ratings[$mine]) ? ['stars' => (int) $ratings[$mine]->stars, 'comment' => $ratings[$mine]->comment, 'editable' => now()->lte((string) $ratings[$mine]->editable_until)] : null,
                    'clientRated' => isset($ratings['client']),
                    'thanks' => $referral->thanks_note !== null ? ['note' => $referral->thanks_note, 'public' => $referral->thanks_public] : null,
                    'canThank' => $mine === 'receiver' && $referral->close_status !== null && $referral->thanks_note === null,
                ];
            })(),
            'fee' => $referral->group->industry->tier === \App\Domains\Network\Industries\Tier::Open ? [
                'terms' => \App\Domains\Referrals\Fees::describe(\App\Domains\Referrals\Fees::forReferral($referral)),
                'custom' => $referral->fee_type !== null,
                'proposal' => $referral->fee_proposal ? ['text' => \App\Domains\Referrals\Fees::describe(['type' => $referral->fee_proposal['type'], 'value' => (int) $referral->fee_proposal['value']]), 'mine' => (int) $referral->fee_proposal['by'] === $team?->id] : null,
                'canChange' => in_array($team?->roleOf($user), ['leader', 'assistant'], true) && ! DB::table('referral_agreements')->where('referral_id', $referral->id)->where(fn ($q) => $q->whereNotNull('sender_signed_at')->orWhereNotNull('receiver_signed_at')->orWhere('status', 'signed'))->exists(),
            ] : null,
            'nurture' => [
                'temperature' => $referral->temperature, 'nextAt' => $referral->nurture_next_at, 'protectedUntil' => $referral->protection_until,
                'canCheckIn' => $team?->id === $referral->receiver_team_id && $referral->status === 'accepted' && $referral->closed_at === null,
                'touchpoints' => DB::table('nurture_touchpoints')->where('referral_id', $referral->id)->where('kind', '!=', 'prompt')->orderByDesc('id')->limit(12)->get(['kind', 'source', 'note', 'created_at'])
                    ->map(fn ($t) => ['kind' => (string) $t->kind, 'source' => (string) $t->source, 'note' => $t->note, 'at' => substr((string) $t->created_at, 0, 10)]),
            ],
            'muted' => \App\Domains\Referrals\Conversations::muted($referral, $user),
            'quickReplies' => $team !== null ? \App\Domains\Referrals\Conversations::quickReplies($team) : [],
            'clientLink' => $team?->id === $referral->sender_team_id && $referral->client_token ? url('/r/'.$referral->client_token) : null,
            'terms' => $referral->group->industry->terms(),
            'timeline' => DB::table('referral_events')->leftJoin('teams', 'teams.id', '=', 'referral_events.team_id')->where('referral_id', $referral->id)->orderBy('referral_events.id')
                ->get(['referral_events.event', 'referral_events.created_at', 'referral_events.meta', 'teams.name as team'])
                ->map(fn ($e) => ['event' => (string) $e->event, 'when' => (string) $e->created_at, 'team' => $e->team, 'meta' => $e->meta ? json_decode((string) $e->meta, true) : null]),
            'status' => session('status'),
            'seo' => SeoData::private('Referral')->toArray(),
        ]);
    }

    /** Download an attachment: signed short-lived link, a member of either team, scanned clean, and after accepting. */
    public function attachment(Request $request, Referral $referral, int $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($this->referrals->canView($referral, $user), 404);
        $a = DB::table('referral_attachments')->where(['id' => $attachment, 'referral_id' => $referral->id])->first();
        abort_if($a === null, 404);
        abort_if(Team::of($user)?->id === $referral->receiver_team_id && $referral->status !== 'accepted', 403, 'Files open after you accept the referral.');
        abort_unless(\App\Domains\Referrals\Attachments::openable($a), 423, 'This file is still being checked for viruses, or failed the check.');

        // Always a download (never rendered in the browser), and the browser must not guess the type.
        return \Illuminate\Support\Facades\Storage::disk('private')->download((string) $a->path, (string) $a->original_name, ['X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'application/octet-stream']);
    }

    public function confirmClose(Request $request, Referral $referral, \App\Domains\Referrals\Closing $closing): RedirectResponse
    {
        $closing->confirm($referral, $this->user($request));

        return back()->with('status', 'close-confirmed');
    }

    public function disputeClose(Request $request, Referral $referral, \App\Domains\Referrals\Closing $closing): RedirectResponse
    {
        $closing->dispute($referral, $this->user($request), (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason']);

        return back()->with('status', 'close-disputed');
    }

    public function rate(Request $request, Referral $referral, \App\Domains\Referrals\Closing $closing): RedirectResponse
    {
        $d = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'aspects' => ['nullable', 'array'], 'aspects.*' => ['integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $aspects = array_intersect_key(array_map('intval', (array) ($d['aspects'] ?? [])), array_flip(['communication', 'speed', 'outcome', 'quality']));
        $closing->rate($referral, $this->user($request), (int) $d['stars'], $aspects, $d['comment'] ?? null);

        return back()->with('status', 'rated');
    }

    public function thank(Request $request, Referral $referral, \App\Domains\Referrals\Closing $closing): RedirectResponse
    {
        $closing->thank($referral, $this->user($request), (string) $request->validate(['note' => ['required', 'string', 'max:500']])['note']);

        return back()->with('status', 'thanked');
    }

    public function thanksPublic(Request $request, Referral $referral, \App\Domains\Referrals\Closing $closing): RedirectResponse
    {
        $closing->setThanksPublic($referral, $this->user($request), $request->boolean('public'));

        return back()->with('status', $request->boolean('public') ? 'thanks-public' : 'thanks-private');
    }

    /** The client's one optional question (public, private link, never indexed). */
    public function clientRate(string $token): Response
    {
        $r = Referral::query()->with('receiver')->where('client_rating_token_hash', hash('sha256', $token))->first();

        return Inertia::render('ClientRate', ['pro' => $r?->receiver?->name, 'token' => $r ? $token : null, 'seo' => (new SeoData(title: 'Rate your experience', description: 'Team to Team Referrals', index: false))->toArray()]);
    }

    public function clientRateStore(Request $request, string $token, \App\Domains\Referrals\Closing $closing): RedirectResponse
    {
        $d = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $closing->rateAsClient($token, (int) $d['stars'], $d['comment'] ?? null);

        return back()->with('status', 'client-rated');
    }

    public function nurtureCheckIn(Request $request, Referral $referral, \App\Domains\Referrals\Nurture $nurture): RedirectResponse
    {
        $d = $request->validate(['outcome' => ['required', 'in:still,ready,lost'], 'note' => ['nullable', 'string', 'max:1000'], 'next_on' => ['nullable', 'date', 'after:today', 'before:+13 months']]);
        $nurture->checkIn($referral, $this->user($request), (string) $d['outcome'], $d['note'] ?? null, $d['next_on'] ?? null);

        return back()->with('status', 'nurture-'.$d['outcome']);
    }

    /** Portal → Nurture: Future leads received (with check-ins) and sent (progress only, no client contact details). */
    public function nurtureList(Request $request): Response
    {
        $team = Team::of($this->user($request)) ?? Team::forLeader($this->user($request));
        $row = fn (Referral $r) => ['id' => $r->ulid, 'client' => $r->shortClientName(), 'territory' => $r->seat->territory_name, 'temperature' => $r->temperature,
            'nextAt' => $r->nurture_next_at, 'protectedUntil' => $r->protection_until, 'other' => $r->receiver_team_id === $team->id ? $r->sender->name : (string) $r->receiver?->name,
            'last' => DB::table('nurture_touchpoints')->where('referral_id', $r->id)->where('kind', '!=', 'prompt')->orderByDesc('id')->value('kind')];
        $base = fn () => Referral::query()->with(['seat', 'sender', 'receiver'])->where('status', 'accepted')->whereNull('closed_at')
            ->where(fn ($q) => $q->where('temperature', 'future')->orWhereIn('id', DB::table('nurture_touchpoints')->select('referral_id')));

        return Inertia::render('app/Nurture', [
            'received' => $base()->where('receiver_team_id', $team->id)->orderBy('nurture_next_at')->get()->map($row)->values(),
            'sent' => $base()->where('sender_team_id', $team->id)->orderBy('nurture_next_at')->get()->map($row)->values(),
            'seo' => SeoData::private('Nurture')->toArray(),
        ]);
    }

    /** The client's check-in from their portal: ready now, still planning, or no longer needed. */
    public function clientCheckIn(Request $request, string $token, \App\Domains\Referrals\Nurture $nurture): RedirectResponse
    {
        $r = Referral::query()->with(['group.industry', 'receiver.leader', 'sender.leader'])->where('client_token', $token)->firstOrFail();
        $nurture->clientCheckIn($r, (string) $request->validate(['answer' => ['required', 'in:ready,later,not_needed']])['answer']);

        return back()->with('status', 'client-checkin');
    }

    public function mute(Request $request, Referral $referral): RedirectResponse
    {
        abort_unless($this->referrals->canView($referral, $this->user($request)), 404);
        $on = \App\Domains\Referrals\Conversations::toggleMute($referral, $this->user($request));

        return back()->with('status', $on ? 'thread-muted' : 'thread-unmuted');
    }

    public function quickReplies(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $team = Team::of($user);
        abort_unless($team !== null && in_array($team->roleOf($user), ['leader', 'assistant'], true), 403);
        // Blank lines arrive as null (ConvertEmptyStringsToNull) and are simply skipped.
        $d = $request->validate(['replies' => ['present', 'array', 'max:20'], 'replies.*' => ['nullable', 'string', 'max:200']]);
        $replies = array_slice(array_values(array_filter(array_map(fn ($r) => trim((string) $r), (array) $d['replies']))), 0, 10);
        DB::table('teams')->where('id', $team->id)->update(['quick_replies' => $replies === [] ? null : json_encode($replies)]);

        return back()->with('status', 'quick-replies-saved');
    }

    public function search(Request $request): Response
    {
        $team = Team::of($this->user($request));
        $q = (string) $request->query('q', '');

        return Inertia::render('app/referrals/Search', ['q' => $q, 'results' => $team ? \App\Domains\Referrals\Conversations::search($team, $q) : [], 'seo' => SeoData::private('Search messages')->toArray()]);
    }

    public function logCall(Request $request, Referral $referral, \App\Domains\Referrals\Pipeline $pipeline): RedirectResponse
    {
        $pipeline->logCall($referral, $this->user($request), $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null);

        return back()->with('status', 'call-logged');
    }

    public function stage(Request $request, Referral $referral, \App\Domains\Referrals\Pipeline $pipeline): RedirectResponse
    {
        $d = $request->validate(['stage' => ['required', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:1000'], 'closing_date' => ['nullable', 'date'], 'closing_value' => ['nullable', 'numeric', 'min:0', 'max:1000000000'], 'earnings' => ['nullable', 'numeric', 'min:0', 'max:1000000000'], 'lost_reason' => ['nullable', 'string', 'max:500']]);
        $pipeline->move($referral, $this->user($request), (string) $d['stage'], $d['note'] ?? null, $d);

        return back()->with('status', 'stage-moved');
    }

    public function note(Request $request, Referral $referral, \App\Domains\Referrals\Pipeline $pipeline): RedirectResponse
    {
        $pipeline->note($referral, $this->user($request), (string) $request->validate(['note' => ['required', 'string', 'max:1000']])['note']);

        return back()->with('status', 'update-posted');
    }

    public function nudge(Request $request, Referral $referral, \App\Domains\Referrals\Pipeline $pipeline): RedirectResponse
    {
        $pipeline->nudge($referral, $this->user($request));

        return back()->with('status', 'nudged');
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
        $d = $request->validate([
            'body' => ['nullable', 'required_without:files', 'string', 'max:4000'],
            'files' => ['nullable', 'array', 'max:'.config('attachments.max_files')],
            'files.*' => ['file', 'max:'.config('attachments.max_kb'), 'mimes:'.implode(',', (array) config('attachments.mimes'))],
        ], ['files.*.mimes' => 'Photos, PDFs, Word, Excel, CSV or text files only.', 'files.*.max' => 'Each file can be up to 10 MB.']);
        $this->referrals->message($referral, $this->user($request), (string) ($d['body'] ?? ''), array_values(array_filter((array) $request->file('files', []))));

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
        $r = Referral::query()->with(['seat', 'receiver.leader', 'sender', 'group.industry'])->where('client_token', $token)->firstOrFail();
        $t = $r->group->industry->terms();
        $receiver = $r->receiver;

        return Inertia::render('ClientStatus', [
            'status' => $r->status, 'firstName' => strtok($r->client_name, ' ') ?: 'there', 'territory' => $r->seat->territory_name, 'sender' => $r->sender->name, 'pro' => $t['pro'],
            'receiver' => $receiver && $r->status === 'accepted' ? [
                'name' => $receiver->name, 'leader' => (string) strtok((string) $receiver->leader->first_name, ' '), 'bio' => $receiver->bio, 'serviceArea' => $receiver->service_area,
                'phone' => $receiver->phone, 'website' => $receiver->website, 'logo' => $receiver->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($receiver->logo_path) : null,
                'licenses' => DB::table('team_licenses')->join('states', 'states.id', '=', 'team_licenses.state_id')->where(['team_id' => $receiver->id, 'status' => 'verified'])->pluck('states.code')->unique()->values(),
                'trained' => app(\App\Domains\Network\Training\Training::class)->teamTrained($receiver),
            ] : null,
            'contactHours' => (int) $r->group->rule('first_contact_hours'),
            'token' => $token, 'consentAt' => $r->client_confirmed_at?->toFormattedDateString(), 'temperature' => $r->temperature,
            'canCheckIn' => $r->status === 'accepted' && $r->closed_at === null, 'flash' => session('status'),
            'stage' => $r->status === 'accepted' && $r->stage !== null ? (function () use ($r) {
                $st = app(\App\Domains\Referrals\Pipeline::class)->stage($r);

                return $st ? ['label' => (string) (($st['client_label'] ?? null) ?: $st['label']), 'kind' => $st['kind']] : null;
            })() : null,
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
