<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Calendar;

use App\Domains\Integrations\Providers\CrmException;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Calendar or Microsoft Outlook through OAuth (the platform's app, set up in Admin → Integrations). Errors
 * use CrmException kinds: auth (the person must sign in again), gone (the entry was deleted there), retry.
 *
 * Each item is: title, description, start, end (CarbonImmutable, UTC), all_day, location, url.
 */
abstract class CalendarProvider
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract protected function authorizeUrl(): string;

    abstract protected function tokenUrl(): string;

    /** @return list<string> */
    abstract protected function scopes(): array;

    /**
     * @param  array<string, mixed>  $tokens  the token endpoint's answer
     */
    abstract protected function emailFrom(array $tokens): ?string;

    /**
     * @param  array<string, mixed>  $creds
     * @return list<array{id: string, name: string, primary: bool}>
     */
    abstract public function calendars(array $creds): array;

    /**
     * @param  array<string, mixed>  $creds
     * @param  array<string, mixed>  $item
     */
    abstract public function create(array $creds, string $calendarId, array $item): string;

    /**
     * @param  array<string, mixed>  $creds
     * @param  array<string, mixed>  $item
     */
    abstract public function update(array $creds, string $calendarId, string $remoteId, array $item): void;

    /** @param array<string, mixed> $creds */
    abstract public function delete(array $creds, string $calendarId, string $remoteId): void;

    public function ready(): bool
    {
        $s = app(Settings::class);

        return $s->get("integrations.{$this->key()}.enabled", '1') === '1' && $s->has("integrations.{$this->key()}.client_id") && $s->has("integrations.{$this->key()}.client_secret");
    }

    public static function redirectUri(string $provider): string
    {
        return url("/app/calendar/{$provider}/callback");
    }

    public function authUrl(string $state): string
    {
        return $this->authorizeUrl().'?'.http_build_query(['client_id' => $this->clientId(), 'redirect_uri' => self::redirectUri($this->key()), 'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()), 'state' => $state, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true']);
    }

    /** @return array{creds: array<string, mixed>, email: ?string} */
    public function exchange(string $code): array
    {
        $t = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri($this->key())]);
        if (empty($t['refresh_token'])) {
            throw new CrmException("{$this->label()} didn’t allow offline access. Please connect again and approve all the requested access.", 'auth');
        }

        return ['creds' => ['refresh_token' => (string) $t['refresh_token'], 'access_token' => (string) $t['access_token'], 'expires_at' => now()->addSeconds((int) ($t['expires_in'] ?? 3600) - 60)->getTimestamp()], 'email' => $this->emailFrom($t)];
    }

    /**
     * A fresh access token when the saved one has run out.
     *
     * @param  array<string, mixed>  $creds
     * @return array<string, mixed>
     */
    public function fresh(array $creds): array
    {
        if ((int) ($creds['expires_at'] ?? 0) > now()->getTimestamp()) {
            return $creds;
        }
        $t = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => (string) ($creds['refresh_token'] ?? '')]);

        return [...$creds, 'access_token' => (string) $t['access_token'], 'refresh_token' => (string) ($t['refresh_token'] ?? $creds['refresh_token']), 'expires_at' => now()->addSeconds((int) ($t['expires_in'] ?? 3600) - 60)->getTimestamp()];
    }

    /**
     * @param  array<string, mixed>  $creds
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function call(array $creds, string $method, string $url, array $data = []): array
    {
        try {
            $req = Http::withToken((string) ($creds['access_token'] ?? ''))->acceptJson()->timeout(15)->connectTimeout(5);
            /** @var Response $res */
            $res = match ($method) {
                'GET' => $req->get($url, $data),
                'DELETE' => $req->delete($url),
                default => $req->send($method, $url, ['json' => $data]),
            };
        } catch (ConnectionException) {
            throw new CrmException("Couldn’t reach {$this->label()}. It will be retried.");
        }
        if (in_array($res->status(), [404, 410], true)) {
            throw new CrmException('Not found.', 'gone');
        }
        if (! $res->successful()) {
            throw CrmException::fromStatus($this->label(), $res->status(), $res->body());
        }

        return (array) ($res->json() ?? []);
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function token(array $params): array
    {
        try {
            $res = Http::asForm()->acceptJson()->timeout(15)->post($this->tokenUrl(), [...$params, 'client_id' => $this->clientId(), 'client_secret' => (string) app(Settings::class)->get("integrations.{$this->key()}.client_secret")]);
        } catch (ConnectionException) {
            throw new CrmException("Couldn’t reach {$this->label()}. It will be retried.");
        }
        if (! $res->successful() || ! isset($res['access_token'])) {
            // invalid_grant: the person removed access, or the sign-in expired.
            throw new CrmException("{$this->label()} no longer accepts this connection. Please connect your calendar again.", $res->status() >= 500 ? 'retry' : 'auth');
        }

        return (array) $res->json();
    }

    private function clientId(): string
    {
        return (string) app(Settings::class)->get("integrations.{$this->key()}.client_id");
    }
}
