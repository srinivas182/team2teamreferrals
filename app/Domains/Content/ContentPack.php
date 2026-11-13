<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The default content pack: the core pages with real copy from the section library, published, so a fresh install
 * shows a finished site. Pages someone has edited are never overwritten: only missing pages, and untouched drafts
 * from "Create core pages", are filled.
 */
final class ContentPack
{
    /** @return array<string, array{title: string, template: string, summary: string, sections: list<string>, blocks?: list<array<string, mixed>>}> */
    public static function corePages(): array
    {
        return [
            'how-it-works' => ['title' => 'How it works', 'template' => 'page', 'sections' => ['steps_how', 'steps_client', 'features_trust', 'faq_privacy', 'cta_reserve'],
                'summary' => '{site_name} works in three steps: claim the seat for your city, send and receive referrals with each client’s consent, and track every referral from acceptance to close.'],
            'pricing' => ['title' => 'Pricing', 'template' => 'landing', 'sections' => ['pricing_seat', 'pricing_addons', 'value_guarantee', 'faq_pricing', 'cta_reserve'],
                'summary' => 'Seats start at {seat_price} a month per city in each industry network, with {trial_months} months free to start and {annual_months_free} months free when paid yearly.'],
            'about' => ['title' => 'About {site_name}', 'template' => 'page', 'sections' => ['stats_network', 'features_why', 'cta_contact'],
                'summary' => '{site_name} is a US referral network for {industries} industries that gives one verified pro per city a seat, so trusted teams can pass clients to each other and track every referral.',
                'blocks' => [['type' => 'rich_text', 'html' => '<p>Good referrals depend on trust. We built {site_name} so that when a client moves or needs help in another city, the pro they already trust can hand them to someone just as reliable, and both can see the referral through.</p><p>Every member is verified, scored on how they serve referrals, and held to clear rules on consent, deadlines and fees.</p>']]],
            'faq' => ['title' => 'Questions and answers', 'template' => 'page', 'sections' => ['faq_general', 'faq_fees', 'faq_pricing', 'faq_privacy', 'cta_contact'],
                'summary' => 'Answers to common questions about {site_name}: who can join, what it costs, how referral fees work in each industry, and how client consent and privacy are handled.'],
            'cost-data' => ['title' => 'How we get our cost data', 'template' => 'page', 'sections' => ['cta_contact'],
                'summary' => 'Cost figures on {site_name} industry pages come from public sources we cite and date on each page; where no reliable figure exists we leave it out rather than estimate.',
                'blocks' => [['type' => 'rich_text', 'html' => '<h2>Where the figures come from</h2><p>Each industry page’s facts sheet may give a typical cost. Every figure is paraphrased from a public source named on that page, such as a government agency, a regulator or an industry data report, with the date we checked it.</p><h2>When we leave a figure out</h2><p>Some prices depend so much on the client, place and job that a single number would mislead, such as insurance premiums or moving quotes. In those cases we say so and publish no figure.</p><h2>Limits</h2><p>Figures are national or regional and can change. They are a starting point, not a quote: ask the pro for a written price.</p><h2>Our own data</h2><p>As referrals close on {site_name}, we plan to add figures from our own records, published only when there are enough to be reliable and never in a way that identifies a client or member.</p><h2>Spotted something out of date?</h2><p>Use “Suggest a correction” on any industry page, ideally with a source. Our editors check every suggestion.</p>']]],
            'editorial-standards' => ['title' => 'How we review content', 'template' => 'page', 'sections' => ['cta_contact'],
                'summary' => 'Every {site_name} industry page is checked by a named reviewer before it goes live, shows the date it was last reviewed, and cites the sources for its facts.',
                'blocks' => [['type' => 'rich_text', 'html' => '<h2>Written, then checked</h2><p>Industry pages are drafted from public sources, then checked by a reviewer with experience in that industry before they are published. A page cannot go live without a named reviewer.</p><h2>What every page shows</h2><ul><li>The reviewer’s name and the date of the last review.</li><li>The sources behind each fact, with the date they were checked.</li><li>A notice where the industry’s rules limit referrals or fees.</li></ul><h2>Rules we follow</h2><ul><li>We never invent figures; where no reliable source exists, we leave the figure out.</li><li>Referral fees are always described as a share of what the receiving pro earns, never of the sale price, and only where the industry allows them.</li><li>Pages are general information, not legal, financial or medical advice.</li></ul><h2>Corrections</h2><p>Anyone can use “Suggest a correction” on an industry page. Editors check each suggestion and update the page and its review date when a fact changes.</p>']]],
            'contact' => ['title' => 'Contact us', 'template' => 'page', 'sections' => ['faq_general'],
                'summary' => 'Contact {site_name} using the form on this page; we answer every message within one business day.',
                'blocks' => [['type' => 'contact_form', 'heading' => 'Send us a message', 'intro' => 'Questions about joining, billing or partnerships: we answer within one business day.']]],
        ];
    }

    /** Create an industry's six template pages as drafts (in its own words); returns how many were added. */
    public static function industryPages(\App\Domains\Network\Industries\Models\Industry $industry, ?User $by): int
    {
        $vars = Tokens::industry($industry);
        $n = 0;
        foreach (Sections::industryTemplates($industry) as $t) {
            if (DB::table('cms_pages')->where('slug', $t['slug'])->exists()) {
                continue;
            }
            /** @var list<array<string, mixed>> $blocks */
            $blocks = array_values(Tokens::fillOnly(Sections::blocks($t['sections']), $vars));
            Pages::save(null, ['title' => (string) Tokens::fillOnly([$t['title']], $vars)[0], 'slug' => $t['slug'], 'template' => 'landing',
                'answer_summary' => (string) Tokens::fillOnly([$t['summary']], $vars)[0], 'seo' => [], 'blocks' => $blocks], $by);
            $n++;
        }

        return $n;
    }

    /** Fill and publish the core pages; returns how many were written. */
    public static function install(?User $by = null): int
    {
        $n = 0;
        foreach (self::corePages() as $slug => $def) {
            $page = DB::table('cms_pages')->where('slug', $slug)->first();
            $untouched = $page !== null && $page->status === 'draft' && DB::table('cms_page_versions')->where('page_id', $page->id)->count() === 1
                && trim((string) DB::table('cms_page_versions')->where('id', $page->draft_version_id)->value('answer_summary')) === '';
            if ($page !== null && ! $untouched) {
                continue; // someone has worked on it: leave it alone
            }
            $blocks = [...($def['blocks'] ?? []), ...Sections::blocks($def['sections'])];
            $ulid = Pages::save($page, ['title' => $def['title'], 'slug' => $slug, 'template' => $def['template'], 'answer_summary' => $def['summary'], 'seo' => [], 'blocks' => $blocks], $by);
            Pages::publish(DB::table('cms_pages')->where('ulid', $ulid)->firstOrFail(), null, $by);
            $n++;
        }

        return $n;
    }

    /**
     * Starter writing, installed as DRAFTS only: the ten blog posts (reviewer shown as "Review pending" until the owner
     * names one), the help-centre articles, and the Terms and Privacy drafts for the attorney. Anything that already
     * exists, or that someone has edited, is left alone. Returns [posts, help articles, legal pages] added.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function installDrafts(?User $by = null): array
    {
        $posts = 0;
        foreach (Pack\StarterPosts::all() as $p) {
            $sources = $p['sources'] === [] ? '' : '<h2>Sources</h2><ul>'.implode('', array_map(fn ($s) => '<li><a href="'.e($s[1]).'">'.e($s[0]).'</a></li>', $p['sources'])).'</ul>';
            $posts += self::draftPost($p['slug'], $p['title'], $p['summary'], $p['body'].$sources.'<p><em>Facts checked '.Pack\StarterPosts::CHECKED.'.</em></p>', $p['category'], $by) ? 1 : 0;
        }
        $help = 0;
        foreach (Pack\HelpArticles::all() as $a) {
            $help += self::draftPost($a['slug'], $a['title'], $a['summary'], $a['body'], 'Help centre', $by) ? 1 : 0;
        }
        $legal = 0;
        foreach (Pack\LegalDrafts::all() as $slug => $d) {
            $page = DB::table('cms_pages')->where('slug', $slug)->first();
            $html = $page !== null ? (string) (json_decode((string) DB::table('cms_page_versions')->where('id', $page->draft_version_id)->value('blocks'), true)[0]['html'] ?? 'x') : '';
            if ($page !== null && ($page->status !== 'draft' || trim(strip_tags($html)) !== '')) {
                continue; // published, already written, or someone has worked on it: only empty drafts are filled
            }
            Pages::save($page, ['title' => $d['title'], 'slug' => $slug, 'template' => 'legal', 'answer_summary' => $d['summary'], 'seo' => [], 'blocks' => [['type' => 'rich_text', 'html' => $d['html']]]], $by);
            $legal++;
        }

        return [$posts, $help, $legal];
    }

    private static function draftPost(string $slug, string $title, string $summary, string $body, string $category, ?User $by): bool
    {
        if (DB::table('posts')->where('slug', $slug)->exists()) {
            return false;
        }
        $catSlug = \Illuminate\Support\Str::slug($category);
        $cat = DB::table('blog_categories')->where('slug', $catSlug)->value('id')
            ?? DB::table('blog_categories')->insertGetId(['slug' => $catSlug, 'name' => $category, 'created_at' => now(), 'updated_at' => now()]);
        Posts::save(null, ['title' => $title, 'slug' => $slug, 'answer_summary' => $summary, 'body' => $body, 'category_id' => (int) $cat, 'author_id' => null, 'reviewer' => null, 'cover_media_id' => null, 'seo' => []], $by);

        return true;
    }
}
