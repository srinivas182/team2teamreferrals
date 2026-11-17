<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Content\Blocks;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * The help centre (Sprint 48a): articles written in Markdown, grouped by topic, each for an audience — everyone
 * (public and indexed), signed-in members, or network owners. Search ranks title matches above summary and body.
 */
final class Help
{
    public const AUDIENCES = ['public' => 'Everyone (public, in search engines)', 'members' => 'Signed-in members', 'owners' => 'Network owners'];

    /**
     * Audiences this person may read.
     *
     * @return list<string>
     */
    public static function audiences(?User $user): array
    {
        if ($user === null) {
            return ['public'];
        }
        if ($user->isStaff() || DB::table('group_owners')->where('user_id', $user->id)->exists()) {
            return ['public', 'members', 'owners'];
        }

        return ['public', 'members'];
    }

    public static function visible(?User $user): Builder
    {
        return DB::table('help_articles')->where('status', 'published')->whereIn('audience', self::audiences($user));
    }

    /** Markdown to safe HTML: raw HTML is stripped, then the result is cleaned again like every rich text here. */
    public static function render(string $markdown): string
    {
        return Blocks::purify((string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }

    /**
     * The numbered steps of a how-to article (its ordered list items), for HowTo structured data.
     *
     * @return list<string>
     */
    public static function steps(string $markdown): array
    {
        preg_match_all('/^\s*\d+[.)]\s+(.+)$/m', $markdown, $m);

        return array_map(fn ($s) => trim(strip_tags((string) Str::markdown((string) $s))), $m[1]);
    }

    /**
     * Articles for a query, best first: title matches count most, then the summary, then the body. Articles with
     * every word come first; for longer, natural questions ("how do I… my email is…") articles with at least half the
     * words count too. Each search is logged with its result count, so searches with nothing to show become writing tasks.
     *
     * @return list<stdClass>
     */
    public static function search(string $q, ?User $user, int $limit = 20, bool $log = true): array
    {
        $stop = ['how', 'do', 'does', 'the', 'and', 'can', 'what', 'is', 'my', 'to', 'of', 'for', 'in', 'an', 'it', 'me', 'we', 'our', 'you', 'your', 'are', 'on', 'at', 'with', 'or', 'be', 'when', 'why', 'where', 'there', 'this', 'that'];
        $words = array_values(array_filter(array_unique(preg_split('/[^\pL\pN]+/u', mb_strtolower($q)) ?: []), fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, $stop, true)));
        if ($words === []) {
            return [];
        }
        $match = fn ($query, string $w) => $query->where(function ($x) use ($w) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $w).'%';
            $x->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(summary) like ?', [$like])->orWhereRaw('lower(body_md) like ?', [$like]);
        });
        $cols = ['id', 'ulid', 'slug', 'title', 'summary', 'category_id', 'body_md', 'updated_at'];
        $all = self::visible($user);
        foreach ($words as $w) {
            $match($all, $w);
        }
        $rows = $all->get($cols);
        $need = count($words);
        if ($rows->isEmpty() && count($words) > 2) {
            $need = (int) ceil(count($words) / 2);
            $rows = self::visible($user)->where(function ($any) use ($words, $match) {
                foreach ($words as $w) {
                    $any->orWhere(fn ($x) => $match($x, $w));
                }
            })->get($cols);
        }
        $scored = $rows->map(function ($r) use ($words) {
            [$score, $hits] = [0, 0];
            foreach ($words as $w) {
                $n = substr_count(mb_strtolower($r->title), $w) * 6 + substr_count(mb_strtolower($r->summary), $w) * 3 + min(5, substr_count(mb_strtolower($r->body_md), $w));
                $score += $n;
                $hits += $n > 0 ? 1 : 0;
            }
            $r->score = $score;
            $r->hits = $hits;

            return $r;
        })->filter(fn ($r) => $r->hits >= $need)->sortByDesc('score')->take($limit)->all();
        $scored = array_values($scored);
        if ($log) {
            DB::table('help_searches')->insert(['query' => mb_substr(trim($q), 0, 200), 'results' => count($scored), 'created_at' => now()]);
        }

        return $scored;
    }

    /**
     * Other articles in the same topic, most helpful first.
     *
     * @return list<array{title: string, href: string}>
     */
    public static function related(stdClass $article, ?User $user, int $limit = 4): array
    {
        return array_values(self::visible($user)->where('category_id', $article->category_id)->where('id', '!=', $article->id)
            ->orderByDesc('helpful_yes')->orderBy('title')->limit($limit)->get(['title', 'slug'])
            ->map(fn ($r) => ['title' => (string) $r->title, 'href' => '/help/'.$r->slug])->all());
    }
}
