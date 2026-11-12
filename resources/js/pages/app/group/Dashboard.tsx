import { Link, router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import { show } from '@/pages/app/Dashboard';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type CardT = { key: string; label: string; kind: string; value: number | null; previous: number | null; change: number | null; small: boolean };
type Leader = { team: string; value: number };
type CheckIn = { teamId: number; team: string; reasons: string[]; checkedIn: string | null };
type Props = {
    group: { slug: string; name: string; restricted: boolean };
    groups: { slug: string; name: string }[];
    period: { preset: string; from: string; to: string; label: string };
    presets: Record<string, string>;
    cards: CardT[];
    leaders: { referrers: Leader[]; closers: Leader[]; basis: string };
    checkIns: CheckIn[];
    template: string;
    status?: string;
    seo: Seo;
};

function CheckInRow({ c, slug, template }: { c: CheckIn; slug: string; template: string }) {
    const [open, setOpen] = useState(false);
    const [msg, setMsg] = useState(template);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    return (
        <li className="space-y-2 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{c.team}</span>
                <span className="text-muted">{c.reasons.join(' · ')}</span>
                <span className="ml-auto">
                    {c.checkedIn ? (
                        <span className="text-xs text-muted">Checked in {c.checkedIn}</span>
                    ) : (
                        <Button size="sm" variant="secondary" onClick={() => setOpen(!open)}>
                            Send a check-in
                        </Button>
                    )}
                </span>
            </div>
            {open && (
                <div className="space-y-2">
                    <textarea
                        aria-label="Message"
                        rows={3}
                        className="w-full rounded-md border border-line bg-surface p-2"
                        value={msg}
                        onChange={(e) => setMsg(e.target.value)}
                    />
                    {errors.team && <p className="text-danger">{errors.team}</p>}
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(
                                `/app/group/${slug}/dashboard/check-in`,
                                { team: c.teamId, message: msg },
                                { preserveScroll: true, onSuccess: () => setOpen(false) },
                            )
                        }
                    >
                        Send
                    </Button>
                </div>
            )}
        </li>
    );
}

/** The network owner’s dashboard. Every number opens its records; teams to check in with are visible to owners only. */
export default function OwnerDashboard({ group, groups, period, presets, cards, leaders, checkIns, template, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const base = `/app/group/${group.slug}/dashboard`;
    const q = period.preset === 'custom' ? `?preset=custom&from=${period.from}&to=${period.to}` : `?preset=${period.preset}`;
    return (
        <PortalLayout title={`${group.name}: dashboard`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-6xl space-y-5">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <Card className="flex flex-wrap items-center gap-2 p-3 text-sm">
                    {groups.length > 1 && (
                        <select
                            aria-label="Network"
                            className="h-9 rounded-md border border-line bg-surface px-2"
                            value={group.slug}
                            onChange={(e) => router.get(`/app/group/${e.target.value}/dashboard${q}`)}
                        >
                            {groups.map((g) => (
                                <option key={g.slug} value={g.slug}>
                                    {g.name}
                                </option>
                            ))}
                        </select>
                    )}
                    <select
                        aria-label="Period"
                        className="h-9 rounded-md border border-line bg-surface px-2"
                        value={period.preset}
                        onChange={(e) => router.get(`${base}?preset=${e.target.value}`)}
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
                    <a href={`${base}/export.csv${q}`} className="ml-auto inline-flex h-9 items-center gap-1 rounded-md border border-line px-3">
                        <Download className="size-4" aria-hidden /> CSV
                    </a>
                </Card>
                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    {cards.map((c) => (
                        <Link
                            key={c.key}
                            href={`${base}/kpi/${c.key}${q}`}
                            className="rounded-lg border border-line bg-surface p-4 hover:border-primary"
                        >
                            <p className="text-xs text-muted">{c.label}</p>
                            <p className="font-display text-2xl font-bold tabular">{show(c.value, c.kind)}</p>
                            {c.change !== null && (
                                <p
                                    className={`text-xs ${c.change >= 0 ? (c.key === 'breaches' ? 'text-danger' : 'text-success') : c.key === 'breaches' ? 'text-success' : 'text-danger'}`}
                                >
                                    {c.change >= 0 ? '▲' : '▼'} {Math.abs(c.change)}% vs before
                                    {c.small && <span className="text-muted"> · small numbers</span>}
                                </p>
                            )}
                        </Link>
                    ))}
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="p-4">
                        <h2 className="mb-2 text-sm font-semibold">{leaders.basis === 'service' ? 'Best service' : 'Top referrers'}</h2>
                        {leaders.basis === 'service' && (
                            <p className="mb-2 text-xs text-muted">In this industry, rankings use service quality only.</p>
                        )}
                        <ol className="space-y-1 text-sm">
                            {leaders.referrers.length === 0 && <li className="text-muted">Nothing yet in this period.</li>}
                            {leaders.referrers.map((l, i) => (
                                <li key={l.team} className="flex justify-between">
                                    <span>
                                        {i + 1}. {l.team}
                                    </span>
                                    <span className="text-muted tabular">{l.value}</span>
                                </li>
                            ))}
                        </ol>
                    </Card>
                    {leaders.basis !== 'service' && (
                        <Card className="p-4">
                            <h2 className="mb-2 text-sm font-semibold">Top closers</h2>
                            <ol className="space-y-1 text-sm">
                                {leaders.closers.length === 0 && <li className="text-muted">Nothing yet in this period.</li>}
                                {leaders.closers.map((l, i) => (
                                    <li key={l.team} className="flex justify-between">
                                        <span>
                                            {i + 1}. {l.team}
                                        </span>
                                        <span className="text-muted tabular">{l.value}</span>
                                    </li>
                                ))}
                            </ol>
                        </Card>
                    )}
                </div>
                <Card className="p-4">
                    <h2 className="text-sm font-semibold">Teams to check in with</h2>
                    <p className="text-xs text-muted">Only you see this. A friendly note often helps; one check-in a week per team.</p>
                    {checkIns.length === 0 ? (
                        <p className="py-3 text-sm text-muted">Everyone is on track.</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {checkIns.map((c) => (
                                <CheckInRow key={c.teamId} c={c} slug={group.slug} template={template} />
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </PortalLayout>
    );
}
