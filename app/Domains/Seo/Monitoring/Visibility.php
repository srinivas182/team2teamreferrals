<?php

declare(strict_types=1);

namespace App\Domains\Seo\Monitoring;

use App\Domains\Content\Industry\IndustryContent;
use App\Domains\Network\Directory\Directory;
use Illuminate\Support\Facades\DB;

/**
 * The monthly answer-engine visibility check (Sprint 43b): a set of questions people ask AI assistants, per industry
 * and city ("How do I find a trusted roofer in Tampa, FL?"), asked of each engine that is switched on; each answer is
 * recorded with whether it cites this site or one of its members. The health report shows the trend, so content work
 * follows what actually gets quoted.
 */
final class Visibility
{
    /** Default cap on questions per month (each is asked of every engine that's on), to keep API costs known. */
    public const DEFAULT_MAX = 40;

    /** Cities per industry in the automatic questions: the largest with a filled seat. */
    public const CITIES_PER_INDUSTRY = 3;

    /** Create the automatic questions from published industries and the cities where they have members. Returns how many were added. */
    public static function refreshQuestions(): int
    {
        $added = 0;
        foreach (IndustryContent::published() as $industry) {
            $t = $industry->terms();
            $qs = [["How do {$industry->name} referral fees work?", null]];
            $cities = DB::table('seats')->join('groups', 'groups.id', '=', 'seats.group_id')->join('cities', function ($j) {
                $j->on('cities.id', '=', 'seats.territory_id')->where('seats.territory_type', '=', 'city');
            })->join('states', 'states.id', '=', 'cities.state_id')
                ->where(['groups.industry_id' => $industry->id, 'groups.status' => 'live', 'groups.is_private' => false])->whereIn('seats.status', Directory::FILLED)
                ->orderByDesc('cities.population')->limit(self::CITIES_PER_INDUSTRY)->get(['cities.id', 'cities.name', 'states.code']);
            foreach ($cities as $c) {
                $qs[] = ["How do I find a trusted {$t['pro']} in {$c->name}, {$c->code}?", (int) $c->id];
                $qs[] = ["Who is the best {$t['pro']} in {$c->name}, {$c->code}?", (int) $c->id];
            }
            foreach ($qs as [$q, $city]) {
                $added += DB::table('aeo_questions')->insertOrIgnore(['question' => mb_substr($q, 0, 300), 'industry_id' => $industry->id, 'city_id' => $city,
                    'source' => 'auto', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return $added;
    }

    /**
     * Hosts that count as "a member was cited": public members' own websites.
     *
     * @return list<string>
     */
    public static function memberHosts(): array
    {
        return array_values(array_unique(array_filter(DB::table('teams')->where('profile_public', true)->whereNotNull('website')->pluck('website')
            ->map(fn ($w) => self::host((string) $w))->all(), fn ($h) => $h !== '')));
    }

    /**
     * Record one engine's answer to one question for a month.
     *
     * @param  array{text: string, urls: list<string>}|null  $answer  null when the engine failed
     */
    public static function record(int $questionId, string $engine, string $month, ?array $answer, ?string $error = null): void
    {
        $ours = self::host((string) config('seo.canonical_url'));
        $members = self::memberHosts();
        $urls = $answer['urls'] ?? [];
        $hosts = array_map(fn ($u) => self::host($u), $urls);
        $platform = in_array($ours, $hosts, true);
        $member = array_intersect($hosts, $members) !== []
            || array_filter($urls, fn ($u) => self::host($u) === $ours && preg_match('#^/t/#', (string) parse_url($u, PHP_URL_PATH))) !== [];
        DB::table('aeo_results')->upsert([[
            'question_id' => $questionId, 'engine' => $engine, 'month' => $month, 'cited_platform' => $platform, 'cited_member' => $member,
            'citations' => json_encode(array_slice($urls, 0, 50)), 'excerpt' => $answer !== null ? mb_substr($answer['text'], 0, 1000) : null,
            'error' => $error !== null ? mb_substr($error, 0, 255) : null, 'asked_at' => now(),
        ]], ['question_id', 'engine', 'month'], ['cited_platform', 'cited_member', 'citations', 'excerpt', 'error', 'asked_at']);
    }

    /**
     * For the health report: each engine's share of answers citing the site or a member, by month (last 6), and the latest answers.
     *
     * @return array{months: list<string>, engines: list<array{engine: string, name: string, months: array<string, array{asked: int, platform: int, member: int}>}>, latest: list<array<string, mixed>>}
     */
    public static function report(): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = now()->startOfMonth()->subMonths($i)->format('Y-m');
        }
        $rows = DB::table('aeo_results')->whereIn('month', $months)->whereNull('error')->groupBy('engine', 'month')
            ->selectRaw('engine, month, COUNT(*) as asked, SUM(CASE WHEN cited_platform THEN 1 ELSE 0 END) as platform, SUM(CASE WHEN cited_member THEN 1 ELSE 0 END) as member')->get();
        $engines = [];
        foreach (AnswerEngines::ENGINES as $e => [$name]) {
            $m = [];
            foreach ($rows->where('engine', $e) as $r) {
                $m[(string) $r->month] = ['asked' => (int) $r->asked, 'platform' => (int) $r->platform, 'member' => (int) $r->member];
            }
            $engines[] = ['engine' => $e, 'name' => $name, 'months' => $m];
        }
        $latest = DB::table('aeo_results as r')->join('aeo_questions as q', 'q.id', '=', 'r.question_id')->orderByDesc('r.asked_at')->limit(40)
            ->get(['q.question', 'r.engine', 'r.month', 'r.cited_platform', 'r.cited_member', 'r.citations', 'r.excerpt', 'r.error'])
            ->map(fn ($r) => ['question' => (string) $r->question, 'engine' => (string) $r->engine, 'month' => (string) $r->month, 'platform' => (bool) $r->cited_platform,
                'member' => (bool) $r->cited_member, 'citations' => array_slice((array) json_decode((string) $r->citations, true), 0, 8), 'excerpt' => $r->excerpt, 'error' => $r->error])->values()->all();

        return ['months' => $months, 'engines' => $engines, 'latest' => array_values($latest)];
    }

    private static function host(string $url): string
    {
        $h = strtolower((string) parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST));

        return (string) preg_replace('/^www\./', '', $h);
    }
}
