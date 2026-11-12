import { useState } from 'react';

type Series = { key: string; label: string; color: string; format?: (n: number) => string };
type Props = { title: string; data: Record<string, number | string>[]; x: string; series: Series[]; height?: number };

/**
 * A light SVG line chart (no chart library), with "Show as table" for screen readers and exact figures.
 */
export function LineChart({ title, data, x, series, height = 160 }: Props) {
    const [table, setTable] = useState(false);
    const w = 600;
    const pad = 24;
    const max = Math.max(1, ...data.flatMap((d) => series.map((s) => Number(d[s.key] ?? 0))));
    const px = (i: number) => pad + (i * (w - 2 * pad)) / Math.max(1, data.length - 1);
    const py = (v: number) => height - pad - (v / max) * (height - 2 * pad);
    const fmt = (s: Series, v: number) => (s.format ? s.format(v) : String(v));
    return (
        <figure className="space-y-2">
            <figcaption className="flex items-center justify-between text-sm font-medium">
                {title}
                <button type="button" className="text-xs font-normal text-primary" onClick={() => setTable(!table)} aria-pressed={table}>
                    {table ? 'Show as chart' : 'Show as table'}
                </button>
            </figcaption>
            {table ? (
                <div className="max-h-64 overflow-auto">
                    <table className="w-full text-xs">
                        <thead className="text-left text-muted">
                            <tr>
                                <th scope="col" className="py-1 pr-3 font-medium">
                                    Day
                                </th>
                                {series.map((s) => (
                                    <th key={s.key} scope="col" className="py-1 pr-3 font-medium">
                                        {s.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((d) => (
                                <tr key={String(d[x])} className="border-t border-line">
                                    <td className="py-1 pr-3 tabular">{String(d[x])}</td>
                                    {series.map((s) => (
                                        <td key={s.key} className="py-1 pr-3 tabular">
                                            {fmt(s, Number(d[s.key] ?? 0))}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <svg
                    viewBox={`0 0 ${w} ${height}`}
                    className="w-full"
                    role="img"
                    aria-label={`${title}: ${series
                        .map(
                            (s) =>
                                `${s.label} ${fmt(
                                    s,
                                    data.reduce((a, d) => a + Number(d[s.key] ?? 0), 0),
                                )} in total`,
                        )
                        .join(', ')}`}
                >
                    <line x1={pad} x2={w - pad} y1={height - pad} y2={height - pad} className="stroke-line" />
                    {series.map((s) => (
                        <polyline
                            key={s.key}
                            fill="none"
                            stroke={s.color}
                            strokeWidth={2}
                            points={data.map((d, i) => `${px(i)},${py(Number(d[s.key] ?? 0))}`).join(' ')}
                        />
                    ))}
                    <text x={pad} y={14} className="fill-muted text-[10px]">
                        {series.length === 1 ? fmt(series[0]!, max) : max}
                    </text>
                </svg>
            )}
            <div className="flex gap-3 text-xs text-muted">
                {series.map((s) => (
                    <span key={s.key} className="flex items-center gap-1">
                        <span className="inline-block size-2 rounded-full" style={{ background: s.color }} aria-hidden /> {s.label}
                    </span>
                ))}
            </div>
        </figure>
    );
}
