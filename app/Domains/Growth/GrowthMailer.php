<?php

declare(strict_types=1);

namespace App\Domains\Growth;

use App\Domains\Content\Tokens;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\URL;

/**
 * Emails to prospects (people who aren't members yet). Every one follows the bulk-sender rules:
 * - it comes from the growth sending address (a separate subdomain, so member and referral email keep their
 *   reputation)
 * - it carries one-click unsubscribe (List-Unsubscribe with List-Unsubscribe-Post) and a visible unsubscribe link
 * - it shows the company's postal address
 * - it is never sent to anyone unsubscribed, bounced, complained or forgotten
 * Until an admin switches sending on (after the subdomain's DNS records are in place), nothing goes out and the
 * prospect's timeline says so.
 */
final class GrowthMailer
{
    public static function sendingOn(): bool
    {
        return app(Settings::class)->get('growth.sending', '0') === '1';
    }

    /** @return ?string why it can't send, or null when it can */
    public static function blocker(Prospect $p): ?string
    {
        return match (true) {
            $p->unsubscribed_at !== null || Prospects::suppressed($p->email) => 'They unsubscribed or can’t receive email.',
            ! self::sendingOn() => 'Not sent: prospect email is switched off (Admin → Growth).',
            trim(Tokens::company()['postal_address']) === '' => 'Not sent: add the company’s postal address in Admin → System → General (required in every email).',
            default => null,
        };
    }

    public static function unsubscribeUrl(Prospect $p): string
    {
        return URL::signedRoute('growth.unsubscribe', ['prospect' => $p->ulid]);
    }

    /**
     * Send one email. `{first_name}` and `{city}` in the text are filled in.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function send(Prospect $p, string $subject, string $text, ?string $ctaLabel, ?string $ctaUrl, string $kind, array $meta = []): bool
    {
        $blocked = self::blocker($p);
        if ($blocked !== null) {
            Prospects::log($p, null, 'email', "“{$subject}”. {$blocked}", [...$meta, 'kind' => $kind, 'sent' => false]);

            return false;
        }
        $fill = fn (string $s) => strtr($s, ['{first_name}' => $p->first_name ?: 'there', '{city}' => $p->city->name ?? 'your city']);
        (new AnonymousNotifiable)->route('mail', $p->email)->notify(new GrowthMessage($fill($subject), $fill($text), $ctaLabel, $ctaUrl, self::unsubscribeUrl($p)));
        Prospects::log($p, null, 'email', "“{$fill($subject)}”", [...$meta, 'kind' => $kind, 'sent' => true]);
        $p->forceFill(['last_activity_at' => now()])->save();

        return true;
    }
}
