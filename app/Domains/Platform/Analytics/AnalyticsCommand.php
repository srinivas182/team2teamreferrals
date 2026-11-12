<?php

declare(strict_types=1);

namespace App\Domains\Platform\Analytics;

use Illuminate\Console\Command;

final class AnalyticsCommand extends Command
{
    protected $signature = 't2t:analytics {action=ship : ship, backfill, status or benchmark} {--events=10000000 : benchmark events} {--max-ms=500 : benchmark limit per query}';

    protected $description = 'Ship records to the analytics store (every minute), backfill from zero, show status, or run the speed check';

    public function handle(AnalyticsStore $store, Shipper $shipper): int
    {
        return match ((string) $this->argument('action')) {
            'ship' => $this->report($shipper->run()),
            'backfill' => $this->done('Backfilled '.number_format($shipper->backfill()).' records into '.$store->name().'.'),
            'status' => $this->status($store, $shipper),
            'benchmark' => $this->benchmark($store),
            default => self::INVALID,
        };
    }

    /** @param array<string, int> $shipped */
    private function report(array $shipped): int
    {
        $list = collect($shipped)->filter()->map(fn ($n, $s) => "{$s} {$n}")->implode(', ');
        $this->line('Shipped: '.($list !== '' ? $list : 'nothing new'));

        return self::SUCCESS;
    }

    private function done(string $msg): int
    {
        $this->info($msg);

        return self::SUCCESS;
    }

    private function status(AnalyticsStore $store, Shipper $shipper): int
    {
        $h = $store->health();
        $this->line($store->name().': '.($h['ok'] ? 'OK' : 'NOT OK').' · '.$h['detail']);
        foreach ($shipper->status() as $s => $st) {
            $this->line(sprintf('  %-16s %s shipped, %s waiting', $s, number_format($st['shipped']), number_format($st['pending'])));
        }

        return $h['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** The PRD's speed target: dashboard queries under 500 ms over 10 million events (ClickHouse). */
    private function benchmark(AnalyticsStore $store): int
    {
        if (! $store instanceof ClickHouseStore) {
            $this->error('The speed check runs on ClickHouse (switch the analytics store in Admin → Settings → Analytics).');

            return self::FAILURE;
        }
        $n = (int) $this->option('events');
        $store->install();
        $store->reset();
        $t = microtime(true);
        $store->generate($n, 5000, 46);
        $this->line(sprintf('Generated %s events in %.1fs', number_format($n), microtime(true) - $t));
        $max = (int) $this->option('max-ms');
        $failed = false;
        foreach (['team' => 1234, 'group' => 7] as $scope => $id) {
            $t = microtime(true);
            $rows = $store->daily($scope, $id, now()->subYear()->toDateString(), now()->toDateString());
            $ms = (int) round((microtime(true) - $t) * 1000);
            $this->line(sprintf('%s dashboard (a year, daily): %d ms, %d rows', $scope, $ms, count($rows)));
            $failed = $failed || $ms > $max || $rows === [];
        }
        $store->reset();

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
