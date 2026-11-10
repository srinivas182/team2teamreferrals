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
type Props = { fees: Fee[]; totals: { toPay: number; toReceive: number; receivedThisYear: number }; canAct: boolean; status?: string; seo: Seo };

const usd = (n: number) => n.toLocaleString(undefined, { style: 'currency', currency: 'USD' });

/** Referral fees between teams (paid directly; Team to Team never holds the money). */
export default function Earnings({ fees, totals, canAct, status, seo }: Props) {
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
            <div className="mb-6 grid max-w-5xl gap-4 sm:grid-cols-3">
                {[
                    ['To pay', totals.toPay],
                    ['To receive', totals.toReceive],
                    ['Received this year', totals.receivedThisYear],
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
