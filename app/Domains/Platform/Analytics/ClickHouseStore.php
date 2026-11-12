<?php

declare(strict_types=1);

namespace App\Domains\Platform\Analytics;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The analytics store on ClickHouse (over its HTTP interface). Events are a ReplacingMergeTree keyed by event, so a
 * re-shipped row replaces the earlier copy; dashboards aggregate per day directly, which ClickHouse does in
 * milliseconds even over tens of millions of events.
 */
final class ClickHouseStore implements AnalyticsStore
{
    public function __construct(private readonly string $url, private readonly string $user, private readonly string $password, private readonly string $database) {}

    public function name(): string
    {
        return 'clickhouse';
    }

    public function install(): void
    {
        $this->run("CREATE DATABASE IF NOT EXISTS {$this->database}");
        $this->run("CREATE TABLE IF NOT EXISTS {$this->database}.events (
            event_key String, event_type LowCardinality(String), occurred_at DateTime,
            team_id Nullable(UInt64), counterpart_team_id Nullable(UInt64), group_id Nullable(UInt64), industry_id Nullable(UInt64),
            city_id Nullable(UInt64), referral_id Nullable(UInt64), amount_cents Int64, value Int64, version UInt64
        ) ENGINE = ReplacingMergeTree(version) ORDER BY (event_key)");
        // Pre-computed daily totals per team and network: what dashboards read. Recomputed (not summed on insert) for the
        // days a batch touches, because re-shipped events replace earlier copies and must not be counted twice.
        $this->run("CREATE TABLE IF NOT EXISTS {$this->database}.daily (
            day Date, scope LowCardinality(String), scope_id UInt64, event_type LowCardinality(String), events UInt64, value Int64, amount_cents Int64, version UInt64
        ) ENGINE = ReplacingMergeTree(version) ORDER BY (scope, scope_id, day, event_type)");
    }

    public function upsert(array $events): void
    {
        if ($events === []) {
            return;
        }
        $version = (int) (microtime(true) * 1000);
        $body = implode("\n", array_map(fn ($e) => json_encode([...$e, 'version' => $version]), $events));
        $this->run("INSERT INTO {$this->database}.events FORMAT JSONEachRow", $body);
        foreach (['team' => 'team_id', 'group' => 'group_id'] as $scope => $col) {
            $keys = collect($events)->filter(fn ($e) => $e[$col] !== null)->map(fn ($e) => '('.(int) $e[$col].",'".substr($e['occurred_at'], 0, 10)."')")->unique()->implode(',');
            if ($keys !== '') {
                $this->rebuildDaily($scope, $col, "({$col}, toDate(occurred_at)) IN ({$keys})", $version);
            }
        }
    }

    /** Recompute the daily totals matching a condition from the (deduplicated) events. */
    private function rebuildDaily(string $scope, string $col, string $where, int $version): void
    {
        $this->run("INSERT INTO {$this->database}.daily SELECT toDate(occurred_at) AS day, '{$scope}', assumeNotNull({$col}), event_type, count(), sum(value), sum(amount_cents), {$version}
            FROM {$this->database}.events FINAL WHERE {$col} IS NOT NULL AND {$where} GROUP BY day, {$col}, event_type");
    }

    public function daily(string $scope, int $id, string $from, string $to, array $types = []): array
    {
        $scope = $scope === 'group' ? 'group' : 'team';
        $typeFilter = $types === [] ? '' : ' AND event_type IN ('.implode(',', array_map(fn ($t) => "'".addslashes($t)."'", $types)).')';
        // Reads the small pre-computed daily table, not the events.
        $sql = "SELECT toString(day) AS day, event_type, events, value, amount_cents
            FROM {$this->database}.daily FINAL WHERE scope = '{$scope}' AND scope_id = {$id} AND day BETWEEN '{$from}' AND '{$to}'{$typeFilter}
            ORDER BY day FORMAT JSON";
        $rows = (array) (json_decode($this->run($sql), true)['data'] ?? []);

        return array_values(array_map(fn ($r) => ['day' => (string) $r['day'], 'event_type' => (string) $r['event_type'], 'events' => (int) $r['events'], 'value' => (int) $r['value'], 'amount_cents' => (int) $r['amount_cents']], $rows));
    }

    public function health(): array
    {
        try {
            $n = trim($this->run("SELECT count() FROM {$this->database}.events"));

            return ['ok' => true, 'detail' => number_format((int) $n).' events stored'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 160)];
        }
    }

    public function reset(): void
    {
        $this->run("TRUNCATE TABLE IF EXISTS {$this->database}.events");
        $this->run("TRUNCATE TABLE IF EXISTS {$this->database}.daily");
    }

    /** For the speed check: generate events inside ClickHouse itself. */
    public function generate(int $count, int $teams, int $groups): void
    {
        $this->run("INSERT INTO {$this->database}.events SELECT concat('bench:', toString(number)), ['referral.sent','referral.accepted','referral.first_contact','referral.updated','fee.received'][number % 5 + 1],
            now() - toIntervalSecond(number % 31536000), number % {$teams} + 1, (number + 7) % {$teams} + 1, number % {$groups} + 1, 1, number % 500 + 1, number, if(number % 5 = 4, 50000, 0), 1, 1
            FROM numbers({$count})");
        $v = (int) (microtime(true) * 1000);
        $this->rebuildDaily('team', 'team_id', '1', $v);
        $this->rebuildDaily('group', 'group_id', '1', $v);
    }

    private function run(string $sql, ?string $body = null): string
    {
        $req = Http::withBasicAuth($this->user, $this->password)->timeout(120);
        $res = $body === null ? $req->withBody($sql, 'text/plain')->post($this->url) : $req->withBody($body, 'application/x-ndjson')->post($this->url.'/?query='.rawurlencode($sql));
        if ($res->failed()) {
            throw new RuntimeException('ClickHouse: '.mb_substr($res->body(), 0, 300));
        }

        return $res->body();
    }
}
