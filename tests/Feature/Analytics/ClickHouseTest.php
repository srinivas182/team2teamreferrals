<?php

use App\Domains\Platform\Analytics\ClickHouseStore;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\Artisan;

/*
 * Runs against a real ClickHouse (the CI test job starts one). Skipped where none is available.
 */
beforeEach(function () {
    if (! getenv('CLICKHOUSE_URL')) {
        // In CI these must run: a missing ClickHouse there is a failure, never a silent skip.
        expect(getenv('GITHUB_ACTIONS'))->toBeFalsy('CI must provide CLICKHOUSE_URL for the analytics tests.');
        $this->markTestSkipped('No ClickHouse here (CI runs these).');
    }
    $this->store = new ClickHouseStore((string) getenv('CLICKHOUSE_URL'), (string) getenv('CLICKHOUSE_USER'), (string) getenv('CLICKHOUSE_PASSWORD'), 't2t_test');
    $this->store->install();
    $this->store->reset();
});

it('stores events, replaces a re-shipped event, and answers daily queries', function () {
    $e = fn (string $key, int $value) => ['event_key' => $key, 'event_type' => 'referral.sent', 'occurred_at' => now()->format('Y-m-d H:i:s'), 'team_id' => 5, 'counterpart_team_id' => 6,
        'group_id' => 2, 'industry_id' => 1, 'city_id' => 9, 'referral_id' => 77, 'amount_cents' => 0, 'value' => $value];
    $this->store->upsert([$e('re:1', 1), $e('re:2', 1)]);
    $this->store->upsert([$e('re:1', 5)]); // shipped again: replaces
    $rows = $this->store->daily('team', 5, now()->toDateString(), now()->toDateString());
    expect($rows[0]['events'])->toBe(2)->and($rows[0]['value'])->toBe(6)->and($this->store->health()['ok'])->toBeTrue();
});

it('DONE WHEN: dashboard queries return in under 500 ms over 10 million events', function () {
    $s = app(Settings::class);
    $s->set('analytics.driver', 'clickhouse');
    foreach (['url' => getenv('CLICKHOUSE_URL'), 'user' => getenv('CLICKHOUSE_USER'), 'database' => 't2t_bench'] as $k => $v) {
        $s->set("analytics.clickhouse.{$k}", (string) $v);
    }
    $s->set('analytics.clickhouse.password', (string) getenv('CLICKHOUSE_PASSWORD'), secret: true);
    $code = Artisan::call('t2t:analytics', ['action' => 'benchmark', '--events' => 10_000_000, '--max-ms' => 500]);
    fwrite(STDERR, "\n".Artisan::output());
    expect($code)->toBe(0);
});
