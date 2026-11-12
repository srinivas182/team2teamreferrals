<?php

declare(strict_types=1);

namespace App\Domains\Platform\Analytics;

use Illuminate\Support\Facades\DB;
use Throwable;

/** The analytics store on MySQL: events plus daily rollups rebuilt for the days each batch touches. */
final class MySqlStore implements AnalyticsStore
{
    public function name(): string
    {
        return 'mysql';
    }

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection(config('analytics.connection'));
    }

    public function upsert(array $events): void
    {
        if ($events === []) {
            return;
        }
        $db = $this->db();
        foreach (array_chunk($events, 500) as $chunk) {
            $db->table('analytics_events')->upsert($chunk, ['event_key'], ['event_type', 'occurred_at', 'team_id', 'counterpart_team_id', 'group_id', 'industry_id', 'city_id', 'referral_id', 'amount_cents', 'value']);
        }
        // Rebuild the rollups for each team/network and day this batch touched.
        foreach (['team' => 'team_id', 'group' => 'group_id'] as $scope => $col) {
            $touched = collect($events)->filter(fn ($e) => $e[$col] !== null)->map(fn ($e) => $e[$col].'|'.substr($e['occurred_at'], 0, 10))->unique();
            foreach ($touched as $key) {
                [$id, $day] = explode('|', $key);
                $db->table('analytics_daily')->where(['scope' => $scope, 'scope_id' => (int) $id, 'day' => $day])->delete();
                $rows = $db->table('analytics_events')->where($col, (int) $id)->whereBetween('occurred_at', [$day.' 00:00:00', $day.' 23:59:59'])
                    ->groupBy('event_type')->selectRaw('event_type, count(*) as events, coalesce(sum(value), 0) as value, coalesce(sum(amount_cents), 0) as amount_cents')->get();
                $db->table('analytics_daily')->insert($rows->map(fn ($r) => ['day' => $day, 'scope' => $scope, 'scope_id' => (int) $id, 'event_type' => (string) $r->event_type,
                    'events' => (int) $r->events, 'value' => (int) $r->value, 'amount_cents' => (int) $r->amount_cents])->all());
            }
        }
    }

    public function daily(string $scope, int $id, string $from, string $to, array $types = []): array
    {
        return array_values($this->db()->table('analytics_daily')->where(['scope' => $scope, 'scope_id' => $id])->whereBetween('day', [$from, $to])
            ->when($types !== [], fn ($q) => $q->whereIn('event_type', $types))->orderBy('day')->get()
            ->map(fn ($r) => ['day' => substr((string) $r->day, 0, 10), 'event_type' => (string) $r->event_type, 'events' => (int) $r->events, 'value' => (int) $r->value, 'amount_cents' => (int) $r->amount_cents])->all());
    }

    public function health(): array
    {
        try {
            return ['ok' => true, 'detail' => number_format($this->db()->table('analytics_events')->count()).' events stored'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 160)];
        }
    }

    public function reset(): void
    {
        $this->db()->table('analytics_events')->delete();
        $this->db()->table('analytics_daily')->delete();
    }
}
