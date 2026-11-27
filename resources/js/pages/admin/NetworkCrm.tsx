import { router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { LedgerTable, LedgerTotalsRow, type LedgerRow, type LedgerTotals } from '@/components/admin/ReferralLedger';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Props = {
    filters: Record<string, string>;
    total: number;
    page: number;
    pages: number;
    rows: LedgerRow[];
    totals: LedgerTotals;
    fullNames: boolean;
    options: { industries: { slug: string; name: string }[]; groups: { slug: string; name: string }[]; states: string[]; stages: string[] };
    revealed?: { id: string; name: string; phone: string | null; email: string | null } | null;
    seo: Seo;
};

const sel = 'h-9 rounded-md border border-line bg-surface px-2 text-sm';

/** Admin → Referrals: every referral with the money, filtered. Super admins see full lead names; others the short form, with full details behind a logged reason. */
export default function NetworkCrm({ filters, total, totals, fullNames, page, pages, rows, options, revealed, seo }: Props) {
    const [f, setF] = useState<Record<string, string>>(filters);
    const [reasonFor, setReasonFor] = useState<string | null>(null);
    const [reason, setReason] = useState('');
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const apply = (extra: Record<string, string | number> = {}) =>
        router.get('/admin/referrals', { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), ...extra }, { preserveState: true });
    const qs = new URLSearchParams(Object.entries(filters)).toString();
    return (
        <AdminLayout title="Referrals">
            <SeoHead seo={seo} />
            <div className="space-y-3">
                <Card className="flex flex-wrap items-center gap-2 p-3">
                    {(
                        [
                            ['industry', 'Industry', options.industries.map((o) => [o.slug, o.name])],
                            ['group', 'Network', options.groups.map((o) => [o.slug, o.name])],
                            ['state', 'State', options.states.map((s) => [s, s])],
                            ['stage', 'Stage', options.stages.map((s) => [s, s])],
                        ] as [string, string, string[][]][]
                    ).map(([k, label, opts]) => (
                        <select key={k} aria-label={label} className={sel} value={f[k] ?? ''} onChange={(e) => setF({ ...f, [k]: e.target.value })}>
                            <option value="">Any {label.toLowerCase()}</option>
                            {opts.map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    ))}
                    {(
                        [
                            ['city', 'City'],
                            ['sender', 'Sender team'],
                            ['receiver', 'Receiver team'],
                        ] as const
                    ).map(([k, l]) => (
                        <Input
                            key={k}
                            aria-label={l}
                            placeholder={l}
                            className="h-9 w-40"
                            value={f[k] ?? ''}
                            onChange={(e) => setF({ ...f, [k]: e.target.value })}
                        />
                    ))}
                    <Input
                        type="date"
                        aria-label="From"
                        className="h-9 w-40"
                        value={f.from ?? ''}
                        onChange={(e) => setF({ ...f, from: e.target.value })}
                    />
                    <Input type="date" aria-label="To" className="h-9 w-40" value={f.to ?? ''} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    <Button size="sm" onClick={() => apply()}>
                        Filter
                    </Button>
                    <a
                        href={`/admin/referrals/export.csv?${qs}`}
                        className="ml-auto inline-flex h-9 items-center gap-1 rounded-md border border-line px-3 text-sm"
                    >
                        <Download className="size-4" aria-hidden /> Export {f.from || f.to ? 'these dates' : 'all'} (CSV)
                    </a>
                </Card>
                {revealed && (
                    <Alert tone="info">
                        Client details for {revealed.id} (viewing was logged): {revealed.name} · {revealed.phone ?? 'no phone'} ·{' '}
                        {revealed.email ?? 'no email'}
                    </Alert>
                )}
                <LedgerTotalsRow totals={totals} />
                <p className="text-sm text-muted">
                    {total.toLocaleString()} referrals{fullNames ? '. As super admin you see full lead names; exports are logged.' : '.'}
                </p>
                <LedgerTable
                    rows={rows}
                    extra={(r) => (
                        <>
                            {reasonFor === r.id ? (
                                <div className="flex gap-1">
                                    <Input
                                        aria-label="Reason"
                                        className="h-8 w-48"
                                        placeholder="Why do you need the details?"
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                    />
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/admin/referrals/${r.id}/reveal`,
                                                { reason },
                                                { preserveScroll: true, preserveState: true, onSuccess: () => setReasonFor(null) },
                                            )
                                        }
                                    >
                                        Show
                                    </Button>
                                </div>
                            ) : (
                                <button className="text-xs text-primary" onClick={() => setReasonFor(r.id)}>
                                    Client details
                                </button>
                            )}
                            {reasonFor === r.id && errors.reason && <p className="text-xs text-danger">{errors.reason}</p>}
                        </>
                    )}
                />
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
