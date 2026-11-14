<?php

declare(strict_types=1);

namespace App\Domains\Seo\Http;

use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/** Super admin → SEO and AEO: which AI crawlers may read the site, and the latest page check. */
final class SeoAdminController
{
    /** What each AI crawler is for, in plain words. */
    public const CRAWLERS = [
        'GPTBot' => 'OpenAI: collects pages to train ChatGPT models',
        'OAI-SearchBot' => 'OpenAI: ChatGPT search results and citations',
        'ChatGPT-User' => 'OpenAI: pages a ChatGPT user asks it to open',
        'ClaudeBot' => 'Anthropic: collects pages to train Claude models',
        'Claude-SearchBot' => 'Anthropic: Claude search results and citations',
        'PerplexityBot' => 'Perplexity: answers and citations',
        'Google-Extended' => 'Google: Gemini and AI training (Google Search itself is not affected)',
        'Applebot-Extended' => 'Apple: Apple Intelligence training (Siri and Spotlight search not affected)',
    ];

    public function __construct(private readonly Settings $settings) {}

    public function index(): Response
    {
        return Inertia::render('admin/Seo', [
            'crawlers' => collect(self::allowed($this->settings))->map(fn ($allowed, $bot) => ['bot' => $bot, 'purpose' => self::CRAWLERS[$bot] ?? '', 'allowed' => $allowed])->values(),
            'indexable' => (bool) config('seo.indexable'),
            'audit' => Cache::get('seo:audit:last'),
            // Nightly check history, newest first.
            'history' => \Illuminate\Support\Facades\DB::table('seo_checks')->orderByDesc('ran_at')->limit(14)->get(['ran_at', 'trigger', 'summary'])
                ->map(fn ($r) => ['at' => (string) $r->ran_at, 'trigger' => (string) $r->trigger, ...(array) json_decode((string) $r->summary, true)])->values(),
            'searchConsole' => ['enabled' => $this->settings->get('seo.gsc.enabled') === '1', 'site' => (string) $this->settings->get('seo.gsc.site'),
                'credentials' => $this->settings->masked('seo.gsc.credentials'), 'last' => Cache::get('seo:gsc:last'), 'indexNow' => (bool) config('seo.indexnow.enabled')],
            'engines' => collect(\App\Domains\Seo\Monitoring\AnswerEngines::ENGINES)->map(fn ($e, $key) => ['key' => $key, 'name' => $e[0], 'hint' => $e[2], 'defaultModel' => $e[1],
                'enabled' => $this->settings->get("aeo.{$key}.enabled") === '1', 'model' => (string) $this->settings->get("aeo.{$key}.model"), 'saved' => $this->settings->masked("aeo.{$key}.key")])->values(),
            'maxQuestions' => (int) ($this->settings->get('aeo.max_questions') ?? \App\Domains\Seo\Monitoring\Visibility::DEFAULT_MAX),
            'questions' => \Illuminate\Support\Facades\DB::table('aeo_questions')->orderByRaw("CASE WHEN source = 'admin' THEN 0 ELSE 1 END")->orderBy('id')->limit(300)
                ->get(['id', 'question', 'source', 'active'])->map(fn ($q) => ['id' => (int) $q->id, 'question' => (string) $q->question, 'source' => (string) $q->source, 'active' => (bool) $q->active])->values(),
            'visibility' => \App\Domains\Seo\Monitoring\Visibility::report(),
            'aiVisits' => \App\Domains\Seo\Monitoring\AiVisits::report(),
            'gone' => \Illuminate\Support\Facades\DB::table('gone_urls')->orderByDesc('gone_at')->limit(20)->get(['path', 'reason', 'gone_at'])->values(),
            'status' => session('status'),
            'seo' => SeoData::private('SEO and AEO')->toArray(),
        ]);
    }

    public function crawlers(Request $request): RedirectResponse
    {
        $d = $request->validate(['allowed' => ['present', 'array'], 'allowed.*' => ['string', 'in:'.implode(',', array_keys(self::CRAWLERS))]]);
        $before = self::allowed($this->settings);
        foreach (array_keys(self::CRAWLERS) as $bot) {
            $this->settings->set('seo.ai_crawler.'.$bot, in_array($bot, $d['allowed'], true) ? '1' : '0', userId: $request->user()?->id);
        }
        activity('settings')->causedBy($request->user())->event('ai-crawlers-changed')
            ->withProperties(['before' => $before, 'after' => self::allowed($this->settings)])->log('AI crawler rules changed');

        return back()->with('status', 'ai-crawlers-saved');
    }

    /** Run the full page, link and duplicate check now (in the background). */
    public function runCheck(Request $request): RedirectResponse
    {
        dispatch(fn () => \Illuminate\Support\Facades\Artisan::call('t2t:seo:audit', ['--site' => true, '--per-section' => 200, '--trigger' => 'manual']));
        activity('settings')->causedBy($request->user())->event('seo-check-run')->log('SEO check started');

        return back()->with('status', 'seo-check-started');
    }

    public function searchConsole(Request $request): RedirectResponse
    {
        $d = $request->validate(['enabled' => ['boolean'], 'site' => ['nullable', 'string', 'max:200', 'regex:#^(sc-domain:[a-z0-9.-]+|https?://\S+/)$#i'], 'credentials' => ['nullable', 'string', 'max:20000', 'json']],
            ['site.regex' => 'Use the property exactly as Search Console shows it: "sc-domain:example.com" or "https://example.com/".']);
        if (($d['credentials'] ?? '') !== '') {
            $c = json_decode((string) $d['credentials'], true);
            if (! is_array($c) || ! isset($c['client_email'], $c['private_key'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['credentials' => 'Paste the whole JSON key file of a Google service account.']);
            }
            $this->settings->set('seo.gsc.credentials', (string) $d['credentials'], true, $request->user()?->id);
        }
        $this->settings->set('seo.gsc.site', $d['site'] ?? null, userId: $request->user()?->id);
        $this->settings->set('seo.gsc.enabled', ($d['enabled'] ?? false) ? '1' : '0', userId: $request->user()?->id);
        activity('settings')->causedBy($request->user())->event('search-console-changed')->withProperties(['enabled' => (bool) ($d['enabled'] ?? false), 'site' => $d['site'] ?? null])->log('Search Console settings changed');

        return back()->with('status', 'search-console-saved');
    }

    public function submitSitemap(): RedirectResponse
    {
        $r = app(\App\Domains\Seo\Monitoring\SearchConsole::class)->submit();

        return back()->with('status', $r['ok'] ? 'sitemap-submitted' : 'sitemap-failed');
    }

    /** API keys and switches for the answer engines; a key is only replaced when a new one is typed. */
    public function engines(Request $request): RedirectResponse
    {
        $keys = array_keys(\App\Domains\Seo\Monitoring\AnswerEngines::ENGINES);
        $d = $request->validate(['engines' => ['required', 'array'], 'engines.*.key' => ['nullable', 'string', 'max:300'], 'engines.*.enabled' => ['boolean'],
            'engines.*.model' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]*$/'], 'max_questions' => ['required', 'integer', 'min:1', 'max:500']]);
        foreach ($keys as $e) {
            $in = (array) ($d['engines'][$e] ?? []);
            if (trim((string) ($in['key'] ?? '')) !== '') {
                $this->settings->set("aeo.{$e}.key", trim((string) $in['key']), true, $request->user()?->id);
            }
            $on = (bool) ($in['enabled'] ?? false);
            if ($on && ! $this->settings->has("aeo.{$e}.key")) {
                throw \Illuminate\Validation\ValidationException::withMessages(["engines.{$e}.key" => 'Add the API key before switching this engine on.']);
            }
            $this->settings->set("aeo.{$e}.enabled", $on ? '1' : '0', userId: $request->user()?->id);
            $this->settings->set("aeo.{$e}.model", trim((string) ($in['model'] ?? '')) ?: null, userId: $request->user()?->id);
        }
        $this->settings->set('aeo.max_questions', (string) $d['max_questions'], userId: $request->user()?->id);
        activity('settings')->causedBy($request->user())->event('answer-engines-changed')
            ->withProperties(['enabled' => app(\App\Domains\Seo\Monitoring\AnswerEngines::class)->enabled(), 'max_questions' => (int) $d['max_questions']])->log('Answer engine settings changed');

        return back()->with('status', 'answer-engines-saved');
    }

    public function addQuestion(Request $request): RedirectResponse
    {
        $d = $request->validate(['question' => ['required', 'string', 'min:10', 'max:300', 'unique:aeo_questions,question']]);
        \Illuminate\Support\Facades\DB::table('aeo_questions')->insert(['question' => trim((string) $d['question']), 'source' => 'admin', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'question-added');
    }

    public function toggleQuestion(int $question): RedirectResponse
    {
        $q = \Illuminate\Support\Facades\DB::table('aeo_questions')->where('id', $question)->first() ?? abort(404);
        \Illuminate\Support\Facades\DB::table('aeo_questions')->where('id', $question)->update(['active' => ! $q->active, 'updated_at' => now()]);

        return back();
    }

    public function runVisibility(Request $request): RedirectResponse
    {
        abort_if(app(\App\Domains\Seo\Monitoring\AnswerEngines::class)->enabled() === [], 422, 'Switch on at least one answer engine first.');
        \Illuminate\Support\Facades\Artisan::call('t2t:aeo:check');
        activity('settings')->causedBy($request->user())->event('aeo-check-run')->log('Answer-engine check started');

        return back()->with('status', 'aeo-check-started');
    }

    /**
     * Each AI crawler and whether robots.txt lets it in: the super admin's choice, else the default in config/seo.php.
     *
     * @return array<string, bool>
     */
    public static function allowed(Settings $settings): array
    {
        $out = [];
        foreach ((array) config('seo.ai_crawlers') as $bot => $default) {
            $saved = $settings->get('seo.ai_crawler.'.$bot);
            $out[(string) $bot] = $saved === null ? (bool) $default : $saved === '1';
        }

        return $out;
    }
}
