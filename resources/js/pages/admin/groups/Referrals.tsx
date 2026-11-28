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
    seo: Seo;
};

/** A group's referrals (owners and staff): how many, the list with the money, and a CSV for any date range. */
export default function GroupReferrals({ group, basePath, admin, filters, totals, page, pages, stages, rows, notRealReviews, seo }: Props) {
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
