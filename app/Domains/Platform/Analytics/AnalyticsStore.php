<?php

declare(strict_types=1);

namespace App\Domains\Platform\Analytics;

/**
 * Where analytics events live: MySQL (a separate database is recommended) or ClickHouse. Events are upserted by
 * their key, so shipping the same row twice replaces rather than duplicates. Dashboards read the query methods.
 */
interface AnalyticsStore
{
    public function name(): string;

    /** @param list<array{event_key: string, event_type: string, occurred_at: string, team_id: ?int, counterpart_team_id: ?int, group_id: ?int, industry_id: ?int, city_id: ?int, referral_id: ?int, amount_cents: int, value: int}> $events */
    public function upsert(array $events): void;

    /**
     * Daily series for a team or a network.
     *
     * @param  list<string>  $types
     * @return list<array{day: string, event_type: string, events: int, value: int, amount_cents: int}>
     */
    public function daily(string $scope, int $id, string $from, string $to, array $types = []): array;

    /** @return array{ok: bool, detail: string} */
    public function health(): array;

    /** Remove everything (backfill from zero). */
    public function reset(): void;
}
