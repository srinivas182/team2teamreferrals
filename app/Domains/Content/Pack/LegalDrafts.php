<?php

declare(strict_types=1);

namespace App\Domains\Content\Pack;

/**
 * Plain-English drafts of the Terms of service and Privacy policy, written to match how the platform works. They
 * install as DRAFTS only, never published: the owner's attorney reads and edits them first. Items in [brackets]
 * are for the owner or attorney to fill in.
 */
final class LegalDrafts
{
    /** @return array<string, array{title: string, summary: string, html: string}> */
    public static function all(): array
    {
        return [
            'terms' => ['title' => 'Terms of service',
                'summary' => 'These terms explain who can use {site_name}, how seats and billing work, the rules for sending and receiving referrals, and how referral fees are handled between members.',
                'html' => '<p><strong>Draft for attorney review.</strong> Effective date: [date]. These terms are an agreement between you and [Company legal name] (“we”), which runs {site_name}.</p>'
                    .'<h2>1. Who can use {site_name}</h2><p>You must be at least 18 and a business or professional in one of the industries we serve. Where your work needs a license, you must hold an active one in the states you serve and keep it current. We may verify your license and identity, and we may refuse or remove members who do not meet these terms.</p>'
                    .'<h2>2. Your account and team</h2><p>Keep your sign-in details safe and turn on two-step sign-in where offered. You are responsible for what happens in your account and for the team members you invite.</p>'
                    .'<h2>3. Seats, trials and billing</h2><p>A seat gives one team a city in one industry network. New seats start with a free trial of {trial_months} months; we remind you before it ends. After the trial, seats are billed monthly at the price shown on our pricing page, or yearly with {annual_months_free} months free. Prices can change with at least [30] days’ notice. [Cancellation and refund terms to be confirmed.]</p>'
                    .'<h2>4. Referral rules</h2><ul><li>Only refer a client who has agreed to be referred, and only share what they agreed to share.</li><li>Respond, contact clients and post updates within the deadlines your network sets.</li><li>Report outcomes honestly. Trust scores are based on what members record, and false records may lead to removal.</li><li>Use client details only to serve that client, never for unrelated marketing.</li></ul>'
                    .'<h2>5. Referral fees</h2><p>Referral fees are only available in industries and networks where they are allowed by law and by the network’s rules. A fee is always a share of the receiving party’s earnings from the referral (or a flat amount), is agreed in the referral agreement before the client is sent, and is paid directly between the members. We are not a party to that agreement unless we say so in writing. In restricted industries, including mortgage lending and settlement services, no fee or other thing of value may be given for a referral. Each member is responsible for following the laws and license rules that apply to them.</p>'
                    .'<h2>6. Content you add</h2><p>You keep ownership of what you post. You give us permission to store, display and process it to run {site_name}. Do not post anything unlawful, misleading or that you have no right to share. We may remove content that breaks these terms.</p>'
                    .'<h2>7. Optional features</h2><p>Some features, such as AI assistance and training videos, can be turned on or off by us or your network. AI suggestions may be wrong; check them before you rely on them.</p>'
                    .'<h2>8. Our service</h2><p>We work to keep {site_name} available and secure but cannot promise it will always be uninterrupted. We do not guarantee any number of referrals or any result from a referral.</p>'
                    .'<h2>9. Ending your membership</h2><p>You can close your account at any time. We may suspend or end an account that breaks these terms, after notice where reasonable.</p>'
                    .'<h2>10. Limits of liability</h2><p>[Disclaimer, limitation of liability and indemnity wording to be supplied by counsel.]</p>'
                    .'<h2>11. Disputes and governing law</h2><p>These terms are governed by the laws of [State]. [Dispute resolution wording to be supplied by counsel.]</p>'
                    .'<h2>12. Changes and contact</h2><p>If we change these terms, we will post the new version with its effective date and tell members of important changes in advance. Questions: [contact email], [postal address].</p>'],
            'privacy' => ['title' => 'Privacy policy',
                'summary' => 'This policy explains what personal information {site_name} collects about members and their clients, why we use it, who we share it with, and the choices you have.',
                'html' => '<p><strong>Draft for attorney review.</strong> Effective date: [date]. This policy explains how [Company legal name] (“we”) handles personal information on {site_name}.</p>'
                    .'<h2>1. What we collect</h2><ul><li><strong>Members:</strong> name, email, phone, business details, license numbers, profile content, billing details (card payments are handled by our payment processor; we do not store full card numbers) and how you use the service.</li><li><strong>Clients being referred:</strong> the name, contact details and needs a member enters, the client’s consent, and the referral’s progress.</li><li><strong>Visitors:</strong> messages sent through our forms, and basic technical data such as browser type and pages visited.</li></ul>'
                    .'<h2>2. How we use it</h2><p>To run the service: verify members, deliver referrals and notifications, keep the shared timeline, calculate trust scores, bill seats, provide support, keep the service secure and prevent misuse, and improve it using summary statistics.</p>'
                    .'<h2>3. How client details are protected</h2><ul><li>A referral is sent only after the referring member confirms the client agreed; the client may also confirm directly.</li><li>The receiving member sees the client’s contact details only after accepting the referral.</li><li>Reports and exports do not include client contact details.</li><li>Our staff can view full client details only for a support case, with a recorded reason.</li></ul>'
                    .'<h2>4. Who we share it with</h2><p>With other members as needed for a referral you send or accept, and with service providers that help us run {site_name}: hosting, email and text-message delivery, payment processing, and, where those features are switched on, AI and video providers. Before text is sent to an AI provider, phone numbers and email addresses are masked. We do not sell personal information. [List of providers and any “sharing” disclosures under state law to be confirmed by counsel.] We may disclose information where the law requires it.</p>'
                    .'<h2>5. Messages</h2><p>We send account and referral messages by email, text and in-app notification. Members choose which messages they receive and can set quiet hours. [Text-message consent and opt-out wording to be confirmed by counsel.]</p>'
                    .'<h2>6. How long we keep it</h2><p>We keep information while an account is active and as needed for referrals, billing records and legal obligations, then delete or anonymize it. [Retention periods to be confirmed.]</p>'
                    .'<h2>7. Security</h2><p>We use encryption in transit, access controls, two-step sign-in, activity logs and regular backups. No system is perfectly secure; tell us at once if you suspect a problem.</p>'
                    .'<h2>8. Your choices and rights</h2><p>You can view and update your profile, change notification settings and ask us to close your account. Depending on where you live, you may have rights to access, correct, delete or obtain a copy of your information. Contact us to make a request. [State-specific rights wording to be supplied by counsel.]</p>'
                    .'<h2>9. Children</h2><p>{site_name} is for professionals and is not directed to anyone under 18.</p>'
                    .'<h2>10. Changes and contact</h2><p>We will post changes here with a new effective date and tell members of important changes. Questions: [contact email], [postal address].</p>'],
        ];
    }
}
