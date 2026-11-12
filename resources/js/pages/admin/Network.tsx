import { Link, router } from '@inertiajs/react';
import { LineChart } from '@/components/charts/LineChart';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import { show } from '@/pages/app/Dashboard';
import type { Seo } from '@/types/seo';

type CardT = { key: string; label: string; kind: string; value: number | null; previous: number | null; change: number | null; small: boolean };
type Revenue = {
    rows: { type: string; label: string; cents: number; previous: number }[];
    net: number;
    previousNet: number;
    refunds: number;
    tax: number;
    invoices: number;
};
type Props = {
    period: { preset: string; from: string; to: string; label: string };
    presets: Record<string, string>;
    cards: CardT[];
    trend: { day: string; referrals: number; breaches: number }[];
    top: { groups: { name: string; slug: string; referrals: number }[]; cities: { name: string; referrals: number }[] };
    revenue: Revenue | null;
    seo: Seo;
};

/** Admin → Network dashboard: the whole network at a glance. Every number opens its records. */
export default function Network({ period, presets, cards, trend, top, revenue, seo }: Props) {
    const q = `?preset=${period.preset}`;
    return (
        <AdminLayout title="Network dashboard">
            <SeoHead seo={seo} />
            <div className="max-w-6xl space-y-5">
                <div className="flex items-center gap-2 text-sm">
                    <select
                        aria-label="Period"
                        className="h-9 rounded-md border border-line bg-surface px-2"
                        value={period.preset}
                        onChange={(e) => router.get(`/admin/network?preset=${e.target.value}`)}
                    >
                        {Object.entries(presets)
                            .filter(([k]) => k !== 'custom')
                            .map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                    </select>
                    <span className="text-muted">
                        {period.from} to {period.to}
                    </span>
                </div>
                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {cards.map((c) => (
                        <Link
                            key={c.key}
                            href={`/admin/network/kpi/${c.key}${q}`}
                            className="rounded-lg border border-line bg-surface p-4 hover:border-primary"
                        >
                            <p className="text-xs text-muted">{c.label}</p>
                            <p className="font-display text-2xl font-bold tabular">{show(c.value, c.kind)}</p>
                            {c.change !== null && (
                                <p className={`text-xs ${c.change >= 0 !== (c.key === 'breaches') ? 'text-success' : 'text-danger'}`}>
                                    {c.change >= 0 ? '▲' : '▼'} {Math.abs(c.change)}%{c.small && <span className="text-muted"> · small numbers</span>}
                                </p>
                            )}
                        </Link>
                    ))}
                </div>
                {revenue && (
                    <Card className="space-y-2 p-4">
                        <div className="flex items-baseline gap-3">
                            <h2 className="font-semibold">Net revenue {show(revenue.net, 'money')}</h2>
                            <span className="text-sm text-muted">previous period {show(revenue.previousNet, 'money')}</span>
                            <Link href={`/admin/revenue${q}`} className="ml-auto text-sm font-medium text-primary">
                                By type and invoices
                            </Link>
                        </div>
                        <p className="text-xs text-muted">
                            Platform revenue only: referral fees between teams aren’t revenue (the platform never holds them).
                        </p>
                    </Card>
                )}
                <Card className="p-4">
                    <LineChart
                        title="SLA trend: referrals and breaches by day"
                        data={trend}
                        x="day"
                        series={[
                            { key: 'referrals', label: 'Referrals', color: '#2563eb' },
                            { key: 'breaches', label: 'Breaches', color: '#dc2626' },
                        ]}
                    />
                </Card>
                <div className="grid gap-4 lg:grid-cols-2">
                    {(
                        [
                            ['Top networks', top.groups.map((g) => [g.name, g.referrals, `/admin/referrals?group=${g.slug}`] as const)],
                            [
                                'Top cities',
                                top.cities.map(
                                    (c) => [c.name, c.referrals, `/admin/referrals?city=${encodeURIComponent(c.name.split(',')[0] ?? '')}`] as const,
                                ),
                            ],
                        ] as const
                    ).map(([title, rows]) => (
                        <Card key={title} className="p-4">
                            <h2 className="mb-2 text-sm font-semibold">{title}</h2>
                            {rows.length === 0 && <p className="text-sm text-muted">Nothing in this period.</p>}
                            <ol className="space-y-1 text-sm">
                                {rows.map(([name, n, href], i) => (
                                    <li key={name} className="flex justify-between">
                                        <Link href={href} className="hover:underline">
                                            {i + 1}. {name}
                                        </Link>
                                        <span className="text-muted tabular">{n}</span>
                                    </li>
                                ))}
                            </ol>
                        </Card>
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}
