<?php

declare(strict_types=1);

namespace App\Domains\Referrals\Intake;

use App\Domains\Messaging\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Forwarded lead emails: only from the member's registered email, to their signed intake address, each email once.
 * The full body is kept (a forwarded lead is the quoted part), turned into a draft, and the member is told it's ready.
 */
final class IntakeEmails
{
    public const CREDITS = 1;

    public function __construct(private readonly Extractor $extractor, private readonly Notifier $notifier) {}

    public function handle(string $to, string $from, string $subject, string $text, string $messageId): string
    {
        if (! app(\App\Domains\Platform\Modules\Modules::class)->enabled('ai_intake')) {
            return 'disabled';
        }
        $started = hrtime(true);
        $user = IntakeAddress::user($to);
        if ($user === null) {
            return 'unknown-address';
        }
        if (! preg_match('/[\w.+-]+@[\w.-]+/', strtolower($from), $m) || $m[0] !== strtolower($user->email)) {
            return 'unknown-sender';
        }
        $id = DB::table('intake_messages')->insertOrIgnore(['user_id' => $user->id, 'channel' => 'email', 'message_id' => mb_substr($messageId, 0, 255), 'subject' => mb_substr($subject, 0, 255),
            'body' => $text, 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
        if ($id !== 1) {
            return 'duplicate';
        }
        $intakeId = (int) DB::table('intake_messages')->where('message_id', mb_substr($messageId, 0, 255))->value('id');
        $ulid = $this->extractor->draft($user, $intakeId, trim($subject."\n\n".$text), self::CREDITS);
        DB::table('intake_messages')->where('id', $intakeId)->update(['status' => 'drafted', 'processing_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'updated_at' => now()]);
        $this->notifier->send($user, 'referral.draft_ready', ['headline' => 'Draft referral ready: check and send', 'body' => 'From your forwarded email'.($subject !== '' ? ' “'.mb_strimwidth($subject, 0, 60, '…').'”' : '').'. Nothing is sent until you confirm.'], url('/app/send?draft='.$ulid));

        return 'drafted';
    }

    /** Daily: unconfirmed drafts expire; the original email, audio and photos are deleted after 14 days. */
    public static function purge(): void
    {
        DB::table('referral_drafts')->where('status', 'pending')->where('expires_at', '<', now())->update(['status' => 'expired', 'updated_at' => now()]);
        $old = DB::table('intake_messages')->whereNull('purged_at')->where('created_at', '<', now()->subDays(Extractor::EXPIRES_DAYS))->get(['id', 'media_path']);
        foreach ($old as $m) {
            if ($m->media_path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete((string) $m->media_path);
            }
        }
        DB::table('intake_messages')->whereIn('id', $old->pluck('id'))->update(['body' => null, 'subject' => null, 'media_path' => null, 'purged_at' => now()]);
        // Chat conversations follow the same 14-day rule.
        DB::table('bot_messages')->where('created_at', '<', now()->subDays(Extractor::EXPIRES_DAYS))->delete();
    }
}
