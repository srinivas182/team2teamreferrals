<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Messaging\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;

/** Admin-edited wording with full history. The newest saved version becomes active; any older one can be restored. */
final class Templates
{
    /** @return array{subject: string, body: string} the active wording, or the catalogue default */
    public function wording(string $type, string $channel): array
    {
        $t = NotificationTemplate::query()->where(['type' => $type, 'channel' => $channel, 'is_active' => true])->first();
        if ($t !== null) {
            return ['subject' => (string) $t->subject, 'body' => $t->body];
        }
        $def = NotificationCatalogue::get($type);

        return ['subject' => $def['title'], 'body' => $channel === 'sms' ? $def['sms'] : $def['body']];
    }

    public function save(string $type, string $channel, ?string $subject, string $body, ?int $userId): NotificationTemplate
    {
        return DB::transaction(function () use ($type, $channel, $subject, $body, $userId) {
            $version = (int) NotificationTemplate::query()->where(['type' => $type, 'channel' => $channel])->max('version') + 1;
            NotificationTemplate::query()->where(['type' => $type, 'channel' => $channel])->update(['is_active' => false]);

            return NotificationTemplate::create(['type' => $type, 'channel' => $channel, 'version' => $version, 'subject' => $subject, 'body' => $body, 'is_active' => true, 'created_by' => $userId]);
        });
    }

    public function activate(NotificationTemplate $template): void
    {
        DB::transaction(function () use ($template) {
            NotificationTemplate::query()->where(['type' => $template->type, 'channel' => $template->channel])->update(['is_active' => false]);
            // Written by query, not save(): a stale model that still thinks it is active would skip the update.
            NotificationTemplate::query()->whereKey($template->getKey())->update(['is_active' => true]);
            $template->is_active = true;
        });
    }

    /** Back to the catalogue default: no version active. */
    public function reset(string $type, string $channel): void
    {
        NotificationTemplate::query()->where(['type' => $type, 'channel' => $channel])->update(['is_active' => false]);
    }
}
