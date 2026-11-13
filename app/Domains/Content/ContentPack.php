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
}
