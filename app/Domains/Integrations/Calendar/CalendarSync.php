<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Calendar;

use App\Domains\Identity\Models\User;
use App\Domains\Integrations\Providers\CrmException;
use App\Domains\Messaging\Notifier;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Writes each person's calendar items into their Google or Microsoft calendar (Sprint 45b). Every 10 minutes (and on
 * "Sync now") the items they should have are compared with what was written before: new ones are added, changed
 * ones updated in place, and ones that are gone (a cancelled event, an answered referral) removed.
 */
final class CalendarSync
{
    public const PROVIDERS = ['google' => GoogleCalendar::class, 'microsoft' => MicrosoftCalendar::class];

    public static function provider(string $key): CalendarProvider
    {
        $class = self::PROVIDERS[$key] ?? throw new InvalidArgumentException("Unknown calendar {$key}");

        return app($class);
    }

    /** After the person approved access: save the connection and fill the calendar. */
    public static function connect(User $user, string $provider, string $code): CalendarConnection
    {
        $p = self::provider($provider);
        $r = $p->exchange($code);
        $c = CalendarConnection::query()->firstOrNew(['user_id' => $user->id, 'provider' => $provider]);
        $c->fill(['status' => 'active', 'credentials' => $r['creds'], 'account_email' => $r['email'], 'kinds' => $c->exists ? $c->kinds : array_keys(CalendarItems::KINDS)]);
        if (! $c->exists) {
            $c->fill(['calendar_id' => 'primary', 'calendar_name' => null]);
        }
        $c->last_error = null;
        $c->save();
        activity('integrations')->causedBy($user)->event('calendar-connected')->withProperties(['provider' => $provider])->log($p->label().' connected');
        self::sync($c);

        return $c;
    }

    /** @return list<array{id: string, name: string, primary: bool}> */
    public static function calendars(CalendarConnection $c): array
    {
        return self::withToken($c, fn (array $creds) => self::provider($c->provider)->calendars($creds)) ?? [];
    }

    /**
     * Change which calendar and which kinds of items. Moving to another calendar clears the old one first.
     *
     * @param  list<string>  $kinds
     */
    public static function update(CalendarConnection $c, ?string $calendarId, ?string $calendarName, array $kinds): void
    {
        if ($calendarId !== null && $calendarId !== $c->calendar_id) {
            self::clear($c);
            $c->forceFill(['calendar_id' => $calendarId, 'calendar_name' => $calendarName]);
        }
        $c->forceFill(['kinds' => array_values(array_intersect(array_keys(CalendarItems::KINDS), $kinds))])->save();
        self::sync($c);
    }

    /** Remove what we added, then the connection. */
    public static function disconnect(CalendarConnection $c): void
    {
        if ($c->status === 'active') {
            self::clear($c);
        }
        activity('integrations')->causedBy($c->user)->event('calendar-disconnected')->withProperties(['provider' => $c->provider])->log(self::provider($c->provider)->label().' disconnected');
        $c->delete();
    }

    /** @return array{added: int, updated: int, removed: int}|null null when the calendar couldn't be reached */
    public static function sync(CalendarConnection $c): ?array
    {
        if ($c->status !== 'active' || ! self::provider($c->provider)->ready()) {
            return null;
        }
        $p = self::provider($c->provider);
        $want = collect(CalendarItems::for($c->user, $c->kinds))->keyBy('key');
        $have = DB::table('calendar_items')->where('calendar_connection_id', $c->id)->get()->keyBy('item_key');
        $n = ['added' => 0, 'updated' => 0, 'removed' => 0];

        return self::withToken($c, function (array $creds) use ($c, $p, $want, $have, &$n) {
            foreach ($want as $key => $item) {
                $hash = CalendarItems::hash($item);
                $row = $have[$key] ?? null;
                if ($row !== null && $row->hash === $hash) {
                    continue;
                }
                if ($row !== null) {
                    try {
                        $p->update($creds, $c->calendar_id, (string) $row->remote_id, $item);
                        DB::table('calendar_items')->where('id', $row->id)->update(['hash' => $hash, 'updated_at' => now()]);
                        $n['updated']++;

                        continue;
                    } catch (CrmException $e) {
                        if ($e->kind !== 'gone') {
                            throw $e;
                        }
                        DB::table('calendar_items')->where('id', $row->id)->delete(); // deleted there: add it again
                    }
                }
                $id = $p->create($creds, $c->calendar_id, $item);
                DB::table('calendar_items')->insert(['calendar_connection_id' => $c->id, 'item_key' => $key, 'remote_id' => $id, 'hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
                $n['added']++;
            }
            foreach ($have as $key => $row) {
                if (! $want->has($key)) {
                    self::remove($p, $creds, $c, $row);
                    $n['removed']++;
                }
            }
            $c->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

            return $n;
        });
    }

    /** Every 10 minutes. */
    public static function syncAll(): void
    {
        foreach (CalendarConnection::query()->with('user')->where('status', 'active')->get() as $c) {
            try {
                self::sync($c);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private static function clear(CalendarConnection $c): void
    {
        $p = self::provider($c->provider);
        self::withToken($c, function (array $creds) use ($c, $p) {
            foreach (DB::table('calendar_items')->where('calendar_connection_id', $c->id)->get() as $row) {
                self::remove($p, $creds, $c, $row);
            }

            return true;
        });
    }

    /** @param array<string, mixed> $creds */
    private static function remove(CalendarProvider $p, array $creds, CalendarConnection $c, stdClass $row): void
    {
        try {
            $p->delete($creds, $c->calendar_id, (string) $row->remote_id);
        } catch (CrmException $e) {
            if ($e->kind !== 'gone') {
                throw $e;
            }
        }
        DB::table('calendar_items')->where('id', $row->id)->delete();
    }

    /**
     * Run with a fresh access token; a refused sign-in marks the connection and tells the person (once).
     *
     * @template T
     *
     * @param  Closure(array<string, mixed>): T  $fn
     * @return T|null
     */
    private static function withToken(CalendarConnection $c, Closure $fn): mixed
    {
        $p = self::provider($c->provider);
        try {
            $creds = $p->fresh($c->credentials);
            if ($creds !== $c->credentials) {
                $c->forceFill(['credentials' => $creds])->save();
            }

            return $fn($creds);
        } catch (CrmException $e) {
            if ($e->kind === 'auth') {
                $first = $c->status !== 'error';
                $c->forceFill(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
                if ($first) {
                    app(Notifier::class)->send($c->user, 'integrations.attention', ['headline' => "Reconnect {$p->label()}",
                        'body' => "{$p->label()} stopped accepting the connection, so your calendar isn’t updating. Connect it again in Integrations."], url('/app/integrations'));
                }
            } else {
                $c->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
            }

            return null;
        }
    }
}
