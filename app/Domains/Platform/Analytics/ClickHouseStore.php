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
    }

    public function upsert(array $events): void
    {
        if ($events === []) {
            return;
        }
        $version = (int) (microtime(true) * 1000);
        $body = implode("\n", array_map(fn ($e) => json_encode([...$e, 'version' => $version]), $events));
        $this->run("INSERT INTO {$this->database}.events FORMAT JSONEachRow", $body);
    }

    public function daily(string $scope, int $id, string $from, string $to, array $types = []): array
    {
        $col = $scope === 'group' ? 'group_id' : 'team_id';
        $typeFilter = $types === [] ? '' : ' AND event_type IN ('.implode(',', array_map(fn ($t) => "'".addslashes($t)."'", $types)).')';
        $sql = "SELECT toString(toDate(occurred_at)) AS day, event_type, count() AS events, sum(value) AS value, sum(amount_cents) AS amount_cents
            FROM {$this->database}.events FINAL WHERE {$col} = {$id} AND occurred_at BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'{$typeFilter}
            GROUP BY day, event_type ORDER BY day FORMAT JSON";
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
    }

    /** For the speed check: generate events inside ClickHouse itself. */
    public function generate(int $count, int $teams, int $groups): void
    {
        $this->run("INSERT INTO {$this->database}.events SELECT concat('bench:', toString(number)), ['referral.sent','referral.accepted','referral.first_contact','referral.updated','fee.received'][number % 5 + 1],
            now() - toIntervalSecond(number % 31536000), number % {$teams} + 1, (number + 7) % {$teams} + 1, number % {$groups} + 1, 1, number % 500 + 1, number, if(number % 5 = 4, 50000, 0), 1, 1
            FROM numbers({$count})");
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
