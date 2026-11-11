import { router, usePage } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Fee = {
    id: number;
    referral: string;
    client: string;
    incoming: boolean;
    other: string;
    amount: number;
    due: string;
    status: string;
    reference: string | null;
};
type Split = { label: string; received: number; expected: number };
type Dash = { expected: number; receivedYtd: number; receivedAll: number; overdue: number; inProgress: number; byGroup: Split[]; byPartner: Split[] };
type Props = {
    dashboard: Dash;
    giveGet: { given: number; received: number; balance: number };
    fees: Fee[];
    totals: { toPay: number; toReceive: number; receivedThisYear: number; platformOwed: number };
    canAct: boolean;
    status?: string;
    seo: Seo;
};

const usd = (n: number) => n.toLocaleString(undefined, { style: 'currency', currency: 'USD' });

/** Referral fees between teams (paid directly; Team to Team never holds the money). */
export default function Earnings({ dashboard: d, giveGet, fees, totals, canAct, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [refs, setRefs] = useState<Record<number, string>>({});
    const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7));
    return (
        <PortalLayout title="Earnings" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-5xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="mb-4 grid max-w-5xl gap-4 sm:grid-cols-3 lg:grid-cols-5">
                {[
                    ['Expected', usd(d.expected)],
                    ['Received this year', usd(d.receivedYtd)],
                    ['Received all time', usd(d.receivedAll)],
                    ['Overdue', usd(d.overdue)],
                    ['Referrals in progress', String(d.inProgress)],
                ].map(([l, v]) => (
                    <Card key={l} className="p-4">
                        <div className="font-display text-xl font-bold tabular">{v}</div>
                        <div className="text-xs text-muted">{l} (net of platform fees)</div>
                    </Card>
                ))}
            </div>
            <div className="mb-6 grid max-w-5xl gap-4 md:grid-cols-3">
                {(
                    [
                        ['By network', d.byGroup],
                        ['By partner team', d.byPartner],
                    ] as [string, Split[]][]
                ).map(([title, rows]) => (
                    <Card key={title} className="p-4 text-sm">
                        <h3 className="mb-2 font-semibold">{title}</h3>
                        {rows.length === 0 && <p className="text-muted">Nothing yet.</p>}
                        {rows.map((r) => (
                            <div key={r.label} className="flex justify-between py-1">
                                <span>{r.label}</span>
                                <span className="text-muted tabular">
                                    {usd(r.received)} · {usd(r.expected)} expected
                                </span>
                            </div>
                        ))}
                    </Card>
                ))}
                <Card className="p-4 text-sm">
                    <h3 className="mb-2 font-semibold">Give and get</h3>
                    <p>
                        {giveGet.given} referrals given (accepted) · {giveGet.received} received
                    </p>
                    <p className="mt-1">
                        Credit balance:{' '}
                        <span className={`font-semibold tabular ${giveGet.balance < 0 ? 'text-warning' : 'text-success'}`}>{giveGet.balance}</span>
                    </p>
                </Card>
            </div>
            <div className="mb-6 grid max-w-5xl gap-4 sm:grid-cols-3">
                {[
                    ['To pay', totals.toPay],
                    ['To receive', totals.toReceive],
                    ['Received this year (net)', totals.receivedThisYear],
                ].map(([l, v]) => (
                    <Card key={l as string} className="p-5">
                        <div className="font-display text-2xl font-bold tabular">{usd(v as number)}</div>
                        <div className="text-sm text-muted">{l as string}</div>
                    </Card>
                ))}
            </div>
            <Card className="max-w-5xl overflow-x-auto">
                <table className="w-full min-w-[760px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['Client', 'With', 'Amount', 'Due', 'Status', ''].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {fees.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-muted">
                                    Referral fees appear here when a Won close is confirmed (in networks that allow fees).
                                </td>
                            </tr>
                        )}
                        {fees.map((f) => (
                            <tr key={f.id} className="border-t border-line">
                                <td className="px-4 py-3 font-medium">
                                    <a href={`/app/referrals/${f.referral}`} className="hover:underline">
                                        {f.client}
                                    </a>
                                </td>
                                <td className="px-4 py-3">
                                    {f.incoming ? 'From' : 'To'} {f.other}
                                </td>
                                <td className={`px-4 py-3 tabular ${f.incoming ? 'text-success' : ''}`}>{usd(f.amount)}</td>
                                <td className="px-4 py-3 text-muted tabular">{f.due}</td>
                                <td className="px-4 py-3">
                                    <Badge tone={f.status === 'received' ? 'success' : f.status === 'paid' ? 'info' : 'warning'}>{f.status}</Badge>
                                    {f.reference && <div className="text-xs text-muted">ref {f.reference}</div>}
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <a href={`/app/earnings/${f.id}/statement.pdf`} className="text-muted hover:text-ink" aria-label="Statement">
                                            <FileText className="size-4" />
                                        </a>
                                        {canAct && !f.incoming && f.status === 'due' && (
                                            <>
                                                <Input
                                                    aria-label="Payment reference"
                                                    placeholder="Payment ref (optional)"
                                                    className="h-8 w-36"
                                                    value={refs[f.id] ?? ''}
                                                    onChange={(e) => setRefs({ ...refs, [f.id]: e.target.value })}
                                                />
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/app/earnings/${f.id}/paid`,
                                                            { reference: refs[f.id] ?? null },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Mark paid
                                                </Button>
                                            </>
                                        )}
                                        {canAct && f.incoming && f.status !== 'received' && (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                onClick={() => router.post(`/app/earnings/${f.id}/received`, {}, { preserveScroll: true })}
                                            >
                                                Confirm received
                                            </Button>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
            <div className="mt-4 flex max-w-5xl items-center gap-2 text-sm">
                <span className="text-muted">For your accountant:</span>
                <a className="font-medium text-primary" href={`/app/earnings/export.csv?month=${month.slice(0, 4)}`}>
                    {month.slice(0, 4)} CSV
                </a>
                <a className="font-medium text-primary" href={`/app/earnings/${month.slice(0, 4)}.pdf`}>
                    {month.slice(0, 4)} PDF summary
                </a>
                <Input type="month" aria-label="Month" className="h-9 w-44" value={month} onChange={(e) => setMonth(e.target.value)} />
                <Button variant="secondary" size="sm" asChild>
                    <a href={`/app/earnings/export.csv?month=${month}`}>
                        <Download /> Export CSV
                    </a>
                </Button>
            </div>
            <p className="mt-3 max-w-5xl text-xs text-muted">
                Referral fees are paid directly between businesses under their signed agreement. Team to Team Referrals never collects or holds them.
            </p>
        </PortalLayout>
    );
}
