import { router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Row = { id: string; client: string; network: string; city: string; sender: string; receiver: string; stage: string; date: string };
type Props = {
    filters: Record<string, string>;
    total: number;
    page: number;
    pages: number;
    rows: Row[];
    options: { industries: { slug: string; name: string }[]; groups: { slug: string; name: string }[]; states: string[]; stages: string[] };
    revealed?: { id: string; name: string; phone: string | null; email: string | null } | null;
    seo: Seo;
};

const sel = 'h-9 rounded-md border border-line bg-surface px-2 text-sm';

/** Admin → Network CRM: every referral, filtered. Short client names; full details need a reason and are audited. */
export default function NetworkCrm({ filters, total, page, pages, rows, options, revealed, seo }: Props) {
    const [f, setF] = useState<Record<string, string>>(filters);
    const [reasonFor, setReasonFor] = useState<string | null>(null);
    const [reason, setReason] = useState('');
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const apply = (extra: Record<string, string | number> = {}) =>
        router.get('/admin/referrals', { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), ...extra }, { preserveState: true });
    const qs = new URLSearchParams(Object.entries(filters)).toString();
    return (
        <AdminLayout title="Network CRM">
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
                        <Download className="size-4" aria-hidden /> CSV
                    </a>
                </Card>
                {revealed && (
                    <Alert tone="info">
                        Client details for {revealed.id} (viewing was logged): {revealed.name} · {revealed.phone ?? 'no phone'} ·{' '}
                        {revealed.email ?? 'no email'}
                    </Alert>
                )}
                <p className="text-sm text-muted">{total.toLocaleString()} referrals</p>
                <Card className="overflow-x-auto">
                    <table className="w-full min-w-[800px] text-sm">
                        <thead className="text-left text-muted">
                            <tr>
                                {['Date', 'Network', 'City', 'Sender', 'Receiver', 'Stage', 'Client', ''].map((h) => (
                                    <th key={h} scope="col" className="px-3 py-2 font-medium">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.id} className="border-t border-line align-top">
                                    <td className="px-3 py-2 tabular">{r.date}</td>
                                    <td className="px-3 py-2">{r.network}</td>
                                    <td className="px-3 py-2">{r.city}</td>
                                    <td className="px-3 py-2">{r.sender}</td>
                                    <td className="px-3 py-2">{r.receiver}</td>
                                    <td className="px-3 py-2">{r.stage}</td>
                                    <td className="px-3 py-2">{r.client}</td>
                                    <td className="px-3 py-2">
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
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
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
