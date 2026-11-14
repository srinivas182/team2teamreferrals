<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Platform\Settings\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Follow Up Boss (docs.followupboss.com). The member's API key is sent as Basic auth; every request also carries the
 * platform's registered system name and key (X-System, X-System-Key), entered by an admin. New referrals go in
 * through /events, Follow Up Boss's preferred way in for leads: it matches existing people by email and phone,
 * and runs the member's lead flow and action plans.
 */
final class FollowUpBoss extends HttpCrm
{
    public const BASE = 'https://api.followupboss.com/v1';

    public function key(): string
    {
        return 'followupboss';
    }

    public function label(): string
    {
        return 'Follow Up Boss';
    }

    public function keyHelp(): string
    {
        return 'In Follow Up Boss: Admin → API → Create API Key. A key from an Owner or Admin user works best.';
    }

    public function ready(): bool
    {
        $s = app(Settings::class);

        return $s->has('integrations.followupboss.system') && $s->has('integrations.followupboss.system_key');
    }

    public function verify(array $creds): array
    {
        $me = $this->call($creds, 'GET', '/me');
        $users = $this->call($creds, 'GET', '/users', ['limit' => 100, 'fields' => 'id,name']);
        $stages = $this->call($creds, 'GET', '/stages', ['limit' => 100]);

        return [
            'account' => (string) ($me['account']['name'] ?? $me['name'] ?? $me['email'] ?? 'Follow Up Boss'),
            'users' => array_values(array_map(fn ($u) => ['id' => (string) $u['id'], 'name' => (string) ($u['name'] ?? $u['id'])], (array) ($users['users'] ?? []))),
            'stages' => array_values(array_map(fn ($s) => (string) $s['name'], (array) ($stages['stages'] ?? []))),
        ];
    }

    public function pushContact(array $creds, array $contact, ?string $existingId): string
    {
        $person = array_filter([
            'firstName' => self::str($contact, 'first_name'), 'lastName' => self::str($contact, 'last_name'),
            'emails' => self::str($contact, 'email') !== '' ? [['value' => self::str($contact, 'email')]] : null,
            'phones' => self::str($contact, 'phone') !== '' ? [['value' => self::str($contact, 'phone')]] : null,
            'tags' => $contact['tags'] ?? [], 'assignedUserId' => self::str($contact, 'assign_user_id') !== '' ? (int) $contact['assign_user_id'] : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
        if ($existingId !== null) {
            $this->call($creds, 'PUT', "/people/{$existingId}", array_diff_key($person, ['assignedUserId' => 1]));

            return $existingId;
        }
        $r = $this->call($creds, 'POST', '/events', ['source' => (string) ($contact['source'] ?? 'Team to Team Referrals'), 'system' => 'Team to Team Referrals',
            'type' => 'General Inquiry', 'message' => (string) ($contact['message'] ?? ''), 'person' => $person]);
        $id = $r['personId'] ?? $r['person']['id'] ?? $r['id'] ?? null;
        if ($id === null) {
            // Follow Up Boss answers 204 when the member's lead flow is set to ignore this source.
            throw new CrmException('Follow Up Boss didn’t create the contact: check that your lead flow doesn’t archive the "Team to Team Referrals" source.', 'reject');
        }

        return (string) $id;
    }

    public function addNote(array $creds, string $remoteId, string $text): void
    {
        $this->call($creds, 'POST', '/notes', ['personId' => (int) $remoteId, 'subject' => 'Team to Team Referrals', 'body' => $text]);
    }

    public function stageOf(array $creds, string $remoteId): ?string
    {
        $p = $this->call($creds, 'GET', "/people/{$remoteId}", ['fields' => 'id,stage']);

        return isset($p['stage']) ? (string) $p['stage'] : null;
    }

    protected function client(array $creds): PendingRequest
    {
        $s = app(Settings::class);

        return Http::baseUrl(self::BASE)->withBasicAuth((string) ($creds['api_key'] ?? ''), '')
            ->withHeaders(['X-System' => (string) $s->get('integrations.followupboss.system'), 'X-System-Key' => (string) $s->get('integrations.followupboss.system_key')]);
    }
}
