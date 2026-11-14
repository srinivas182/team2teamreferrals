<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * BoldTrail, formerly kvCORE (public API v2 at api.kvcore.com). The member's API token (BoldTrail → Lead Engine →
 * Lead Dropbox → My API Tokens, "Contacts" ticked; valid for a year) is sent as a Bearer token. A contact's stage is
 * its status.
 */
final class BoldTrail extends HttpCrm
{
    public const BASE = 'https://api.kvcore.com/v2/public';

    /** BoldTrail contact statuses. */
    public const STATUSES = [0 => 'New', 1 => 'Client', 2 => 'Closed', 3 => 'Sphere', 4 => 'Active', 5 => 'Contract', 6 => 'Archived', 7 => 'Prospect'];

    public function key(): string
    {
        return 'boldtrail';
    }

    public function label(): string
    {
        return 'BoldTrail (kvCORE)';
    }

    public function keyHelp(): string
    {
        return 'In BoldTrail: Lead Engine → Lead Dropbox → My API Tokens. Tick "Contacts", generate, and paste it here. Tokens last a year.';
    }

    public function ready(): bool
    {
        return true;
    }

    public function verify(array $creds): array
    {
        $this->call($creds, 'GET', '/contacts', ['limit' => 1]);

        return ['account' => 'BoldTrail', 'users' => [], 'stages' => self::STATUSES];
    }

    public function pushContact(array $creds, array $contact, ?string $existingId): string
    {
        $email = self::str($contact, 'email');
        if ($existingId === null && $email !== '') {
            $found = $this->call($creds, 'GET', '/contacts', ['search' => $email, 'limit' => 1]);
            $existingId = isset($found['data'][0]['id']) ? (string) $found['data'][0]['id'] : null;
        }
        $body = array_filter(['first_name' => self::str($contact, 'first_name'), 'last_name' => self::str($contact, 'last_name'), 'email' => $email,
            'cell_phone_1' => self::str($contact, 'phone'), 'source' => (string) ($contact['source'] ?? 'Team to Team Referrals')], fn ($v) => $v !== '');
        if ($existingId !== null) {
            $this->call($creds, 'PUT', "/contact/{$existingId}", array_diff_key($body, ['source' => 1]));
            $id = $existingId;
        } else {
            $r = $this->call($creds, 'POST', '/contact', [...$body, 'status' => 0]);
            $id = (string) ($r['id'] ?? $r['data']['id'] ?? throw new CrmException('BoldTrail didn’t return the new contact’s id.'));
        }
        foreach ((array) ($contact['tags'] ?? []) as $tag) {
            $this->call($creds, 'PUT', "/contact/{$id}/tag", ['name' => (string) $tag]);
        }

        return $id;
    }

    public function addNote(array $creds, string $remoteId, string $text): void
    {
        $this->call($creds, 'POST', "/contact/{$remoteId}/action/note", ['note' => $text]);
    }

    public function stageOf(array $creds, string $remoteId): ?string
    {
        $c = $this->call($creds, 'GET', "/contact/{$remoteId}");
        $status = $c['status'] ?? $c['data']['status'] ?? null;

        return is_numeric($status) ? (self::STATUSES[(int) $status] ?? (string) $status) : null;
    }

    protected function client(array $creds): PendingRequest
    {
        return Http::baseUrl(self::BASE)->withToken((string) ($creds['api_key'] ?? ''));
    }
}
