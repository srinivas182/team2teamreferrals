<?php

declare(strict_types=1);

namespace App\Domains\Content\Pack;

/**
 * Starter help-centre articles, written to match how the platform works today. They install as drafts in the
 * "Help centre" category; the help centre itself (Sprint 48) will list them. {tokens} fill from live settings.
 */
final class HelpArticles
{
    /** @return list<array{slug: string, title: string, summary: string, body: string}> */
    public static function all(): array
    {
        return [
            ['slug' => 'help-getting-started', 'title' => 'Getting started',
                'summary' => 'To get started, create your account, verify your email, set up your team profile and claim the seat for your city in your industry network.',
                'body' => '<ol><li><strong>Create your account</strong> and verify your email address.</li><li><strong>Set up your team:</strong> name, logo, the cities you serve and, for licensed work, your license number. We check licenses before your profile goes live.</li><li><strong>Claim your seat:</strong> one team per city in each industry network.</li><li><strong>Complete the network training</strong> if your network uses it. New members have a grace period.</li><li><strong>Invite your team members</strong> so they can send and receive referrals too.</li></ol>'],
            ['slug' => 'help-claim-a-seat', 'title' => 'How do I claim a seat?',
                'summary' => 'Search your city in your industry; if the seat is open, reserve it and start your free trial. If it is taken, apply for the waitlist and we will tell you if it opens.',
                'body' => '<p>A seat gives your team the city in one industry network. Seats start at {seat_price} a month, with {trial_months} months free to start and {annual_months_free} months free when you pay yearly.</p><ul><li><strong>Open seat:</strong> reserve it, then confirm your details to start the trial.</li><li><strong>Taken seat:</strong> join the waitlist. If the seat opens, we tell you.</li></ul>'],
            ['slug' => 'help-send-a-referral', 'title' => 'How do I send a referral?',
                'summary' => 'Choose Send a referral, enter what the client needs and where, confirm the client agreed, and pick the receiving team for that city.',
                'body' => '<ol><li>Choose <strong>Send a referral</strong>.</li><li>Enter the client’s name, contact details and what they need, and the city.</li><li><strong>Confirm the client agreed</strong> to be referred. The client may also be asked to confirm by text or link.</li><li>Choose the receiving team. If the network allows fees, the agreed terms are shown before you send.</li></ol><p>The receiving team sees the request without the client’s contact details until they accept.</p>'],
            ['slug' => 'help-accept-or-decline', 'title' => 'How do I accept or decline a referral?',
                'summary' => 'Open the referral from your notification or inbox and choose Accept or Decline before the network’s deadline; a decline lets the sender choose someone else.',
                'body' => '<p>Each network sets how long you have to respond. You get a reminder before the deadline passes.</p><ul><li><strong>Accept:</strong> the client’s details are shared with you, and the first-contact clock starts.</li><li><strong>Decline:</strong> give a short reason. The sender can then choose another team.</li></ul><p>Fast, honest answers improve your trust score.</p>'],
            ['slug' => 'help-updates-and-closing', 'title' => 'How do updates and closing work?',
                'summary' => 'Post an update on the referral’s timeline at least as often as your network asks, then mark it won or lost when it finishes; the referring team confirms the outcome.',
                'body' => '<p>Every referral has a shared timeline. Add an update after each step: first contact, meeting, offer, and so on. Your network sets how often updates are due, and reminders arrive before they are late.</p><p>When the work ends, mark the referral <strong>won</strong> or <strong>lost</strong>. The referring team confirms it. If the two sides disagree, either can raise it with support.</p>'],
            ['slug' => 'help-fees-and-statements', 'title' => 'How do referral fees and statements work?',
                'summary' => 'Where a network allows fees, the fee is agreed before the referral is sent, as a share of the receiving team’s earnings, and teams pay each other directly; statements show what is due and paid.',
                'body' => '<ul><li>Fees exist only in industries and networks that allow them. In restricted industries, such as mortgage lending, referrals are exchanged without payment.</li><li>The fee is a share of what the receiving team earns from the referral, never a share of the sale price, and it is written into the referral agreement.</li><li>After closing, the receiving team pays the referring team directly and records the payment. Both sides see it on their statements.</li></ul>'],
            ['slug' => 'help-billing-and-trial', 'title' => 'Billing and your free trial',
                'summary' => 'Your trial lasts {trial_months} months; we remind you before it ends, and you can pay monthly or yearly.',
                'body' => '<ul><li>We remind you before your trial ends. No charge is taken during the trial.</li><li>Pay monthly, or yearly to get {annual_months_free} months free.</li><li>Your invoices are on the Billing page.</li></ul>'],
            ['slug' => 'help-notifications', 'title' => 'Notifications and quiet hours',
                'summary' => 'Choose how you hear about each kind of event (in the app, by email, by text or by push) and set quiet hours so non-urgent messages wait until morning.',
                'body' => '<p>Go to <strong>Settings, Notifications</strong>. For each kind of event, pick the channels you want. Quiet hours hold non-urgent messages until they end, in your own time zone. Deadline reminders always come before a deadline, not after.</p>'],
            ['slug' => 'help-privacy-and-consent', 'title' => 'Client privacy and consent',
                'summary' => 'Only refer clients who agreed to it; their contact details are shared only after the receiving team accepts.',
                'body' => '<ul><li>You confirm the client agreed before sending. The client can also confirm by text or link.</li><li>Contact details stay hidden until the referral is accepted.</li><li>Reports and exports never include client contact details.</li><li>Where AI features are on, client phone numbers and email addresses are masked before anything is sent to the AI provider.</li></ul>'],
        ];
    }
}
