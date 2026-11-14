<?php

declare(strict_types=1);

namespace App\Domains\Seo\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * Which public pages AI assistants read and send people to (Sprint 43b): visits by AI crawlers (and, for comparison,
 * search engine crawlers), and people arriving from an AI assistant's answer. Counted per day, page and source; no
 * personal data. Crawler visits are counted on the server, so pages served straight from the CDN cache are not
 * included; arrivals from AI answers are counted from the browser, so they are.
 */
final class AiVisits
{
    /** Crawler user-agent token => name shown in the report. AI first, then search engines for comparison. */
    public const CRAWLERS = [
        'GPTBot' => 'GPTBot', 'OAI-SearchBot' => 'OAI-SearchBot', 'ChatGPT-User' => 'ChatGPT-User', 'ClaudeBot' => 'ClaudeBot',
        'Claude-SearchBot' => 'Claude-SearchBot', 'Claude-User' => 'Claude-User', 'PerplexityBot' => 'PerplexityBot', 'Perplexity-User' => 'Perplexity-User',
        'Google-Extended' => 'Google-Extended', 'Applebot-Extended' => 'Applebot-Extended', 'Bytespider' => 'Bytespider', 'CCBot' => 'CCBot',
        'meta-externalagent' => 'Meta AI', 'Amazonbot' => 'Amazonbot', 'DuckAssistBot' => 'DuckAssistBot', 'MistralAI-User' => 'MistralAI-User',
        'Googlebot' => 'Googlebot', 'bingbot' => 'Bingbot', 'Applebot' => 'Applebot',
    ];

    /** Search engines (not AI assistants): shown separately in the report. */
    public const SEARCH = ['Googlebot', 'Bingbot', 'Applebot'];

    /** Hostname (or suffix) of an AI assistant => name in the report. */
    public const ASSISTANTS = [
        'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'perplexity.ai' => 'Perplexity', 'claude.ai' => 'Claude',
        'gemini.google.com' => 'Gemini', 'copilot.microsoft.com' => 'Copilot', 'chat.deepseek.com' => 'DeepSeek', 'meta.ai' => 'Meta AI', 'you.com' => 'You.com',
    ];

    public static function crawler(string $userAgent): ?string
    {
        foreach (self::CRAWLERS as $token => $name) {
            if (stripos($userAgent, $token) !== false) {
                return $name;
            }
        }

        return null;
    }

    /** The assistant a visit came from: the referring site, or a utm_source an assistant adds (chatgpt.com does). */
    public static function assistant(?string $referrer, ?string $utmSource = null): ?string
    {
        foreach ([parse_url((string) $referrer, PHP_URL_HOST), $utmSource] as $host) {
            $host = strtolower(trim((string) $host));
            if ($host === '') {
                continue;
            }
            foreach (self::ASSISTANTS as $domain => $name) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $name;
                }
            }
        }

        return null;
    }

    public static function count(string $kind, string $source, string $path): void
    {
        $path = mb_substr('/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/'), 0, 255);
        $key = ['day' => now()->toDateString(), 'kind' => $kind, 'source' => mb_substr($source, 0, 40), 'path' => $path];
        DB::table('seo_ai_visits')->insertOrIgnore([...$key, 'count' => 0]);
        DB::table('seo_ai_visits')->where($key)->increment('count');
    }

    /**
     * The last 30 days for the health report.
     *
     * @return array{crawlers: list<array{source: string, visits: int, search: bool}>, referrals: list<array{source: string, visits: int}>, pages: list<array{path: string, crawls: int, referrals: int}>}
     */
    public static function report(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->toDateString();
        $by = fn (string $kind) => DB::table('seo_ai_visits')->where('kind', $kind)->where('day', '>=', $from)->groupBy('source')
            ->selectRaw('source, SUM(count) as visits')->orderByDesc('visits')->get();
        $pages = DB::table('seo_ai_visits')->where('day', '>=', $from)->whereNotIn('source', self::SEARCH)->groupBy('path')
            ->selectRaw("path, SUM(CASE WHEN kind = 'crawler' THEN count ELSE 0 END) as crawls, SUM(CASE WHEN kind = 'referral' THEN count ELSE 0 END) as referrals")
            ->orderByRaw('SUM(count) DESC')->limit(15)->get();

        return [
            'crawlers' => array_values($by('crawler')->map(fn ($r) => ['source' => (string) $r->source, 'visits' => (int) $r->visits, 'search' => in_array($r->source, self::SEARCH, true)])->all()),
            'referrals' => array_values($by('referral')->map(fn ($r) => ['source' => (string) $r->source, 'visits' => (int) $r->visits])->all()),
            'pages' => array_values($pages->map(fn ($r) => ['path' => (string) $r->path, 'crawls' => (int) $r->crawls, 'referrals' => (int) $r->referrals])->all()),
        ];
    }
}
