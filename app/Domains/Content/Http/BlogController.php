<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Content\MediaLibrary;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The public blog: posts lead with their answer summary, and carry Article data with author, reviewer and dates. */
final class BlogController
{
    public const PER_PAGE = 12;

    public function index(Request $request, ?string $category = null): Response
    {
        $cat = $category !== null ? DB::table('blog_categories')->where('slug', $category)->first() : null;
        abort_if($category !== null && $cat === null, 404);
        $page = max(1, (int) $request->query('page', '1'));
        $q = DB::table('posts as p')->leftJoin('media as m', 'm.id', '=', 'p.cover_media_id')->where('p.status', 'published')->when($cat, fn ($q, $c) => $q->where('p.category_id', $c->id));
        $total = (clone $q)->count();
        $posts = $q->orderByDesc('p.published_at')->forPage($page, self::PER_PAGE)->get(['p.slug', 'p.title', 'p.answer_summary', 'p.published_at', 'm.thumb_path', 'm.alt'])
            ->map(fn ($p) => ['slug' => (string) $p->slug, 'title' => \App\Domains\Content\Tokens::fill((string) $p->title), 'summary' => \App\Domains\Content\Tokens::fill((string) $p->answer_summary), 'date' => substr((string) $p->published_at, 0, 10),
                'image' => $p->thumb_path ? MediaLibrary::url((string) $p->thumb_path) : null, 'alt' => (string) ($p->alt ?? '')])->values();
        $path = $cat !== null ? '/blog/category/'.$cat->slug : '/blog';
        $title = $cat !== null ? $cat->name.' · Blog' : 'Blog';
        $intro = $cat?->description ?: 'Guides and news on referral networks: how out-of-area referrals work, client consent, referral fees and RESPA rules, and getting the most from your city seat.';

        return Inertia::render('Blog', [
            'posts' => $posts, 'intro' => $intro, 'page' => $page, 'pages' => (int) max(1, ceil($total / self::PER_PAGE)), 'category' => $cat?->name,
            'categories' => DB::table('blog_categories')->orderBy('name')->get(['slug', 'name'])->values(),
            'seo' => (new SeoData(title: $title,
                description: $intro,
                path: $path, schema: [Schema::organization(), ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'url' => url($path), 'description' => $intro],
                    ...($posts->isEmpty() ? [] : [['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $posts->values()->map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => url('/blog/'.$p['slug']), 'name' => $p['title']])->all()]]),
                    Schema::breadcrumbs($cat !== null ? [['Home', '/'], ['Blog', '/blog'], [(string) $cat->name, $path]] : [['Home', '/'], ['Blog', '/blog']])]))->toArray(),
        ]);
    }

    public function show(string $slug): Response
    {
        $p = DB::table('posts as p')->leftJoin('users as u', 'u.id', '=', 'p.author_id')->leftJoin('blog_categories as c', 'c.id', '=', 'p.category_id')->leftJoin('media as m', 'm.id', '=', 'p.cover_media_id')
            ->where(['p.slug' => $slug, 'p.status' => 'published'])->first(['p.*', 'u.first_name', 'u.last_name', 'c.name as category', 'c.slug as category_slug', 'm.path as image_path', 'm.alt as image_alt']);
        abort_if($p === null, 404);
        foreach (['title', 'answer_summary', 'body'] as $k) { // live figures such as {seat_price}
            $p->{$k} = \App\Domains\Content\Tokens::fill((string) $p->{$k});
        }
        $linked = \App\Domains\Content\InternalLinks::apply((string) $p->body); // first mention of each industry links to its page
        $p->body = $linked['html'];
        $author = trim($p->first_name.' '.$p->last_name) ?: 'Team to Team Referrals';
        $seo = (array) json_decode((string) $p->seo, true);
        $image = $p->image_path ? MediaLibrary::url((string) $p->image_path) : null;
        $published = date(DATE_ATOM, (int) strtotime((string) $p->published_at));
        $modified = date(DATE_ATOM, (int) strtotime((string) $p->updated_at));

        return Inertia::render('BlogPost', [
            'post' => ['title' => (string) $p->title, 'summary' => (string) $p->answer_summary, 'body' => (string) $p->body, 'author' => $author, 'reviewer' => $p->reviewer,
                'published' => substr((string) $p->published_at, 0, 10), 'updated' => substr((string) $p->updated_at, 0, 10), 'category' => $p->category, 'categorySlug' => $p->category_slug,
                'image' => $image, 'alt' => (string) ($p->image_alt ?? '')],
            'related' => ['industries' => (array_map(fn ($t) => ['name' => $t['name'], 'url' => $t['url']], array_slice($linked['mentioned'], 0, 6))),
                'articles' => \App\Domains\Content\InternalLinks::moreArticles((int) $p->id, $p->category_id !== null ? (int) $p->category_id : null)],
            'seo' => (new SeoData(title: ($seo['title'] ?? '') !== '' ? (string) $seo['title'] : $p->title,
                description: ($seo['description'] ?? '') !== '' ? (string) $seo['description'] : (string) $p->answer_summary, path: '/blog/'.$p->slug,
                index: ! (bool) ($seo['noindex'] ?? false), image: $image, type: 'article', modifiedAt: (string) $p->updated_at,
                schema: [Schema::organization(), Schema::article((string) $p->title, '/blog/'.$p->slug, $author, $published, $modified, (string) $p->answer_summary, $p->reviewer, $image),
                    Schema::breadcrumbs([['Home', '/'], ['Blog', '/blog'], [(string) $p->title, '/blog/'.$p->slug]])]))->toArray(),
        ]);
    }
}
