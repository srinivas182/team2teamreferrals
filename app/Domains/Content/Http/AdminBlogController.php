<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Content\MediaLibrary;
use App\Domains\Content\Menus;
use App\Domains\Content\Posts;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Website: blog posts and categories, menus and the media library ("manage content"). */
final class AdminBlogController
{
    public function posts(): Response
    {
        return Inertia::render('admin/content/Posts', [
            'posts' => DB::table('posts as p')->leftJoin('blog_categories as c', 'c.id', '=', 'p.category_id')->orderByDesc('p.updated_at')
                ->get(['p.ulid', 'p.title', 'p.slug', 'p.status', 'p.publish_at', 'p.published_at', 'p.reviewer', 'c.name as category'])
                ->map(fn ($p) => [...(array) $p, 'reviewPending' => Posts::needsReviewer((string) $p->slug, $p->reviewer)])->values(),
            'categories' => DB::table('blog_categories')->orderBy('name')->get(['id', 'slug', 'name'])->values(),
            'status' => session('status'), 'seo' => SeoData::private('Blog')->toArray(),
        ]);
    }

    public function edit(?string $post = null): Response
    {
        $p = $post !== null ? DB::table('posts')->where('ulid', $post)->firstOrFail() : null;

        return Inertia::render('admin/content/PostEdit', [
            'post' => $p === null ? null : ['id' => (string) $p->ulid, 'title' => (string) $p->title, 'slug' => (string) $p->slug, 'answer_summary' => $p->answer_summary, 'body' => (string) $p->body,
                'category_id' => $p->category_id, 'author_id' => $p->author_id, 'reviewer' => $p->reviewer, 'cover_media_id' => $p->cover_media_id, 'status' => (string) $p->status,
                'publishAt' => $p->publish_at, 'seo' => json_decode((string) $p->seo, true)],
            'categories' => DB::table('blog_categories')->orderBy('name')->get(['id', 'name'])->values(),
            'authors' => User::permission('content.manage')->get()->merge(User::role(Permissions::SUPER_ADMIN)->get())->unique('id')->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'media' => $this->mediaList(),
            'status' => session('status'), 'seo' => SeoData::private($p !== null ? (string) $p->title : 'New post')->toArray(),
        ]);
    }

    public function save(Request $request, ?string $post = null): RedirectResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:160'], 'slug' => ['required', 'string', 'max:160'], 'answer_summary' => ['nullable', 'string', 'max:400'],
            'body' => ['nullable', 'string', 'max:100000'], 'category_id' => ['nullable', 'integer', 'exists:blog_categories,id'], 'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'reviewer' => ['nullable', 'string', 'max:120'], 'cover_media_id' => ['nullable', 'integer', 'exists:media,id'], 'seo' => ['array']]);
        $p = $post !== null ? DB::table('posts')->where('ulid', $post)->firstOrFail() : null;
        $ulid = Posts::save($p, ['title' => (string) $d['title'], 'slug' => (string) $d['slug'], 'answer_summary' => $d['answer_summary'] ?? null, 'body' => (string) ($d['body'] ?? ''),
            'category_id' => isset($d['category_id']) ? (int) $d['category_id'] : null, 'author_id' => isset($d['author_id']) ? (int) $d['author_id'] : null, 'reviewer' => $d['reviewer'] ?? null,
            'cover_media_id' => isset($d['cover_media_id']) ? (int) $d['cover_media_id'] : null, 'seo' => (array) ($d['seo'] ?? [])], $this->user($request));

        return redirect('/admin/content/posts/'.$ulid)->with('status', 'post-saved');
    }

    public function act(Request $request): RedirectResponse
    {
        $p = DB::table('posts')->where('ulid', (string) $request->route('post'))->firstOrFail();
        $action = (string) $request->route('action');
        match ($action) {
            'publish' => Posts::publish($p, $request->validate(['at' => ['nullable', 'date']])['at'] ?? null, $this->user($request)),
            'unpublish' => Posts::unpublish($p),
            default => abort(404),
        };

        return back()->with('status', 'post-'.$action.($action === 'publish' && $request->filled('at') ? '-scheduled' : ''));
    }

    public function category(Request $request): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300']]);
        $slug = Str::slug((string) $d['name']);
        abort_if($slug === '' || DB::table('blog_categories')->where('slug', $slug)->exists(), 422, 'That category already exists.');
        DB::table('blog_categories')->insert(['slug' => $slug, 'name' => $d['name'], 'description' => $d['description'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'category-saved');
    }

    public function menus(): Response
    {
        return Inertia::render('admin/content/Menus', ['menus' => Menus::all(), 'limits' => Menus::LOCATIONS, 'status' => session('status'), 'seo' => SeoData::private('Menus')->toArray()]);
    }

    public function saveMenu(Request $request): RedirectResponse
    {
        $d = $request->validate(['location' => ['required', 'in:header,footer'], 'items' => ['array'], 'items.*' => ['array']]);
        Menus::save((string) $d['location'], array_values((array) ($d['items'] ?? [])));

        return back()->with('status', 'menu-saved');
    }

    public function media(): Response
    {
        return Inertia::render('admin/content/Media', ['media' => $this->mediaList(), 'status' => session('status'), 'seo' => SeoData::private('Media library')->toArray()]);
    }

    public function upload(Request $request, MediaLibrary $library): RedirectResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'max:10240'], 'alt' => ['required', 'string', 'max:160']]);
        $file = $request->file('file');
        abort_unless($file instanceof \Illuminate\Http\UploadedFile, 422);
        $library->store($file, (string) $d['alt'], $this->user($request));

        return back()->with('status', 'media-uploaded');
    }

    /** @return list<array{id: int, url: string, thumb: string, alt: string, width: int, height: int}> */
    private function mediaList(): array
    {
        return array_values(DB::table('media')->orderByDesc('id')->limit(200)->get()->map(fn ($m) => ['id' => (int) $m->id, 'url' => MediaLibrary::url((string) $m->path),
            'thumb' => MediaLibrary::url((string) $m->thumb_path), 'alt' => (string) $m->alt, 'width' => (int) $m->width, 'height' => (int) $m->height])->all());
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
