<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Ai\AiCredits;
use App\Domains\Platform\Ai\AiProvider;
use App\Domains\Platform\Ai\Prompts;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Admin → AI: versioned prompts (edit, history, rollback, test run), usage and cost reporting, and credit defaults. */
final class AiAdminController
{
    /**
     * Sample input for test runs: invented, never member data.
     *
     * @var array<string, array<string, string>>
     */
    public const SAMPLES = [
        'follow_up_draft' => ['facts' => "From: Sunrise Solar (the team that received the referral)\nTo: Peak Realty\nIndustry: Solar installers · City: Houston, TX\nStage: quoted\nTiming: hot\nWhat needs doing: Update Peak Realty", 'tone' => 'friendly', 'fee_rule' => 'Don’t mention fees unless asked.'],
        'lead_summary' => ['referral' => "Industry: Solar installers\nCity: Houston, TX\nClient: Sam P.\nTiming: hot\nRoof type: Asphalt shingle\nAverage monthly bill: 240\nSender’s notes: Wants quotes before the summer; interested in a battery.", 'fee_rule' => 'You may mention the agreed referral fee only if it appears in the data.'],
    ];

    public function index(Request $request): Response
    {
        $month = (string) ($request->query('month') ?: now()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);
        foreach (array_keys(Prompts::DEFAULTS) as $key) {
            Prompts::active($key); // seeds version 1 the first time
        }
        $logs = DB::table('ai_usage_logs')->where('created_at', 'like', $month.'%');
        $feedback = DB::table('ai_feedback as f')->join('ai_usage_logs as l', 'l.id', '=', 'f.usage_id')
            ->groupBy('l.prompt_key', 'l.prompt_version')->selectRaw('l.prompt_key, l.prompt_version, sum(case when f.rating > 0 then 1 else 0 end) as up, sum(case when f.rating < 0 then 1 else 0 end) as down')->get();

        return Inertia::render('admin/Ai', [
            'month' => $month,
            'totals' => [
                'calls' => (clone $logs)->where('status', 'ok')->count(), 'cost' => round((float) (clone $logs)->sum('cost_usd'), 2), 'credits' => (int) (clone $logs)->sum('credits'),
                'blocked' => (clone $logs)->where('status', 'blocked')->count(), 'failed' => (clone $logs)->where('status', 'failed')->count(),
            ],
            'byFeature' => (clone $logs)->where('status', 'ok')->groupBy('feature')->selectRaw('feature, count(*) as calls, sum(cost_usd) as cost, sum(credits) as credits')->get()
                ->map(fn ($r) => ['feature' => (string) $r->feature, 'calls' => (int) $r->calls, 'cost' => round((float) $r->cost, 2), 'credits' => (int) $r->credits]),
            'byModel' => (clone $logs)->where('status', 'ok')->groupBy('provider', 'model')->selectRaw('provider, model, count(*) as calls, sum(input_tokens) as input, sum(output_tokens) as output, sum(cost_usd) as cost')->get()
                ->map(fn ($r) => ['provider' => (string) $r->provider, 'model' => (string) $r->model, 'calls' => (int) $r->calls, 'tokens' => (int) $r->input + (int) $r->output, 'cost' => round((float) $r->cost, 2)]),
            'byTeam' => DB::table('ai_usage_logs as l')->join('teams as t', 't.id', '=', 'l.team_id')->where('l.created_at', 'like', $month.'%')->where('l.status', 'ok')
                ->groupBy('t.id', 't.name')->selectRaw('t.name, count(*) as calls, sum(l.credits) as credits, sum(l.cost_usd) as cost')->orderByDesc('credits')->limit(20)->get()
                ->map(fn ($r) => ['team' => (string) $r->name, 'calls' => (int) $r->calls, 'credits' => (int) $r->credits, 'cost' => round((float) $r->cost, 2)]),
            'problems' => (clone $logs)->whereIn('status', ['blocked', 'failed'])->groupBy('status', 'reason')->selectRaw('status, reason, count(*) as n')->orderByDesc('n')->get()
                ->map(fn ($r) => ['status' => (string) $r->status, 'reason' => (string) $r->reason, 'count' => (int) $r->n]),
            'prompts' => collect(array_keys(Prompts::DEFAULTS))->map(fn ($key) => ['key' => $key,
                'versions' => DB::table('ai_prompts')->where('key', $key)->orderByDesc('version')->get()->map(function ($v) use ($feedback, $key) {
                    $fb = $feedback->first(fn ($f) => $f->prompt_key === $key && (int) $f->prompt_version === (int) $v->version);

                    return ['version' => (int) $v->version, 'active' => (bool) $v->active, 'system' => (string) $v->system, 'template' => (string) $v->template,
                        'note' => $v->note, 'at' => substr((string) $v->created_at, 0, 16), 'up' => $fb !== null ? (int) $fb->up : 0, 'down' => $fb !== null ? (int) $fb->down : 0];
                })->values(),
                'placeholders' => self::required($key)])->values(),
            'credits' => ['per_seat' => AiCredits::setting('per_seat'), 'per_seat_premium' => AiCredits::setting('per_seat_premium'), 'daily_cap' => AiCredits::setting('daily_cap')],
            'testResult' => session('ai_test'),
            'status' => session('status'),
            'seo' => SeoData::private('AI')->toArray(),
        ]);
    }

    /** Editing a prompt always creates a new version; it goes live only when activated. */
    public function savePrompt(Request $request, string $key): RedirectResponse
    {
        abort_unless(isset(Prompts::DEFAULTS[$key]), 404);
        Prompts::active($key); // the default is version 1 in the history before anything else happens
        $d = $request->validate(['system' => ['required', 'string', 'max:6000'], 'template' => ['required', 'string', 'max:6000'], 'note' => ['required', 'string', 'max:300'], 'activate' => ['boolean']]);
        // The prompt must still receive its data, and keep the safety rules the platform relies on.
        $missing = array_values(array_filter(self::required($key), fn ($p) => ! str_contains($d['system'].$d['template'], $p)));
        if ($missing !== []) {
            throw ValidationException::withMessages(['template' => 'Keep these placeholders: '.implode(', ', $missing)]);
        }
        // Any prompt whose default protects against injected instructions must keep that rule.
        if (str_contains(strtolower(Prompts::DEFAULTS[$key]['system']), 'not instructions') && ! str_contains(strtolower($d['system']), 'not instructions')) {
            throw ValidationException::withMessages(['system' => 'Keep the rule that member text is data, not instructions.']);
        }
        $version = (int) DB::table('ai_prompts')->where('key', $key)->max('version') + 1;
        DB::transaction(function () use ($key, $version, $d, $request) {
            if ($d['activate'] ?? false) {
                DB::table('ai_prompts')->where('key', $key)->update(['active' => false]);
            }
            DB::table('ai_prompts')->insert(['key' => $key, 'version' => $version, 'system' => $d['system'], 'template' => $d['template'], 'active' => (bool) ($d['activate'] ?? false),
                'note' => $d['note'], 'created_by' => $request->user()?->id, 'created_at' => now()]);
        });
        activity('admin')->causedBy($request->user())->event('ai-prompt')->withProperties(['key' => $key, 'version' => $version])->log("AI prompt {$key} v{$version} saved");

        return back()->with('status', 'ai-prompt-saved');
    }

    /** Make a version the live one: also how a change is rolled back. */
    public function activate(Request $request, string $key, int $version): RedirectResponse
    {
        abort_unless(isset(Prompts::DEFAULTS[$key]), 404);
        Prompts::active($key); // the default is version 1 in the history before anything else happens
        abort_unless(DB::table('ai_prompts')->where(['key' => $key, 'version' => $version])->exists(), 404);
        DB::transaction(function () use ($key, $version) {
            DB::table('ai_prompts')->where('key', $key)->update(['active' => false]);
            DB::table('ai_prompts')->where(['key' => $key, 'version' => $version])->update(['active' => true]);
        });
        activity('admin')->causedBy($request->user())->event('ai-prompt')->withProperties(['key' => $key, 'version' => $version])->log("AI prompt {$key} v{$version} made live");

        return back()->with('status', 'ai-prompt-live');
    }

    /** Try a version on invented sample data before it goes live. */
    public function test(Request $request, string $key, int $version, AiProvider $ai): RedirectResponse
    {
        abort_unless(isset(Prompts::DEFAULTS[$key]), 404);
        Prompts::active($key); // the default is version 1 in the history before anything else happens
        $v = DB::table('ai_prompts')->where(['key' => $key, 'version' => $version])->first();
        abort_if($v === null, 404);
        $vars = self::SAMPLES[$key]; // every prompt has a sample (enforced by a test)
        try {
            $r = $ai->complete(Prompts::fill((string) $v->template, $vars), 'admin_test', 300, Prompts::fill((string) $v->system, $vars));
            $result = ['version' => $version, 'text' => trim($r['text']), 'meta' => "{$r['provider']} · {$r['model']} · \${$r['cost_usd']}"];
        } catch (Throwable $e) {
            $result = ['version' => $version, 'text' => null, 'meta' => 'Test failed: '.$e->getMessage()];
        }

        return back()->with('ai_test', $result);
    }

    public function saveCredits(Request $request, Settings $settings): RedirectResponse
    {
        $d = $request->validate(['per_seat' => ['required', 'integer', 'between:0,100000'], 'per_seat_premium' => ['required', 'integer', 'between:0,100000'], 'daily_cap' => ['required', 'integer', 'between:1,100000']]);
        foreach ($d as $k => $v) {
            $settings->set("ai.credits.{$k}", (string) $v);
        }
        activity('admin')->causedBy($request->user())->event('ai-credits')->withProperties($d)->log('AI credit settings changed');

        return back()->with('status', 'ai-credits-saved');
    }

    /** @return list<string> placeholders a prompt must keep */
    public static function required(string $key): array
    {
        preg_match_all('/\{[a-z_]+\}/', Prompts::DEFAULTS[$key]['system'].Prompts::DEFAULTS[$key]['template'], $m);

        return array_values(array_unique($m[0]));
    }
}
