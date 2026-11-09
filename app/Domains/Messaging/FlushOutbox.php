<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Mail\PlatformMessage;
use App\Domains\Messaging\Models\OutboxItem;
use App\Support\Sms\SmsSender;
use Illuminate\Console\Command;

/** Runs every minute. Sends held email as one message per member (bundles and digests) and held texts after quiet hours. */
final class FlushOutbox extends Command
{
    protected $signature = 'notifications:flush';

    protected $description = 'Send bundled, digest and quiet-hours notifications that are due';

    public function handle(SmsSender $sms): int
    {
        $due = OutboxItem::query()->whereNull('sent_at')->where('deliver_after', '<=', now())->distinct()->pluck('user_id');
        foreach ($due as $userId) {
            $user = User::query()->find($userId);
            if (! $user instanceof User) {
                continue;
            }
            // Everything pending for this member goes in the same email, including items not yet due.
            $mail = OutboxItem::query()->where('user_id', $user->id)->whereNull('sent_at')->where('channel', 'mail')->orderBy('id')->get();
            if ($mail->isNotEmpty() && $mail->contains(fn (OutboxItem $i) => $i->deliver_after->lessThanOrEqualTo(now()))) {
                $items = array_values($mail->map(fn (OutboxItem $i) => ['title' => $i->title, 'body' => $i->body, 'url' => $i->url])->all());
                /** @var OutboxItem $first */
                $first = $mail->first();
                $user->notify(new PlatformMessage($first->type, $items, digest: $user->email_digest));
                OutboxItem::query()->whereIn('id', $mail->pluck('id'))->update(['sent_at' => now()]);
            }
            $texts = OutboxItem::query()->where('user_id', $user->id)->whereNull('sent_at')->where('channel', 'sms')->where('deliver_after', '<=', now())->get();
            foreach ($texts as $t) {
                if ($user->phone !== null) {
                    $sms->send($user->phone, $t->body);
                }
                $t->forceFill(['sent_at' => now()])->save();
            }
        }

        return self::SUCCESS;
    }
}
