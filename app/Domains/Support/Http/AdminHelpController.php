<?php

declare(strict_types=1);

namespace App\Domains\Support\Http;

use App\Domains\Seo\Events\PublicUrlsChanged;
use App\Domains\Seo\SeoData;
use App\Domains\Support\Help;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Help centre: articles (Markdown, with preview), topics, articles due for review, and what people looked for but didn't find. */
final class AdminHelpController
{
    public function index(): Response
    {
        $since = now()->subDays(30);

        return Inertia::render('admin/help/Index', [
            'articles' => DB::table('help_articles as a')->join('help_categories as c', 'c.id', '=', 'a.category_id')->orderBy('c.sort')->orderBy('a.title')
                ->get(['a.ulid', 'a.title', 'a.slug', 'a.status', 'a.audience', 'a.views', 'a.helpful_yes', 'a.helpful_no', 'a.review_due_on', 'c.name as category'])
                ->map(fn ($a) => ['id' => $a->ulid, 'title' => $a->title, 'slug' => $a->slug, 'status' => $a->status, 'audience' => Help::AUDIENCES[$a->audience] ?? $a->audience, 'category' => $a->category,
                    'views' => (int) $a->views, 'helpful' => ($a->helpful_yes + $a->helpful_no) > 0 ? (int) round($a->helpful_yes * 100 / ($a->helpful_yes + $a->helpful_no)) : null,
                    'votes' => (int) ($a->helpful_yes + $a->helpful_no), 'reviewDue' => $a->review_due_on !== null && $a->review_due_on <= now()->toDateString()])->values(),
            'categories' => DB::table('help_categories')->orderBy('sort')->get(['id', 'slug', 'name', 'description', 'sort'])->map(fn ($c) => (array) $c)->values(),
            // Searches with nothing to show: what to write next.
            'gaps' => DB::table('help_searches')->where('results', 0)->where('created_at', '>=', $since)->selectRaw('lower(query) as q, count(*) as n')->groupByRaw('lower(query)')
                ->orderByDesc('n')->limit(20)->get()->map(fn ($r) => ['query' => (string) $r->q, 'count' => (int) $r->n])->values(),
            'comments' => DB::table('help_feedback as f')->join('help_articles as a', 'a.id', '=', 'f.article_id')->where('f.helpful', false)->whereNotNull('f.comment')->orderByDesc('f.id')->limit(20)
                ->get(['a.title', 'a.ulid', 'f.comment', 'f.created_at'])->map(fn ($r) => ['article' => $r->title, 'id' => $r->ulid, 'comment' => $r->comment, 'at' => substr((string) $r->created_at, 0, 10)])->values(),
            'flash' => session('status'),
            'seo' => SeoData::private('Help centre')->toArray(),
        ]);
    }

    public function edit(Request $request, ?string $article = null): Response
    {
        $id = $request->route('article');
        $a = $id !== null ? DB::table('help_articles')->where('ulid', (string) $id)->first() : null;
        abort_if($id !== null && $a === null, 404);

        return Inertia::render('admin/help/Edit', [
            'article' => $a !== null ? ['id' => $a->ulid, 'title' => $a->title, 'slug' => $a->slug, 'summary' => $a->summary, 'body' => $a->body_md, 'category_id' => (int) $a->category_id,
                'audience' => $a->audience, 'howto' => (bool) $a->howto, 'status' => $a->status, 'review_due_on' => $a->review_due_on] : null,
            'prefill' => $a === null ? ['title' => (string) $request->query('title', '')] : null,
            'categories' => DB::table('help_categories')->orderBy('sort')->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->values(),
            'audiences' => Help::AUDIENCES,
            'flash' => session('status'),
            'seo' => SeoData::private($a->title ?? 'New article')->toArray(),
        ]);
    }

    public function save(Request $request, ?string $article = null): RedirectResponse
    {
        $id = $request->route('article');
        $a = $id !== null ? DB::table('help_articles')->where('ulid', (string) $id)->first() : null;
        abort_if($id !== null && $a === null, 404);
        $d = $request->validate([
            'title' => ['required', 'string', 'max:160'], 'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('help_articles', 'slug')->ignore($a?->id)],
            'summary' => ['required', 'string', 'min:20', 'max:400'], 'body' => ['required', 'string', 'max:25000'], 'category_id' => ['required', 'integer', 'exists:help_categories,id'],
            'audience' => ['required', Rule::in(array_keys(Help::AUDIENCES))], 'howto' => ['boolean'], 'status' => ['required', 'in:draft,published'], 'review_due_on' => ['nullable', 'date'],
        ], ['slug.regex' => 'Use lowercase words and hyphens, like "how-to-claim-a-seat".', 'summary.min' => 'Write the short answer first (at least a sentence).']);
        $row = ['title' => trim($d['title']), 'slug' => $d['slug'], 'summary' => trim($d['summary']), 'body_md' => $d['body'], 'body_html' => Help::render($d['body']), 'category_id' => (int) $d['category_id'],
            'audience' => $d['audience'], 'howto' => (bool) ($d['howto'] ?? false), 'status' => $d['status'], 'review_due_on' => $d['review_due_on'] ?? now()->addMonths(6)->toDateString(), 'updated_at' => now()];
        if ($d['status'] === 'published' && ($a === null || $a->published_at === null)) {
            $row['published_at'] = now();
        }
        if ($a === null) {
            $ulid = strtolower((string) Str::ulid());
            DB::table('help_articles')->insert([...$row, 'ulid' => $ulid, 'author_id' => $request->user()?->id, 'created_at' => now()]);
        } else {
            $ulid = (string) $a->ulid;
            DB::table('help_articles')->where('id', $a->id)->update($row);
            if ($a->status === 'published' && $a->slug !== $d['slug']) {
                \App\Domains\Content\Pages::redirect('/help/'.$a->slug, '/help/'.$d['slug'], 301, true, $request->user() instanceof \App\Domains\Identity\Models\User ? $request->user() : null);
            }
        }
        activity('content')->causedBy($request->user())->event('help-article-saved')->log("Saved help article “{$row['title']}” ({$d['status']})");
        PublicUrlsChanged::dispatch(array_values(array_unique(['/help', '/help/'.$d['slug'], ...($a !== null ? ['/help/'.$a->slug] : [])])));

        return redirect('/admin/help/articles/'.$ulid)->with('status', 'help-article-saved');
    }

    public function destroy(Request $request, string $article): RedirectResponse
    {
        $a = DB::table('help_articles')->where('ulid', (string) $request->route('article'))->first();
        abort_if($a === null, 404);
        DB::table('help_articles')->where('id', $a->id)->delete();
        activity('content')->causedBy($request->user())->event('help-article-deleted')->log("Deleted help article “{$a->title}”");
        PublicUrlsChanged::dispatch(['/help', '/help/'.$a->slug]);

        return redirect('/admin/help')->with('status', 'help-article-deleted');
    }

    /** The article as it will look, for the editor's preview. */
    public function preview(Request $request): JsonResponse
    {
        return response()->json(['html' => Help::render((string) $request->validate(['body' => ['required', 'string', 'max:25000']])['body'])]);
    }

    public function saveCategory(Request $request, ?int $category = null): RedirectResponse
    {
        $id = $request->route('category') !== null ? (int) $request->route('category') : null;
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300'], 'sort' => ['nullable', 'integer', 'between:0,999'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('help_categories', 'slug')->ignore($id)]]);
        $row = ['name' => $d['name'], 'description' => $d['description'] ?? null, 'sort' => (int) ($d['sort'] ?? 0), 'slug' => $d['slug'], 'updated_at' => now()];
        if ($id !== null) {
            DB::table('help_categories')->where('id', $id)->update($row);
        } else {
            DB::table('help_categories')->insert([...$row, 'created_at' => now()]);
        }

        return back()->with('status', 'help-category-saved');
    }
}
