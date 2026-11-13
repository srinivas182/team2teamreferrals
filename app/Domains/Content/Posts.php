<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Blog posts: drafts, scheduled publishing, an answer summary and a body before publishing. */
final class Posts
{
    /** @param array{title: string, slug: string, answer_summary: ?string, body: string, category_id: ?int, author_id: ?int, reviewer: ?string, cover_media_id: ?int, seo: array<string, mixed>} $d */
    public static function save(?stdClass $post, array $d, ?User $by): string
    {
        $slug = strtolower(trim($d['slug'], ' /'));
        if (! preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $slug)) {
            throw ValidationException::withMessages(['slug' => 'Use lowercase words and hyphens, like "how-referral-fees-work".']);
        }
        if (DB::table('posts')->where('slug', $slug)->when($post, fn ($q, $p) => $q->where('id', '!=', $p->id))->exists()) {
            throw ValidationException::withMessages(['slug' => 'Another post already uses that address.']);
        }
        $row = ['slug' => $slug, 'title' => mb_substr(trim($d['title']), 0, 160), 'answer_summary' => $d['answer_summary'] !== null ? mb_substr(trim($d['answer_summary']), 0, 400) : null,
            'body' => Blocks::purify($d['body']), 'category_id' => $d['category_id'], 'author_id' => $d['author_id'] ?? $by?->id, 'reviewer' => $d['reviewer'] !== null ? mb_substr(trim($d['reviewer']), 0, 120) : null,
            'cover_media_id' => $d['cover_media_id'], 'updated_at' => now(),
            'seo' => json_encode(['title' => mb_substr(trim((string) ($d['seo']['title'] ?? '')), 0, 70), 'description' => mb_substr(trim((string) ($d['seo']['description'] ?? '')), 0, 170), 'noindex' => (bool) ($d['seo']['noindex'] ?? false)])];
        if ($post === null) {
            $ulid = (string) Str::ulid();
            DB::table('posts')->insert([...$row, 'ulid' => $ulid, 'status' => 'draft', 'created_at' => now()]);

            return $ulid;
        }
        DB::table('posts')->where('id', $post->id)->update($row);

        return (string) $post->ulid;
    }

    /** Starter posts make legal and industry claims: they need a named reviewer before they can go live. */
    public static function needsReviewer(string $slug, ?string $reviewer): bool
    {
        return trim((string) $reviewer) === '' && in_array($slug, array_column(Pack\StarterPosts::all(), 'slug'), true);
    }

    public static function publish(stdClass $post, ?string $at, User $by): void
    {
        if (self::needsReviewer((string) $post->slug, $post->reviewer)) {
            throw ValidationException::withMessages(['reviewer' => 'Review pending: this post makes legal and industry claims. Name the expert who checked the facts before publishing.']);
        }
        $len = mb_strlen(trim((string) $post->answer_summary));
        if ($len < Pages::SUMMARY_MIN || $len > Pages::SUMMARY_MAX) {
            throw ValidationException::withMessages(['answer_summary' => 'Before publishing, add an answer summary of '.Pages::SUMMARY_MIN.' to '.Pages::SUMMARY_MAX.' characters: the one or two sentences answer engines quote.']);
        }
        if (mb_strlen(trim(strip_tags((string) $post->body))) < 100) {
            throw ValidationException::withMessages(['body' => 'Write the post (at least 100 characters) before publishing.']);
        }
        if ($at !== null && strtotime($at) > time()) {
            DB::table('posts')->where('id', $post->id)->update(['status' => 'scheduled', 'publish_at' => date('Y-m-d H:i:s', (int) strtotime($at)), 'updated_at' => now()]);

            return;
        }
        DB::table('posts')->where('id', $post->id)->update(['status' => 'published', 'publish_at' => null, 'published_at' => $post->published_at ?? now(), 'updated_at' => now()]);
        activity('content')->causedBy($by)->event('post-published')->withProperties(['post' => $post->ulid])->log('Post published');
    }

    public static function publishDue(): int
    {
        return DB::table('posts')->where('status', 'scheduled')->where('publish_at', '<=', now())
            ->update(['status' => 'published', 'published_at' => DB::raw('COALESCE(published_at, publish_at)'), 'publish_at' => null, 'updated_at' => now()]);
    }
}
