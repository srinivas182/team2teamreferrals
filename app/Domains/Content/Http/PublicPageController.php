<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Content\Blocks;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Website pages, served only when no app route matches (so a page can never shadow the app). Redirects are applied
 * first; then the published page, answer summary first, with its structured data.
 */
final class PublicPageController
{
    public function fallback(Request $request): Response|RedirectResponse
    {
        $path = '/'.trim($request->path(), '/');
        $r = DB::table('redirects')->where('from_path', $path)->first();
        if ($r !== null) {
            DB::table('redirects')->where('id', $r->id)->increment('hits');

            return redirect((string) $r->to_path, (int) $r->code);
        }
        $page = DB::table('cms_pages')->where(['slug' => trim($path, '/'), 'status' => 'published'])->first();
        abort_if($page === null || $request->method() !== 'GET', 404);
        $v = DB::table('cms_page_versions')->where('id', $page->published_version_id)->firstOrFail();

        return $this->render($page, $v, false);
    }

    /** A signed preview of any version, for content editors; never indexed. */
    public function preview(Request $request, string $page): Response
    {
        abort_unless($request->hasValidRelativeSignature() && (bool) $request->user()?->can('content.manage'), 403); // links are signed as relative addresses
        $p = DB::table('cms_pages')->where('ulid', $page)->firstOrFail();
        $v = DB::table('cms_page_versions')->where(['page_id' => $p->id, 'id' => (int) $request->query('v', (string) $p->draft_version_id)])->firstOrFail();

        return $this->render($p, $v, true);
    }

    private function render(stdClass $page, stdClass $v, bool $preview): Response
    {
        $blocks = (array) json_decode((string) $v->blocks, true);
        $seo = (array) json_decode((string) $v->seo, true);
        $url = rtrim((string) config('seo.canonical_url', config('app.url')), '/').'/'.$page->slug;
        $summary = (string) ($v->answer_summary ?? '');
        $modified = (string) ($page->published_at ?? $v->created_at);
        $schema = [Schema::organization(),
            ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $v->title, 'description' => $summary, 'url' => $url, 'dateModified' => date(DATE_ATOM, (int) strtotime($modified))],
            Schema::breadcrumbs([['Home', '/'], [(string) $v->title, '/'.$page->slug]]), // [name, address] pairs
            ...Blocks::schema(array_values($blocks), (string) $v->title, $url)];
        $data = $preview ? SeoData::private('Preview: '.$v->title) : new SeoData(
            title: (string) (($seo['title'] ?? '') !== '' ? $seo['title'] : $v->title.' · Team to Team Referrals'),
            description: (string) (($seo['description'] ?? '') !== '' ? $seo['description'] : $summary),
            path: '/'.$page->slug, index: ! (bool) ($seo['noindex'] ?? false), image: ($seo['image'] ?? '') !== '' ? (string) $seo['image'] : null,
            schema: $schema, modifiedAt: $modified);

        return Inertia::render('CmsPage', [
            'page' => ['title' => (string) $v->title, 'summary' => $summary, 'template' => (string) $page->template, 'updated' => substr($modified, 0, 10), 'blocks' => array_values($blocks)],
            'preview' => $preview ? ['version' => (int) $v->number] : null,
            'seo' => $data->toArray(),
        ]);
    }
}
