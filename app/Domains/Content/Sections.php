<?php

declare(strict_types=1);

namespace App\Domains\Content;

use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Industries\Tier;

/**
 * The section library: ready-made sections built from the page builder's blocks, with accurate default copy. Figures
 * are placeholders filled from live settings ({seat_price}…); industry and city sections use {industry}, {pro}, {city}.
 * Fee wording follows the business model: a referral fee is a share of what the receiving team earns, agreed up front;
 * the platform never holds referral money; restricted industries (Tier B) exchange referrals without fees; the value
 * guarantee never applies to directory listings (Tier C).
 */
final class Sections
{
    public const IMG = '/illustrations/';

    /** @return array<string, array{name: string, group: string, blocks: list<array<string, mixed>>}> */
    public static function all(): array
    {
        $s = [];
        // Heroes
        $s['hero_reserve'] = ['Hero: reserve your city', 'Hero', [['type' => 'hero', 'heading' => 'One trusted pro per city. Referrals you can track.', 'subheading' => '{site_name} connects one verified pro per city in each industry, so you can hand out-of-area clients to a team you trust and see every step.', 'cta_label' => 'Reserve your city', 'cta_url' => '/reserve', 'image' => self::IMG.'network.svg', 'image_alt' => 'A map of connected cities across the United States']]];
        $s['hero_simple'] = ['Hero: simple', 'Hero', [['type' => 'hero', 'heading' => 'Send clients to pros you trust, anywhere in the US', 'subheading' => 'Verified pros, one per city, with every referral tracked from first call to close.', 'cta_label' => 'See how it works', 'cta_url' => '/how-it-works']]];
        $s['hero_join'] = ['Hero: join', 'Hero', [['type' => 'hero', 'heading' => 'Claim your city before someone else does', 'subheading' => 'Each city has one seat per industry. Try it free for {trial_months} months.', 'cta_label' => 'Claim your city', 'cta_url' => '/register']]];
        // How it works
        $s['steps_how'] = ['Three steps: how it works', 'How it works', [['type' => 'steps', 'heading' => 'How it works', 'items' => [
            ['title' => 'Claim your city', 'text' => 'Verify your license and hold the seat for your city in your industry network.'],
            ['title' => 'Send and receive referrals', 'text' => 'When a client moves or needs help elsewhere, send them to the verified pro in that city, with the client’s consent.'],
            ['title' => 'Track every step', 'text' => 'See when the referral is accepted, contacted and closed. Deadlines and updates keep everyone accountable.']]]]];
        $s['steps_client'] = ['Steps: what the client sees', 'How it works', [['type' => 'steps', 'heading' => 'What your client experiences', 'items' => [
            ['title' => 'They agree to be referred', 'text' => 'Nothing is shared until your client says yes.'],
            ['title' => 'A verified pro contacts them', 'text' => 'The receiving pro accepts quickly and reaches out within the network’s deadline.'],
            ['title' => 'They can check progress', 'text' => 'Your client gets a private link to see who is helping them.']]]]];
        $s['steps_join'] = ['Steps: joining', 'How it works', [['type' => 'steps', 'heading' => 'Getting started takes minutes', 'items' => [
            ['title' => 'Create your team', 'text' => 'Add your business and team members.'], ['title' => 'Verify your license', 'text' => 'Licensed industries are checked before a seat is confirmed.'],
            ['title' => 'Complete the short training', 'text' => 'Learn the network’s rules on consent, deadlines and fees.']]]]];
        // Why join
        $s['features_why'] = ['Features: why join', 'Value', [['type' => 'features', 'heading' => 'Why pros join', 'items' => [
            ['title' => 'Exclusive city seat', 'text' => 'One verified pro per city in each network, so referrals come to you.'], ['title' => 'Trusted partners', 'text' => 'Every member is verified and scored on how they serve referrals.'],
            ['title' => 'Nothing falls through', 'text' => 'Deadlines, reminders and a shared timeline for every referral.'], ['title' => 'Reputation that travels', 'text' => 'A public profile with your trust score and badges.']]]]];
        $s['features_trust'] = ['Features: trust and safety', 'Value', [['type' => 'features', 'heading' => 'Built on trust', 'items' => [
            ['title' => 'Verified licenses', 'text' => 'Licensed industries are checked, with reminders before licenses expire.'], ['title' => 'Client consent first', 'text' => 'Client details are shared only after the client agrees and the referral is accepted.'],
            ['title' => 'Signed agreements', 'text' => 'Each referral can carry an e-signed agreement with the terms both teams accepted.']]]]];
        $s['features_crm'] = ['Features: referral tracking', 'Value', [['type' => 'features', 'heading' => 'Every referral, tracked', 'items' => [
            ['title' => 'Pipeline', 'text' => 'Move referrals through your industry’s stages.'], ['title' => 'Reminders', 'text' => 'Nudges before deadlines, never after.'], ['title' => 'Reports', 'text' => 'Dashboards and exports for your team and your network.']]]]];
        $s['features_ai'] = ['Features: AI help', 'Value', [['type' => 'features', 'heading' => 'Help where it saves time', 'items' => [
            ['title' => 'Lead summaries', 'text' => 'A short summary before you accept, so you know what the client needs.'], ['title' => 'Forward a lead', 'text' => 'Forward an email, record a voice note or snap a business card to start a referral.'],
            ['title' => 'Monthly credits included', 'text' => '{ai_free} AI credits per seat each month ({ai_premium} with premium).']]]]];
        $s['stats_network'] = ['Stats: the network', 'Proof', [['type' => 'stats', 'items' => [['value' => '{industries}', 'label' => 'industries'], ['value' => '{cities}', 'label' => 'US cities'], ['value' => '1', 'label' => 'verified pro per city, per industry']]]]];
        $s['testimonials'] = ['Testimonials (choose approved ones)', 'Proof', [['type' => 'testimonials', 'items' => []]]];
        // Fees, by tier
        $s['fees_open'] = ['Fees: how referral fees work (Tier A)', 'Fees', [['type' => 'faq', 'heading' => 'How referral fees work', 'items' => [
            ['q' => 'How is a referral fee worked out?', 'a' => 'A referral fee is a share of what the receiving team earns from the referral, such as their commission, agreed before the referral is sent. It is never a share of the sale price.'],
            ['q' => 'Who pays whom?', 'a' => 'The receiving team pays the referring team once the deal closes. The money goes directly between the two teams; {site_name} never holds it.'],
            ['q' => 'Is a fee required?', 'a' => 'No. Each network sets its default, and teams can agree a flat fee, a percentage or no fee where the rules allow.']]]]];
        $s['fees_restricted'] = ['Fees: no referral fees (Tier B)', 'Fees', [['type' => 'faq', 'heading' => 'No referral fees in this industry', 'items' => [
            ['q' => 'Can I pay or receive a fee for a referral here?', 'a' => 'No. In settlement-service industries such as mortgage, title and insurance, federal rules (RESPA Section 8) prohibit paying for referrals, so referrals are exchanged without any payment.'],
            ['q' => 'Then why refer?', 'a' => 'Clients get a verified pro they can trust, and members earn credits and recognition that show the value they give the network. Credits have no cash value.']]]]];
        $s['fees_directory'] = ['Fees: directory listing (Tier C)', 'Fees', [['type' => 'faq', 'heading' => 'How listings work in this industry', 'items' => [
            ['q' => 'How does a listing work?', 'a' => 'Pros in this industry appear in the directory for their city for a flat listing fee. Clients are not routed between members, and no referral fees apply.']]]]];
        $s['faq_fees'] = ['FAQ: are referral fees allowed?', 'Fees', [['type' => 'faq', 'heading' => 'Referral fees', 'items' => [
            ['q' => 'Are referral fees allowed?', 'a' => 'It depends on the industry. In most industries, teams may agree a referral fee: a share of what the receiving team earns from the referral, never of the sale price. In settlement-service industries such as mortgage, title and insurance, federal rules (RESPA Section 8) prohibit referral fees, so referrals are exchanged without payment. Directory industries pay only a flat listing fee.'],
            ['q' => 'Does {site_name} handle the fee?', 'a' => 'No. Any referral fee is paid directly between the two teams; {site_name} never holds referral money.']]]]];
        // Pricing
        $s['pricing_seat'] = ['Pricing: seat', 'Pricing', [['type' => 'pricing', 'heading' => 'Simple pricing', 'plans' => [
            ['name' => 'City seat', 'price' => 'From {seat_price}', 'period' => 'month', 'features' => "One city in one network\n{trial_months} months free to start\n{annual_months_free} months free when paid yearly\n{ai_free} AI credits a month", 'cta_label' => 'Claim your city', 'cta_url' => '/register']]]]];
        $s['pricing_addons'] = ['Pricing: premium and private networks', 'Pricing', [['type' => 'pricing', 'heading' => 'Optional extras', 'plans' => [
            ['name' => 'Premium', 'price' => '{premium_price}', 'period' => 'month per team', 'features' => '{ai_premium} AI credits a month per seat, instead of {ai_free}', 'cta_label' => '', 'cta_url' => ''],
            ['name' => 'Private network', 'price' => '{private_price}', 'period' => 'month per company', 'features' => "Your company’s own invite-only network\nYour own network rules", 'cta_label' => '', 'cta_url' => '']]]]];
        $s['value_guarantee'] = ['Value guarantee', 'Pricing', [['type' => 'cta', 'heading' => 'Our value guarantee', 'text' => 'Where the guarantee applies, any month in which no referral is offered to your seat is free. It doesn’t apply to directory listings.', 'label' => 'See pricing', 'url' => '/pricing']]];
        // FAQs
        $s['faq_general'] = ['FAQ: general', 'FAQ', [['type' => 'faq', 'heading' => 'Questions and answers', 'items' => [
            ['q' => 'What is {site_name}?', 'a' => 'A referral network that gives one verified pro per city a seat in each industry network, so members can send out-of-area clients to someone they trust and track the referral to the end.'],
            ['q' => 'Who can join?', 'a' => 'Pros in {industries} industries across the US. Licensed industries need a verified license before the seat is confirmed.'],
            ['q' => 'What does it cost?', 'a' => 'Seats start at {seat_price} a month per city, with {trial_months} months free to start.']]]]];
        $s['faq_pricing'] = ['FAQ: pricing', 'FAQ', [['type' => 'faq', 'heading' => 'Pricing questions', 'items' => [
            ['q' => 'Is there a free trial?', 'a' => 'Yes. New seats are free for {trial_months} months; you add a card before the trial ends.'],
            ['q' => 'Is there a discount for paying yearly?', 'a' => 'Yes: {annual_months_free} months free when you pay for a year.'],
            ['q' => 'What happens if I stop paying?', 'a' => 'We retry the payment, then give a 7-day grace period before the seat is paused; after 30 days the city is offered to the next pro on the waitlist.']]]]];
        $s['faq_privacy'] = ['FAQ: privacy and consent', 'FAQ', [['type' => 'faq', 'heading' => 'Privacy and consent', 'items' => [
            ['q' => 'When are client details shared?', 'a' => 'Only after the client agrees to be referred and the receiving pro accepts the referral.'],
            ['q' => 'Can a client see who is helping them?', 'a' => 'Yes. Clients get a private status link showing the pro they were referred to.']]]]];
        $s['faq_industry'] = ['FAQ: an industry', 'FAQ', [['type' => 'faq', 'heading' => '{industry} referrals: questions', 'items' => [
            ['q' => 'How do {industry} referrals work here?', 'a' => 'Each city has one verified {pro}. When your {client} needs help in another city, you send them to that {pro} with their consent and follow every step.'],
            ['q' => 'How is the {pro} chosen?', 'a' => 'The first verified {pro} to claim the city holds the seat, as long as they keep serving referrals well; others can join the waitlist.']]]]];
        // Calls to action
        $s['cta_reserve'] = ['CTA: reserve your city', 'Call to action', [['type' => 'cta', 'heading' => 'Your city is waiting', 'text' => 'One seat per city per industry. Claim yours while it’s open.', 'label' => 'Reserve your city', 'url' => '/reserve']]];
        $s['cta_contact'] = ['CTA: contact us', 'Call to action', [['type' => 'cta', 'heading' => 'Questions?', 'text' => 'We answer within one business day.', 'label' => 'Contact us', 'url' => '/contact']]];
        $s['cta_invite'] = ['CTA: invite a pro', 'Call to action', [['type' => 'cta', 'heading' => 'Know a great pro in another city?', 'text' => 'Invite them to claim their city, and earn a free month when they start referring.', 'label' => 'Join and invite', 'url' => '/register']]];
        // City and industry
        $s['industry_intro'] = ['Industry introduction', 'Industry and city', [['type' => 'rich_text', 'html' => '<p>The {industry} network connects one verified {pro} per {territory}, so {clients} who move or need help elsewhere get someone their {pro} trusts.</p>']]];
        $s['city_intro'] = ['City introduction', 'Industry and city', [['type' => 'rich_text', 'html' => '<p>Need a trusted {pro} in {city}? The {industry} network has one verified {pro} for {city}, chosen for how well they serve referrals.</p>']]];
        $s['illustration_network'] = ['Illustration: the network', 'Images', [['type' => 'media', 'image' => self::IMG.'network.svg', 'alt' => 'Connected cities on a map of the United States', 'caption' => '']]];

        return array_map(fn ($x) => ['name' => $x[0], 'group' => $x[1], 'blocks' => $x[2]], $s);
    }

    /**
     * The six page templates for an industry, with the fee section that matches its tier.
     *
     * @return array<string, array{title: string, slug: string, summary: string, sections: list<string>}>
     */
    public static function industryTemplates(Industry $i): array
    {
        $fees = match ($i->tier) {
            Tier::Open => 'fees_open', Tier::Restricted => 'fees_restricted', Tier::Directory => 'fees_directory'
        };
        $base = 'networks/'.$i->slug;

        return [
            'landing' => ['title' => '{industry} referral network', 'slug' => $base, 'summary' => '{site_name}’s {industry} network gives one verified {pro} per {territory} a seat, so {pros} can send out-of-area {clients} to someone they trust and track every referral.', 'sections' => ['hero_reserve', 'industry_intro', 'steps_how', $fees, 'faq_industry', 'cta_reserve']],
            'how' => ['title' => 'How {industry} referrals work', 'slug' => $base.'/how-it-works', 'summary' => 'A {pro} sends a {client} to the verified {pro} in another {territory} with the {client}’s consent; the receiving {pro} accepts, makes contact within the deadline and updates the sender until it closes.', 'sections' => ['steps_how', 'steps_client', 'features_trust', 'faq_industry']],
            'faq' => ['title' => '{industry} referrals: questions and answers', 'slug' => $base.'/faq', 'summary' => 'Answers to the questions {pros} ask most about the {industry} network: how seats work, consent, deadlines and the rules on referral fees.', 'sections' => ['faq_industry', $fees, 'faq_privacy', 'cta_contact']],
            'pricing' => ['title' => '{industry} network pricing', 'slug' => $base.'/pricing', 'summary' => 'A {industry} seat starts at {seat_price} a month per {territory}, with {trial_months} months free to start and {annual_months_free} months free when paid yearly.', 'sections' => ['pricing_seat', 'faq_pricing', 'cta_reserve']],
            'join' => ['title' => 'Join the {industry} network', 'slug' => $base.'/join', 'summary' => 'Claim the {industry} seat for your {territory}: create your team, verify your license if required, and complete the short training.', 'sections' => ['hero_join', 'steps_join', 'features_why', 'cta_reserve']],
            'city' => ['title' => '{industry} in {city}', 'slug' => $base.'/city-template', 'summary' => 'The verified {pro} for {city} in the {industry} network, chosen for how well they serve referrals.', 'sections' => ['city_intro', 'steps_client', 'cta_reserve']],
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    public static function blocks(array $keys): array
    {
        $all = self::all();
        $out = [];
        foreach ($keys as $k) {
            foreach ($all[$k]['blocks'] ?? [] as $b) {
                $out[] = $b;
            }
        }

        return $out;
    }
}
