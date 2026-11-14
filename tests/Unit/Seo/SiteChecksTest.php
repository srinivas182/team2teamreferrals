<?php

use App\Domains\Seo\Audit\SiteChecks;

function sitePage(string $title, string $desc, string $body): string
{
    return "<html><head><title>{$title}</title><meta name=\"description\" content=\"{$desc}\"></head><body><main>{$body}</main></body></html>";
}

it('finds links to our pages that were not checked, and links to pages that failed', function () {
    $s = new SiteChecks;
    $s->add('/a', sitePage('A', 'About A', '<a href="/b">B</a> <a href="https://t2t.test/contact?x=1">C</a> <a href="https://other.test/x">X</a> <a href="/app/send">portal</a> <a href="#top">top</a> <a href="mailto:a@b.c">mail</a>'), 't2t.test');
    $s->add('/b', sitePage('B', 'About B', '<a href="/a">A</a> <a href="/gone">gone</a>'), 't2t.test');
    expect($s->linkTargets())->toBe(['/contact' => ['/a'], '/gone' => ['/b']])
        ->and($s->linksToFailedPages(['/a' => 200, '/b' => 404]))->toBe(['/b' => ['/a']]);
});

it('finds duplicate titles and descriptions, and pages with nearly the same wording', function () {
    $text = implode(' ', array_map(fn ($i) => 'word'.$i, range(1, 120))).' '; // a long, templated page
    $s = new SiteChecks;
    $s->add('/x', sitePage('Same title', 'Desc one', '<p>'.$text.'Austin.</p>'), 't2t.test');
    $s->add('/y', sitePage('Same Title', 'Desc two', '<p>'.$text.'Dallas.</p>'), 't2t.test');
    $s->add('/z', sitePage('Other', 'Desc two', '<p>Completely different words about mortgage lending rules and fees.</p>'), 't2t.test');
    $d = $s->duplicates();
    expect($d['titles'])->toBe(['same title' => ['/x', '/y']])
        ->and($d['descriptions'])->toBe(['desc two' => ['/y', '/z']])
        ->and($d['near'])->toHaveCount(1)->and($d['near'][0])->toMatchArray(['a' => '/x', 'b' => '/y']);
});
