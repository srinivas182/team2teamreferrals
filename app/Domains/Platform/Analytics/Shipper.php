<?php

declare(strict_types=1);

namespace App\Domains\Platform\Analytics;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Ships the platform's own records into the analytics store. The records are the source of truth, so nothing is lost:
 * each source resumes from its saved position (append-only tables by id; records that change by update time), and a
 * backfill is the same process from zero. Events carry IDs, types, amounts and times only, never client names,
 * phones, emails or notes. Every event has a fixed key, so shipping a row again replaces rather than duplicates.
 */
final class Shipper
{
    public const SOURCES = ['referral_events', 'seat_events', 'credit_ledger', 'feed_posts', 'referral_fees', 'invoices', 'event_rsvps', 'profile_stats'];

    public function __construct(private readonly AnalyticsStore $store) {}

    /** @return array<string, int> events shipped per source */
    public function run(): array
    {
        $out = [];
        foreach (self::SOURCES as $source) {
            $out[$source] = $this->ship($source);
        }

        return $out;
    }

    public function backfill(): int
    {
        $this->store->reset();
        DB::table('analytics_cursors')->delete();
        $total = 0;
        do {
            $n = array_sum($this->run());
            $total += $n;
        } while ($n > 0);

        return $total;
    }

    /** @return array<string, array{position: string, shipped: int, shippedAt: ?string, pending: int}> */
    public function status(): array
    {
        $out = [];
        foreach (self::SOURCES as $s) {
            $c = DB::table('analytics_cursors')->where('source', $s)->first();
            $out[$s] = ['position' => (string) ($c->position ?? '0'), 'shipped' => (int) ($c->shipped ?? 0), 'shippedAt' => $c->shipped_at ?? null, 'pending' => $this->pending($s, (string) ($c->position ?? '0'))];
        }

        return $out;
    }

    private function ship(string $source): int
    {
        $batch = (int) config('analytics.batch', 5000);
        $position = (string) (DB::table('analytics_cursors')->where('source', $source)->value('position') ?? '0');
        [$rows, $next] = $this->read($source, $position, $batch);
        $events = [];
        foreach ($rows as $row) {
            foreach ($this->map($source, $row) as $e) {
                $events[] = $e;
            }
        }
        $this->store->upsert($events);
        if ($rows !== [] || $source === 'profile_stats') {
            DB::table('analytics_cursors')->upsert([['source' => $source, 'position' => $next, 'shipped' => (int) (DB::table('analytics_cursors')->where('source', $source)->value('shipped') ?? 0) + count($events), 'shipped_at' => now()]],
                ['source'], ['position', 'shipped', 'shipped_at']);
        }

        return $source === 'profile_stats' && $position !== '0' ? 0 : count($rows);
    }

    /** @return array{0: list<stdClass>, 1: string} rows after the position, and the new position */
    private function read(string $source, string $position, int $batch): array
    {
        if ($source === 'profile_stats') {
            // Daily counters incremented in place: everything once, then today and yesterday on each run.
            $rows = DB::table('profile_stats')->when($position !== '0', fn ($q) => $q->where('day', '>=', now()->subDay()->toDateString()))->get()->all();

            return [array_values($rows), 'recent'];
        }
        if (in_array($source, ['referral_fees', 'invoices', 'event_rsvps'], true)) {
            [$at, $id] = $position === '0' ? ['1970-01-01 00:00:00', 0] : explode('|', $position) + [1 => 0];
            $rows = DB::table($source)->where(fn ($q) => $q->where('updated_at', '>', $at)->orWhere(fn ($w) => $w->where('updated_at', $at)->where('id', '>', (int) $id)))
                ->orderBy('updated_at')->orderBy('id')->limit($batch)->get()->all();
            $last = end($rows);

            return [array_values($rows), $last !== false ? $last->updated_at.'|'.$last->id : $position];
        }
        $rows = DB::table($source)->where('id', '>', (int) $position)->orderBy('id')->limit($batch)->get()->all();
        $last = end($rows);

        return [array_values($rows), $last !== false ? (string) $last->id : $position];
    }

    private function pending(string $source, string $position): int
    {
        if ($source === 'profile_stats') {
            return $position === '0' ? DB::table('profile_stats')->count() : 0;
        }
        if (in_array($source, ['referral_fees', 'invoices', 'event_rsvps'], true)) {
            $at = $position === '0' ? '1970-01-01 00:00:00' : explode('|', $position)[0];

            return DB::table($source)->where('updated_at', '>', $at)->count();
        }

        return DB::table($source)->where('id', '>', (int) $position)->count();
    }

    /**
     * One analytics event: IDs, type, amount and time only, each field set explicitly (no client personal data).
     *
     * @param  array<string, int|null>  $more
     * @return array{event_key: string, event_type: string, occurred_at: string, team_id: ?int, counterpart_team_id: ?int, group_id: ?int, industry_id: ?int, city_id: ?int, referral_id: ?int, amount_cents: int, value: int}
     */
    private static function event(string $key, string $type, mixed $at, ?int $team, array $more): array
    {
        $id = fn (string $k) => isset($more[$k]) ? (int) $more[$k] : null;

        return ['event_key' => $key, 'event_type' => $type, 'occurred_at' => substr((string) $at, 0, 19), 'team_id' => $team, 'counterpart_team_id' => $id('counterpart_team_id'),
            'group_id' => $id('group_id'), 'industry_id' => $id('industry_id'), 'city_id' => $id('city_id'), 'referral_id' => $id('referral_id'),
            'amount_cents' => (int) ($more['amount_cents'] ?? 0), 'value' => (int) ($more['value'] ?? 1)];
    }

    /** @return list<array{event_key: string, event_type: string, occurred_at: string, team_id: ?int, counterpart_team_id: ?int, group_id: ?int, industry_id: ?int, city_id: ?int, referral_id: ?int, amount_cents: int, value: int}> */
    private function map(string $source, stdClass $r): array
    {
        $e = fn (string $key, string $type, mixed $at, ?int $team = null, array $more = []) => self::event($key, $type, $at, $team, $more);
        $ref = fn (?int $id) => $id ? DB::table('referrals as r')->join('seats as s', 's.id', '=', 'r.seat_id')->join('groups as g', 'g.id', '=', 'r.group_id')->where('r.id', $id)
            ->first(['r.id', 'r.group_id', 'r.sender_team_id', 'r.receiver_team_id', 's.territory_id', 'g.industry_id']) : null;
        $ctx = fn (?stdClass $x) => $x ? ['group_id' => (int) $x->group_id, 'industry_id' => (int) $x->industry_id, 'city_id' => (int) $x->territory_id, 'referral_id' => (int) $x->id] : [];

        switch ($source) {
            case 'referral_events':
                $x = $ref((int) $r->referral_id);
                $team = $r->team_id !== null ? (int) $r->team_id : ($x !== null ? (int) $x->receiver_team_id : null);
                $other = $x === null ? null : ($team === (int) $x->sender_team_id ? (int) $x->receiver_team_id : (int) $x->sender_team_id);

                return [$e('re:'.$r->id, 'referral.'.$r->event, $r->created_at, $team, [...$ctx($x), 'counterpart_team_id' => $other])];
            case 'seat_events':
                $s = DB::table('seats as s')->join('groups as g', 'g.id', '=', 's.group_id')->where('s.id', $r->seat_id)->first(['s.group_id', 's.territory_id', 'g.industry_id']);

                return [$e('se:'.$r->id, 'seat.'.$r->event, $r->created_at, $r->team_id !== null ? (int) $r->team_id : null,
                    $s ? ['group_id' => (int) $s->group_id, 'industry_id' => (int) $s->industry_id, 'city_id' => (int) $s->territory_id] : [])];
            case 'credit_ledger':
                return [$e('cl:'.$r->id, 'credit.'.$r->kind, $r->created_at, (int) $r->team_id, [...$ctx($ref((int) $r->referral_id)), 'value' => (int) $r->credits])];
            case 'feed_posts':
                return [$e('fp:'.$r->id, 'community.'.$r->kind, $r->created_at, $r->team_id !== null ? (int) $r->team_id : null, ['group_id' => (int) $r->group_id])];
            case 'referral_fees':
                $x = $ctx($ref((int) $r->referral_id));
                $money = ['amount_cents' => (int) $r->amount_cents];

                return array_values(array_filter([
                    $e('fee:'.$r->id.':due', 'fee.due', $r->created_at, (int) $r->payer_team_id, [...$x, ...$money, 'counterpart_team_id' => (int) $r->payee_team_id]),
                    $r->paid_at ? $e('fee:'.$r->id.':paid', 'fee.paid', $r->paid_at, (int) $r->payer_team_id, [...$x, ...$money, 'counterpart_team_id' => (int) $r->payee_team_id]) : null,
                    $r->received_at ? $e('fee:'.$r->id.':received', 'fee.received', $r->received_at, (int) $r->payee_team_id, [...$x, ...$money, 'counterpart_team_id' => (int) $r->payer_team_id]) : null,
                ]));
            case 'invoices':
                $team = $r->owner_type === 'team' ? (int) $r->owner_id : null;

                return array_filter([
                    $e('inv:'.$r->id, 'billing.invoiced', $r->created_at, $team, ['amount_cents' => (int) $r->total_cents]),
                    $r->paid_at ? $e('inv:'.$r->id.':paid', 'billing.paid', $r->paid_at, $team, ['amount_cents' => (int) $r->total_cents]) : null,
                ]);
            case 'event_rsvps':
                return array_filter([
                    $e('rsvp:'.$r->id, 'community.rsvp_'.$r->status, $r->updated_at, $r->team_id !== null ? (int) $r->team_id : null),
                    $r->checked_in_at ? $e('rsvp:'.$r->id.':in', 'community.checked_in', $r->checked_in_at, $r->team_id !== null ? (int) $r->team_id : null) : null,
                ]);
            default: // profile_stats: one event per team, day and kind, valued at the day's count
                return [$e('ps:'.$r->team_id.':'.$r->day.':'.$r->kind, 'profile.'.$r->kind, $r->day.' 12:00:00', (int) $r->team_id, ['value' => (int) $r->count])];
        }
    }
}
