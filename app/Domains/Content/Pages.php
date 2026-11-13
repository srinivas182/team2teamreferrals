<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Website pages: every save is a new version; publishing needs an answer summary; publish now or on a schedule; roll
 * back to any earlier version. A page can never take an address the app uses, and when a published page's address
 * changes, the old one redirects to the new (with loop detection).
 */
final class Pages
{
    /** Addresses the app needs, on top of every first segment its routes already use. */
    public const RESERVED = ['app', 'admin', 'api', 't', 'r', 'c', 'q', 'join', 'reserve', 'login', 'logout', 'register', 'password', 'forgot-password', 'reset-password',
        'email', 'two-factor', 'passkeys', 'webhooks', 'storage', 'files', 'preview', 'sitemap.xml', 'sitemaps', 'robots.txt', 'llms.txt', 'health', 'status', 'up', 'build', 'blog'];

    public const SUMMARY_MIN = 40;

    public const SUMMARY_MAX = 320;

    /** @return list<string> */
    public static function reserved(): array
    {
        $used = [];
        foreach (Route::getRoutes()->getRoutes() as $r) {
            // Only addresses the app shows (GET): a page and a form can share one, like /contact (page) and its POST.
            if (! in_array('GET', $r->methods(), true) || $r->getName() === 'content.page') {
                continue;
            }
            $first = explode('/', $r->uri())[0];
            if ($first !== '' && ! str_contains($first, '{')) {
                $used[] = $first;
            }
        }

        return array_values(array_unique([...self::RESERVED, ...$used]));
    }

    public static function normaliseSlug(string $slug): string
    {
        $slug = strtolower(trim($slug, " /\t\n"));
        if ($slug === '' || ! preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*){0,2}$#', $slug)) {
            throw ValidationException::withMessages(['slug' => 'Use lowercase words and hyphens, like "how-it-works" (up to three parts, like "guides/solar").']);
        }
        if (in_array(explode('/', $slug)[0], self::reserved(), true)) {
            throw ValidationException::withMessages(['slug' => 'That address is used by the app. Choose another.']);
        }

        return $slug;
    }

    /**
     * Save a draft: a new version of the page (or a new page).
     *
     * @param  array{title: string, slug: string, template: string, answer_summary: ?string, seo: array<string, mixed>, blocks: list<array<string, mixed>>}  $d
     */
    public static function save(?stdClass $page, array $d, ?User $by): string
    {
        $slug = self::normaliseSlug($d['slug']);
        if (DB::table('cms_pages')->where('slug', $slug)->when($page, fn ($q, $p) => $q->where('id', '!=', $p->id))->exists()) {
            throw ValidationException::withMessages(['slug' => 'Another page already uses that address.']);
        }
        $blocks = [];
        foreach ($d['blocks'] as $n => $b) {
            $blocks[] = Blocks::clean((array) $b, $n);
        }
        $seo = ['title' => mb_substr(trim((string) ($d['seo']['title'] ?? '')), 0, 70), 'description' => mb_substr(trim((string) ($d['seo']['description'] ?? '')), 0, 170),
            'image' => mb_substr(trim((string) ($d['seo']['image'] ?? '')), 0, 300), 'noindex' => (bool) ($d['seo']['noindex'] ?? false)];

        return DB::transaction(function () use ($page, $d, $slug, $blocks, $seo, $by) {
            if ($page === null) {
                $id = DB::table('cms_pages')->insertGetId(['ulid' => (string) Str::ulid(), 'slug' => $slug, 'template' => in_array($d['template'], ['page', 'landing', 'legal'], true) ? $d['template'] : 'page',
                    'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $id = (int) $page->id;
                DB::table('cms_pages')->where('id', $id)->update(['slug' => $slug, 'template' => in_array($d['template'], ['page', 'landing', 'legal'], true) ? $d['template'] : 'page', 'updated_at' => now()]);
            }
            $number = (int) DB::table('cms_page_versions')->where('page_id', $id)->max('number') + 1;
            $vid = DB::table('cms_page_versions')->insertGetId(['page_id' => $id, 'number' => $number, 'title' => mb_substr(trim($d['title']), 0, 160),
                'answer_summary' => $d['answer_summary'] !== null ? mb_substr(trim($d['answer_summary']), 0, 400) : null, 'seo' => json_encode($seo), 'blocks' => json_encode($blocks),
                'created_by' => $by?->id, 'created_at' => now()]);
            DB::table('cms_pages')->where('id', $id)->update(['draft_version_id' => $vid]);

            return (string) DB::table('cms_pages')->where('id', $id)->value('ulid');
        });
    }

    /** Publish the draft now, or on a schedule. */
    public static function publish(stdClass $page, ?string $at, ?User $by): void
    {
        $v = DB::table('cms_page_versions')->where('id', $page->draft_version_id)->first();
        abort_if($v === null, 422, 'Save the page first.');
        self::checkPublishable($v);
        if ($at !== null && strtotime($at) > time()) {
            DB::table('cms_pages')->where('id', $page->id)->update(['status' => 'scheduled', 'publish_at' => date('Y-m-d H:i:s', (int) strtotime($at)), 'updated_at' => now()]);
            activity('content')->causedBy($by)->event('page-scheduled')->withProperties(['page' => $page->ulid, 'at' => $at])->log('Page scheduled');

            return;
        }
        self::goLive($page, (int) $v->id, $by);
    }

    /** Put an earlier version live again (it also becomes the draft). */
    public static function rollback(stdClass $page, int $versionId, User $by): void
    {
        $v = DB::table('cms_page_versions')->where(['id' => $versionId, 'page_id' => $page->id])->first();
        abort_if($v === null, 404);
        self::checkPublishable($v);
        DB::table('cms_pages')->where('id', $page->id)->update(['draft_version_id' => $v->id]);
        self::goLive($page, (int) $v->id, $by);
    }

    public static function unpublish(stdClass $page, User $by): void
    {
        DB::table('cms_pages')->where('id', $page->id)->update(['status' => 'unpublished', 'publish_at' => null, 'updated_at' => now()]);
        Menus::forget();
        activity('content')->causedBy($by)->event('page-unpublished')->withProperties(['page' => $page->ulid])->log('Page unpublished');
    }

    /** The core pages, created once as drafts for the content pack (Sprint 38) to fill. Returns how many were added. */
    public static function createCore(User $by): int
    {
        $core = [
            ['how-it-works', 'How it works', 'page', [['type' => 'steps', 'heading' => 'How it works', 'items' => []]]],
            ['pricing', 'Pricing', 'landing', [['type' => 'pricing', 'heading' => 'Pricing', 'plans' => []]]],
            ['about', 'About Team to Team Referrals', 'page', [['type' => 'rich_text', 'html' => '']]],
            ['faq', 'Questions and answers', 'page', [['type' => 'faq', 'heading' => 'Questions and answers', 'items' => []]]],
            ['contact', 'Contact us', 'page', [['type' => 'contact_form', 'heading' => 'Send us a message', 'intro' => 'We answer within one business day.']]],
            ['terms', 'Terms of service', 'legal', [['type' => 'rich_text', 'html' => '']]],
            ['privacy', 'Privacy policy', 'legal', [['type' => 'rich_text', 'html' => '']]],
        ];
        $n = 0;
        foreach ($core as [$slug, $title, $template, $blocks]) {
            if (! DB::table('cms_pages')->where('slug', $slug)->exists()) {
                self::save(null, ['title' => $title, 'slug' => $slug, 'template' => $template, 'answer_summary' => null, 'seo' => [], 'blocks' => $blocks], $by);
                $n++;
            }
        }

        return $n;
    }

    /** Every minute: publish pages whose time has come. */
    public static function publishDue(): int
    {
        $n = 0;
        foreach (DB::table('cms_pages')->where('status', 'scheduled')->where('publish_at', '<=', now())->get() as $p) {
            self::goLive($p, (int) $p->draft_version_id, null);
            $n++;
        }

        return $n;
    }

    /** Add a redirect, refusing chains that come back to where they started. */
    public static function redirect(string $from, string $to, int $code = 301, bool $automatic = false, ?User $by = null): void
    {
        $from = '/'.trim($from, '/');
        $to = str_starts_with($to, 'https://') ? $to : '/'.trim($to, '/');
        if ($from === $to) {
            throw ValidationException::withMessages(['to' => 'A redirect can’t point to itself.']);
        }
        // Follow the chain from the destination: it must never lead back to the source.
        $seen = [$from];
        $next = $to;
        for ($i = 0; $i < 20 && ! str_starts_with($next, 'https://'); $i++) {
            if (in_array($next, $seen, true)) {
                throw ValidationException::withMessages(['to' => 'That would create a redirect loop.']);
            }
            $seen[] = $next;
            $hop = DB::table('redirects')->where('from_path', $next)->value('to_path');
            if ($hop === null) {
                break;
            }
            $next = (string) $hop;
        }
        Menus::forget();
        DB::table('redirects')->updateOrInsert(['from_path' => $from], ['to_path' => $to, 'code' => in_array($code, [301, 302], true) ? $code : 301, 'automatic' => $automatic,
            'created_by' => $by?->id, 'updated_at' => now(), 'created_at' => now()]);
    }

    private static function goLive(stdClass $page, int $versionId, ?User $by): void
    {
        $fresh = DB::table('cms_pages')->where('id', $page->id)->first();
        if ($fresh === null) {
            return;
        }
        DB::transaction(function () use ($fresh, $versionId) {
            // The address changed since it was last live: send the old address to the new one.
            if ($fresh->published_slug !== null && $fresh->published_slug !== $fresh->slug) {
                DB::table('redirects')->where('from_path', '/'.$fresh->slug)->delete(); // the new address is a real page again
                self::redirect('/'.$fresh->published_slug, '/'.$fresh->slug, 301, true);
            }
            DB::table('cms_pages')->where('id', $fresh->id)->update(['status' => 'published', 'published_version_id' => $versionId, 'published_slug' => $fresh->slug,
                'published_at' => now(), 'publish_at' => null, 'updated_at' => now()]);
        });
        Menus::forget(); // a newly live page may complete a menu link
        activity('content')->causedBy($by)->event('page-published')->withProperties(['page' => $fresh->ulid, 'version' => $versionId])->log('Page published');
    }

    private static function checkPublishable(stdClass $v): void
    {
        $len = mb_strlen(trim((string) $v->answer_summary));
        if ($len < self::SUMMARY_MIN || $len > self::SUMMARY_MAX) {
            throw ValidationException::withMessages(['answer_summary' => 'Before publishing, add an answer summary of '.self::SUMMARY_MIN.' to '.self::SUMMARY_MAX.' characters: the one or two sentences answer engines quote (who, what, where, cost or how).']);
        }
        if (json_decode((string) $v->blocks, true) === []) {
            throw ValidationException::withMessages(['blocks' => 'Add at least one block before publishing.']);
        }
    }
}
