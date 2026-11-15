<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Calendar;

/** Outlook / Microsoft 365 through Microsoft Graph. Scopes: read and write the person's calendars. */
final class MicrosoftCalendar extends CalendarProvider
{
    public const API = 'https://graph.microsoft.com/v1.0';

    public function key(): string
    {
        return 'microsoft';
    }

    public function label(): string
    {
        return 'Outlook (Microsoft 365)';
    }

    protected function authorizeUrl(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    }

    protected function tokenUrl(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    }

    protected function scopes(): array
    {
        return ['offline_access', 'openid', 'email', 'User.Read', 'Calendars.ReadWrite'];
    }

    protected function emailFrom(array $tokens): ?string
    {
        try {
            $me = $this->call(['access_token' => (string) ($tokens['access_token'] ?? '')], 'GET', self::API.'/me', ['$select' => 'mail,userPrincipalName']);
        } catch (\App\Domains\Integrations\Providers\CrmException) {
            return null;
        }

        return isset($me['mail']) ? (string) $me['mail'] : (isset($me['userPrincipalName']) ? (string) $me['userPrincipalName'] : null);
    }

    public function calendars(array $creds): array
    {
        $r = $this->call($creds, 'GET', self::API.'/me/calendars', ['$select' => 'id,name,isDefaultCalendar,canEdit']);

        return array_values(array_map(fn ($c) => ['id' => (string) $c['id'], 'name' => (string) $c['name'], 'primary' => (bool) ($c['isDefaultCalendar'] ?? false)],
            array_filter((array) ($r['value'] ?? []), fn ($c) => (bool) ($c['canEdit'] ?? true))));
    }

    public function create(array $creds, string $calendarId, array $item): string
    {
        $path = $calendarId === 'primary' ? '/me/events' : '/me/calendars/'.rawurlencode($calendarId).'/events';

        return (string) $this->call($creds, 'POST', self::API.$path, $this->body($item))['id'];
    }

    public function update(array $creds, string $calendarId, string $remoteId, array $item): void
    {
        $this->call($creds, 'PATCH', self::API.'/me/events/'.rawurlencode($remoteId), $this->body($item));
    }

    public function delete(array $creds, string $calendarId, string $remoteId): void
    {
        $this->call($creds, 'DELETE', self::API.'/me/events/'.rawurlencode($remoteId));
    }

    /**
     * @param  array<string, mixed>  $i
     * @return array<string, mixed>
     */
    private function body(array $i): array
    {
        $when = fn ($t) => ['dateTime' => $i['all_day'] ? $t->format('Y-m-d\T00:00:00') : $t->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];

        return ['subject' => $i['title'], 'body' => ['contentType' => 'text', 'content' => $i['description']], 'start' => $when($i['start']), 'end' => $when($i['end']),
            'isAllDay' => $i['all_day'], 'location' => ['displayName' => (string) ($i['location'] ?? '')], 'categories' => ['Team to Team Referrals']];
    }
}
