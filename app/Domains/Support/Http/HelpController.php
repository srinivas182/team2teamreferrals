<?php

declare(strict_types=1);

namespace App\Domains\Support\Http;

use App\Domains\Identity\Models\User;
use App\Domains\Partners\PartnerStats;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use App\Domains\Support\Help;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The public help centre: topics, articles, search and "was this helpful?". */
final class HelpController
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $counts = Help::visible($user)->groupBy('category_id')->selectRaw('category_id, count(*) as n')->pluck('n', 'category_id');
        $popular = Help::visible($user)->orderByDesc('views')->orderByDesc('helpful_yes')->limit(8)->get(['title', 'slug', 'summary']);
        $answer = 'Answers to common questions about '.config('seo.site_name').': joining a network and claiming a seat, sending and receiving referrals, billing, the partner directory, and your account. Can’t find it? Contact support and a person replies.';

        return Inertia::render('help/Index', [
            'categories' => DB::table('help_categories')->orderBy('sort')->get()->filter(fn ($c) => ($counts[$c->id] ?? 0) > 0)
                ->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name, 'description' => $c->description, 'count' => (int) $counts[$c->id]])->values(),
            'popular' => $popular->map(fn ($a) => ['title' => $a->title, 'href' => '/help/'.$a->slug, 'summary' => $a->summary])->values(),
            'answer' => $answer,
            'seo' => (new SeoData(title: 'Help centre', description: $answer, path: '/help',
                schema: [Schema::organization(), Schema::faq(array_values($popular->map(fn ($a) => [(string) $a->title, (string) $a->summary])->all())), Schema::breadcrumbs([['Home', '/'], ['Help centre', '/help']])]))->toArray(),
        ]);
    }

    public function category(Request $request, string $slug): Response
    {
        $user = $this->user($request);
        $c = DB::table('help_categories')->where('slug', $slug)->first();
        abort_if($c === null, 404);
        $articles = Help::visible($user)->where('category_id', $c->id)->orderByDesc('helpful_yes')->orderBy('title')->get(['title', 'slug', 'summary', 'audience']);
        abort_if($articles->isEmpty(), 404);
        $path = '/help/topic/'.$c->slug;
        $public = $articles->where('audience', 'public');

        $n = $articles->count();
        $answer = trim(($c->description ?? '').' '.$n.' '.($n === 1 ? 'question is' : 'questions are').' answered here, each with a short answer first. If yours isn’t, contact support and a person replies.');

        return Inertia::render('help/Category', [
            'category' => ['slug' => $c->slug, 'name' => $c->name, 'description' => $c->description], 'answer' => $answer,
            'articles' => $articles->map(fn ($a) => ['title' => $a->title, 'href' => '/help/'.$a->slug, 'summary' => $a->summary])->values(),
            'seo' => (new SeoData(title: $c->name.' · Help centre', description: $answer, path: $path, index: $public->isNotEmpty(),
                schema: [Schema::organization(), Schema::faq(array_values($public->map(fn ($a) => [(string) $a->title, (string) $a->summary])->all())),
                    Schema::breadcrumbs([['Home', '/'], ['Help centre', '/help'], [(string) $c->name, $path]])]))->toArray(),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $user = $this->user($request);
        $a = Help::visible($user)->where('slug', $slug)->first();
        abort_if($a === null, 404);
        $c = DB::table('help_categories')->where('id', $a->category_id)->first();
        $path = '/help/'.$a->slug;
        $site = (string) config('seo.site_name');
        $article = ['@context' => 'https://schema.org', '@type' => 'TechArticle', 'headline' => $a->title, 'description' => $a->summary, 'url' => url($path),
            'datePublished' => substr((string) ($a->published_at ?? $a->created_at), 0, 10), 'dateModified' => substr((string) $a->updated_at, 0, 10),
            'publisher' => ['@type' => 'Organization', 'name' => $site, 'url' => url('/')], 'isAccessibleForFree' => true];
        $schema = [Schema::organization(), $article, Schema::faq([[(string) $a->title, (string) $a->summary]]),
            Schema::breadcrumbs([['Home', '/'], ['Help centre', '/help'], [(string) $c?->name, '/help/topic/'.$c?->slug], [(string) $a->title, $path]])];
        $steps = $a->howto ? Help::steps((string) $a->body_md) : [];
        if ($steps !== []) {
            $schema[] = ['@context' => 'https://schema.org', '@type' => 'HowTo', 'name' => $a->title, 'description' => $a->summary,
                'step' => array_map(fn ($s, $i) => ['@type' => 'HowToStep', 'position' => $i + 1, 'text' => $s], $steps, array_keys($steps))];
        }

        return Inertia::render('help/Article', [
            'article' => ['title' => $a->title, 'slug' => $a->slug, 'summary' => $a->summary, 'html' => $a->body_html, 'updated' => substr((string) $a->updated_at, 0, 10), 'audience' => $a->audience],
            'category' => ['slug' => $c?->slug, 'name' => $c?->name],
            'related' => Help::related($a, $user),
            'seo' => (new SeoData(title: (string) $a->title, description: (string) $a->summary, path: $path, index: $a->audience === 'public', type: 'article', schema: $schema,
                modifiedAt: substr((string) $a->updated_at, 0, 10)))->toArray(),
        ]);
    }

    public function search(Request $request): Response
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        $user = $this->user($request);
        $results = $q !== '' ? Help::search($q, $user, 20, ! PartnerStats::isBot($request)) : [];

        return Inertia::render('help/Search', [
            'q' => $q,
            'results' => array_map(fn ($r) => ['title' => $r->title, 'href' => '/help/'.$r->slug, 'summary' => $r->summary], $results),
            'seo' => SeoData::private('Search help')->toArray(),
        ]);
    }

    /** Page views from the browser (the page is cached), once per visitor a day, robots left out. */
    public function seen(Request $request, string $slug): \Illuminate\Http\Response
    {
        $id = DB::table('help_articles')->where(['slug' => $slug, 'status' => 'published'])->value('id');
        if ($id !== null && ! PartnerStats::isBot($request) && Cache::add('help:seen:'.$id.':'.hash('sha256', (string) $request->ip()).':'.now()->toDateString(), 1, now()->endOfDay())) {
            DB::table('help_articles')->where('id', $id)->increment('views');
        }

        return response()->noContent();
    }

    /** "Was this helpful?" Once per visitor per article a day; a "no" can say what was missing. */
    public function feedback(Request $request, string $slug): \Illuminate\Http\JsonResponse
    {
        $d = $request->validate(['helpful' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $a = DB::table('help_articles')->where(['slug' => $slug, 'status' => 'published'])->first(['id']);
        abort_if($a === null, 404);
        $ip = hash('sha256', (string) $request->ip().'|'.config('app.key'));
        $key = 'help:fb:'.$a->id.':'.$ip.':'.now()->toDateString();
        if (! PartnerStats::isBot($request)) {
            if (Cache::add($key, 1, now()->endOfDay())) {
                DB::table('help_articles')->where('id', $a->id)->increment($d['helpful'] ? 'helpful_yes' : 'helpful_no');
                DB::table('help_feedback')->insert(['article_id' => $a->id, 'helpful' => (bool) $d['helpful'], 'comment' => $d['comment'] ?? null, 'ip_hash' => $ip, 'created_at' => now()]);
            } elseif (filled($d['comment'] ?? null)) {
                // The comment usually follows the "no" a moment later.
                DB::table('help_feedback')->where(['article_id' => $a->id, 'ip_hash' => $ip])->whereNull('comment')->orderByDesc('id')->limit(1)->update(['comment' => $d['comment']]);
            }
        }

        return response()->json(['ok' => true]);
    }

    private function user(Request $request): ?User
    {
        $u = $request->user();

        return $u instanceof User ? $u : null;
    }
}
