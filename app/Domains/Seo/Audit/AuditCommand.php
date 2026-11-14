<?php

declare(strict_types=1);

namespace App\Domains\Seo\Audit;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks the live site the way crawlers see it: every page in the sitemaps (or a sample per section) against the
 * SEO and answer-engine rules. Fails (exit 1) on any problem, so CI blocks a release that breaks them.
 */
final class AuditCommand extends Command
{
    protected $signature = 't2t:seo:audit
        {--base= : Site to check (defaults to the canonical address)}
        {--per-section=25 : Pages to check from each sitemap section (0 = all)}
        {--require=* : Page types that must have been checked, e.g. home,group,city}';

    protected $description = 'Check public pages for search and answer-engine essentials (titles, canonical, structured data, answer-first, FAQ, llms.txt)';

    /** @var array<string, string> page type by path pattern, first match wins */
    public const TYPES = [
        '#^/$#' => 'home',
        '#^/groups$#' => 'groups',
        '#^/groups/[^/]+$#' => 'group',
        '#^/industries$#' => 'industries',
        '#^/industries/[^/]+$#' => 'industry',
        '#^/industries/[^/]+/[^/]+$#' => 'industry-state',
        '#^/industries/[^/]+/[^/]+/[^/]+$#' => 'city',
        '#^/t/[^/]+$#' => 'profile',
        '#^/blog$#' => 'blog',
        '#^/blog/category/#' => 'blog-category',
        '#^/blog/[^/]+$#' => 'blog-post',
        '#^/reserve$#' => 'reserve',
        '#^/reserve/c/#' => 'reserve-share',
        '#^/find-a-pro$#' => 'find-a-pro',
        '#^/help(/|$)#' => 'help',
    ];

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('base') ?: config('seo.canonical_url')), '/');
        $get = fn (string $path) => Http::timeout(30)->withHeaders(['User-Agent' => 'T2T-SEO-Audit/1.0'])->get($base.$path);
        $llms = $get('/llms.txt')->body()."\n".$get('/llms-full.txt')->body();
        $paths = $this->paths($base, $get);
        $results = [];
        $failed = 0;
        foreach ($paths as $path) {
            $r = $get($path);
            $problems = $r->successful() ? PageAudit::check($r->body(), $base.$path, $llms) : ['Returned HTTP '.$r->status()];
            $results[] = ['path' => $path, 'type' => self::type($path), 'problems' => $problems];
            if ($problems !== []) {
                $failed++;
                $this->line("<fg=red>✗</> {$path}");
                foreach ($problems as $p) {
                    $this->line("    - {$p}");
                }
            } else {
                $this->line("<fg=green>✓</> {$path}");
            }
        }
        $checked = array_unique(array_column($results, 'type'));
        $missing = array_diff(array_filter(explode(',', implode(',', (array) $this->option('require')))), $checked);
        Cache::forever('seo:audit:last', ['at' => now()->toIso8601String(), 'pages' => count($results), 'failed' => $failed, 'results' => $results]);
        $this->newLine();
        $this->info(count($results).' pages checked ('.implode(', ', $checked).'), '.$failed.' with problems.');
        if ($missing !== []) {
            $this->error('Page types not checked (none in the sitemap): '.implode(', ', $missing));
        }

        return $failed === 0 && $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    public static function type(string $path): string
    {
        foreach (self::TYPES as $re => $type) {
            if (preg_match($re, $path)) {
                return $type;
            }
        }

        return 'page';
    }

    /**
     * Paths from the sitemaps (rewritten to the site being checked), sampled per section.
     *
     * @return list<string>
     */
    private function paths(string $base, Closure $get): array
    {
        $per = (int) $this->option('per-section');
        $paths = [];
        preg_match_all('#<loc>([^<]+)</loc>#', $get('/sitemap.xml')->body(), $parts);
        foreach ($parts[1] as $part) {
            preg_match_all('#<loc>([^<]+)</loc>#', $get((string) parse_url(html_entity_decode($part), PHP_URL_PATH))->body(), $locs);
            $section = array_map(fn ($l) => (string) (parse_url(html_entity_decode($l), PHP_URL_PATH) ?: '/'), $locs[1]);
            // Spread the sample across page types: a few of each kind before more of any one.
            $byType = [];
            foreach ($section as $p) {
                $byType[self::type($p)][] = $p;
            }
            $sample = [];
            while (($per === 0 || count($sample) < $per) && $byType !== []) {
                foreach ($byType as $t => &$list) {
                    if ($list === []) {
                        unset($byType[$t]);

                        continue;
                    }
                    $sample[] = array_shift($list);
                    if ($per !== 0 && count($sample) >= $per) {
                        break 2;
                    }
                }
                unset($list);
            }
            $paths = [...$paths, ...$sample];
        }

        return array_values(array_unique($paths));
    }
}
