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
