<?php

declare(strict_types=1);

namespace App\Domains\Support\Http;

use App\Domains\Platform\Ai\AiProvider;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use App\Domains\Support\Ally\AllyEvals;
use App\Domains\Support\Ally\AllySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Ally: how it's doing, what it couldn't answer, conversations, settings (draft, test, publish) and test questions. */
final class AdminAllyController
{
    public function index(): Response
    {
        $since = now()->subDays(30);
        $answers = DB::table('ally_messages as m')->join('ally_conversations as c', 'c.id', '=', 'm.conversation_id')->where('m.role', 'ally')->where('m.created_at', '>=', $since);
        $total = (clone $answers)->count();
        $voted = (clone $answers)->whereNotNull('m.helpful')->count();
        $conversations = DB::table('ally_conversations')->where('created_at', '>=', $since)->count();
        $sources = [];
        foreach ((clone $answers)->whereNotNull('m.sources')->pluck('m.sources') as $json) {
            foreach ((array) json_decode((string) $json, true) as $s) {
                $sources[(string) ($s['title'] ?? '')] = ($sources[(string) ($s['title'] ?? '')] ?? 0) + 1;
            }
        }
        arsort($sources);
        $s = app(Settings::class);

        return Inertia::render('admin/ally/Index', [
            'metrics' => [
                'conversations' => $conversations, 'questions' => $total,
                'answered' => $total > 0 ? (int) round((clone $answers)->where('m.answered', true)->count() * 100 / $total) : null,
                'helpful' => $voted > 0 ? (int) round((clone $answers)->where('m.helpful', true)->count() * 100 / $voted) : null,
                'handedOff' => $conversations > 0 ? (int) round(DB::table('ally_conversations')->where('created_at', '>=', $since)->whereNotNull('ticket_id')->count() * 100 / $conversations) : null,
                'cost' => round((float) DB::table('ai_usage_logs')->where('feature', 'ally')->where('created_at', '>=', $since)->sum('cost_usd'), 2),
                'aiOn' => app(AiProvider::class)->enabled(),
            ],
            'topSources' => collect($sources)->take(8)->map(fn ($n, $t) => ['title' => $t, 'count' => $n])->values(),
            // Questions Ally couldn't answer, or whose answer was marked unhelpful: the list to write articles from.
            'gaps' => DB::table('ally_messages as a')->where('a.role', 'ally')->where(fn ($q) => $q->where('a.answered', false)->orWhere('a.helpful', false))->where('a.created_at', '>=', $since)
                ->orderByDesc('a.id')->limit(50)->get(['a.id', 'a.conversation_id', 'a.helpful', 'a.answered', 'a.created_at'])
                ->map(function ($a) {
                    $q = DB::table('ally_messages')->where('conversation_id', $a->conversation_id)->where('role', 'user')->where('id', '<', $a->id)->orderByDesc('id')->value('body');

                    return ['question' => (string) $q, 'why' => $a->helpful === false ? 'Marked unhelpful' : 'Couldn’t answer', 'at' => substr((string) $a->created_at, 0, 10),
                        'conversation' => DB::table('ally_conversations')->where('id', $a->conversation_id)->value('ulid')];
                })->filter(fn ($g) => $g['question'] !== '')->values(),
            'conversations' => DB::table('ally_conversations as c')->leftJoin('support_tickets as t', 't.id', '=', 'c.ticket_id')->orderByDesc('c.updated_at')->limit(30)
                ->get(['c.ulid', 'c.audience', 'c.page', 'c.updated_at', 't.number'])
                ->map(fn ($c) => ['id' => $c->ulid, 'audience' => $c->audience, 'page' => $c->page, 'at' => substr((string) $c->updated_at, 0, 16), 'ticket' => $c->number,
                    'first' => (string) DB::table('ally_messages as m')->join('ally_conversations as cc', 'cc.id', '=', 'm.conversation_id')->where('cc.ulid', $c->ulid)->where('m.role', 'user')->orderBy('m.id')->value('m.body')])->values(),
            'settings' => [
                'audiences' => collect(AllySettings::AUDIENCES)->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'on' => $s->get('ally.audience.'.$k, '1') === '1'])->values(),
                'visitorHourly' => AllySettings::int('visitor_hourly', 20), 'memberDaily' => AllySettings::int('member_daily', 100), 'handoffAfter' => AllySettings::int('handoff_after', 2),
                'budget' => AllySettings::budget(), 'live' => AllySettings::live(), 'draft' => AllySettings::draft(), 'draftReady' => AllyEvals::draftReady(),
                'draftRate' => $s->get('ally.draft.rate') !== null ? (int) $s->get('ally.draft.rate') : null, 'passRate' => AllyEvals::PASS_RATE,
            ],
            'evals' => DB::table('ally_evals')->orderBy('id')->get()->map(fn ($e) => ['id' => (int) $e->id, 'question' => $e->question, 'mustInclude' => implode(', ', (array) json_decode((string) $e->must_include, true)),
                'mustNotInclude' => implode(', ', (array) json_decode((string) ($e->must_not_include ?? '[]'), true)), 'expectAnswer' => (bool) $e->expect_answer,
                'passed' => $e->last_passed === null ? null : (bool) $e->last_passed, 'answer' => $e->last_answer, 'ran' => $e->last_run_at !== null ? substr((string) $e->last_run_at, 0, 16) : null])->values(),
            'flash' => session('status'),
            'seo' => SeoData::private('Ally')->toArray(),
        ]);
    }

    public function conversation(Request $request, string $conversation): Response
    {
        $c = DB::table('ally_conversations')->where('ulid', (string) $request->route('conversation'))->first();
        abort_if($c === null, 404);

        return Inertia::render('admin/ally/Conversation', [
            'conversation' => ['id' => $c->ulid, 'audience' => $c->audience, 'page' => $c->page, 'started' => substr((string) $c->created_at, 0, 16),
                'ticket' => $c->ticket_id !== null ? DB::table('support_tickets')->where('id', $c->ticket_id)->first(['ulid', 'number']) : null],
            'messages' => DB::table('ally_messages')->where('conversation_id', $c->id)->orderBy('id')->get()->map(fn ($m) => ['role' => $m->role, 'body' => $m->body, 'mode' => $m->mode,
                'answered' => $m->answered === null ? null : (bool) $m->answered, 'helpful' => $m->helpful === null ? null : (bool) $m->helpful,
                'sources' => json_decode((string) ($m->sources ?? '[]'), true) ?: [], 'at' => substr((string) $m->created_at, 0, 16)])->values(),
            'seo' => SeoData::private('Ally conversation')->toArray(),
        ]);
    }

    /** Limits and who sees Ally: these apply straight away. */
    public function limits(Request $request): RedirectResponse
    {
        $d = $request->validate(['audiences' => ['array'], 'audiences.*' => ['boolean'], 'visitor_hourly' => ['required', 'integer', 'between:1,500'], 'member_daily' => ['required', 'integer', 'between:1,5000'],
            'handoff_after' => ['required', 'integer', 'between:1,10'], 'budget' => ['required', 'numeric', 'between:0,1000']]);
        $s = app(Settings::class);
        foreach (array_keys(AllySettings::AUDIENCES) as $k) {
            $s->set('ally.audience.'.$k, ($d['audiences'][$k] ?? false) ? '1' : '0');
        }
        foreach (['visitor_hourly', 'member_daily', 'handoff_after', 'budget'] as $k) {
            $s->set('ally.'.$k, (string) $d[$k]);
        }
        activity('support')->causedBy($request->user())->event('ally-limits-saved')->withProperties($d)->log('Changed Ally’s audiences and limits');

        return back()->with('status', 'ally-saved');
    }

    /** Tone and topics to avoid: saved as a draft, which must pass the test questions before it goes live. */
    public function draft(Request $request): RedirectResponse
    {
        $d = $request->validate(['tone' => ['required', 'string', 'max:500'], 'blocked' => ['nullable', 'string', 'max:2000']]);
        $s = app(Settings::class);
        $s->set('ally.draft.tone', trim($d['tone']));
        $s->set('ally.draft.blocked', trim((string) ($d['blocked'] ?? '')));

        return back()->with('status', 'ally-draft-saved');
    }

    public function test(Request $request): RedirectResponse
    {
        $draft = $request->input('which') !== 'live';
        $r = AllyEvals::run($draft ? AllySettings::draft() : AllySettings::live(), $draft);

        return back()->with('status', 'ally-tested')->with('result', $r);
    }

    public function publish(Request $request): RedirectResponse
    {
        if (! AllyEvals::draftReady()) {
            return back()->withErrors(['draft' => 'Run the test questions on this draft first. At least '.AllyEvals::PASS_RATE.'% must pass.']);
        }
        $s = app(Settings::class);
        $old = AllySettings::live();
        $new = AllySettings::draft();
        $s->set('ally.live.tone', $new['tone']);
        $s->set('ally.live.blocked', $new['blocked']);
        activity('support')->causedBy($request->user())->event('ally-published')->withProperties(['old' => $old, 'new' => $new, 'rate' => $s->get('ally.draft.rate')])->log('Published Ally’s new instructions');

        return back()->with('status', 'ally-published');
    }

    public function saveEval(Request $request, ?int $eval = null): RedirectResponse
    {
        $id = $request->route('eval') !== null ? (int) $request->route('eval') : null;
        $d = $request->validate(['question' => ['required', 'string', 'max:300'], 'must_include' => ['nullable', 'string', 'max:300'], 'must_not_include' => ['nullable', 'string', 'max:300'],
            'expect_answer' => ['boolean'], 'audience' => ['nullable', Rule::in(['visitor'])]]);
        $split = fn (?string $v) => json_encode(array_values(array_filter(array_map('trim', explode(',', (string) $v)))));
        $row = ['question' => trim($d['question']), 'must_include' => $split($d['must_include'] ?? null), 'must_not_include' => $split($d['must_not_include'] ?? null),
            'expect_answer' => (bool) ($d['expect_answer'] ?? true), 'audience' => 'visitor', 'updated_at' => now()];
        $id !== null ? DB::table('ally_evals')->where('id', $id)->update($row) : DB::table('ally_evals')->insert([...$row, 'created_at' => now()]);

        return back()->with('status', 'ally-eval-saved');
    }

    public function deleteEval(Request $request, int $eval): RedirectResponse
    {
        DB::table('ally_evals')->where('id', (int) $request->route('eval'))->delete();

        return back()->with('status', 'ally-eval-saved');
    }
}
