<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Content\Blocks;
use App\Domains\Content\Pages;
use App\Domains\Identity\Models\User;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Website: pages built from blocks, with versions, preview, publishing and redirects ("manage content"). */
final class AdminContentController
{
    public function index(): Response
    {
        $industries = DB::table('industries')->where('status', 'active')->orderBy('name')->get(['slug', 'name'])->values();
        $pages = DB::table('cms_pages as p')->leftJoin('cms_page_versions as v', 'v.id', '=', 'p.draft_version_id')->orderBy('p.slug')
            ->get(['p.ulid', 'p.slug', 'p.status', 'p.publish_at', 'p.published_at', 'p.template', 'v.title'])
            ->map(fn ($p) => ['id' => (string) $p->ulid, 'title' => (string) $p->title, 'slug' => (string) $p->slug, 'status' => (string) $p->status, 'template' => (string) $p->template,
                'publishAt' => $p->publish_at, 'publishedAt' => $p->published_at])->values();

        return Inertia::render('admin/content/Index', [
            'pages' => $pages,
            'industries' => $industries,
            'redirects' => DB::table('redirects')->orderBy('from_path')->get(['id', 'from_path', 'to_path', 'code', 'hits', 'automatic'])->values(),
            'status' => session('status'),
            'seo' => SeoData::private('Website')->toArray(),
        ]);
    }

    public function edit(Request $request, ?string $page = null): Response
    {
        $p = $page !== null ? DB::table('cms_pages')->where('ulid', $page)->firstOrFail() : null;
        $v = $p !== null ? DB::table('cms_page_versions')->where('id', $p->draft_version_id)->first() : null;

        return Inertia::render('admin/content/Edit', [
            'page' => $p === null ? null : ['id' => (string) $p->ulid, 'slug' => (string) $p->slug, 'template' => (string) $p->template, 'status' => (string) $p->status, 'publishAt' => $p->publish_at,
                'publishedVersion' => $p->published_version_id, 'draftVersion' => $p->draft_version_id,
                'preview' => URL::temporarySignedRoute('content.preview', now()->addHour(), ['page' => $p->ulid], false)],
            'draft' => $v === null ? null : ['title' => (string) $v->title, 'answer_summary' => $v->answer_summary, 'seo' => json_decode((string) $v->seo, true), 'blocks' => json_decode((string) $v->blocks, true)],
            'versions' => $p === null ? [] : DB::table('cms_page_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.created_by')->where('v.page_id', $p->id)->orderByDesc('v.number')->limit(30)
                ->get(['v.id', 'v.number', 'v.title', 'v.created_at', 'u.first_name', 'u.last_name'])
                ->map(fn ($x) => ['id' => (int) $x->id, 'number' => (int) $x->number, 'title' => (string) $x->title, 'at' => substr((string) $x->created_at, 0, 16), 'by' => trim($x->first_name.' '.$x->last_name),
                    'preview' => URL::temporarySignedRoute('content.preview', now()->addHour(), ['page' => $p->ulid, 'v' => $x->id], false)])->values(),
            'blockTypes' => Blocks::TYPES, 'summary' => ['min' => Pages::SUMMARY_MIN, 'max' => Pages::SUMMARY_MAX],
            'sections' => collect(\App\Domains\Content\Sections::all())->map(fn ($x, $k) => ['key' => $k, 'name' => $x['name'], 'group' => $x['group'], 'blocks' => $x['blocks']])->values(),
            'approved' => DB::table('testimonials')->where('status', 'approved')->orderByDesc('id')->get(['id', 'quote', 'author_name'])->map(fn ($t) => ['id' => (int) $t->id, 'label' => $t->author_name.': '.mb_strimwidth((string) $t->quote, 0, 60, '…')])->values(),
            'media' => DB::table('media')->orderByDesc('id')->limit(200)->get(['path', 'alt'])->map(fn ($m) => ['url' => \App\Domains\Content\MediaLibrary::url((string) $m->path), 'alt' => (string) $m->alt])->values(),
            'status' => session('status'),
            'seo' => SeoData::private($v !== null ? (string) $v->title : 'New page')->toArray(),
        ]);
    }

    public function save(Request $request, ?string $page = null): RedirectResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:160'], 'slug' => ['required', 'string', 'max:160'], 'template' => ['required', 'in:page,landing,legal'],
            'answer_summary' => ['nullable', 'string', 'max:400'], 'seo' => ['array'], 'blocks' => ['array', 'max:40'], 'blocks.*' => ['array']]);
        $p = $page !== null ? DB::table('cms_pages')->where('ulid', $page)->firstOrFail() : null;
        $ulid = Pages::save($p, ['title' => (string) $d['title'], 'slug' => (string) $d['slug'], 'template' => (string) $d['template'], 'answer_summary' => $d['answer_summary'] ?? null,
            'seo' => (array) ($d['seo'] ?? []), 'blocks' => array_values((array) ($d['blocks'] ?? []))], $this->user($request));

        return redirect('/admin/content/'.$ulid)->with('status', 'page-saved');
    }

    public function act(Request $request, string $page, string $action): RedirectResponse
    {
        $p = DB::table('cms_pages')->where('ulid', $page)->firstOrFail();
        $user = $this->user($request);
        match ($action) {
            'publish' => Pages::publish($p, $request->validate(['at' => ['nullable', 'date']])['at'] ?? null, $user),
            'rollback' => Pages::rollback($p, (int) $request->validate(['version' => ['required', 'integer']])['version'], $user),
            'unpublish' => Pages::unpublish($p, $user),
            default => abort(404),
        };

        return back()->with('status', 'page-'.$action.($action === 'publish' && $request->filled('at') ? '-scheduled' : ''));
    }

    public function industryPages(Request $request): RedirectResponse
    {
        $slug = (string) $request->validate(['industry' => ['required', 'string', 'exists:industries,slug']])['industry'];
        $n = \App\Domains\Content\ContentPack::industryPages(\App\Domains\Network\Industries\Models\Industry::query()->where('slug', $slug)->firstOrFail(), $this->user($request));

        return back()->with('status', $n > 0 ? 'industry-pages-created' : 'industry-pages-exist');
    }

    public function core(Request $request): RedirectResponse
    {
        $n = Pages::createCore($this->user($request));

        return back()->with('status', $n > 0 ? 'core-pages-created' : 'core-pages-exist');
    }

    public function redirectStore(Request $request): RedirectResponse
    {
        $d = $request->validate(['from' => ['required', 'string', 'max:200', 'regex:#^/[A-Za-z0-9/_.-]*$#'], 'to' => ['required', 'string', 'max:300'], 'code' => ['required', 'in:301,302']]);
        if (in_array(explode('/', trim($d['from'], '/'))[0], Pages::reserved(), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['from' => 'That address is used by the app and can’t be redirected.']);
        }
        Pages::redirect((string) $d['from'], (string) $d['to'], (int) $d['code'], false, $this->user($request));

        return back()->with('status', 'redirect-saved');
    }

    public function redirectDelete(int $redirect): RedirectResponse
    {
        DB::table('redirects')->where('id', $redirect)->delete();
        \App\Domains\Content\Menus::forget();

        return back()->with('status', 'redirect-deleted');
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
