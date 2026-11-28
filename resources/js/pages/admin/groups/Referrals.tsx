import { Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { LedgerTable, LedgerTotalsRow, type LedgerRow, type LedgerTotals } from '@/components/admin/ReferralLedger';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Props = {
    group: { slug: string; name: string };
    basePath: string;
    admin: boolean;
    filters: Record<string, string>;
    totals: LedgerTotals;
    page: number;
    pages: number;
    stages: string[];
    rows: LedgerRow[];
    notRealReviews: { id: string; date: string; sender: string; receiver: string; reason: string; note: string }[];
    pausedTeams: PausedTeam[];
    seo: Seo;
};

/** A group's referrals (owners and staff): how many, the list with the money, and a CSV for any date range. */
export default function GroupReferrals({
    group,
    basePath,
    admin,
    filters,
    totals,
    page,
    pages,
    stages,
    rows,
    notRealReviews,
    pausedTeams,
    seo,
}: Props) {
    const [f, setF] = useState<Record<string, string>>(filters);
    const apply = (extra: Record<string, string | number> = {}) =>
        router.get(`${basePath}/referrals`, { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), ...extra }, { preserveState: true });
    const qs = new URLSearchParams(Object.entries(filters)).toString();
    return (
        <AdminLayout title={`Referrals · ${group.name}`}>
            <SeoHead seo={seo} />
            <div className="space-y-4">
                <Link href={admin ? `/admin/groups/${group.slug}` : `/app/group/${group.slug}`} className="text-sm font-medium text-primary">
                    ← {group.name}
                </Link>
                <Card className="flex flex-wrap items-end gap-2 p-3">
                    <label className="text-sm">
                        <span className="block text-muted">From</span>
                        <Input type="date" className="h-9 w-40" value={f.from ?? ''} onChange={(e) => setF({ ...f, from: e.target.value })} />
                    </label>
                    <label className="text-sm">
                        <span className="block text-muted">To</span>
                        <Input type="date" className="h-9 w-40" value={f.to ?? ''} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    </label>
                    <Select
                        aria-label="Lead status"
                        className="h-9 w-auto"
                        value={f.stage ?? ''}
                        onChange={(e) => setF({ ...f, stage: e.target.value })}
                    >
                        <option value="">Any status</option>
                        {stages.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </Select>
                    {(
                        [
                            ['city', 'City'],
                            ['sender', 'Referred by'],
                            ['receiver', 'Referred to'],
                        ] as const
                    ).map(([k, l]) => (
                        <Input
                            key={k}
                            aria-label={l}
                            placeholder={l}
                            className="h-9 w-36"
                            value={f[k] ?? ''}
                            onChange={(e) => setF({ ...f, [k]: e.target.value })}
                        />
                    ))}
                    <Button size="sm" onClick={() => apply()}>
                        Filter
                    </Button>
                    <a
                        href={`${basePath}/referrals.csv?${qs}`}
                        className="ml-auto inline-flex h-9 items-center gap-1 rounded-md border border-line px-3 text-sm"
                    >
                        <Download className="size-4" aria-hidden /> Export {filters.from || filters.to ? 'these dates' : 'all'} (CSV)
                    </a>
                </Card>
                {pausedTeams.length > 0 && (
                    <Card className="space-y-3 p-4 text-sm">
                        <h2 className="font-semibold">Sharing reward paused ({pausedTeams.length})</h2>
                        <p className="text-muted">
                            These teams had 3 referrals marked “not a real client” in 90 days, so they earn no free months in this group until the
                            date shown. Their seats and the referrals they receive are not affected.
                        </p>
                        <ul className="divide-y divide-line">
                            {pausedTeams.map((t) => (
                                <PausedTeamRow key={t.teamId} t={t} basePath={basePath} />
                            ))}
                        </ul>
                    </Card>
                )}
                {notRealReviews.length > 0 && (
                    <Card className="space-y-3 border-warning/40 p-4 text-sm">
                        <h2 className="font-semibold">“Not a real client” calls to review ({notRealReviews.length})</h2>
                        <p className="text-muted">
                            The receiving team said the client wasn’t real; the sending team disagrees. Reinstate if the client was real (it then
                            counts against the receiver), or uphold (it counts against the sender).
                        </p>
                        <ul className="divide-y divide-line">
                            {notRealReviews.map((n) => (
                                <li key={n.id} className="flex flex-wrap items-start justify-between gap-3 py-2">
                                    <span>
                                        {n.date} · {n.sender} → {n.receiver}
                                        <span className="block text-muted">
                                            {n.reason}: {n.note}
                                        </span>
                                    </span>
                                    <span className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            onClick={() =>
                                                router.post(`${basePath}/not-real/${n.id}`, { decision: 'reinstate' }, { preserveScroll: true })
                                            }
                                        >
                                            Reinstate
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(`${basePath}/not-real/${n.id}`, { decision: 'uphold' }, { preserveScroll: true })
                                            }
                                        >
                                            Uphold
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
                <LedgerTotalsRow totals={totals} />
                <LedgerTable rows={rows} showNetwork={false} />
                {pages > 1 && (
                    <div className="flex items-center gap-2 text-sm">
                        <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => apply({ page: page - 1 })}>
                            Previous
                        </Button>
                        <span>
                            Page {page} of {pages}
                        </span>
                        <Button size="sm" variant="secondary" disabled={page >= pages} onClick={() => apply({ page: page + 1 })}>
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

type PausedTeam = { team: string; teamId: number; count: number; until: string; pauses: number; kept: boolean };

/** One paused team: lift (with a reason), keep, or remove from the group (two steps, with a reason). */
function PausedTeamRow({ t, basePath }: { t: PausedTeam; basePath: string }) {
    const [mode, setMode] = useState<'none' | 'lift' | 'remove'>('none');
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const send = (action: 'lift' | 'keep' | 'remove') =>
        router.post(
            `${basePath}/sharing-pause/${t.teamId}`,
            { action, reason },
            { preserveScroll: true, onError: (e) => setError(e.reason ?? Object.values(e)[0] ?? null) },
        );
    return (
        <li className="space-y-2 py-3">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <span>
                    <span className="font-medium">{t.team}</span>
                    <span className="block text-muted">
                        {t.count} not-real in 90 days · paused until {t.until}
                        {t.pauses > 1 && ` · pause ${t.pauses} in 12 months`}
                        {t.kept && ' · you kept this pause'}
                    </span>
                </span>
                {mode === 'none' && (
                    <span className="flex flex-wrap gap-2">
                        <Button size="sm" variant="secondary" onClick={() => setMode('lift')}>
                            Lift pause
                        </Button>
                        {!t.kept && (
                            <Button size="sm" variant="ghost" onClick={() => send('keep')}>
                                Keep paused
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" className="text-danger" onClick={() => setMode('remove')}>
                            Remove team
                        </Button>
                    </span>
                )}
            </div>
            {mode !== 'none' && (
                <div className="flex flex-wrap items-end gap-2">
                    <label className="min-w-64 flex-1">
                        <span className="mb-1 block text-xs text-muted">
                            {mode === 'lift'
                                ? 'Why lift it? (kept in the activity log)'
                                : 'Why remove them? Their seats are offered to the next teams.'}
                        </span>
                        <Input value={reason} onChange={(e) => setReason(e.target.value)} aria-invalid={error !== null} />
                    </label>
                    <Button size="sm" variant={mode === 'remove' ? 'danger' : 'primary'} onClick={() => send(mode)}>
                        {mode === 'lift' ? 'Lift pause' : `Remove ${t.team}`}
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            setMode('none');
                            setError(null);
                        }}
                    >
                        Cancel
                    </Button>
                    {error && <p className="w-full text-xs text-danger">{error}</p>}
                </div>
            )}
        </li>
    );
}
