<?php

declare(strict_types=1);

namespace App\Domains\Content\Http;

use App\Domains\Content\Tokens;
use App\Domains\Network\Directory\Directory;
use App\Domains\Platform\Settings\Settings;
use App\Domains\Seo\Aeo;
use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * The press kit (Sprint 52b): logos, screenshots, the product tour video with captions and a transcript, key facts
 * (live figures, not round numbers), the company boilerplate and who to contact. Everything here may be used in
 * coverage of the product; the files live in public/press-kit and are regenerated with scripts/press/press-kit.mjs.
 */
final class PressController
{
    /** Screenshots, in the order shown, with what each one shows. */
    public const SCREENS = [
        'home' => 'The home page: find your industry’s network and see which cities are held.',
        'network-map' => 'A network’s map: one verified team per city, and the cities still open.',
        'member-home' => 'A member’s home: what needs attention today.',
        'send-referral' => 'Sending a referral: pick the city, and it goes to the team that holds it.',
        'pipeline' => 'The pipeline: every referral from first call to closing.',
        'phone' => 'On a phone: the same account, built for working on the move.',
    ];

    /** What the product tour shows, scene by scene (also the captions, so the transcript and the video always agree). */
    public const TOUR = [
        [0, 'Team to Team Referrals is a referral network for licensed pros: one verified team per city, in each industry’s network.'],
        [6, 'Pick your industry to see its network, and which cities are already held.'],
        [12, 'Each network’s map shows the team that holds every city, and the seats still open.'],
        [19, 'Members sign in to a home that shows what needs them today.'],
        [25, 'To send a referral, pick the city: it goes to the verified team that holds it, with the client’s consent.'],
        [33, 'Referrals sent to you arrive in your inbox; accept one and the client’s details are yours.'],
        [40, 'The pipeline tracks every referral from first call to closing.'],
        [47, 'Fees owed and paid, where the industry allows them, are in one place.'],
        [53, 'Claim your city before someone else in your network does.'],
    ];

    public const TOUR_SECONDS = 58;

    public function show(): Response
    {
        $site = (string) config('seo.site_name', 'Team to Team Referrals');
        $company = Tokens::company();
        $s = app(Settings::class);
        $figures = Cache::remember('press:figures', 300, function (): array {
            $groups = Directory::groups();
            $seats = DB::table('seats')->whereIn('status', Directory::FILLED)->whereNotNull('primary_team_id');

            return ['industries' => DB::table('industries')->count(), 'networks' => $groups->count(), 'seats' => (clone $seats)->count(),
                'states' => (clone $seats)->whereNotNull('state_code')->distinct()->count('state_code')];
        });
        $answer = "{$site} is a US referral network for licensed professionals: each industry has its own network with one verified team per city, so a pro can hand a client who needs help elsewhere to a team they can trust. This press kit has our logos, screenshots, a product tour video, key facts and who to contact.";
        $boilerplate = "{$site} connects licensed professionals across {$figures['industries']} industries, from real estate and mortgage to solar, insurance and home care. Each industry network has one verified team per city, county, metro area or state, so every referral has a clear destination. Members send clients who need help elsewhere to the verified pro there, with the client’s consent, and track each referral to closing. A seat is a flat monthly fee per city after a free trial.";
        $press = trim((string) $s->get('general.press_email')) ?: $company['support_email'];
        $faq = [
            ['q' => "How do I contact {$site} for a press enquiry?", 'a' => "Email {$press}. We usually reply within one business day, and can arrange a demo account or an interview."],
            ['q' => 'Can I use these logos, screenshots and the video?', 'a' => "Yes, in coverage of {$site}. Please don’t change the logo’s colours or proportions, and credit screenshots to {$site}. The screenshots and the video use sample data, not real members."],
            ['q' => 'Where do the figures on this page come from?', 'a' => 'They’re counted live from the platform each time this page is built: industries we serve, networks open to members, seats held by a verified team, and the states those seats are in.'],
        ];
        $video = is_file(public_path('press-kit/tour.mp4')) ? [
            'src' => '/press-kit/tour.mp4', 'poster' => '/press-kit/tour-poster.jpg', 'captions' => '/press-kit/tour.vtt', 'seconds' => self::TOUR_SECONDS,
            'social' => is_file(public_path('press-kit/tour-social.mp4')) ? '/press-kit/tour-social.mp4' : null,
            'transcript' => array_map(fn ($c) => ['at' => $c[0], 'text' => $c[1]], self::TOUR),
        ] : null;
        $updated = (string) (Cache::remember('press:updated', 3600, fn () => date('Y-m-d', (int) (@filemtime(public_path('press-kit/tour.vtt')) ?: time()))));
        $base = rtrim((string) config('seo.canonical_url', config('app.url')), '/');
        $schema = [Schema::organization(), Schema::faq(array_map(fn ($f) => [$f['q'], $f['a']], $faq)), Schema::breadcrumbs([['Home', '/'], ['Press kit', '/press']])];
        if ($video !== null) {
            $schema[] = ['@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => "{$site} product tour", 'description' => 'A one-minute tour of '.$site.': finding your industry’s network, sending and receiving referrals, and tracking them to closing.',
                'thumbnailUrl' => $base.$video['poster'], 'contentUrl' => $base.$video['src'], 'uploadDate' => $updated, 'duration' => 'PT'.self::TOUR_SECONDS.'S',
                'transcript' => implode(' ', array_column(self::TOUR, 1))];
        }

        return Inertia::render('Press', [
            'answer' => $answer,
            'boilerplate' => $boilerplate,
            'facts' => Aeo::facts(),
            'figures' => $figures,
            'contact' => ['email' => $press, 'company' => $company['company_legal_name'], 'address' => $company['postal_address'] ?: null, 'phone' => trim((string) $s->get('general.support_phone')) ?: null],
            'logos' => self::logos(),
            'screens' => array_values(array_filter(array_map(fn ($key, $caption) => is_file(public_path("press-kit/screens/{$key}.jpg"))
                ? ['src' => "/press-kit/screens/{$key}.jpg", 'caption' => $caption, 'name' => $key] : null, array_keys(self::SCREENS), self::SCREENS))),
            'video' => $video,
            'faq' => $faq,
            'updated' => $updated,
            'seo' => (new SeoData(title: "Press kit: logos, screenshots and facts about {$site}", description: "Logos, product screenshots, a product tour video and key facts about {$site}, the referral network for licensed pros, plus who to contact for press.",
                path: '/press', schema: $schema))->toArray(),
        ]);
    }

    /** All the logos in one download. */
    public function logosZip(): BinaryFileResponse
    {
        $zip = storage_path('app/press-logos.zip');
        $files = glob(public_path('press-kit/logos/*')) ?: [];
        $newest = $files === [] ? 0 : max(array_map(fn ($f) => (int) filemtime($f), $files));
        abort_if($files === [], 404);
        if (! is_file($zip) || filemtime($zip) < $newest) {
            $z = new ZipArchive;
            $z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($files as $f) {
                $z->addFile($f, basename($f));
            }
            $z->close();
        }

        return response()->download($zip, 'team-to-team-referrals-logos.zip', ['Cache-Control' => 'public, max-age=86400']);
    }

    /** @return list<array{name: string, svg: string|null, png: string|null, dark: bool}> */
    private static function logos(): array
    {
        $names = ['t2t-logo-horizontal' => ['Horizontal logo', false], 't2t-logo-horizontal-on-dark' => ['Horizontal logo, for dark backgrounds', true],
            't2t-logo-stacked' => ['Stacked logo', false], 't2t-logo-stacked-on-dark' => ['Stacked logo, for dark backgrounds', true],
            't2t-mark' => ['Mark', false], 't2t-mark-on-dark' => ['Mark, for dark backgrounds', true],
            't2t-logo-mono-navy' => ['One colour, navy', false], 't2t-logo-mono-white' => ['One colour, white', true]];
        $out = [];
        foreach ($names as $file => [$label, $dark]) {
            $svg = is_file(public_path("press-kit/logos/{$file}.svg")) ? "/press-kit/logos/{$file}.svg" : null;
            $png = is_file(public_path("press-kit/logos/{$file}.png")) ? "/press-kit/logos/{$file}.png" : null;
            if ($svg !== null || $png !== null) {
                $out[] = ['name' => $label, 'svg' => $svg, 'png' => $png, 'dark' => $dark];
            }
        }

        return $out;
    }
}
