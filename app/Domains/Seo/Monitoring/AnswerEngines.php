<?php

declare(strict_types=1);

namespace App\Domains\Seo\Monitoring;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Asks the main AI answer engines a question with web search on, and returns the answer and every address it cited
 * (Sprint 43b). Each engine is off until a super admin adds its API key and switches it on in Admin → SEO and AEO.
 * Answers are read defensively: every cited address anywhere in the reply is collected, so a change in an engine's
 * reply format doesn't silently lose citations.
 */
final class AnswerEngines
{
    /** engine => [name, default model, key hint] */
    public const ENGINES = [
        'openai' => ['ChatGPT (OpenAI)', 'gpt-4.1-mini', 'An OpenAI API key (platform.openai.com)'],
        'perplexity' => ['Perplexity', 'sonar', 'A Perplexity API key (perplexity.ai/settings/api)'],
        'anthropic' => ['Claude (Anthropic)', 'claude-haiku-5-5', 'An Anthropic API key (console.anthropic.com)'],
        'google' => ['Google AI Overviews (via SerpApi)', '', 'A SerpApi key (serpapi.com), a paid service'],
    ];

    public function __construct(private readonly Settings $settings) {}

    /** @return list<string> engines switched on with a key */
    public function enabled(): array
    {
        return array_values(array_filter(array_keys(self::ENGINES), fn ($e) => $this->settings->get("aeo.{$e}.enabled") === '1' && $this->settings->has("aeo.{$e}.key")));
    }

    public function model(string $engine): string
    {
        return trim((string) $this->settings->get("aeo.{$engine}.model")) ?: self::ENGINES[$engine][1];
    }

    /** @return array{text: string, urls: list<string>} */
    public function ask(string $engine, string $question): array
    {
        return $this->fetch($engine, $question);
    }

    /** @return array{text: string, urls: list<string>} */
    private function fetch(string $engine, string $question): array
    {
        $key = (string) $this->settings->get("aeo.{$engine}.key");
        if ($key === '') {
            throw new RuntimeException('No API key.');
        }
        $http = Http::timeout(90)->acceptJson();

        return match ($engine) {
            'openai' => $this->read($http->withToken($key)->post('https://api.openai.com/v1/responses', [
                'model' => $this->model('openai'), 'tools' => [['type' => 'web_search']], 'input' => $question,
            ])->throw()->json(), 'output'),
            'perplexity' => $this->read($http->withToken($key)->post('https://api.perplexity.ai/chat/completions', [
                'model' => $this->model('perplexity'), 'messages' => [['role' => 'user', 'content' => $question]],
            ])->throw()->json()),
            'anthropic' => $this->read($http->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model('anthropic'), 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => $question]],
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            ])->throw()->json(), 'content'),
            'google' => $this->google($http, $key, $question),
            default => throw new RuntimeException('Unknown engine.'),
        };
    }

    /**
     * Google's AI Overview for the question, through SerpApi (the overview sometimes needs a second request).
     *
     * @return array{text: string, urls: list<string>}
     */
    private function google(\Illuminate\Http\Client\PendingRequest $http, string $key, string $question): array
    {
        $r = $http->get('https://serpapi.com/search.json', ['engine' => 'google', 'q' => $question, 'gl' => 'us', 'hl' => 'en', 'api_key' => $key])->throw()->json();
        $overview = is_array($r['ai_overview'] ?? null) ? $r['ai_overview'] : null;
        if ($overview !== null && isset($overview['page_token']) && ! isset($overview['text_blocks'])) {
            $more = $http->get('https://serpapi.com/search.json', ['engine' => 'google_ai_overview', 'page_token' => $overview['page_token'], 'api_key' => $key])->throw()->json();
            $overview = is_array($more['ai_overview'] ?? null) ? $more['ai_overview'] : $overview;
        }
        if ($overview === null) {
            return ['text' => 'Google showed no AI Overview for this question.', 'urls' => []];
        }

        return $this->read($overview);
    }

    /**
     * The answer's text and every cited address.
     *
     * @param  array<mixed>|null  $json
     * @return array{text: string, urls: list<string>}
     */
    private function read(?array $json, ?string $within = null): array
    {
        $json ??= [];
        $root = $within !== null && is_array($json[$within] ?? null) ? $json[$within] : $json;
        $urls = [];
        $text = [];
        array_walk_recursive($root, function ($v, $k) use (&$urls, &$text) {
            if (! is_string($v)) {
                return;
            }
            if (in_array($k, ['url', 'link', 'source'], true) && preg_match('#^https?://#', $v)) {
                $urls[] = $v;
            } elseif (in_array($k, ['text', 'content', 'snippet'], true) && mb_strlen($v) > 20) {
                $text[] = $v;
                preg_match_all('#https?://[^\s)\]>"\']+#', $v, $m);
                $urls = [...$urls, ...$m[0]];
            }
        });
        foreach (['citations'] as $k) { // Perplexity lists them as plain strings
            foreach ((array) ($json[$k] ?? []) as $u) {
                if (is_string($u) && preg_match('#^https?://#', $u)) {
                    $urls[] = $u;
                }
            }
        }

        return ['text' => mb_substr(trim(implode("\n", array_unique($text))), 0, 2000), 'urls' => array_values(array_unique(array_map(fn ($u) => rtrim($u, '.,;'), $urls)))];
    }
}
