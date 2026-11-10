<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Messaging\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;

/** Admin-edited wording with full history. The newest saved version becomes active; any older one can be restored. */
final class Templates
{
    /** @return array{subject: string, body: string, source: string} the industry's wording, else the platform's, else the catalogue default */
    public function wording(string $type, string $channel, ?int $industryId = null, ?int $groupId = null): array
    {
        if ($groupId !== null) {
            // Owners word a message once for email; in-app and push use the same words. Texts have their own.
            $g = DB::table('group_message_wording')->where(['group_id' => $groupId, 'type' => $type, 'channel' => $channel === 'sms' ? 'sms' : 'mail'])->first();
            if ($g !== null) {
                return ['subject' => (string) $g->subject, 'body' => (string) $g->body, 'source' => 'group'];
            }
        }
        foreach (array_filter([$industryId, 0], fn ($v) => $v !== null) as $scope) {
            $t = $this->scoped($type, $channel, $scope === 0 ? null : $scope)->where('is_active', true)->first();
            if ($t !== null) {
                return ['subject' => (string) $t->subject, 'body' => $t->body, 'source' => $scope === 0 ? 'platform' : 'industry'];
            }
        }
        $def = NotificationCatalogue::get($type);

        return ['subject' => $def['title'], 'body' => $channel === 'sms' ? $def['sms'] : $def['body'], 'source' => 'default'];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<NotificationTemplate> */
    public function scoped(string $type, string $channel, ?int $industryId)
    {
        return NotificationTemplate::query()->where(['type' => $type, 'channel' => $channel])
            ->when($industryId === null, fn ($q) => $q->whereNull('industry_id'), fn ($q) => $q->where('industry_id', $industryId));
    }

    public function save(string $type, string $channel, ?string $subject, string $body, ?int $userId, ?int $industryId = null): NotificationTemplate
    {
        return DB::transaction(function () use ($type, $channel, $subject, $body, $userId, $industryId) {
            $version = (int) $this->scoped($type, $channel, $industryId)->max('version') + 1;
            $this->scoped($type, $channel, $industryId)->update(['is_active' => false]);

            return NotificationTemplate::create(['type' => $type, 'channel' => $channel, 'industry_id' => $industryId, 'version' => $version, 'subject' => $subject, 'body' => $body, 'is_active' => true, 'created_by' => $userId]);
        });
    }

    public function activate(NotificationTemplate $template): void
    {
        DB::transaction(function () use ($template) {
            $this->scoped($template->type, $template->channel, $template->industry_id)->update(['is_active' => false]);
            // Written by query, not save(): a stale model that still thinks it is active would skip the update.
            NotificationTemplate::query()->whereKey($template->getKey())->update(['is_active' => true]);
            $template->is_active = true;
        });
    }

    /** Platform scope: back to the catalogue default. Industry scope: back to inheriting the platform wording. */
    public function reset(string $type, string $channel, ?int $industryId = null): void
    {
        $this->scoped($type, $channel, $industryId)->update(['is_active' => false]);
    }
}
