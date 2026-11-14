<?php

use App\Domains\Seo\Audit\PageAudit;
use App\Domains\Seo\Audit\SchemaRules;

/** A page that passes every rule; each test breaks one thing. */
function auditPage(array $over = []): string
{
    $o = [...[
        'title' => '<title>Solar in Austin, TX: the verified installer</title>',
        'description' => '<meta name="description" content="Lone Star Solar holds the solar seat for Austin, TX on Team to Team Referrals: licensed, verified, rated.">',
        'canonical' => '<link rel="canonical" href="https://t2t.example/industries/solar/tx/austin">',
        'robots' => '<meta name="robots" content="index, follow">',
        'schema' => [
            ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Team to Team Referrals', 'url' => 'https://t2t.example'],
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'How do I find a trusted installer in Austin, TX?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Ask the seat holder.']]]],
            ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://t2t.example/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Austin', 'item' => 'https://t2t.example/industries/solar/tx/austin']]],
            ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'Austin', 'url' => 'https://t2t.example/industries/solar/tx/austin', 'dateModified' => '2027-01-15T10:00:00+00:00'],
        ],
        'body' => '<main><h1>Solar in Austin, TX</h1><p>Lone Star Solar holds the solar seat for Austin, TX on Team to Team Referrals: a licensed installer, verified by staff, who answers referrals within 24 hours.</p>'
            .'<p>Updated <time datetime="2027-01-15">Jan 15, 2027</time></p><h2>How do I find a trusted <!-- -->installer<!-- --> in <!-- -->Austin<!-- -->, TX?</h2><p>Ask the seat holder.</p></main>',
    ], ...$over];
    $ld = implode('', array_map(fn ($s) => '<script type="application/ld+json">'.json_encode($s).'</script>', $o['schema']));

    return "<!doctype html><html><head>{$o['title']}{$o['description']}{$o['canonical']}{$o['robots']}{$ld}</head><body>{$o['body']}</body></html>";
}

const AUDIT_URL = 'https://t2t.example/industries/solar/tx/austin';
const AUDIT_LLMS = "- https://t2t.example/industries/solar/tx/austin\n";

it('passes a page that has everything', function () {
    expect(PageAudit::check(auditPage(), AUDIT_URL, AUDIT_LLMS))->toBe([]);
});

it('catches each missing search essential', function (array $over, string $problem) {
    expect(PageAudit::check(auditPage($over), AUDIT_URL, AUDIT_LLMS))->toContain($problem);
})->with([
    'no title' => [['title' => ''], 'No title'],
    'long title' => [['title' => '<title>'.str_repeat('Long title ', 8).'</title>'], 'Title is longer than 70 characters (87)'],
    'thin description' => [['description' => '<meta name="description" content="Too short">'], 'Meta description is missing or under 50 characters'],
    'no canonical' => [['canonical' => ''], 'No canonical link'],
    'canonical elsewhere' => [['canonical' => '<link rel="canonical" href="https://t2t.example/groups">'], 'Canonical points elsewhere (https://t2t.example/groups)'],
    'noindex' => [['robots' => '<meta name="robots" content="noindex, nofollow">'], 'Listed in the sitemap but marked noindex'],
    'no structured data' => [['schema' => []], 'No structured data'],
]);

it('catches answer-engine problems: short opening, hidden FAQ, unshown date, unlisted page, no entity', function () {
    $short = auditPage(['body' => '<main><h1>Austin</h1><p>Solar here.</p><h2>How do I find a trusted installer in Austin, TX?</h2><time datetime="2027-01-15">x</time></main>']);
    expect(PageAudit::check($short, AUDIT_URL, AUDIT_LLMS))->toContain('Opening answer is too short (2 words before the first section; needs 20)');

    $hidden = auditPage(['body' => '<main><h1>Solar in Austin</h1><p>'.str_repeat('word ', 25).'</p><time datetime="2027-01-15">x</time></main>']);
    expect(PageAudit::check($hidden, AUDIT_URL, AUDIT_LLMS))->toContain('FAQ question in the structured data but not on the page: How do I find a trusted installer in Austin, TX?');

    $undated = str_replace('<time datetime="2027-01-15">Jan 15, 2027</time>', 'recently', auditPage());
    expect(PageAudit::check($undated, AUDIT_URL, AUDIT_LLMS))->toContain('Updated date (2027-01-15) is in the structured data but not shown on the page');

    expect(PageAudit::check(auditPage(), AUDIT_URL, "- https://t2t.example/industries/solar\n"))->toContain('Not listed in llms.txt or llms-full.txt');

    $noEntity = auditPage(['schema' => [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://t2t.example/']]]]]);
    expect(PageAudit::check($noEntity, AUDIT_URL, AUDIT_LLMS))->toContain('No entity facts (Organization, LocalBusiness, Service, Place or similar) in the structured data');
});

it('validates structured data by type, including nested objects', function () {
    expect(SchemaRules::check(['@type' => 'LocalBusiness', 'name' => 'X']))->toBe(['LocalBusiness is missing address or areaServed'])
        ->and(SchemaRules::check(['@type' => 'Article', 'headline' => 'H', 'datePublished' => '2027-02-01', 'dateModified' => '2027-01-01', 'author' => ['@type' => 'Person', 'name' => 'A']]))
        ->toBe(['Article was modified before it was published'])
        ->and(SchemaRules::check(['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 2, 'name' => 'Home', 'item' => 'https://x.test/']]]))
        ->toContain('BreadcrumbList item 1 has the wrong position')
        ->and(SchemaRules::check(['@type' => 'Organization', 'name' => 'X', 'url' => '/relative']))->toContain('Organization has a relative url (needs a full https address): /relative')
        ->and(SchemaRules::check(['@type' => 'AggregateRating', 'ratingValue' => 7, 'ratingCount' => 3]))->toBe(['AggregateRating value is out of range'])
        ->and(SchemaRules::check(['@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'Q']]]))->toBe(['FAQPage question 1 needs a name and an accepted answer']);
    $nested = SchemaRules::objects([['@type' => 'Service', 'name' => 'S', 'areaServed' => 'Austin', 'provider' => ['@type' => 'LocalBusiness', 'name' => 'P']]]);
    expect(array_column($nested, '@type'))->toBe(['Service', 'LocalBusiness'])
        ->and(SchemaRules::check($nested[1]))->toBe(['LocalBusiness is missing address or areaServed']);
    expect(PageAudit::check(auditPage(['schema' => [...[], ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'T', 'url' => 'https://t2t.example']]]).'<script type="application/ld+json">{bad</script>', AUDIT_URL, AUDIT_LLMS))
        ->toContain('Structured data that is not valid JSON');
});
