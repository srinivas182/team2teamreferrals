<?php

declare(strict_types=1);

namespace App\Domains\Content\Industry;

use App\Domains\Content\Pages;
use App\Domains\Content\Tokens;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;
use App\Domains\Seo\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Industry hubs: one reviewed content record per industry, at /industries/{slug}. Content is written once (drafted,
 * then checked by a named reviewer) and every page that talks about the industry draws on it: the hub, its facts sheet
 * (quotable statements for answer engines), the state and city summaries, llms.txt and the structured data. Nothing is
 * public until a reviewer is named and the content passes the publishing checks below. The tier disclosure is never
 * typed into the copy: it comes from the industry's tier, so a tier change updates every page at once.
 */
final class IndustryContent
{
    public const FAQ_MIN = 8;

    public const FAQ_MAX = 10;

    public const HIRE_MIN = 5;

    /** The parts of an industry's content, with what each holds. */
    public const PARTS = [
        'summary' => 'Answer-first opening: who, what and how, in two or three sentences',
        'intro' => 'Hub introduction (plain paragraphs)',
        'how' => 'How the network works, in the industry’s own words',
        'request' => 'The “Request a pro” call to action',
        'state_summary' => 'State page opening ({state})',
        'city_summary' => 'City page opening ({city}, {state})',
        'facts' => 'Facts sheet: covers, cost, licensing, fees',
        'cost_note' => 'How the cost figure was found (and its limits)',
        'lookups' => 'Official license lookups (name, address)',
        'hire_questions' => 'Questions to ask before you hire',
        'faqs' => 'Questions and answers',
        'glossary' => 'Glossary (term, definition)',
        'related' => 'Related industries (slugs)',
        'sources' => 'Sources (title, address)',
        'checked' => 'Date the facts were checked',
    ];

    /** @return array<string, mixed> */
    public static function blank(): array
    {
        return ['summary' => '', 'intro' => '', 'how' => '', 'request' => '', 'state_summary' => '', 'city_summary' => '',
            'facts' => ['covers' => '', 'cost' => '', 'licensing' => '', 'fees' => ''], 'cost_note' => '', 'lookups' => [], 'hire_questions' => [], 'faqs' => [],
            'glossary' => [], 'related' => [], 'sources' => [], 'checked' => ''];
    }

    public static function record(Industry $industry): ?stdClass
    {
        return DB::table('industry_content')->where('industry_id', $industry->id)->first();
    }

    /** @return array<string, mixed> the stored content, with any missing part filled with its blank */
    public static function content(Industry $industry): array
    {
        $r = self::record($industry);

        return self::normalise($r !== null ? (array) json_decode((string) $r->content, true) : []);
    }

    /**
     * Clean and shape content: plain text only (no markup), https addresses only, lists trimmed of empty rows.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function normalise(array $in): array
    {
        $txt = fn ($v, int $max = 4000) => mb_substr(trim(strip_tags((string) (is_scalar($v) ? $v : ''))), 0, $max);
        $url = fn ($v) => preg_match('#^https://[^\s<>"]+$#', trim((string) $v)) ? trim((string) $v) : '';
        $rows = function ($v, array $keys, int $max = 30) use ($txt, $url): array {
            $out = [];
            foreach (is_array($v) ? $v : [] as $row) {
                $r = [];
                foreach ($keys as $k) {
                    $r[$k] = $k === 'url' ? $url(is_array($row) ? ($row[$k] ?? '') : '') : $txt(is_array($row) ? ($row[$k] ?? '') : '', 1500);
                }
                if (implode('', $r) !== '') {
                    $out[] = $r;
                }
            }

            return array_slice($out, 0, $max);
        };
        $b = self::blank();
        $facts = is_array($in['facts'] ?? null) ? $in['facts'] : [];

        return [
            ...array_map(fn ($k) => $txt($in[$k] ?? ''), array_combine($k = ['summary', 'intro', 'how', 'request', 'state_summary', 'city_summary', 'cost_note', 'checked'], $k)),
            'facts' => array_map(fn ($k) => $txt($facts[$k] ?? '', 1500), array_combine(array_keys($b['facts']), array_keys($b['facts']))),
            'lookups' => $rows($in['lookups'] ?? [], ['name', 'url'], 10),
            'hire_questions' => array_values(array_filter(array_map(fn ($q) => $txt($q, 300), is_array($in['hire_questions'] ?? null) ? $in['hire_questions'] : []), fn ($q) => $q !== '')),
            'faqs' => $rows($in['faqs'] ?? [], ['q', 'a'], 12),
            'glossary' => $rows($in['glossary'] ?? [], ['term', 'definition'], 20),
            'related' => array_values(array_unique(array_filter(array_map(fn ($s) => strtolower(trim((string) $s)), is_array($in['related'] ?? null) ? $in['related'] : []), fn ($s) => preg_match('/^[a-z0-9-]+$/', $s) === 1))),
            'sources' => $rows($in['sources'] ?? [], ['title', 'url'], 12),
        ];
    }

    /**
     * Save a draft (or update published content, which stays live: edits to a published hub are re-checked on save).
     *
     * @param  array<string, mixed>  $content
     */
    public static function save(Industry $industry, array $content, ?string $reviewer, ?User $by): void
    {
        $c = self::normalise($content);
        $r = self::record($industry);
        $reviewer = $reviewer !== null && trim($reviewer) !== '' ? mb_substr(trim(strip_tags($reviewer)), 0, 120) : null;
        if ($r !== null && $r->status === 'published') {
            self::assertPublishable($industry, $c, $reviewer); // a live hub never falls below the bar
        }
        $row = ['content' => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'reviewer' => $reviewer, 'updated_by' => $by?->id, 'updated_at' => now()];
        if ($r === null) {
            DB::table('industry_content')->insert([...$row, 'industry_id' => $industry->id, 'status' => 'draft', 'created_at' => now()]);
        } else {
            DB::table('industry_content')->where('id', $r->id)->update($row);
        }
        if ($by !== null) {
            activity('content')->causedBy($by)->performedOn($industry)->event('industry-content-saved')->log('Industry content saved');
        }
    }

    public static function publish(Industry $industry, User $by): void
    {
        $r = self::record($industry) ?? throw ValidationException::withMessages(['content' => 'Write the content before publishing.']);
        $c = self::normalise((array) json_decode((string) $r->content, true));
        self::assertPublishable($industry, $c, $r->reviewer);
        DB::table('industry_content')->where('id', $r->id)->update(['status' => 'published', 'reviewed_on' => now()->toDateString(), 'published_at' => $r->published_at ?? now(), 'updated_at' => now()]);
        activity('content')->causedBy($by)->performedOn($industry)->event('industry-content-published')->withProperties(['reviewer' => $r->reviewer])->log('Industry hub published');
    }

    public static function unpublish(Industry $industry, User $by): void
    {
        DB::table('industry_content')->where('industry_id', $industry->id)->update(['status' => 'draft', 'updated_at' => now()]);
        activity('content')->causedBy($by)->performedOn($industry)->event('industry-content-unpublished')->log('Industry hub unpublished');
    }

    /**
     * What a hub needs before it goes live. Returns the problems (empty when ready), so the editor can show a checklist.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, string>
     */
    public static function problems(Industry $industry, array $c, ?string $reviewer): array
    {
        $p = [];
        $len = mb_strlen($c['summary']);
        if ($len < Pages::SUMMARY_MIN || $len > Pages::SUMMARY_MAX) {
            $p['summary'] = 'Write an answer-first summary of '.Pages::SUMMARY_MIN.' to '.Pages::SUMMARY_MAX.' characters.';
        }
        foreach (['intro' => 'Write the introduction.', 'how' => 'Explain how the network works.', 'request' => 'Write the “Request a pro” wording.', 'state_summary' => 'Write the state page opening.', 'city_summary' => 'Write the city page opening.'] as $k => $msg) {
            if (mb_strlen($c[$k]) < 40) {
                $p[$k] = $msg;
            }
        }
        if (! str_contains($c['state_summary'], '{state}')) {
            $p['state_summary'] ??= 'The state page opening must name the state ({state}).';
        }
        if (! str_contains($c['city_summary'], '{city}')) {
            $p['city_summary'] ??= 'The city page opening must name the city ({city}).';
        }
        foreach (['covers', 'licensing', 'fees'] as $k) {
            if (mb_strlen($c['facts'][$k]) < 20) {
                $p['facts.'.$k] = 'Complete the facts sheet: '.$k.'.';
            }
        }
        $n = count($c['faqs']);
        if ($n < self::FAQ_MIN || $n > self::FAQ_MAX) {
            $p['faqs'] = 'Write '.self::FAQ_MIN.' to '.self::FAQ_MAX.' questions and answers (now '.$n.').';
        } elseif (array_filter($c['faqs'], fn ($f) => $f['q'] === '' || mb_strlen($f['a']) < 20) !== []) {
            $p['faqs'] = 'Every question needs a full answer.';
        }
        if (count($c['hire_questions']) < self::HIRE_MIN) {
            $p['hire_questions'] = 'Add at least '.self::HIRE_MIN.' questions to ask before hiring.';
        }
        if ($c['facts']['cost'] !== '' && $c['sources'] === []) {
            $p['sources'] = 'A cost figure needs the source it came from.';
        }
        if ($c['sources'] !== [] && $c['checked'] === '') {
            $p['checked'] = 'Give the date the facts were checked.';
        }
        if ($industry->license_required && $c['lookups'] === []) {
            $p['lookups'] = 'This industry needs a license: link at least one official lookup.';
        }
        if (array_filter([...$c['lookups'], ...$c['sources']], fn ($r) => $r['url'] === '') !== []) {
            $p['links'] = 'Every lookup and source needs a full https address.';
        }
        if ($industry->tier === Tier::Restricted && preg_match('/\b(we|you) (can|may) (pay|earn|receive) (a )?referral fee/i', implode(' ', [$c['intro'], $c['how'], $c['facts']['fees'], ...array_column($c['faqs'], 'a')]))) {
            $p['fees'] = 'This industry doesn’t allow referral fees: the copy can’t offer them.';
        }
        if ($reviewer === null || trim($reviewer) === '') {
            $p['reviewer'] = 'Review pending: name the expert who checked the facts.';
        }

        return $p;
    }

    /** @param array<string, mixed> $c */
    private static function assertPublishable(Industry $industry, array $c, ?string $reviewer): void
    {
        $p = self::problems($industry, $c, $reviewer);
        if ($p !== []) {
            throw ValidationException::withMessages(['content' => array_values($p)]);
        }
    }

    /** The notice every Tier B and C page shows, from the tier itself (null for Tier A). */
    public static function disclosure(Industry $industry): ?string
    {
        $t = $industry->terms();
        $legal = $industry->family === 'legal';

        return match ($industry->tier) {
            Tier::Open => null,
            Tier::Restricted => 'No referral fees: in this industry, '.$t['pros'].' exchange introductions without any payment, fee or other thing of value. '
                .'{site_name} is not a '.$t['pro'].' and does not give '.mb_strtolower($industry->name).' advice; listing is not an endorsement.',
            Tier::Directory => 'Directory only: '.$t['pros'].' here pay a flat listing fee that never depends on referrals, and {site_name} does not route '.$t['clients'].' or take any share of fees. '
                .($legal ? 'This page may be considered attorney advertising. It is general information, not legal advice, and no attorney-client relationship is created by using it. ' : '')
                .'Listing is not an endorsement.',
        };
    }

    /** The plain statement of what the industry's tier means, for the facts sheet. */
    public static function tierFact(Industry $industry): string
    {
        $t = $industry->terms();

        return match ($industry->tier) {
            Tier::Open => ucfirst($t['pros']).' may agree a referral fee with each other: a share of what the receiving '.$t['pro'].' earns from the referral, or a flat amount, agreed before the '.$t['client'].' is sent and paid directly between them; never a share of the sale price.',
            Tier::Restricted => 'Referral fees are not allowed: '.$t['pros'].' introduce '.$t['clients'].' to each other without any payment, and earn credits and recognition that have no cash value.',
            Tier::Directory => 'Directory only: '.$t['pros'].' are listed by city for a flat fee; '.$t['clients'].' contact them directly and no referral fees apply.',
        };
    }

    /**
     * The industry's words, for {pro}, {clients} and so on in its copy.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public static function vars(Industry $industry, array $extra = []): array
    {
        return [...Tokens::industry($industry), ...$extra];
    }

    /** A state or city page opening, filled for one place (Sprint 40 renders the pages). */
    public static function placeSummary(Industry $industry, string $state, ?string $city = null): string
    {
        $c = self::content($industry);
        $tpl = $city === null ? $c['state_summary'] : $c['city_summary'];

        return Tokens::fill($tpl, self::vars($industry, ['state' => $state, 'city' => (string) $city]));
    }

    /**
     * Everything the public hub shows, with words and live figures filled in.
     *
     * @return array<string, mixed>
     */
    public static function page(Industry $industry): array
    {
        $r = self::record($industry);
        $c = self::content($industry);
        unset($c['state_summary'], $c['city_summary']); // place-page templates, used by the state and city pages
        $c = Tokens::fillAll($c, self::vars($industry));
        $related = Industry::query()->whereIn('slug', $c['related'])->whereIn('id', DB::table('industry_content')->where('status', 'published')->select('industry_id'))
            ->orderBy('name')->get(['slug', 'name'])->map(fn ($i) => ['slug' => $i->slug, 'name' => $i->name])->values()->all();
        $disclosure = self::disclosure($industry);

        return [
            'industry' => ['slug' => $industry->slug, 'name' => $industry->name, 'tier' => $industry->tier->value, 'family' => $industry->family, 'terms' => $industry->terms(),
                'consumerRequests' => $industry->tier->allowsConsumerRequests()],
            'content' => [...$c, 'facts' => [...$c['facts'], 'tier' => self::tierFact($industry)]],
            'disclosure' => $disclosure !== null ? Tokens::fill($disclosure) : null,
            'related' => $related,
            'reviewer' => $r?->reviewer, 'reviewedOn' => $r?->reviewed_on, 'updatedOn' => $r !== null ? substr((string) $r->updated_at, 0, 10) : null,
        ];
    }

    /**
     * The structured data for a hub: the service, its questions and answers, its glossary and the breadcrumb trail.
     *
     * @param  array<string, mixed>  $page  from page()
     * @return list<array<string, mixed>>
     */
    public static function schema(Industry $industry, array $page): array
    {
        $base = rtrim((string) config('seo.canonical_url'), '/');
        $path = '/industries/'.$industry->slug;
        $c = $page['content'];
        $out = [Schema::organization(), array_filter([
            '@context' => 'https://schema.org', '@type' => 'Service', '@id' => $base.$path.'#service',
            'name' => $industry->name.' referrals', 'serviceType' => $industry->name, 'description' => $c['summary'],
            'provider' => ['@id' => $base.'/#organization'], 'areaServed' => ['@type' => 'Country', 'name' => 'United States'],
            'url' => $base.$path,
        ]), array_filter([
            '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $industry->name, 'url' => $base.$path, 'description' => $c['summary'],
            'lastReviewed' => $page['reviewedOn'], 'reviewedBy' => $page['reviewer'] !== null ? ['@type' => 'Person', 'name' => $page['reviewer']] : null,
            'about' => ['@id' => $base.$path.'#service'],
        ])];
        if ($c['faqs'] !== []) {
            $out[] = Schema::faq(array_values(array_map(fn (array $f): array => [(string) $f['q'], (string) $f['a']], $c['faqs'])));
        }
        if ($c['glossary'] !== []) {
            $out[] = ['@context' => 'https://schema.org', '@type' => 'DefinedTermSet', 'name' => $industry->name.' glossary', 'url' => $base.$path.'#glossary',
                'hasDefinedTerm' => array_map(fn ($g) => ['@type' => 'DefinedTerm', 'name' => $g['term'], 'description' => $g['definition']], $c['glossary'])];
        }
        $out[] = Schema::breadcrumbs([['Home', '/'], ['Industries', '/industries'], [$industry->name, $path]]);

        return $out;
    }

    /**
     * Quotable statements for a published hub (llms.txt and the facts sheet).
     *
     * @return list<string>
     */
    public static function facts(Industry $industry): array
    {
        $p = self::page($industry);
        $f = $p['content']['facts'];

        return array_values(array_filter([$f['covers'], $f['cost'], $f['licensing'], $f['fees'], $f['tier']], fn ($s) => $s !== ''));
    }

    /** @return \Illuminate\Support\Collection<int, Industry> industries with a published hub */
    public static function published(): \Illuminate\Support\Collection
    {
        return Industry::query()->whereIn('id', DB::table('industry_content')->where('status', 'published')->select('industry_id'))->orderBy('name')->get();
    }

    /** Install the drafted content for industries that have none yet; returns how many were added. Never overwrites. */
    public static function install(?User $by = null): int
    {
        $n = 0;
        foreach (IndustryPack::all() as $slug => $content) {
            $industry = Industry::query()->where('slug', $slug)->first();
            if ($industry !== null && self::record($industry) === null) {
                self::save($industry, $content, null, $by);
                $n++;
            }
        }

        return $n;
    }
}
