<?php

declare(strict_types=1);

namespace App\Domains\Referrals;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a reply email into a referral message. Accepted only for a validly signed reply address, from the
 * member's own registered email, on a referral they can still see; quoted history is stripped; each email once.
 */
final class InboundReplies
{
    public function __construct(private readonly Referrals $referrals) {}

    public function handle(string $to, string $from, string $text, string $messageId): string
    {
        if (DB::table('inbound_emails')->where('message_id', $messageId)->exists()) {
            return 'duplicate';
        }
        $status = $this->process($to, $from, $text);
        DB::table('inbound_emails')->insertOrIgnore(['message_id' => mb_substr($messageId, 0, 255), 'status' => $status, 'received_at' => now()]);

        return $status;
    }

    public static function strip(string $text): string
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $t = trim($line);
            // Stop at the quoted history or signature most mail apps add.
            if (preg_match('/^(On .+wrote:|-{2,}\s*Original Message|From: .+|Sent from my |--\s*$|_{5,})/i', $t)) {
                break;
            }
            if (str_starts_with($t, '>')) {
                continue;
            }
            $out[] = rtrim($line);
        }

        return trim(implode("\n", $out));
    }

    private function process(string $to, string $from, string $text): string
    {
        $who = ReplyAddress::parse($to);
        if ($who === null) {
            return 'bad_address';
        }
        $user = User::query()->find($who['user']);
        $ref = Referral::query()->find($who['referral']);
        if ($user === null || $ref === null || ! $user->is_active) {
            return 'unknown';
        }
        // Only from the member's registered address (a forwarded or spoofed reply is ignored).
        if (! preg_match('/[\w.+-]+@[\w.-]+/', strtolower($from), $m) || $m[0] !== strtolower($user->email)) {
            return 'wrong_sender';
        }
        if (! $this->referrals->canView($ref, $user)) {
            return 'no_access';
        }
        $body = self::strip($text);
        if ($body === '') {
            return 'empty';
        }
        $this->referrals->message($ref, $user, mb_substr($body, 0, 4000));

        return 'posted';
    }
}
