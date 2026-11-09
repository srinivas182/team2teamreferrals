<?php

declare(strict_types=1);

namespace App\Domains\Platform\Deliveries;

/** Green, amber or red from the last hour of sends, so problems show before members report them. */
final class ProviderHealth
{
    /** @return array{status: 'healthy'|'degraded'|'failing'|'idle', sent: int, failed: int, failureRate: float} */
    public static function for(string $channel): array
    {
        $rows = MessageDelivery::query()->where('channel', $channel)->where('created_at', '>=', now()->subHour())
            ->selectRaw("count(*) as total, sum(case when status in ('failed','bounced') then 1 else 0 end) as failed")->first();
        $total = (int) ($rows?->getAttribute('total') ?? 0);
        $failed = (int) ($rows?->getAttribute('failed') ?? 0);
        $rate = $total > 0 ? round($failed / $total, 3) : 0.0;

        return [
            'status' => $total === 0 ? 'idle' : ($rate >= 0.2 ? 'failing' : ($rate >= 0.05 ? 'degraded' : 'healthy')),
            'sent' => $total,
            'failed' => $failed,
            'failureRate' => $rate,
        ];
    }
}
