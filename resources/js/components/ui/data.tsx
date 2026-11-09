import { Clock, TrendingDown, TrendingUp } from 'lucide-react';
import * as React from 'react';
import { Card } from './card';
import { cn } from '@/lib/utils';

type KpiProps = { label: string; value: string; change?: number; lowerIsBetter?: boolean; hint?: string; spark?: number[] };

/** Key number with period-over-period change; "lowerIsBetter" flips the colour for times and breaches. */
export function Kpi({ label, value, change, lowerIsBetter = false, hint, spark }: KpiProps) {
    const up = (change ?? 0) >= 0;
    const good = lowerIsBetter ? !up : up;
    const max = spark ? Math.max(...spark, 1) : 1;
    return (
        <Card className="h-full p-5">
            <div className="text-sm text-muted">{label}</div>
            <div className="mt-2 flex items-end justify-between gap-3">
                <div className="font-display text-[28px] leading-none font-bold tabular">{value}</div>
                {spark && (
                    <svg viewBox={`0 0 ${(spark.length - 1) * 12} 28`} className="h-7 w-20" aria-hidden>
                        <polyline
                            points={spark.map((v, i) => `${i * 12},${28 - (v / max) * 24}`).join(' ')}
                            fill="none"
                            stroke="var(--color-cobalt-500)"
                            strokeWidth="2"
                            strokeLinejoin="round"
                            strokeLinecap="round"
                        />
                    </svg>
                )}
            </div>
            {change !== undefined && (
                <div className={cn('mt-3 flex items-center gap-1 text-sm font-medium', good ? 'text-success' : 'text-danger')}>
                    {up ? <TrendingUp className="size-4" aria-hidden /> : <TrendingDown className="size-4" aria-hidden />}
                    {up ? '+' : ''}
                    {change}% <span className="font-normal text-muted">vs last month</span>
                </div>
            )}
            {hint && <div className="mt-3 text-sm text-muted">{hint}</div>}
        </Card>
    );
}

/** SLA countdown; colour shifts at 50% and 90% of the allowed time. */
export function Countdown({ minutesLeft, total, overdue = false }: { minutesLeft: number; total?: number; overdue?: boolean }) {
    const late = overdue || minutesLeft <= 0;
    const used = total ? 1 - minutesLeft / total : 0;
    const tone =
        late || used >= 0.9
            ? 'bg-[#FDECEE] text-danger dark:bg-danger/20 dark:text-[#FDA4AF]'
            : used >= 0.5
              ? 'bg-[#FEF9E7] text-warning dark:bg-warning/20 dark:text-[#FCD34D]'
              : 'bg-navy-100 text-navy-700 dark:bg-navy-800 dark:text-navy-200';
    const h = Math.floor(minutesLeft / 60);
    const m = minutesLeft % 60;
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold tabular', tone)}>
            <Clock className="size-3" aria-hidden />
            {late ? 'Overdue' : h ? `${h}h ${m}m left` : `${m}m left`}
        </span>
    );
}

type SegmentedProps<T extends string> = { label: string; value: T; options: [T, string][]; onChange: (v: T) => void };

/** Small set of mutually exclusive options (e.g. Board / List). Arrow keys move between options. */
export function Segmented<T extends string>({ label, value, options, onChange }: SegmentedProps<T>) {
    const refs = React.useRef<(HTMLButtonElement | null)[]>([]);
    const onKey = (e: React.KeyboardEvent, i: number) => {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        const next = (i + (e.key === 'ArrowRight' ? 1 : -1) + options.length) % options.length;
        onChange(options[next]![0]);
        refs.current[next]?.focus();
    };
    return (
        <div role="radiogroup" aria-label={label} className="inline-flex rounded-md border border-line bg-surface p-0.5">
            {options.map(([v, l], i) => (
                <button
                    key={v}
                    ref={(el) => {
                        refs.current[i] = el;
                    }}
                    type="button"
                    role="radio"
                    aria-checked={value === v}
                    tabIndex={value === v ? 0 : -1}
                    onKeyDown={(e) => onKey(e, i)}
                    onClick={() => onChange(v)}
                    className={cn(
                        'rounded px-3 py-1.5 text-sm font-medium',
                        value === v ? 'bg-cobalt-50 text-cobalt-700 dark:bg-cobalt-500/20 dark:text-cobalt-200' : 'text-muted hover:text-ink',
                    )}
                >
                    {l}
                </button>
            ))}
        </div>
    );
}
