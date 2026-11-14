<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Lofty (developer.lofty.com). The member's personal API key is sent as `Authorization: token <key>`.
 * `X-Error-Status: true` makes Lofty answer errors with real HTTP status codes.
 */
final class Lofty extends HttpCrm
{
    public const BASE = 'https://api.lofty.com/v1.0';

    public function key(): string
    {
        return 'lofty';
    }

    public function label(): string
    {
        return 'Lofty';
    }

    public function keyHelp(): string
    {
        return 'In Lofty: Settings → Integrations → API → generate an API key.';
    }

    public function ready(): bool
    {
        return true;
    }

    public function verify(array $creds): array
    {
        $this->call($creds, 'GET', '/leads', ['limit' => 1]);

        return ['account' => 'Lofty', 'users' => [], 'stages' => []];
    }

    public function pushContact(array $creds, array $contact, ?string $existingId): string
    {
        $email = self::str($contact, 'email');
        if ($existingId === null && $email !== '') {
            $found = $this->call($creds, 'GET', '/leads', ['email' => $email, 'limit' => 1]);
            $existingId = isset($found['leads'][0]['leadId']) ? (string) $found['leads'][0]['leadId'] : null;
        }
        $body = array_filter([
            'firstName' => mb_substr(self::str($contact, 'first_name') ?: 'Client', 0, 30), 'lastName' => mb_substr(self::str($contact, 'last_name'), 0, 30),
            'emails' => $email !== '' ? [$email] : null, 'phones' => self::str($contact, 'phone') !== '' ? [mb_substr(self::str($contact, 'phone'), 0, 20)] : null,
            'source' => (string) ($contact['source'] ?? 'Team to Team Referrals'),
            'assignedUserId' => self::str($contact, 'assign_user_id') !== '' ? (int) $contact['assign_user_id'] : null,
        ], fn ($v) => $v !== null && $v !== '');
        $tags = array_map(fn ($t) => mb_substr((string) $t, 0, 64), (array) ($contact['tags'] ?? []));
        if ($existingId !== null) {
            $this->call($creds, 'PUT', "/leads/{$existingId}", [...array_diff_key($body, ['assignedUserId' => 1, 'source' => 1]), 'tagsAdd' => $tags]);

            return $existingId;
        }
        $r = $this->call($creds, 'POST', '/leads', [...$body, 'tags' => $tags]);

        return (string) ($r['leadId'] ?? throw new CrmException('Lofty didn’t return the new lead’s id.'));
    }

    public function addNote(array $creds, string $remoteId, string $text): void
    {
        $this->call($creds, 'POST', '/notes', ['leadId' => (int) $remoteId, 'content' => mb_substr($text, 0, 2000), 'isPin' => false]);
    }

    public function stageOf(array $creds, string $remoteId): ?string
    {
        $l = $this->call($creds, 'GET', "/leads/{$remoteId}");

        return isset($l['stage']) ? (string) $l['stage'] : null;
    }

    protected function client(array $creds): PendingRequest
    {
        return Http::baseUrl(self::BASE)->withHeaders(['Authorization' => 'token '.($creds['api_key'] ?? ''), 'X-Error-Status' => 'true']);
    }
}
