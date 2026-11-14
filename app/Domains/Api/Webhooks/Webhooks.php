<?php

declare(strict_types=1);

namespace App\Domains\Api\Webhooks;

use App\Domains\Api\ApiAccess;
use App\Domains\Api\Resources;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Referral;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Signed outgoing webhooks (Sprint 44b).
 *
 * - Every change on a referral's timeline (and every verified profile lead) becomes an event for each team involved,
 *   built at that moment with the same privacy rules as the API (a receiver sees the client's contact details only
 *   once it has accepted).
 * - Each POST carries `T2T-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">`. For 24 hours after a secret
 *   is rotated, a second v1 signed with the old secret is included, so receivers can switch without missing events.
 * - Failed attempts are retried for about 24 hours (1 min, 5 min, 30 min, 2 h, 5 h, 10 h, 6 h). An endpoint that has
 *   failed for 3 days in a row is switched off and the team is told. Any delivery can be sent again by hand.
 * - Only public https addresses are allowed, checked when added and again on every attempt (the checked address is
 *   the one connected to), so a webhook can't be pointed at the platform's own network.
 * - Test-mode endpoints get the sandbox's events; live endpoints get real ones.
 */
final class Webhooks
{
    /** Endpoints per team without and with the API plan. */
    public const BASE_ENDPOINTS = 1;

    public const PLAN_ENDPOINTS = 10;

    /** Seconds to wait after attempt N before the next one (about 24 hours in all). */
    public const RETRY_AFTER = [1 => 60, 2 => 300, 3 => 1800, 4 => 7200, 5 => 18000, 6 => 36000, 7 => 21600];

    public const MAX_ATTEMPTS = 8;

    public const DISABLE_AFTER_DAYS = 3;

    /** Deliveries and request logs are kept this long. */
    public const KEEP_DAYS = 30;

    public const SECRET_OVERLAP_HOURS = 24;

    /** @var (Closure(string): list<string>)|null */
    private static ?Closure $resolver = null;

    /** Tests: decide what a host name resolves to. */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function limit(Team $team): int
    {
        return ApiAccess::hasPlan($team) ? self::PLAN_ENDPOINTS : self::BASE_ENDPOINTS;
    }

    /**
     * @param  list<string>  $events  event types, or ['*'] for all
     * @return array{endpoint: WebhookEndpoint, secret: string}
     */
    public static function create(Team $team, User $user, string $url, string $mode, array $events, ?string $description): array
    {
        if (WebhookEndpoint::query()->where('team_id', $team->id)->count() >= self::limit($team)) {
            throw ValidationException::withMessages(['url' => self::limit($team) === self::BASE_ENDPOINTS
                ? 'Your team can have 1 webhook endpoint. The API plan allows up to '.self::PLAN_ENDPOINTS.' (Billing).'
                : 'Your team already has '.self::PLAN_ENDPOINTS.' webhook endpoints, the most allowed. Delete one first.']);
        }
        self::checkUrl($url, 'url');
        $secret = self::newSecret();
        $endpoint = WebhookEndpoint::create(['team_id' => $team->id, 'created_by' => $user->id, 'url' => $url, 'description' => $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 120) : null,
            'mode' => $mode, 'events' => self::events($events), 'secret' => $secret, 'is_active' => true]);
        activity('api')->causedBy($user)->performedOn($team)->event('webhook-created')->withProperties(['endpoint' => $endpoint->ulid, 'url' => $url, 'mode' => $mode])->log('Webhook endpoint added: '.$url);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    /** @param array{url?: string, events?: list<string>, description?: ?string, active?: bool} $changes */
    public static function update(WebhookEndpoint $endpoint, User $user, array $changes): WebhookEndpoint
    {
        if (isset($changes['url']) && $changes['url'] !== $endpoint->url) {
            self::checkUrl($changes['url'], 'url');
            $endpoint->url = $changes['url'];
        }
        if (isset($changes['events'])) {
            $endpoint->events = self::events($changes['events']);
        }
        if (array_key_exists('description', $changes)) {
            $endpoint->description = $changes['description'] !== null && trim($changes['description']) !== '' ? mb_substr(trim($changes['description']), 0, 120) : null;
        }
        if (isset($changes['active'])) {
            $endpoint->is_active = $changes['active'];
            // Switching back on starts afresh: the failure clock and the switched-off note are cleared.
            if ($changes['active']) {
                $endpoint->forceFill(['failing_since' => null, 'disabled_at' => null, 'disabled_reason' => null]);
            }
        }
        $endpoint->save();
        activity('api')->causedBy($user)->performedOn($endpoint->team)->event('webhook-updated')->withProperties(['endpoint' => $endpoint->ulid, 'changes' => array_keys($changes)])->log('Webhook endpoint changed: '.$endpoint->url);

        return $endpoint;
    }

    /** A new secret; the old one keeps signing too for 24 hours. */
    public static function rotate(WebhookEndpoint $endpoint, User $user): string
    {
        $secret = self::newSecret();
        $endpoint->forceFill(['previous_secret' => $endpoint->secret, 'previous_secret_expires_at' => now()->addHours(self::SECRET_OVERLAP_HOURS), 'secret' => $secret])->save();
        activity('api')->causedBy($user)->performedOn($endpoint->team)->event('webhook-secret-rotated')->withProperties(['endpoint' => $endpoint->ulid])->log('Webhook signing secret rotated: '.$endpoint->url);

        return $secret;
    }

    public static function delete(WebhookEndpoint $endpoint, User $user): void
    {
        activity('api')->causedBy($user)->performedOn($endpoint->team)->event('webhook-deleted')->withProperties(['endpoint' => $endpoint->ulid, 'url' => $endpoint->url])->log('Webhook endpoint deleted: '.$endpoint->url);
        $endpoint->delete();
    }

    /** A timeline entry on a referral: one event per team involved, to that team's subscribed endpoints. */
    public static function referralEvent(Referral $ref, string $timelineEvent): void
    {
        $type = WebhookEvents::FROM_TIMELINE[$timelineEvent] ?? null;
        if ($type === null) {
            return;
        }
        $ref = $ref->fresh() ?? $ref;
        foreach (array_unique(array_filter([$ref->sender_team_id, $ref->receiver_team_id])) as $teamId) {
            $team = Team::query()->find($teamId);
            if ($team !== null) {
                self::emit($team, $type, fn () => ['object' => Resources::referral($ref, $team)]);
            }
        }
    }

    /** A profile lead was verified. */
    public static function leadReceived(int $leadId): void
    {
        $lead = DB::table('profile_leads')->where('id', $leadId)->first();
        $team = $lead !== null ? Team::query()->find((int) $lead->team_id) : null;
        if ($lead !== null && $team !== null) {
            self::emit($team, 'lead.received', fn () => ['object' => Resources::lead($lead)]);
        }
    }

    /** "Send test event": a ping to one endpoint, whatever it subscribes to. */
    public static function ping(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $id = 'evt_'.strtolower((string) Str::ulid());
        $delivery = self::queue($endpoint, $id, WebhookEvents::PING, self::envelope($endpoint->team, WebhookEvents::PING, $id, $endpoint->mode === 'test',
            ['object' => ['message' => 'A test event from Team to Team Referrals. If you can read this, your endpoint works.', 'endpoint' => $endpoint->ulid]]));

        return $delivery->refresh();
    }

    /** Send an earlier event again (same event id, so receivers that already have it can ignore it). */
    public static function resend(WebhookDelivery $delivery): WebhookDelivery
    {
        $endpoint = WebhookEndpoint::query()->findOrFail($delivery->webhook_endpoint_id); // as it is now
        if (! $endpoint->is_active) {
            throw ValidationException::withMessages(['delivery' => 'This endpoint is switched off. Switch it on first.']);
        }

        return self::queue($endpoint, $delivery->event_id, $delivery->event_type, $delivery->payload)->refresh();
    }

    /** One attempt at one delivery (run by DeliverWebhook). Safe if run twice: only one run claims it. */
    public static function deliver(int $deliveryId): void
    {
        $claimed = DB::table('webhook_deliveries')->where('id', $deliveryId)->whereIn('status', ['pending', 'retrying'])->update(['status' => 'sending', 'updated_at' => now()]);
        $d = $claimed === 1 ? WebhookDelivery::query()->with('endpoint')->find($deliveryId) : null;
        if ($d === null) {
            return;
        }
        $endpoint = $d->endpoint;
        if (! $endpoint->is_active) {
            $d->forceFill(['status' => 'failed', 'error' => 'The endpoint was switched off.', 'next_attempt_at' => null])->save();

            return;
        }
        $timestamp = now()->getTimestamp();
        $started = hrtime(true);
        $status = null;
        $body = null;
        $error = null;
        try {
            [$host, $port, $ip] = self::checkUrl($endpoint->url, 'url');
            $response = Http::withHeaders([
                'User-Agent' => 'TeamToTeamReferrals-Webhooks/1.0 (+'.url('/developers').')',
                'T2T-Signature' => self::signatureHeader($endpoint, $timestamp, $d->payload),
                'T2T-Event-Id' => $d->event_id, 'T2T-Event-Type' => $d->event_type, 'T2T-Delivery-Id' => $d->ulid,
            ])->withBody($d->payload, 'application/json')->timeout(10)->connectTimeout(5)->withoutRedirecting()
                // Connect to the address that was checked, so DNS can't be switched between the check and the request.
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]]])
                ->post($endpoint->url);
            $status = $response->status();
            $body = mb_substr($response->body(), 0, 1000);
            if (! $response->successful()) {
                $error = 'Your endpoint answered '.$status.' (any 2xx counts as received).';
            }
        } catch (ValidationException $e) {
            $error = (string) collect($e->errors())->flatten()->first();
        } catch (ConnectionException $e) {
            $error = 'Couldn’t connect or no answer within 10 seconds.';
        } catch (Throwable $e) {
            report($e);
            $error = 'The request couldn’t be sent.';
        }
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);
        $attempt = $d->attempts + 1;
        $d->forceFill(['attempts' => $attempt, 'response_status' => $status, 'response_body' => $body, 'error' => $error === null ? null : mb_substr($error, 0, 255), 'duration_ms' => $ms]);
        if ($error === null) {
            $d->forceFill(['status' => 'succeeded', 'delivered_at' => now(), 'next_attempt_at' => null])->save();
            $endpoint->forceFill(['last_success_at' => now(), 'failing_since' => null])->save();

            return;
        }
        $last = $attempt >= self::MAX_ATTEMPTS;
        $d->forceFill(['status' => $last ? 'failed' : 'retrying', 'next_attempt_at' => $last ? null : now()->addSeconds(self::RETRY_AFTER[$attempt])])->save();
        $endpoint->failing_since ??= now();
        $endpoint->save();
        if ($endpoint->failing_since->lte(now()->subDays(self::DISABLE_AFTER_DAYS))) {
            self::switchOff($endpoint, $error);
        }
    }

    /** Every minute: send the retries that are due, and pick up attempts a stopped worker left half-done. */
    public static function retryDue(): int
    {
        DB::table('webhook_deliveries')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'retrying', 'next_attempt_at' => now()]);
        $ids = DB::table('webhook_deliveries')->whereIn('status', ['pending', 'retrying'])->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(500)->pluck('id');
        foreach ($ids as $id) {
            DeliverWebhook::dispatch((int) $id);
        }

        return $ids->count();
    }

    /** Daily: forget deliveries and request logs older than 30 days, and old secrets past their overlap. */
    public static function prune(): void
    {
        DB::table('webhook_deliveries')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->whereNotIn('status', ['pending', 'retrying', 'sending'])->delete();
        DB::table('api_request_logs')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        DB::table('webhook_endpoints')->whereNotNull('previous_secret_expires_at')->where('previous_secret_expires_at', '<', now())->update(['previous_secret' => null, 'previous_secret_expires_at' => null]);
    }

    /** `t=<time>,v1=<sig>[,v1=<sig with the previous secret>]` */
    public static function signatureHeader(WebhookEndpoint $endpoint, int $timestamp, string $body): string
    {
        $sigs = ['v1='.self::sign($endpoint->secret, $timestamp, $body)];
        if ($endpoint->previous_secret !== null && $endpoint->previous_secret_expires_at?->isFuture()) {
            $sigs[] = 'v1='.self::sign($endpoint->previous_secret, $timestamp, $body);
        }

        return 't='.$timestamp.','.implode(',', $sigs);
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * The endpoints that get a team's events: a sandbox twin's events go to the real team's test endpoints. Beyond the
     * plan's limit (after a downgrade), only the oldest endpoints are used.
     *
     * @return Collection<int, WebhookEndpoint>
     */
    public static function endpointsFor(Team $team): Collection
    {
        $owner = $team->sandbox_of_team_id !== null ? Team::query()->find($team->sandbox_of_team_id) : $team;
        if ($owner === null) {
            return collect();
        }

        return WebhookEndpoint::query()->with('team')->where('team_id', $owner->id)->orderBy('id')->limit(self::limit($owner))->get()
            ->filter(fn (WebhookEndpoint $e) => $e->is_active && $e->mode === ($team->sandbox_of_team_id !== null ? 'test' : 'live'))->values();
    }

    /**
     * Whether a URL may receive webhooks: https, a real host name or address, and only public addresses.
     *
     * @return array{0: string, 1: int, 2: string} host, port and the checked address to connect to
     */
    public static function checkUrl(string $url, string $field): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages([$field => $m]);
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || mb_strlen($url) > 500) {
            $fail('Use a full https:// address (no user name or password in it).');
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? 443);
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (self::$resolver !== null ? (self::$resolver)($host) : (gethostbynamel($host) ?: []));
        if ($ips === []) {
            $fail('That address doesn’t resolve to a server.');
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                $fail('That address points to a private or reserved network. Webhooks can only go to public servers.');
            }
        }

        return [$host, $port, $ips[0]];
    }

    private static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Ranges the PHP flags don't cover: shared address space (100.64.0.0/10) and IPv4-mapped IPv6.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $n = (int) ip2long($ip);

            return ($n & 0xFFC00000) !== (int) ip2long('100.64.0.0');
        }

        return ! str_starts_with($ip, '::ffff:') && ! str_starts_with($ip, '64:ff9b:');
    }

    /** @param Closure(): array<string, mixed> $data */
    private static function emit(Team $team, string $type, Closure $data): void
    {
        $endpoints = self::endpointsFor($team)->filter(fn (WebhookEndpoint $e) => $e->wants($type));
        if ($endpoints->isEmpty()) {
            return;
        }
        $id = 'evt_'.strtolower((string) Str::ulid());
        $payload = self::envelope($team, $type, $id, $team->sandbox_of_team_id !== null, $data());
        foreach ($endpoints as $endpoint) {
            self::queue($endpoint, $id, $type, $payload);
        }
    }

    /** @param array<string, mixed> $data */
    private static function envelope(Team $team, string $type, string $id, bool $test, array $data): string
    {
        return (string) json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'api_version' => 'v1', 'created_at' => now()->toIso8601String(),
            'livemode' => ! $test, 'team' => Resources::teamRef($team), 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function queue(WebhookEndpoint $endpoint, string $eventId, string $type, string $payload): WebhookDelivery
    {
        $delivery = WebhookDelivery::create(['webhook_endpoint_id' => $endpoint->id, 'event_id' => $eventId, 'event_type' => $type, 'payload' => $payload, 'status' => 'pending', 'next_attempt_at' => now()]);
        DeliverWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    private static function switchOff(WebhookEndpoint $endpoint, string $error): void
    {
        $reason = 'Switched off after failing for '.self::DISABLE_AFTER_DAYS.' days in a row. Last error: '.$error;
        $endpoint->forceFill(['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => mb_substr($reason, 0, 255)])->save();
        $team = $endpoint->team;
        $notify = collect([$team->leader, $endpoint->creator])->filter()->unique('id');
        foreach ($notify as $user) {
            app(Notifier::class)->send($user, 'api.webhook_disabled', ['headline' => 'A webhook endpoint was switched off',
                'body' => "{$endpoint->url} failed for ".self::DISABLE_AFTER_DAYS." days in a row, so we stopped sending to it. Fix it, then switch it back on in Developers (API). Last error: {$error}"], url('/app/developers'));
        }
    }

    /**
     * @param  list<string>  $events
     * @return list<string>
     */
    private static function events(array $events): array
    {
        if ($events === [] || in_array('*', $events, true)) {
            return ['*'];
        }
        $unknown = array_diff($events, array_keys(WebhookEvents::TYPES));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['events' => 'Unknown event type: '.implode(', ', $unknown).'.']);
        }

        return array_values(array_unique($events));
    }

    private static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
