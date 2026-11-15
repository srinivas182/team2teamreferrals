<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Calendar;

/** Google Calendar API v3. Scopes: create and change events, and list the person's calendars. */
final class GoogleCalendar extends CalendarProvider
{
    public const API = 'https://www.googleapis.com/calendar/v3';

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Calendar';
    }

    protected function authorizeUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function scopes(): array
    {
        return ['openid', 'email', 'https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.calendarlist.readonly'];
    }

    protected function emailFrom(array $tokens): ?string
    {
        // The ID token comes straight from Google over TLS in this exchange, so its payload can be read as is.
        $parts = explode('.', (string) ($tokens['id_token'] ?? ''));
        $claims = isset($parts[1]) ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;

        return is_array($claims) && isset($claims['email']) ? (string) $claims['email'] : null;
    }

    public function calendars(array $creds): array
    {
        $r = $this->call($creds, 'GET', self::API.'/users/me/calendarList', ['minAccessRole' => 'writer']);

        return array_values(array_map(fn ($c) => ['id' => (string) $c['id'], 'name' => (string) ($c['summaryOverride'] ?? $c['summary'] ?? $c['id']), 'primary' => (bool) ($c['primary'] ?? false)], (array) ($r['items'] ?? [])));
    }

    public function create(array $creds, string $calendarId, array $item): string
    {
        return (string) $this->call($creds, 'POST', self::API.'/calendars/'.rawurlencode($calendarId).'/events', $this->body($item))['id'];
    }

    public function update(array $creds, string $calendarId, string $remoteId, array $item): void
    {
        $this->call($creds, 'PUT', self::API.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($remoteId), $this->body($item));
    }

    public function delete(array $creds, string $calendarId, string $remoteId): void
    {
        $this->call($creds, 'DELETE', self::API.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($remoteId));
    }

    /**
     * @param  array<string, mixed>  $i
     * @return array<string, mixed>
     */
    private function body(array $i): array
    {
        $when = fn ($t) => $i['all_day'] ? ['date' => $t->format('Y-m-d')] : ['dateTime' => $t->utc()->format('Y-m-d\TH:i:s\Z'), 'timeZone' => 'UTC'];

        return array_filter(['summary' => $i['title'], 'description' => $i['description'], 'location' => $i['location'], 'start' => $when($i['start']), 'end' => $when($i['end']),
            'source' => ['title' => 'Team to Team Referrals', 'url' => $i['url']], 'extendedProperties' => ['private' => ['t2t' => $i['key']]]], fn ($v) => $v !== null);
    }
}
