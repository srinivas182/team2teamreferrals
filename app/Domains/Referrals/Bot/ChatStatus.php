<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Bot;

use App\Domains\Identity\Models\User;
use App\Domains\Platform\Deliveries\SmsOptOut;
use App\Domains\Platform\Settings\Settings;
use App\Support\Sms\SmsSender;
use App\Support\Sms\WhatsAppSender;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Referral status replies by WhatsApp or SMS, for members who choose them. Short client names only, never contact
 * details. WhatsApp: free text within 24 hours of the member's last message, otherwise a Meta-approved template (and
 * skipped if none is set up). Opt-outs, verified phones and the monthly cost cap are respected; every attempt is logged.
 */
final class ChatStatus
{
    /** Notification type => template setting */
    public const TYPES = ['referral.accepted' => 'template_accepted', 'referral.declined' => 'template_declined'];

    public function __construct(private readonly Settings $settings, private readonly SmsSender $sms, private readonly WhatsAppSender $whatsapp) {}

    /** @param array<string, mixed> $data the notification's data (client, city, team) */
    public function send(User $user, string $type, array $data): void
    {
        $channel = $user->chat_channel;
        if (! isset(self::TYPES[$type]) || ! in_array($channel, ['whatsapp', 'sms'], true) || $user->phone === null || $user->phone_verified_at === null) {
            return;
        }
        $log = fn (string $outcome, ?string $reason = null, string $kind = 'status', float $cost = 0) => DB::table('chat_deliveries')->insert([
            'user_id' => $user->id, 'channel' => $channel, 'kind' => $kind, 'type' => $type, 'cost_usd' => $cost, 'outcome' => $outcome, 'reason' => $reason, 'created_at' => now()]);
        if (SmsOptOut::query()->where('phone', $user->phone)->exists()) {
            $log('skipped', 'Opted out');

            return;
        }
        if (self::overCap($this->settings)) {
            $log('skipped', 'Monthly messaging cap reached');

            return;
        }
        $client = Bot::short((string) ($data['client'] ?? 'your client'));
        $city = (string) ($data['city'] ?? '');
        $team = (string) ($data['team'] ?? '');
        $text = $type === 'referral.accepted'
            ? "Your referral for {$client} in {$city} was accepted by {$team}."
            : "Your referral for {$client} in {$city} wasn’t accepted by {$team}. Open the app to see nearby pros.";
        $cost = (float) $this->settings->get('sms.twilio.cost_per_message', '0.01');
        try {
            if ($channel === 'sms') {
                $this->sms->send((string) $user->phone, $text.' Reply STOP to opt out.');
                $log('sent', null, 'status', $cost);

                return;
            }
            $open = DB::table('bot_sessions')->where(['user_id' => $user->id, 'channel' => 'whatsapp'])->where('last_inbound_at', '>=', now()->subHours(24))->exists();
            if ($open) {
                $this->whatsapp->send((string) $user->phone, $text);
                $log('sent', null, 'status', $cost);

                return;
            }
            $template = $this->settings->get('sms.twilio.'.self::TYPES[$type]);
            if (! is_string($template) || $template === '') {
                $log('skipped', 'No approved WhatsApp template');

                return;
            }
            $this->whatsapp->send((string) $user->phone, null, $template, ['1' => $client, '2' => $city, '3' => $team]);
            $log('sent', null, 'template', $cost);
        } catch (Throwable $e) {
            report($e);
            $log('skipped', mb_substr('Failed: '.$e->getMessage(), 0, 80));
        }
    }

    public static function spentThisMonth(): float
    {
        return (float) DB::table('chat_deliveries')->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
    }

    public static function overCap(Settings $settings): bool
    {
        $cap = (float) $settings->get('sms.twilio.monthly_cap', '0');

        return $cap > 0 && self::spentThisMonth() >= $cap;
    }
}
