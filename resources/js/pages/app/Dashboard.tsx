import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, Timer } from 'lucide-react';
import { useEffect, useState } from 'react';
import { LineChart } from '@/components/charts/LineChart';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type CardT = { key: string; label: string; kind: string; value: number | null; previous: number | null; change: number | null; small: boolean };
type Action = { key: string; title: string; body: string; action: string; label: string; due: string | null; referral: string };
type View = { id: number; name: string; preset: string; from: string | null; to: string | null; default: boolean };
type Period = { preset: string; from: string; to: string; label: string };
type Props = {
    period: Period;
    presets: Record<string, string>;
    cards: CardT[];
    series: { day: string; sent: number; accepted: number; fees: number }[];
    actions: Action[];
    credits: number;
    views: View[];
    status?: string;
    seo: Seo;
};

export const show = (v: number | null, kind: string) =>
    v === null
        ? '—'
        : kind === 'money'
          ? `$${(v / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`
          : kind === 'percent'
            ? `${v}%`
            : kind === 'minutes'
              ? v < 60
                  ? `${v} min`
                  : `${(v / 60).toFixed(1)} h`
              : v.toLocaleString();

const query = (p: { preset: string; from?: string | null; to?: string | null }) =>
    p.preset === 'custom' ? `?preset=custom&from=${p.from}&to=${p.to}` : `?preset=${p.preset}`;

/** Time left until a deadline, updating live. */
function Countdown({ due }: { due: string }) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const t = window.setInterval(() => setNow(Date.now()), 30000);
        return () => window.clearInterval(t);
    }, []);
    const ms = new Date(due).getTime() - now;
    const h = Math.floor(Math.abs(ms) / 3600000);
    const m = Math.floor((Math.abs(ms) % 3600000) / 60000);
    const text = h >= 48 ? `${Math.floor(h / 24)} days` : `${h}h ${m}m`;
    return (
        <span className={`text-xs font-semibold tabular ${ms < 0 ? 'text-danger' : ms < 4 * 3600000 ? 'text-warning' : 'text-muted'}`}>
            {ms < 0 ? `${text} overdue` : `${text} left`}
        </span>
    );
}

/** Portal → Dashboard: your numbers for a period, compared with the one before; every number opens its records. */
export default function Dashboard({ period, presets, cards, series, actions, credits, views, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [custom, setCustom] = useState({ from: period.from, to: period.to });
    const save = useForm({ name: '', preset: period.preset, from: period.from, to: period.to, default: false });
    const go = (p: { preset: string; from?: string | null; to?: string | null }) =>
        router.get(`/app/dashboard${query(p)}`, {}, { preserveScroll: true });
    const q = query(period);
    return (
        <PortalLayout title="Dashboard" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-6xl space-y-5">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <Card className="flex flex-wrap items-center gap-2 p-3 text-sm">
                    <select
                        aria-label="Period"
                        className="h-9 rounded-md border border-line bg-surface px-2"
                        value={period.preset}
                        onChange={(e) => (e.target.value === 'custom' ? null : go({ preset: e.target.value }))}
                    >
                        {Object.entries(presets).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <Input
                        type="date"
                        aria-label="From"
                        className="h-9 w-40"
                        value={custom.from}
                        onChange={(e) => setCustom({ ...custom, from: e.target.value })}
                    />
                    <Input
                        type="date"
                        aria-label="To"
                        className="h-9 w-40"
                        value={custom.to}
                        onChange={(e) => setCustom({ ...custom, to: e.target.value })}
                    />
                    <Button size="sm" variant="secondary" onClick={() => go({ preset: 'custom', ...custom })}>
                        Apply dates
                    </Button>
                    {views.length > 0 && (
                        <select
                            aria-label="Saved views"
                            className="h-9 rounded-md border border-line bg-surface px-2"
                            value=""
                            onChange={(e) => {
                                const v = views.find((x) => String(x.id) === e.target.value);
                                if (v) go(v);
                            }}
                        >
                            <option value="">Saved views…</option>
                            {views.map((v) => (
                                <option key={v.id} value={v.id}>
                                    {v.name}
                                    {v.default ? ' (default)' : ''}
                                </option>
                            ))}
                        </select>
                    )}
                    <span className="ml-auto flex gap-2">
                        <a href={`/app/dashboard/export.csv${q}`} className="inline-flex h-9 items-center gap-1 rounded-md border border-line px-3">
                            <Download className="size-4" aria-hidden /> CSV
                        </a>
                        <a href={`/app/dashboard/export.pdf${q}`} className="inline-flex h-9 items-center gap-1 rounded-md border border-line px-3">
                            <Download className="size-4" aria-hidden /> PDF
                        </a>
                    </span>
                </Card>
                <p className="text-sm text-muted">
                    {period.label}: {period.from} to {period.to}, compared with the{' '}
                    {Math.round((new Date(period.to).getTime() - new Date(period.from).getTime()) / 86400000) + 1} days before.
                </p>
                {actions.length > 0 && (
                    <Card className="space-y-2 p-4">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <Timer className="size-4 text-primary" aria-hidden /> Do next
                        </h2>
                        <ul className="divide-y divide-line">
                            {actions.map((a) => (
                                <li key={a.referral + a.key} className="flex flex-wrap items-center gap-2 py-2 text-sm">
                                    <span className="min-w-0 flex-1">
                                        <span className="font-medium">{a.title}</span> <span className="text-muted">{a.body}</span>
                                    </span>
                                    {a.due && <Countdown due={a.due} />}
                                    <Link href={a.action} className="font-medium text-primary">
                                        {a.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    {cards.map((c) => (
                        <Link
                            key={c.key}
                            href={c.key === 'trust' ? '/app/performance' : `/app/dashboard/kpi/${c.key}${q}`}
                            className="rounded-lg border border-line bg-surface p-4 hover:border-primary"
                        >
                            <p className="text-xs text-muted">{c.label}</p>
                            <p className="font-display text-2xl font-bold tabular">
                                {c.key === 'trust' && c.value === null ? 'New' : show(c.value, c.kind)}
                            </p>
                            {c.change !== null && (
                                <p className={`text-xs ${c.change >= 0 ? 'text-success' : 'text-danger'}`}>
                                    {c.change >= 0 ? '▲' : '▼'} {Math.abs(c.change)}% vs before
                                    {c.small && <span className="text-muted"> · small numbers</span>}
                                </p>
                            )}
                        </Link>
                    ))}
                    <Link href="/app/credits" className="rounded-lg border border-line bg-surface p-4 hover:border-primary">
                        <p className="text-xs text-muted">Credit balance</p>
                        <p className="font-display text-2xl font-bold tabular">{credits}</p>
                    </Link>
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="p-4">
                        <LineChart
                            title="Referrals by day"
                            data={series}
                            x="day"
                            series={[
                                { key: 'sent', label: 'Sent', color: '#2563eb' },
                                { key: 'accepted', label: 'Accepted by you', color: '#16a34a' },
                            ]}
                        />
                    </Card>
                    <Card className="p-4">
                        <LineChart
                            title="Fees received by day"
                            data={series}
                            x="day"
                            series={[{ key: 'fees', label: 'Fees received', color: '#ea580c', format: (n) => `$${Math.round(n / 100)}` }]}
                        />
                    </Card>
                </div>
                <Card className="flex flex-wrap items-center gap-2 p-3 text-sm">
                    <span className="font-medium">Save this view:</span>
                    <Input
                        aria-label="View name"
                        className="h-9 w-48"
                        placeholder="Name, e.g. This quarter"
                        value={save.data.name}
                        onChange={(e) => save.setData('name', e.target.value)}
                    />
                    <label className="flex items-center gap-1">
                        <input type="checkbox" checked={save.data.default} onChange={(e) => save.setData('default', e.target.checked)} /> Open with
                        this view
                    </label>
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={!save.data.name}
                        onClick={() => save.post('/app/dashboard/views', { preserveScroll: true, onSuccess: () => save.reset('name') })}
                    >
                        Save
                    </Button>
                    {views.map((v) => (
                        <button
                            key={v.id}
                            className="text-xs text-muted underline"
                            onClick={() =>
                                window.confirm(`Delete “${v.name}”?`) &&
                                router.post(`/app/dashboard/views/${v.id}/delete`, {}, { preserveScroll: true })
                            }
                        >
                            Delete “{v.name}”
                        </button>
                    ))}
                </Card>
            </div>
        </PortalLayout>
    );
}
