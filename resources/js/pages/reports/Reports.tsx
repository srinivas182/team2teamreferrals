import { router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Dataset = { key: string; label: string; columns: Record<string, string>; groupBy: Record<string, string> };
type Schedule = {
    id: number;
    frequency: string;
    weekday: number | null;
    day: number | null;
    hour: number;
    timezone: string;
    paused: boolean;
    next: string;
    recipients: string[];
};
type Report = { id: string; name: string; dataset: string; preset: string; groupBy: string | null; schedules: Schedule[] };
type Props = {
    base: string;
    previewUrl: string;
    scope: string | null;
    datasets: Dataset[];
    presets: Record<string, string>;
    reports: Report[];
    deliveries: { report: string; to: string | null; status: string; attempts: number; rows: number; error: string | null; updated_at: string }[];
    recipients: { id: number; name: string }[];
    timezone: string;
    preview?: { columns: string[]; rows: (string | number)[][]; total: number } | null;
    status?: string;
    seo: Seo;
};

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const sel = 'h-9 rounded-md border border-line bg-surface px-2 text-sm';
const opts = { preserveScroll: true, preserveState: true } as const;

function ScheduleForm({ r, p }: { r: Report; p: Props }) {
    const [f, setF] = useState({
        frequency: 'weekly',
        weekday: '1',
        day: '1',
        hour: '8',
        timezone: p.timezone,
        recipients: p.recipients.length === 1 ? [p.recipients[0]!.id] : ([] as number[]),
    });
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    return (
        <div className="flex flex-wrap items-center gap-2 text-sm">
            <select aria-label="How often" className={sel} value={f.frequency} onChange={(e) => setF({ ...f, frequency: e.target.value })}>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
            </select>
            {f.frequency === 'weekly' ? (
                <select aria-label="Day" className={sel} value={f.weekday} onChange={(e) => setF({ ...f, weekday: e.target.value })}>
                    {DAYS.map((d, i) => (
                        <option key={d} value={i + 1}>
                            {d}
                        </option>
                    ))}
                </select>
            ) : (
                <select aria-label="Day of the month" className={sel} value={f.day} onChange={(e) => setF({ ...f, day: e.target.value })}>
                    {Array.from({ length: 28 }, (_, i) => (
                        <option key={i} value={i + 1}>
                            Day {i + 1}
                        </option>
                    ))}
                </select>
            )}
            <select aria-label="Hour" className={sel} value={f.hour} onChange={(e) => setF({ ...f, hour: e.target.value })}>
                {Array.from({ length: 24 }, (_, h) => (
                    <option key={h} value={h}>
                        {String(h).padStart(2, '0')}:00
                    </option>
                ))}
            </select>
            <Input
                aria-label="Backup time zone"
                title="Reports go out at the chosen hour in each recipient’s own time zone; this is used only if theirs can’t be read"
                className="h-9 w-48"
                value={f.timezone}
                onChange={(e) => setF({ ...f, timezone: e.target.value })}
            />
            {p.recipients.length > 1 && (
                <select
                    aria-label="Recipients"
                    multiple
                    className="h-20 rounded-md border border-line bg-surface px-2 text-sm"
                    value={f.recipients.map(String)}
                    onChange={(e) => setF({ ...f, recipients: Array.from(e.target.selectedOptions).map((o) => Number(o.value)) })}
                >
                    {p.recipients.map((u) => (
                        <option key={u.id} value={u.id}>
                            {u.name}
                        </option>
                    ))}
                </select>
            )}
            <Button size="sm" onClick={() => router.post(`${p.base}/${r.id}/schedules`, f, opts)}>
                Schedule by email
            </Button>
            {(errors.recipients || errors.timezone) && <span className="text-danger">{errors.recipients ?? errors.timezone}</span>}
        </div>
    );
}

/** Reports: build, preview, save, export and schedule. Client contact details are never included. */
export default function Reports(p: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [d, setD] = useState({
        dataset: p.datasets[0]?.key ?? 'referrals',
        columns: [] as string[],
        group_by: '',
        preset: 'last_30',
        filters: { industry: '', state: '', stage: '' },
        name: '',
    });
    const ds = p.datasets.find((x) => x.key === d.dataset);
    const payload = { ...d, group_by: d.group_by || null };
    const body: ReactNode = (
        <div className="max-w-6xl space-y-4">
            <SeoHead seo={p.seo} />
            {p.status && <Alert tone="success">{statusMessage(p.status)}</Alert>}
            <Card className="space-y-3 p-4">
                <h2 className="font-semibold">Build a report{p.scope ? ` for ${p.scope}` : ''}</h2>
                <div className="flex flex-wrap gap-2">
                    <select
                        aria-label="Dataset"
                        className={sel}
                        value={d.dataset}
                        onChange={(e) => setD({ ...d, dataset: e.target.value, columns: [], group_by: '' })}
                    >
                        {p.datasets.map((x) => (
                            <option key={x.key} value={x.key}>
                                {x.label}
                            </option>
                        ))}
                    </select>
                    <select aria-label="Period" className={sel} value={d.preset} onChange={(e) => setD({ ...d, preset: e.target.value })}>
                        {Object.entries(p.presets)
                            .filter(([k]) => k !== 'custom')
                            .map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                    </select>
                    {ds && Object.keys(ds.groupBy).length > 0 && (
                        <select aria-label="Group by" className={sel} value={d.group_by} onChange={(e) => setD({ ...d, group_by: e.target.value })}>
                            <option value="">No grouping (rows)</option>
                            {Object.entries(ds.groupBy).map(([k, l]) => (
                                <option key={k} value={k}>
                                    Count by {l.toLowerCase()}
                                </option>
                            ))}
                        </select>
                    )}
                    {(d.dataset === 'referrals' || d.dataset === 'seats') && (
                        <>
                            <Input
                                aria-label="State"
                                placeholder="State (e.g. TX)"
                                className="h-9 w-32"
                                value={d.filters.state}
                                onChange={(e) => setD({ ...d, filters: { ...d.filters, state: e.target.value } })}
                            />
                            <Input
                                aria-label="Industry"
                                placeholder="Industry (slug)"
                                className="h-9 w-40"
                                value={d.filters.industry}
                                onChange={(e) => setD({ ...d, filters: { ...d.filters, industry: e.target.value } })}
                            />
                        </>
                    )}
                    {d.dataset === 'referrals' && (
                        <select
                            aria-label="Stage"
                            className={sel}
                            value={d.filters.stage}
                            onChange={(e) => setD({ ...d, filters: { ...d.filters, stage: e.target.value } })}
                        >
                            <option value="">Any stage</option>
                            {['sent', 'accepted', 'declined', 'expired', 'won', 'lost'].map((s) => (
                                <option key={s} value={s}>
                                    {s}
                                </option>
                            ))}
                        </select>
                    )}
                </div>
                {ds && !d.group_by && (
                    <div className="flex flex-wrap gap-3 text-sm">
                        {Object.entries(ds.columns).map(([k, l]) => (
                            <label key={k} className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    checked={d.columns.length === 0 || d.columns.includes(k)}
                                    onChange={(e) => {
                                        const all = d.columns.length === 0 ? Object.keys(ds.columns) : d.columns;
                                        setD({ ...d, columns: e.target.checked ? [...all, k] : all.filter((c) => c !== k) });
                                    }}
                                />{' '}
                                {l}
                            </label>
                        ))}
                    </div>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" variant="secondary" onClick={() => router.post(p.previewUrl, payload, opts)}>
                        Preview
                    </Button>
                    <Input
                        aria-label="Report name"
                        placeholder="Name, e.g. Weekly revenue"
                        className="h-9 w-56"
                        value={d.name}
                        onChange={(e) => setD({ ...d, name: e.target.value })}
                    />
                    <Button size="sm" disabled={!d.name} onClick={() => router.post(p.base, payload, opts)}>
                        Save report
                    </Button>
                </div>
            </Card>
            {p.preview && (
                <Card className="overflow-x-auto p-4">
                    <p className="mb-2 text-sm text-muted">
                        {p.preview.total.toLocaleString()} rows
                        {p.preview.total > p.preview.rows.length ? ` (showing ${p.preview.rows.length}; export for all)` : ''}
                    </p>
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted">
                            <tr>
                                {p.preview.columns.map((c) => (
                                    <th key={c} scope="col" className="py-1 pr-3 font-medium">
                                        {c}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {p.preview.rows.map((r, i) => (
                                <tr key={i} className="border-t border-line">
                                    {r.map((v, j) => (
                                        <td key={j} className="py-1 pr-3">
                                            {String(v)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}
            {p.reports.map((r) => (
                <Card key={r.id} className="space-y-3 p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-semibold">{r.name}</h3>
                        <span className="text-sm text-muted">
                            {r.dataset} · {p.presets[r.preset]}
                            {r.groupBy ? ` · by ${r.groupBy}` : ''}
                        </span>
                        <a
                            href={`${p.base}/${r.id}/export.csv`}
                            className="ml-auto inline-flex h-9 items-center gap-1 rounded-md border border-line px-3 text-sm"
                        >
                            <Download className="size-4" aria-hidden /> CSV
                        </a>
                        <button
                            className="text-xs text-muted underline"
                            onClick={() =>
                                window.confirm(`Delete “${r.name}” and its schedules?`) && router.post(`${p.base}/${r.id}/delete`, {}, opts)
                            }
                        >
                            Delete
                        </button>
                    </div>
                    {r.schedules.map((s) => (
                        <div key={s.id} className="flex flex-wrap items-center gap-2 text-sm">
                            <span>
                                {s.frequency === 'weekly' ? `Every ${DAYS[(s.weekday ?? 1) - 1]}` : `Monthly on day ${s.day}`} at{' '}
                                {String(s.hour).padStart(2, '0')}:00 in each recipient’s own time zone to {s.recipients.join(', ')}
                            </span>
                            <span className="text-muted">{s.paused ? 'Paused' : `next ${s.next.slice(0, 16)} UTC`}</span>
                            <button
                                className="text-primary"
                                onClick={() => router.post(`${p.base}/schedules/${s.id}/${s.paused ? 'resume' : 'pause'}`, {}, opts)}
                            >
                                {s.paused ? 'Resume' : 'Pause'}
                            </button>
                            <button
                                className="text-muted underline"
                                onClick={() => window.confirm('Delete this schedule?') && router.post(`${p.base}/schedules/${s.id}/delete`, {}, opts)}
                            >
                                Delete
                            </button>
                        </div>
                    ))}
                    <ScheduleForm r={r} p={p} />
                </Card>
            ))}
            {p.deliveries.length > 0 && (
                <Card className="overflow-x-auto p-4">
                    <h2 className="mb-2 text-sm font-semibold">Delivery log</h2>
                    <table className="w-full text-sm">
                        <tbody>
                            {p.deliveries.map((x, i) => (
                                <tr key={i} className="border-t border-line">
                                    <td className="py-1 pr-3">{x.updated_at.slice(0, 16)}</td>
                                    <td className="py-1 pr-3">{x.report}</td>
                                    <td className="py-1 pr-3">{x.to}</td>
                                    <td className="py-1 pr-3">{x.status}</td>
                                    <td className="py-1 pr-3 text-muted">{x.error ?? `${x.rows} rows`}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}
        </div>
    );
    return p.scope ? (
        <PortalLayout title={`${p.scope}: reports`} user={{ name: auth.user?.name ?? '', team: '' }}>
            {body}
        </PortalLayout>
    ) : (
        <AdminLayout title="Reports">{body}</AdminLayout>
    );
}
