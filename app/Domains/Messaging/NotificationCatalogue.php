<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use InvalidArgumentException;

/**
 * Every notification the platform sends, defined once: its group, default channels,
 * whether it is urgent (ignores quiet hours, never bundled) and whether members may
 * turn it off. Default wording lives here; admins override it with versioned templates.
 *
 * Merge fields: {pro}, {pros}, {client_term}, {clients_term}, {referral_term}, {fee_term} (the industry's own words), {first_name}, {client}, {city}, {industry}, {team}, {hours}, {days}, {month}, {next_month}, {date}, {device}.
 */
final class NotificationCatalogue
{
    public const CHANNELS = ['mail' => 'Email', 'sms' => 'Text', 'database' => 'In-app', 'push' => 'Push'];

    /**
     * @var array<string, array{group: string, label: string, description: string, channels: list<string>, urgent: bool, locked: bool, title: string, body: string, sms: string}>
     */
    public const TYPES = [
        'referral.waiting' => ['group' => 'Referrals', 'label' => 'New referral waiting for you', 'description' => 'A member sent you a client and the clock is running.', 'channels' => ['mail', 'sms', 'database', 'push'], 'urgent' => true, 'locked' => false,
            'title' => 'New {referral_term}: {client} in {city}', 'body' => '{team} sent you a {client_term} in {city}. Accept or decline within {hours} hours.', 'sms' => 'New t2t {referral_term}: {client} in {city}. Respond within {hours}h: {url}'],
        'referral.deadline' => ['group' => 'Referrals', 'label' => 'Deadline reminders', 'description' => 'Before a response, first contact or update is due.', 'channels' => ['mail', 'sms', 'database', 'push'], 'urgent' => true, 'locked' => false,
            'title' => '{hours} hours left to respond', 'body' => 'Your {referral_term} for {client} in {city} needs a response within {hours} hours.', 'sms' => 't2t: {hours}h left on the {client} {referral_term}. {url}'],
        'referral.accepted' => ['group' => 'Referrals', 'label' => 'Your referral was accepted', 'description' => 'When a pro accepts a client you sent.', 'channels' => ['mail', 'database', 'push'], 'urgent' => false, 'locked' => false,
            'title' => '{team} accepted {client}', 'body' => '{team} in {city} accepted your {referral_term} and will contact {client} shortly.', 'sms' => ''],
        'referral.declined' => ['group' => 'Referrals', 'label' => 'Your referral was declined', 'description' => 'When a pro declines, with their reason.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{team} declined {client}', 'body' => '{team} declined the {referral_term}. It has moved to the backup {pro} in {city}.', 'sms' => ''],
        'referral.agreement' => ['group' => 'Referrals', 'label' => 'Referral agreements', 'description' => 'When an agreement is ready to sign and when both sides have signed.', 'channels' => ['mail', 'database', 'push'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'referral.message' => ['group' => 'Referrals', 'label' => 'Messages on a referral', 'description' => 'When the other team sends you a message about a referral.', 'channels' => ['mail', 'database', 'push'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'referral.nurture' => ['group' => 'Referrals', 'label' => 'Future lead check-ins', 'description' => 'A monthly prompt for each long-term lead you’re nurturing.', 'channels' => ['mail', 'database', 'push'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'referral.update' => ['group' => 'Referrals', 'label' => 'Progress updates', 'description' => 'Stage changes and notes on referrals you sent.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => 'Update on {client}', 'body' => '{team}: {client} is now at “{stage}”. {note}', 'sms' => ''],
        'seat.offered' => ['group' => 'Network', 'label' => 'A seat opened for you', 'description' => 'A city you are waitlisted for became available.', 'channels' => ['mail', 'sms', 'database'], 'urgent' => true, 'locked' => false,
            'title' => '{city} is available to you', 'body' => 'The {industry} seat in {city} is yours to claim for the next {hours} hours.', 'sms' => 't2t: the {city} seat is available to you for {hours}h. Claim it: {url}'],
        'seat.decision' => ['group' => 'Network', 'label' => 'Your seat applications', 'description' => 'Approved, waitlisted or declined, and when you claim a seat.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => true,
            'title' => '{group}: {headline}', 'body' => '{body}', 'sms' => ''],
        'seat.payment' => ['group' => 'Billing', 'label' => 'Seat at risk', 'description' => 'When a payment problem puts your seat into grace, makes it inactive or releases it.', 'channels' => ['mail', 'sms', 'database'], 'urgent' => true, 'locked' => true,
            'title' => '{headline}', 'body' => '{body}', 'sms' => 't2t: {headline}. {url}'],
        'seat.update' => ['group' => 'Network', 'label' => 'Seat activity in groups you run', 'description' => 'For group owners and platform owners: applications, claims, grace, inactive and released seats.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{group}: {headline}', 'body' => '{body}', 'sms' => ''],
        'license.update' => ['group' => 'Account and security', 'label' => 'License verification and expiry', 'description' => 'When a license is verified or rejected, and before it expires.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => true,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'onboarding.nudge' => ['group' => 'Network', 'label' => 'Getting started reminders', 'description' => 'A nudge in your first week if you haven’t applied for a seat yet.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'training.reminder' => ['group' => 'Network', 'label' => 'Training reminders', 'description' => 'A nudge to finish the network training, from the platform or your team leader.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'network.report_card' => ['group' => 'Network', 'label' => 'Monthly report card', 'description' => 'Your month on the network: referrals, response times and your service score.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'network.score' => ['group' => 'Network', 'label' => 'Service score alerts', 'description' => 'A heads-up when your service score dips, with what would help most.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => true,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'seat.challenge' => ['group' => 'Seats', 'label' => 'Seat challenges', 'description' => 'When your seat is challenged, its progress and outcome, and appeals.', 'channels' => ['mail', 'database', 'push'], 'urgent' => true, 'locked' => true,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'profile.lead' => ['group' => 'Referrals', 'label' => 'Leads from your profile', 'description' => 'When someone outside the network refers a client from your public profile.', 'channels' => ['mail', 'database', 'push'], 'urgent' => true, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'network.invite' => ['group' => 'Network', 'label' => 'Invite rewards', 'description' => 'When someone you invited is up and running, and what you earned.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'feed.mention' => ['group' => 'Community', 'label' => 'Mentions', 'description' => 'When someone mentions your team in the feed.', 'channels' => ['mail', 'database', 'push'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'feed.reply' => ['group' => 'Community', 'label' => 'Replies', 'description' => 'Replies to your posts, and requests to share a win.', 'channels' => ['mail', 'database', 'push'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'feed.digest' => ['group' => 'Community', 'label' => 'Feed digest', 'description' => 'What happened in your network’s feed, daily or weekly (your choice).', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'feed.moderation' => ['group' => 'Community', 'label' => 'Moderation', 'description' => 'For network owners: items hidden after reports.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'network.recap' => ['group' => 'Network', 'label' => 'Monthly recap', 'description' => 'Your network’s month on the 1st: activity, the leaderboard and your badges.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'event.reminder' => ['group' => 'Community', 'label' => 'Event reminders', 'description' => 'A day and an hour before events you’re going to, in your time zone.', 'channels' => ['mail', 'database', 'push'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'event.update' => ['group' => 'Community', 'label' => 'Event changes', 'description' => 'When an event you’re going to changes or is cancelled, or a place opens up.', 'channels' => ['mail', 'database', 'push'], 'urgent' => false, 'locked' => true,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'network.insights' => ['group' => 'Network', 'label' => 'Weekly insights', 'description' => 'For network owners: cities to strengthen, teams to check in with, rising members.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'referral.draft_ready' => ['group' => 'Referrals', 'label' => 'Draft ready', 'description' => 'When a forwarded email, voice note or photo has been turned into a draft referral.', 'channels' => ['mail', 'database', 'push'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'admin.contact' => ['group' => 'Admin', 'label' => 'Messages from the Contact page', 'description' => 'When someone writes through the website.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'network.checkin' => ['group' => 'Network', 'label' => 'Check-ins from your network owner', 'description' => 'A friendly note when deadlines slip.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{headline}', 'body' => '{body}', 'sms' => ''],
        'network.announcement' => ['group' => 'Network', 'label' => 'Network announcements', 'description' => 'News from your network owner.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => 'News from your network', 'body' => '{body}', 'sms' => ''],
        'billing.trial_ending' => ['group' => 'Billing', 'label' => 'Trial ending', 'description' => '14, 7 and 1 days before a seat’s free trial ends.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => true,
            'title' => 'Your free trial for {city} ends in {days} days', 'body' => 'Your {industry} seat in {city} moves to the monthly price on {date}. Any month no referral reaches you stays free.', 'sms' => ''],
        'billing.free_month' => ['group' => 'Billing', 'label' => 'Free month credited', 'description' => 'When a month is free because no referral reached your seat.', 'channels' => ['mail', 'database'], 'urgent' => false, 'locked' => false,
            'title' => '{next_month} is on us', 'body' => 'No referrals reached your {city} seat in {month}, so {next_month} is free. You never pay for a quiet month.', 'sms' => ''],
        'billing.payment_failed' => ['group' => 'Billing', 'label' => 'Payment problems', 'description' => 'When a card payment fails.', 'channels' => ['mail', 'sms', 'database'], 'urgent' => true, 'locked' => true,
            'title' => 'Your payment didn’t go through', 'body' => 'Update your card to keep your seats active. We’ll retry automatically.', 'sms' => 't2t: your payment failed. Update your card to keep your seats: {url}'],
        'account.security' => ['group' => 'Account and security', 'label' => 'Security alerts', 'description' => 'New sign-ins, password and two-step changes.', 'channels' => ['mail'], 'urgent' => true, 'locked' => true,
            'title' => 'Security alert for your account', 'body' => '{body}', 'sms' => ''],
    ];

    /** @return array{group: string, label: string, description: string, channels: list<string>, urgent: bool, locked: bool, title: string, body: string, sms: string} */
    public static function get(string $type): array
    {
        return self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown notification type {$type}");
    }

    /** @param array<string, scalar|null> $data */
    public static function render(string $text, array $data): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $data) ? (string) $data[$m[1]] : $m[0], $text);
    }
}
