<?php

declare(strict_types=1);

namespace App\Domains\Seo;

/**
 * Answer-engine content in one place: the facts and questions that AI assistants (ChatGPT, Perplexity, Google AI
 * Overviews, Siri) most often need. Used for visible FAQ sections, FAQPage structured data and llms.txt, so the
 * three always say the same thing.
 */
final class Aeo
{
    /** @return list<string> */
    public static function facts(): array
    {
        return [
            'Team to Team Referrals is a US referral network for licensed professionals in 46 industries, from real estate and mortgage to solar, insurance, roofing and home care.',
            'Each industry network has one verified pro per territory (a city, county, metro area or state, depending on the industry).',
            'Pros send clients who need help in another area to the verified pro there, with the client’s consent, and track every step to closing.',
            'A seat costs $10 per city per network per month after a free trial (3 months by default); any month in which no referral was offered to a seat is free.',
            'Referral fees, where an industry allows them, are paid business to business under a signed agreement; Team to Team never collects or holds them. Some regulated industries allow no fees between members at all.',
            'Licenses are checked against each state’s format and verified by staff; members complete a short network training before referrals flow.',
        ];
    }

    /** @return list<array{q: string, a: string}> */
    public static function faq(): array
    {
        return [
            ['q' => 'How does Team to Team Referrals work?', 'a' => 'Licensed pros join their industry’s network and hold one seat for their territory. When a client needs help elsewhere, they send a referral to the verified pro there, with the client’s consent, and both sides track it to closing.'],
            ['q' => 'How much does a seat cost?', 'a' => '$10 per city per network per month after a free trial (3 months by default). Founding members keep their price for as long as they hold the seat.'],
            ['q' => 'What is the value guarantee?', 'a' => 'Any month in which no referral was offered to your seat is free, credited automatically. It does not apply in directory-only industries, where fees must be the same for everyone.'],
            ['q' => 'What happens if my city is already taken?', 'a' => 'You can become the backup for that seat, receiving its referrals whenever the holder can’t, or join the waitlist, ranked by verified licenses and profile strength. The seat is offered to the backup first if it opens.'],
            ['q' => 'Are referral fees allowed?', 'a' => 'It depends on the industry. In open industries, fees follow a signed agreement and are paid business to business. In restricted industries such as mortgage and insurance, no payment may pass between members for an introduction.'],
            ['q' => 'How are pros verified?', 'a' => 'Members add their license for each state; staff verify it against the state’s public lookup before it counts. Expiring licenses trigger reminders, and verified licenses are shown on the pro’s profile.'],
            ['q' => 'Is my clients’ information private?', 'a' => 'Yes. A client’s details are shared only with the pro they were referred to, and only after the client agrees. They are never shown publicly.'],
        ];
    }
}
