<?php

declare(strict_types=1);

namespace App\Domains\Api;

use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referral;
use stdClass;

/**
 * How things look in the API. The same privacy rules as the app: the receiving team sees only the client's first
 * name and initial, and no contact details, until it accepts the referral.
 */
final class Resources
{
    /** @return array<string, mixed> */
    public static function referral(Referral $ref, Team $team): array
    {
        $ref->loadMissing(['group.industry', 'seat', 'sender', 'receiver']);
        $received = $ref->receiver_team_id === $team->id;
        $hide = $received && $ref->status !== 'accepted';
        $form = collect(app(IndustryTemplates::class)->resolve('form', $ref->group->industry, $ref->group_id)['body'])->keyBy('key');
        $stage = $ref->stage !== null ? app(Pipeline::class)->stage($ref) : null;
        $answers = [];
        foreach ((array) $ref->answers as $k => $v) {
            if (in_array($k, ['client_name', 'client_phone', 'client_email'], true)) {
                continue; // under "client"
            }
            $answers[$k] = $hide && ($form[$k]['sensitive'] ?? false) ? null : $v;
        }

        return [
            'id' => $ref->ulid,
            'object' => 'referral',
            'direction' => $received ? 'received' : 'sent',
            'status' => $ref->status,
            'group' => ['slug' => $ref->group->slug, 'name' => $ref->group->name],
            'territory' => $ref->seat->territory_name,
            'sender' => self::teamRef($ref->sender),
            'receiver' => $ref->receiver !== null ? self::teamRef($ref->receiver) : null,
            'routed_to' => $ref->routed_to,
            'client' => [
                'name' => $hide ? $ref->shortClientName() : $ref->client_name,
                'phone' => $hide ? null : $ref->client_phone,
                'email' => $hide ? null : $ref->client_email,
                'confirmed_at' => $ref->client_confirmed_at?->toIso8601String(),
            ],
            'answers' => $answers,
            'notes' => $hide && $ref->notes !== null ? Referral::maskContacts($ref->notes) : $ref->notes,
            'stage' => $stage !== null ? ['key' => $stage['key'], 'label' => $stage['label'], 'kind' => $stage['kind']] : null,
            'respond_by' => $ref->respond_by->toIso8601String(),
            'accepted_at' => $ref->accepted_at?->toIso8601String(),
            'decline_reason' => $ref->decline_reason,
            'closed_at' => $ref->closed_at?->toIso8601String(),
            'close_status' => $ref->close_status,
            'closing' => $ref->closing_date !== null ? ['date' => $ref->closing_date->toDateString(), 'value' => $ref->closing_value_cents !== null ? $ref->closing_value_cents / 100 : null] : null,
            'lost_reason' => $ref->lost_reason,
            'consent' => ['at' => $ref->consent_at->toIso8601String(), 'method' => $ref->consent_method],
            'sandbox' => $ref->group->is_sandbox,
            'created_at' => $ref->created_at->toIso8601String(),
            'updated_at' => $ref->updated_at?->toIso8601String(),
            'app_url' => url('/app/referrals/'.$ref->ulid),
        ];
    }

    /** @return array<string, mixed> */
    public static function lead(stdClass $l): array
    {
        return ['id' => (int) $l->id, 'object' => 'lead', 'status' => (string) $l->status, 'source' => (string) ($l->source ?? 'profile'),
            'referrer' => ['name' => (string) $l->referrer_name, 'email' => (string) $l->referrer_email, 'business' => $l->referrer_business],
            'client' => ['name' => (string) $l->client_name, 'phone' => $l->client_phone, 'email' => $l->client_email], 'need' => (string) $l->need,
            'consent_text' => (string) $l->consent_text, 'verified_at' => $l->verified_at !== null ? now()->parse((string) $l->verified_at)->toIso8601String() : null,
            'created_at' => now()->parse((string) $l->created_at)->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public static function webhookEndpoint(Webhooks\WebhookEndpoint $e): array
    {
        return ['id' => $e->ulid, 'object' => 'webhook_endpoint', 'url' => $e->url, 'description' => $e->description, 'mode' => $e->mode, 'events' => $e->events,
            'active' => $e->is_active, 'disabled_reason' => $e->disabled_reason, 'failing_since' => $e->failing_since?->toIso8601String(),
            'last_success_at' => $e->last_success_at?->toIso8601String(), 'created_at' => $e->created_at->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public static function webhookDelivery(Webhooks\WebhookDelivery $d): array
    {
        return ['id' => $d->ulid, 'object' => 'webhook_delivery', 'event_id' => $d->event_id, 'event_type' => $d->event_type, 'status' => $d->status, 'attempts' => $d->attempts,
            'next_attempt_at' => $d->next_attempt_at?->toIso8601String(), 'response_status' => $d->response_status, 'response_body' => $d->response_body, 'error' => $d->error,
            'duration_ms' => $d->duration_ms, 'delivered_at' => $d->delivered_at?->toIso8601String(), 'created_at' => $d->created_at->toIso8601String(),
            'payload' => json_decode($d->payload, true)];
    }

    /** @return array{name: string, profile: ?string} */
    public static function teamRef(Team $team): array
    {
        return ['name' => (string) $team->name, 'profile' => $team->profile_public && $team->slug ? url('/t/'.$team->slug) : null];
    }

    /**
     * A list page: items plus the cursor for the next page.
     *
     * @param  \Illuminate\Contracts\Pagination\CursorPaginator<int, mixed>  $page
     * @param  list<array<string, mixed>>  $items
     * @return array{object: string, data: list<array<string, mixed>>, has_more: bool, next_cursor: ?string}
     */
    public static function page($page, array $items): array
    {
        return ['object' => 'list', 'data' => $items, 'has_more' => $page->hasMorePages(), 'next_cursor' => $page->nextCursor()?->encode()];
    }
}
