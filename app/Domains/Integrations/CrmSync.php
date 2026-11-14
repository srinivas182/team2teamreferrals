<?php

declare(strict_types=1);

namespace App\Domains\Integrations;

use App\Domains\Identity\Models\User;
use App\Domains\Integrations\Providers\CrmException;
use App\Domains\Messaging\Notifier;
use App\Domains\Network\Industries\Templates\IndustryTemplates;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Referrals\Pipeline;
use App\Domains\Referrals\Referral;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Keeps members' CRMs in step with their referrals (Sprint 45a).
 *
 * Out, within seconds (queued): an accepted referral becomes a contact in the receiving team's CRM (an existing
 * contact with the same email or phone is updated instead), tagged "Team to Team Referrals" and the network, with
 * a summary of the referral. Optionally the sending team's own CRM gets the clients it refers out. Updates posted on
 * the referral are copied as notes. Client details are never sent before the receiving team has accepted.
 *
 * In, every 5 minutes: each open referral's contact is checked; when its CRM stage changes, the mapped stage is
 * applied here (open stages move; a lost stage closes it as lost; a won stage asks the team to record the close,
 * because that needs the closing date and value).
 *
 * Failures retry after 1 min, 5 min, 30 min, 2 h and 6 h. A CRM that refuses the key pauses the connection and the
 * team is told; nothing is lost, and "Try again" resends.
 */
final class CrmSync
{
    public const RETRY_AFTER = [1 => 60, 2 => 300, 3 => 1800, 4 => 7200, 5 => 21600];

    public const MAX_ATTEMPTS = 6;

    public const POLL_BATCH = 100;

    public const KEEP_DAYS = 30;

    public const MANAGERS = ['leader', 'assistant'];

    /**
     * Connect (or reconnect with a new key). Checks the key with the CRM first.
     *
     * @param  array{sync_sent?: bool, sync_notes?: bool, assign_user_id?: ?string, backfill?: bool}  $settings
     */
    public static function connect(Team $team, User $user, string $provider, string $apiKey, array $settings = []): Integration
    {
        if (! Connectors::available($provider)) {
            throw ValidationException::withMessages(['provider' => 'That CRM isn’t available yet.']);
        }
        $crm = Connectors::get($provider);
        $creds = ['api_key' => trim($apiKey)];
        try {
            $info = $crm->verify($creds);
        } catch (CrmException $e) {
            throw ValidationException::withMessages(['api_key' => $e->getMessage()]);
        }
        $integration = Integration::query()->firstOrNew(['team_id' => $team->id, 'provider' => $provider]);
        $integration->fill(['status' => 'active', 'credentials' => $creds, 'account_name' => mb_substr($info['account'], 0, 150), 'remote_users' => $info['users'],
            'remote_stages' => self::mergeStages($integration->remote_stages ?? [], $info['stages']), 'connected_by' => $user->id,
            'settings' => ['sync_sent' => (bool) ($settings['sync_sent'] ?? $integration->setting('sync_sent', false)), 'sync_notes' => (bool) ($settings['sync_notes'] ?? $integration->setting('sync_notes', true)),
                'assign_user_id' => $settings['assign_user_id'] ?? $integration->setting('assign_user_id')]]);
        $integration->last_error = null;
        $new = ! $integration->exists;
        $integration->save();
        if ($new) {
            self::defaultMappings($integration);
        }
        activity('integrations')->causedBy($user)->performedOn($team)->event($new ? 'integration-connected' : 'integration-reconnected')
            ->withProperties(['provider' => $provider, 'account' => $integration->account_name])->log($crm->label().($new ? ' connected' : ' key updated'));
        if (! $new) {
            self::retryFailed($integration);
        }
        if (! empty($settings['backfill'])) {
            self::backfill($integration);
        }

        return $integration;
    }

    /**
     * @param  array{sync_sent?: bool, sync_notes?: bool, assign_user_id?: ?string}  $settings
     * @param  array<string, string>|null  $stageMap  CRM stage name => our stage key ('' = leave as is)
     */
    public static function update(Integration $integration, User $user, array $settings, ?array $stageMap): void
    {
        $integration->settings = [...$integration->settings, ...array_intersect_key($settings, array_flip(['sync_sent', 'sync_notes', 'assign_user_id']))];
        $integration->save();
        if ($stageMap !== null) {
            foreach ($stageMap as $remote => $local) {
                $remote = mb_substr(trim((string) $remote), 0, 100);
                if ($remote !== '') {
                    DB::table('integration_mappings')->updateOrInsert(['integration_id' => $integration->id, 'kind' => 'stage', 'remote_value' => $remote],
                        ['local_value' => mb_substr((string) $local, 0, 100), 'updated_at' => now(), 'created_at' => now()]);
                }
            }
            $integration->remote_stages = self::mergeStages($integration->remote_stages ?? [], array_keys($stageMap));
            $integration->save();
        }
        activity('integrations')->causedBy($user)->performedOn($integration->team)->event('integration-updated')->withProperties(['provider' => $integration->provider])->log(Connectors::get($integration->provider)->label().' settings changed');
    }

    public static function setPaused(Integration $integration, User $user, bool $paused): void
    {
        $integration->status = $paused ? 'paused' : 'active';
        $integration->save();
        activity('integrations')->causedBy($user)->performedOn($integration->team)->event($paused ? 'integration-paused' : 'integration-resumed')->withProperties(['provider' => $integration->provider])
            ->log(Connectors::get($integration->provider)->label().($paused ? ' paused' : ' resumed'));
        if (! $paused) {
            self::retryFailed($integration);
        }
    }

    public static function disconnect(Integration $integration, User $user): void
    {
        activity('integrations')->causedBy($user)->performedOn($integration->team)->event('integration-disconnected')->withProperties(['provider' => $integration->provider])
            ->log(Connectors::get($integration->provider)->label().' disconnected');
        $integration->delete();
    }

    /** "Test connection": check the key again and refresh the CRM's agents and stages. */
    public static function test(Integration $integration): ?string
    {
        try {
            $info = Connectors::get($integration->provider)->verify($integration->credentials);
        } catch (CrmException $e) {
            if ($e->kind === 'auth') {
                self::refused($integration, $e->getMessage());
            }

            return $e->getMessage();
        }
        $integration->forceFill(['account_name' => mb_substr($info['account'], 0, 150), 'remote_users' => $info['users'], 'remote_stages' => self::mergeStages($integration->remote_stages ?? [], $info['stages']),
            'last_error' => null, 'status' => $integration->status === 'error' ? 'active' : $integration->status])->save();
        if ($integration->status === 'active') {
            self::retryFailed($integration);
        }

        return null;
    }

    /** A new entry on a referral's timeline (from ReferralEventRecorded). */
    public static function onTimeline(Referral $ref, string $event): void
    {
        $ref = $ref->fresh(['group']) ?? $ref;
        if ($ref->group->is_sandbox) {
            return;
        }
        if ($event === 'accepted' && $ref->receiver_team_id !== null) {
            foreach (self::activeFor($ref->receiver_team_id) as $i) {
                self::queue($i, $ref, 'contact', ['side' => 'received']);
            }
        }
        if ($event === 'sent') {
            foreach (self::activeFor($ref->sender_team_id) as $i) {
                if ($i->setting('sync_sent', false)) {
                    self::queue($i, $ref, 'contact', ['side' => 'sent']);
                }
            }
        }
        if ($event === 'update') {
            $note = DB::table('referral_events')->where(['referral_id' => $ref->id, 'event' => 'update'])->orderByDesc('id')->first(['team_id', 'meta']);
            $text = (string) (json_decode((string) ($note->meta ?? ''), true)['note'] ?? '');
            $by = $note !== null ? (string) DB::table('teams')->where('id', $note->team_id)->value('name') : '';
            foreach ([$ref->sender_team_id, $ref->receiver_team_id] as $teamId) {
                foreach ($teamId !== null ? self::activeFor($teamId) : [] as $i) {
                    if ($text !== '' && $i->setting('sync_notes', true) && self::record($i, $ref->id) !== null) {
                        self::queue($i, $ref, 'note', ['text' => "Update from {$by}: {$text}"]);
                    }
                }
            }
        }
    }

    /** One sync (run by SyncCrm). Safe to run twice: only one run claims it. */
    public static function run(int $logId): void
    {
        $claimed = DB::table('sync_logs')->where('id', $logId)->whereIn('status', ['pending', 'retrying'])->update(['status' => 'running', 'updated_at' => now()]);
        $log = $claimed === 1 ? DB::table('sync_logs')->where('id', $logId)->first() : null;
        $integration = $log !== null ? Integration::query()->find((int) $log->integration_id) : null;
        if ($log === null || $integration === null) {
            return;
        }
        if ($integration->status !== 'active') {
            self::finish($log, 'failed', $integration->status === 'paused' ? 'The connection is paused. Resume it to send this.' : 'The connection needs attention: '.$integration->last_error);

            return;
        }
        $ref = $log->referral_id !== null ? Referral::query()->with(['group.industry', 'seat', 'sender', 'receiver'])->find((int) $log->referral_id) : null;
        $payload = (array) json_decode((string) $log->payload, true);
        $crm = Connectors::get($integration->provider);
        try {
            if ($ref === null) {
                self::finish($log, 'skipped', 'The referral no longer exists.');

                return;
            }
            if ($log->action === 'contact') {
                $side = (string) ($payload['side'] ?? 'received');
                // Never before the receiving team accepted: until then it may not see the client's details.
                if ($side === 'received' && ($ref->receiver_team_id !== $integration->team_id || $ref->status !== 'accepted')) {
                    self::finish($log, 'skipped', 'Not accepted by your team, so not sent.');

                    return;
                }
                $existing = self::record($integration, $ref->id);
                try {
                    $remoteId = $crm->pushContact($integration->credentials, self::contact($ref, $integration, $side), $existing?->remote_value);
                } catch (CrmException $e) {
                    if ($e->kind !== 'gone' || $existing === null) {
                        throw $e;
                    }
                    // Deleted in the CRM since: make it again.
                    DB::table('integration_mappings')->where('id', $existing->id)->delete();
                    $remoteId = $crm->pushContact($integration->credentials, self::contact($ref, $integration, $side), null);
                }
                DB::table('integration_mappings')->updateOrInsert(['integration_id' => $integration->id, 'kind' => 'record', 'remote_value' => $remoteId],
                    ['referral_id' => $ref->id, 'local_value' => (string) $ref->id, 'meta' => json_encode(['side' => $side, 'stage' => null, 'checked_at' => null]), 'updated_at' => now(), 'created_at' => now()]);
                self::finish($log, 'ok', $existing !== null ? 'Contact updated.' : 'Contact created.', $remoteId);
            } elseif ($log->action === 'note') {
                $rec = self::record($integration, $ref->id);
                if ($rec === null) {
                    self::finish($log, 'skipped', 'The contact isn’t in your CRM yet.');

                    return;
                }
                $crm->addNote($integration->credentials, $rec->remote_value, (string) ($payload['text'] ?? ''));
                self::finish($log, 'ok', 'Note added.', $rec->remote_value);
            }
            $integration->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
        } catch (CrmException $e) {
            self::failed($integration, $log, $e);
        } catch (Throwable $e) {
            report($e);
            self::failed($integration, $log, new CrmException('Something went wrong on our side. It will be retried.'));
        }
    }

    /** Every 5 minutes: check open referrals' contacts for stage changes in the CRM. */
    public static function pollStages(): void
    {
        foreach (Integration::query()->where('status', 'active')->get() as $integration) {
            if (! Connectors::available($integration->provider)) {
                continue;
            }
            $records = DB::table('integration_mappings as m')->join('referrals as r', 'r.id', '=', 'm.referral_id')
                ->where(['m.integration_id' => $integration->id, 'm.kind' => 'record', 'r.status' => 'accepted', 'r.receiver_team_id' => $integration->team_id])
                ->whereNull('r.closed_at')->orderBy('m.updated_at')->limit(self::POLL_BATCH)->get(['m.*']);
            foreach ($records as $rec) {
                if ((json_decode((string) $rec->meta, true)['side'] ?? 'received') !== 'received') {
                    continue;
                }
                try {
                    self::checkStage($integration, $rec);
                } catch (CrmException $e) {
                    if ($e->kind === 'auth') {
                        self::refused($integration, $e->getMessage());
                        break;
                    }
                    if ($e->kind === 'gone') {
                        DB::table('integration_mappings')->where('id', $rec->id)->delete();
                    }
                    self::log($integration, (int) $rec->referral_id, 'in', 'check', 'failed', $e->getMessage(), $rec->remote_value);
                }
            }
        }
    }

    /** Every minute: retries that are due, and syncs a stopped worker left half-done. */
    public static function retryDue(): int
    {
        DB::table('sync_logs')->where('status', 'running')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'retrying', 'next_attempt_at' => now()]);
        $ids = DB::table('sync_logs')->whereIn('status', ['pending', 'retrying'])->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(500)->pluck('id');
        foreach ($ids as $id) {
            SyncCrm::dispatch((int) $id);
        }

        return $ids->count();
    }

    /** "Try again": resend everything that failed in the last 7 days. */
    public static function retryFailed(Integration $integration): int
    {
        $ids = DB::table('sync_logs')->where(['integration_id' => $integration->id, 'status' => 'failed', 'direction' => 'out'])->where('created_at', '>=', now()->subDays(7))->pluck('id');
        DB::table('sync_logs')->whereIn('id', $ids)->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'updated_at' => now()]);
        foreach ($ids as $id) {
            SyncCrm::dispatch((int) $id)->afterCommit();
        }

        return $ids->count();
    }

    public static function prune(): void
    {
        DB::table('sync_logs')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->whereNotIn('status', ['pending', 'retrying', 'running'])->delete();
    }

    /** Open, accepted referrals the team received, sent to a CRM just connected. */
    public static function backfill(Integration $integration): int
    {
        $n = 0;
        foreach (Referral::query()->where(['receiver_team_id' => $integration->team_id, 'status' => 'accepted'])->whereNull('closed_at')
            ->whereHas('group', fn ($q) => $q->where('is_sandbox', false))->orderBy('id')->limit(500)->get() as $ref) {
            if (self::record($integration, $ref->id) === null) {
                self::queue($integration, $ref, 'contact', ['side' => 'received']);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Our stages a CRM stage can map to: every stage in the networks the team is in, by key.
     *
     * @return list<array{key: string, label: string, kind: string}>
     */
    public static function localStages(Team $team): array
    {
        $templates = app(IndustryTemplates::class);
        $out = [];
        $groups = \App\Domains\Network\Groups\Models\Group::query()->with('industry')->where('is_sandbox', false)
            ->whereIn('id', DB::table('seats')->where(fn ($q) => $q->where('primary_team_id', $team->id)->orWhere('backup_team_id', $team->id))->select('group_id'))->get();
        foreach ($groups as $g) {
            foreach ($templates->resolve('stages', $g->industry, $g->id)['body'] as $s) {
                $out[(string) $s['key']] ??= ['key' => (string) $s['key'], 'label' => (string) $s['label'], 'kind' => (string) $s['kind']];
            }
        }

        return array_values($out);
    }

    /** @return array<string, string> CRM stage => our stage key */
    public static function stageMap(Integration $integration): array
    {
        return DB::table('integration_mappings')->where(['integration_id' => $integration->id, 'kind' => 'stage'])->pluck('local_value', 'remote_value')->map(fn ($v) => (string) $v)->all();
    }

    /** @param array<string, mixed> $payload */
    private static function queue(Integration $integration, Referral $ref, string $action, array $payload): void
    {
        $id = self::log($integration, $ref->id, 'out', $action, 'pending', null, null, $payload);
        SyncCrm::dispatch($id)->afterCommit();
    }

    /** @param array<string, mixed>|null $payload */
    private static function log(Integration $integration, ?int $referralId, string $direction, string $action, string $status, ?string $message, ?string $remoteId, ?array $payload = null): int
    {
        return (int) DB::table('sync_logs')->insertGetId(['integration_id' => $integration->id, 'referral_id' => $referralId, 'direction' => $direction, 'action' => $action, 'status' => $status,
            'payload' => $payload !== null ? json_encode($payload) : null, 'message' => $message !== null ? mb_substr($message, 0, 255) : null, 'remote_id' => $remoteId,
            'next_attempt_at' => $status === 'pending' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private static function finish(stdClass $log, string $status, string $message, ?string $remoteId = null): void
    {
        DB::table('sync_logs')->where('id', $log->id)->update(['status' => $status, 'message' => mb_substr($message, 0, 255), 'remote_id' => $remoteId ?? $log->remote_id,
            'attempts' => (int) $log->attempts + 1, 'next_attempt_at' => null, 'updated_at' => now()]);
    }

    private static function failed(Integration $integration, stdClass $log, CrmException $e): void
    {
        if ($e->kind === 'auth') {
            self::refused($integration, $e->getMessage());
        }
        $attempt = (int) $log->attempts + 1;
        $retry = $e->kind === 'retry' && $attempt < self::MAX_ATTEMPTS;
        DB::table('sync_logs')->where('id', $log->id)->update(['status' => $retry ? 'retrying' : 'failed', 'attempts' => $attempt, 'message' => mb_substr($e->getMessage(), 0, 255),
            'next_attempt_at' => $retry ? now()->addSeconds(self::RETRY_AFTER[$attempt]) : null, 'updated_at' => now()]);
        if ($e->kind !== 'auth') {
            $integration->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
        }
    }

    /** The CRM refused the key: pause sending and tell the team (once). */
    private static function refused(Integration $integration, string $message): void
    {
        $first = $integration->status !== 'error';
        $integration->forceFill(['status' => 'error', 'last_error' => mb_substr($message, 0, 255)])->save();
        if ($first) {
            $label = Connectors::get($integration->provider)->label();
            foreach (self::managers($integration->team) as $u) {
                app(Notifier::class)->send($u, 'integrations.attention', ['headline' => "{$label} needs a new API key",
                    'body' => "{$label} refused your API key, so we’ve stopped sending referrals to it. Nothing is lost: add a working key in Integrations and everything waiting is sent."], url('/app/integrations'));
            }
        }
    }

    private static function checkStage(Integration $integration, stdClass $rec): void
    {
        $meta = (array) json_decode((string) $rec->meta, true);
        $stage = Connectors::get($integration->provider)->stageOf($integration->credentials, (string) $rec->remote_value);
        $before = $meta['stage'] ?? null;
        DB::table('integration_mappings')->where('id', $rec->id)->update(['meta' => json_encode([...$meta, 'stage' => $stage, 'checked_at' => now()->toIso8601String()]), 'updated_at' => now()]);
        if ($stage === null || $stage === $before) {
            return;
        }
        if ($stage !== '' && ! in_array($stage, $integration->remote_stages ?? [], true)) {
            $integration->forceFill(['remote_stages' => self::mergeStages($integration->remote_stages ?? [], [$stage])])->save();
        }
        // The first look only records where the contact is; changes after that are applied.
        if ($before === null) {
            return;
        }
        $ref = Referral::query()->find((int) $rec->referral_id);
        $key = self::stageMap($integration)[$stage] ?? '';
        $label = Connectors::get($integration->provider)->label();
        if ($ref === null || $key === '' || $key === $ref->stage) {
            self::log($integration, $ref?->id, 'in', 'stage', 'skipped', "Moved to “{$stage}” in {$label}: not mapped to a stage here.", (string) $rec->remote_value);

            return;
        }
        $pipeline = app(Pipeline::class);
        $target = $pipeline->stage($ref, $key);
        $actor = self::actor($integration);
        if ($target === null) {
            self::log($integration, $ref->id, 'in', 'stage', 'skipped', "Moved to “{$stage}” in {$label}: that stage isn’t in this network’s pipeline.", (string) $rec->remote_value);

            return;
        }
        if ($target['kind'] === 'won') {
            foreach (self::managers($integration->team) as $u) {
                app(Notifier::class)->send($u, 'integrations.attention', ['headline' => "Record the close for {$ref->client_name}",
                    'body' => "{$label} shows {$ref->client_name} as “{$stage}”. Add the closing date and value here to close the referral."], url('/app/referrals/'.$ref->ulid));
            }
            self::log($integration, $ref->id, 'in', 'stage', 'ok', "“{$stage}” in {$label}: asked your team to record the close.", (string) $rec->remote_value);

            return;
        }
        $pipeline->move($ref, $actor, $key, "Moved to “{$stage}” in {$label}.", $target['kind'] === 'lost' ? ['lost_reason' => "Marked “{$stage}” in {$label}."] : []);
        self::log($integration, $ref->id, 'in', 'stage', 'ok', "Moved to {$target['label']} (“{$stage}” in {$label}).", (string) $rec->remote_value);
    }

    /** Who stage changes from the CRM are made as: whoever connected it, while they still manage the team. */
    private static function actor(Integration $integration): User
    {
        $team = $integration->team;
        $u = $integration->connector;

        return $u !== null && $u->is_active && in_array($team->roleOf($u), self::MANAGERS, true) ? $u : $team->leader;
    }

    /** @return list<User> */
    private static function managers(Team $team): array
    {
        $ids = DB::table('team_members')->where('team_id', $team->id)->where('role', 'assistant')->pluck('user_id')->push($team->leader_id)->unique();

        return array_values(User::query()->whereIn('id', $ids)->where('is_active', true)->get()->all());
    }

    /**
     * The team's connections to queue syncs for. Paused ones and ones needing a new key are included: their syncs
     * wait in the log as failed, and are sent when the team resumes or adds a working key.
     *
     * @return \Illuminate\Support\Collection<int, Integration>
     */
    private static function activeFor(int $teamId): \Illuminate\Support\Collection
    {
        return Integration::query()->where('team_id', $teamId)->get()->filter(fn (Integration $i) => Connectors::available($i->provider))->values();
    }

    private static function record(Integration $integration, int $referralId): ?stdClass
    {
        return DB::table('integration_mappings')->where(['integration_id' => $integration->id, 'kind' => 'record', 'referral_id' => $referralId])->first();
    }

    /** @return array<string, mixed> */
    private static function contact(Referral $ref, Integration $integration, string $side): array
    {
        $parts = preg_split('/\s+/', trim($ref->client_name)) ?: [];
        $first = (string) array_shift($parts);
        $form = collect(app(IndustryTemplates::class)->resolve('form', $ref->group->industry, $ref->group_id)['body'])->keyBy('key');
        $lines = [];
        foreach ((array) $ref->answers as $k => $v) {
            if (in_array($k, ['client_name', 'client_phone', 'client_email'], true) || $v === null || $v === '' || $v === []) {
                continue;
            }
            $lines[] = '- '.($form[$k]['label'] ?? $k).': '.(is_array($v) ? implode(', ', array_map('strval', $v)) : (is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v));
        }
        $intro = $side === 'received'
            ? "Referral from {$ref->sender->name} through Team to Team Referrals ({$ref->group->name}, {$ref->seat->territory_name})."
            : "Referred out to {$ref->receiver?->name} through Team to Team Referrals ({$ref->group->name}, {$ref->seat->territory_name}).";

        return ['first_name' => $first !== '' ? $first : 'Client', 'last_name' => implode(' ', $parts), 'email' => (string) $ref->client_email, 'phone' => (string) $ref->client_phone,
            'tags' => array_values(array_filter(['Team to Team Referrals', $ref->group->name, $side === 'sent' ? 'Referred out' : null])), 'source' => 'Team to Team Referrals',
            'assign_user_id' => $side === 'received' ? $integration->setting('assign_user_id') : null,
            'message' => trim($intro."\n\n".implode("\n", $lines).($ref->notes ? "\n\nNotes: {$ref->notes}" : '')."\n\nOpen the referral: ".url('/app/referrals/'.$ref->ulid))];
    }

    /** Default stage mapping: CRM stages whose name matches one of our open stages. Won and lost are never mapped by default. */
    private static function defaultMappings(Integration $integration): void
    {
        $ours = collect(self::localStages($integration->team))->where('kind', 'open');
        foreach ($integration->remote_stages ?? [] as $remote) {
            $match = $ours->first(fn ($s) => mb_strtolower($s['label']) === mb_strtolower($remote));
            if ($match !== null) {
                DB::table('integration_mappings')->insertOrIgnore(['integration_id' => $integration->id, 'kind' => 'stage', 'remote_value' => mb_substr($remote, 0, 100), 'local_value' => $match['key'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private static function mergeStages(array $a, array $b): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($s) => mb_substr(trim((string) $s), 0, 100), [...$a, ...$b]), fn ($s) => $s !== '')));
    }
}
