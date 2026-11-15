<?php

declare(strict_types=1);

namespace App\Domains\Growth\Http;

use App\Domains\Growth\Attribution;
use App\Domains\Growth\Campaign;
use App\Domains\Growth\Campaigns;
use App\Domains\Growth\CampaignStep;
use App\Domains\Growth\GrowthMailer;
use App\Domains\Growth\GrowthMessage;
use App\Domains\Growth\Prospect;
use App\Domains\Growth\Prospects;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Growth → Campaigns and Sources. */
final class CampaignsController
{
    public function index(): Response
    {
        return Inertia::render('growth/Campaigns', [
            'campaigns' => Campaign::query()->withCount('steps')->orderByDesc('id')->get()->map(fn (Campaign $c) => ['id' => $c->ulid, 'name' => $c->name, 'kind' => $c->kind, 'status' => $c->status,
                'steps' => $c->steps_count, 'stats' => Campaigns::stats($c)])->values(),
            'sending' => GrowthMailer::sendingOn(),
            'status' => session('status'),
            'seo' => SeoData::private('Campaigns')->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $c = Campaign::create(['name' => $d['name'], 'kind' => 'drip', 'status' => 'draft', 'created_by' => $this->user($request)->id]);
        CampaignStep::create(['campaign_id' => $c->id, 'position' => 1, 'wait_hours' => 0, 'subject' => 'Hi {first_name}', 'body' => "Hi {first_name},\n\n", 'cta_label' => 'See the networks', 'cta_url' => '/groups']);

        return redirect('/admin/growth/campaigns/'.$c->ulid)->with('status', 'campaign-created');
    }

    public function show(string $campaign): Response
    {
        $c = $this->find($campaign);

        return Inertia::render('growth/Campaign', [
            'campaign' => ['id' => $c->ulid, 'name' => $c->name, 'kind' => $c->kind, 'status' => $c->status, 'audience' => (object) ($c->audience ?? []), 'goal' => $c->goal,
                'windowStart' => $c->window_start, 'windowEnd' => $c->window_end, 'weekdaysOnly' => $c->weekdays_only, 'dailyCap' => $c->daily_cap],
            'steps' => $c->steps->map(fn (CampaignStep $s) => ['id' => $s->id, 'position' => $s->position, 'waitHours' => $s->wait_hours, 'subject' => $s->subject, 'subjectB' => $s->subject_b,
                'winner' => $s->winner, 'body' => $s->body, 'ctaLabel' => $s->cta_label, 'ctaUrl' => $s->cta_url])->values(),
            'stats' => Campaigns::stats($c),
            'stepStats' => Campaigns::stepStats($c),
            'people' => DB::table('campaign_enrollments as e')->join('prospects as p', 'p.id', '=', 'e.prospect_id')->where('e.campaign_id', $c->id)->orderByDesc('e.id')->limit(50)
                ->get(['p.ulid', 'p.first_name', 'p.last_name', 'p.email', 'e.status', 'e.exit_reason', 'e.next_position', 'e.next_send_at'])
                ->map(fn ($r) => ['id' => (string) $r->ulid, 'name' => trim($r->first_name.' '.$r->last_name) ?: (string) $r->email, 'status' => (string) $r->status, 'reason' => $r->exit_reason,
                    'step' => (int) $r->next_position, 'next' => $r->next_send_at !== null ? now()->parse((string) $r->next_send_at)->toIso8601String() : null])->values(),
            'stages' => Prospect::STAGES,
            'sources' => Prospect::SOURCES,
            'industries' => Industry::query()->orderBy('name')->get(['id', 'name'])->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])->values(),
            'sending' => GrowthMailer::sendingOn(),
            'status' => session('status'),
            'seo' => SeoData::private('Campaign')->toArray(),
        ]);
    }

    public function update(Request $request, string $campaign): RedirectResponse
    {
        $c = $this->find($campaign);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'goal' => ['required', 'in:applied,joined'], 'window_start' => ['required', 'integer', 'between:0,23'],
            'window_end' => ['required', 'integer', 'between:1,24', 'gt:window_start'], 'weekdays_only' => ['boolean'], 'daily_cap' => ['required', 'integer', 'between:1,5000'],
            'audience' => ['nullable', 'array'], 'audience.stage' => ['nullable', 'in:'.implode(',', array_keys(Prospect::STAGES))], 'audience.source' => ['nullable', 'in:'.implode(',', array_keys(Prospect::SOURCES))],
            'audience.industry' => ['nullable', 'integer'], 'audience.state' => ['nullable', 'string', 'size:2'], 'audience.temp' => ['nullable', 'in:hot,warm,cold']]);
        $audience = array_filter((array) ($d['audience'] ?? []), fn ($v) => $v !== null && $v !== '');
        $c->fill([...array_diff_key($d, ['audience' => 1]), 'weekdays_only' => (bool) ($d['weekdays_only'] ?? false), 'audience' => $c->kind === 'drip' && $audience !== [] ? $audience : null])->save();

        return back()->with('status', 'campaign-saved');
    }

    public function setStatus(Request $request, string $campaign): RedirectResponse
    {
        $c = $this->find($campaign);
        $status = (string) $request->validate(['status' => ['required', 'in:active,paused']])['status'];
        if ($status === 'active' && $c->steps()->count() === 0) {
            return back()->withErrors(['status' => 'Add at least one email first.']);
        }
        $c->forceFill(['status' => $status])->save();
        activity('growth')->causedBy($request->user())->performedOn($c)->event('campaign-'.$status)->log("Campaign “{$c->name}” ".($status === 'active' ? 'started' : 'paused'));

        return back()->with('status', $status === 'active' ? 'campaign-started' : 'campaign-paused');
    }

    public function saveStep(Request $request, string $campaign, ?int $step = null): RedirectResponse
    {
        $c = $this->find($campaign);
        $step = $request->route('step') !== null ? (int) $request->route('step') : null;
        $d = $request->validate(['wait_hours' => ['required', 'integer', 'between:0,2160'], 'subject' => ['required', 'string', 'max:150'], 'subject_b' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'], 'cta_label' => ['nullable', 'string', 'max:60', 'required_with:cta_url'], 'cta_url' => ['nullable', 'string', 'max:500', 'regex:#^(/|https://)#']],
            ['cta_url.regex' => 'Use a page on this site (starting with /) or a full https:// address.']);
        if ($step !== null) {
            $s = CampaignStep::query()->where(['id' => $step, 'campaign_id' => $c->id])->firstOrFail();
            $abChanged = ($d['subject_b'] ?? null) !== $s->subject_b || $d['subject'] !== $s->subject;
            $s->fill($d);
            if ($abChanged) {
                $s->winner = null; // a new test starts
            }
            $s->save();
        } else {
            CampaignStep::create([...$d, 'campaign_id' => $c->id, 'position' => (int) $c->steps()->max('position') + 1]);
        }

        return back()->with('status', 'campaign-saved');
    }

    public function deleteStep(Request $request, string $campaign, int $step): RedirectResponse
    {
        $c = $this->find($campaign);
        $s = CampaignStep::query()->where(['id' => (int) $request->route('step'), 'campaign_id' => $c->id])->firstOrFail();
        abort_if(DB::table('campaign_sends')->where('campaign_step_id', $s->id)->exists(), 422, 'This email has been sent already: pause the campaign or edit it instead.');
        $s->delete();
        foreach ($c->steps()->get() as $i => $rest) {
            $rest->forceFill(['position' => $i + 1])->save();
        }

        return back()->with('status', 'campaign-saved');
    }

    /** Send one email to yourself, as [Test]. */
    public function test(Request $request, string $campaign, int $step): RedirectResponse
    {
        $c = $this->find($campaign);
        $s = CampaignStep::query()->with('campaign')->where(['id' => (int) $request->route('step'), 'campaign_id' => $c->id])->firstOrFail();
        Campaigns::test($s, $this->user($request), $request->input('variant') === 'b' ? 'b' : 'a');

        return back()->with('status', 'campaign-test-sent');
    }

    /** What the email looks like (HTML), for the preview pane. */
    public function preview(Request $request, string $campaign, int $step): HttpResponse
    {
        $c = $this->find($campaign);
        $s = CampaignStep::query()->with('campaign')->where(['id' => (int) $request->route('step'), 'campaign_id' => $c->id])->firstOrFail();
        $user = $this->user($request);
        $fill = fn (string $t) => strtr($t, ['{first_name}' => $user->first_name, '{city}' => 'Austin']);
        $html = (new GrowthMessage($fill($s->subject), $fill($s->body), $s->cta_label, Campaigns::link($s), url('/growth/unsubscribe/preview')))->toMail($user)->render();

        return response((string) $html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src https: data:", 'X-Frame-Options' => 'SAMEORIGIN']);
    }

    /** From the prospect list: add the selected prospects. */
    public function enroll(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $d = $request->validate(['campaign' => ['required', 'string', 'size:26'], 'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['string', 'max:30']]);
        $c = $this->find((string) $d['campaign']);
        $added = 0;
        foreach (Prospects::visibleTo($user)->whereIn('ulid', (array) $d['ids'])->get() as $p) {
            $added += Campaigns::enroll($c, $p, $user) ? 1 : 0;
        }

        return back()->with('status', 'campaign-enrolled')->with('enrolled', $added);
    }

    public function sources(Request $request): Response
    {
        $d = $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90,365'], 'touch' => ['nullable', 'in:first,last']]);
        $days = (int) ($d['days'] ?? 30);
        $from = now()->subDays($days)->toDateTimeString();
        $to = now()->toDateTimeString();

        return Inertia::render('growth/Sources', [
            'days' => $days, 'touch' => $d['touch'] ?? 'first',
            'rows' => Attribution::report($from, $to, (string) ($d['touch'] ?? 'first')),
            'byCampaign' => DB::table('signup_attributions as a')->join('campaigns as c', 'c.id', '=', 'a.campaign_id')->whereBetween('a.created_at', [$from, $to])
                ->groupBy('c.name')->selectRaw('c.name, count(*) as signups')->orderByDesc('signups')->get()->map(fn ($r) => ['name' => (string) $r->name, 'signups' => (int) $r->signups])->values(),
            'total' => DB::table('users')->whereBetween('created_at', [$from, $to])->count(),
            'tracked' => DB::table('signup_attributions')->whereBetween('created_at', [$from, $to])->count(),
            'seo' => SeoData::private('Sign-up sources')->toArray(),
        ]);
    }

    private function find(string $ulid): Campaign
    {
        return Campaign::query()->with('steps')->where('ulid', $ulid)->firstOrFail();
    }

    private function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }
}
