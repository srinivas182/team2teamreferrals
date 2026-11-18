<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Content\Tokens;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The accessibility statement (Sprint 53a): the standard we build to, how it's checked on every change, what we know
 * isn't perfect yet, and how to tell us about a problem.
 */
final class AccessibilityController
{
    /** The date the statement was last reviewed; update it whenever the statement changes. */
    public const REVIEWED = '2026-11-18';

    public function __invoke(): Response
    {
        $site = (string) config('seo.site_name', 'Team to Team Referrals');
        $company = Tokens::company();
        $answer = "{$site} aims to meet the Web Content Accessibility Guidelines (WCAG) 2.1 at level AA on every page, for members, visitors, partners and staff. Every change is checked automatically before it goes live, and we fix reported problems as a priority.";
        $faq = [
            ['q' => "Does {$site} work with a screen reader?", 'a' => 'Yes. Pages use proper headings, labels and landmarks, images have text alternatives, and every control has a name a screen reader can read out. We test with the keyboard alone and with automated WCAG checks on every change.'],
            ['q' => 'How do I report an accessibility problem?', 'a' => "Email {$company['support_email']} and tell us the page and what happened. A person replies within one business day, and we can help you complete the task another way while we fix it."],
        ];

        return Inertia::render('Accessibility', [
            'answer' => $answer,
            'reviewed' => self::REVIEWED,
            'email' => $company['support_email'],
            'faq' => $faq,
            'seo' => (new SeoData(title: "Accessibility statement: {$site}", description: $answer, path: '/accessibility',
                schema: [Schema::organization(), Schema::faq(array_map(fn ($f) => [$f['q'], $f['a']], $faq)), Schema::breadcrumbs([['Home', '/'], ['Accessibility', '/accessibility']])]))->toArray(),
        ]);
    }
}
