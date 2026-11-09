import { Badge, type BadgeTone } from '@/components/ui/badge';

export type Health = { status: 'healthy' | 'degraded' | 'failing' | 'idle'; sent: number; failed: number; failureRate: number };

const map: Record<Health['status'], [BadgeTone, string]> = {
    healthy: ['success', 'Healthy'],
    degraded: ['warning', 'Some failures'],
    failing: ['danger', 'Failing'],
    idle: ['neutral', 'No sends in the last hour'],
};

/** Provider health from the last hour of sends. Label and colour together, never colour alone. */
export function HealthBadge({ health }: { health: Health }) {
    const [tone, label] = map[health.status];
    return (
        <Badge tone={tone}>
            {label}
            {health.sent > 0 && (
                <span className="font-normal tabular">
                    {' '}
                    · {health.failed}/{health.sent} failed
                </span>
            )}
        </Badge>
    );
}
