import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { Kpi } from '@/components/ui/data';

export type LedgerRow = {
    sn: number;
    id: string;
    date: string;
    client: string;
    network: string;
    city: string;
    sender: string;
    senderPerson: string;
    receiver: string;
    receiverPerson: string;
    status: string;
    gci: number | null;
    commission: number | null;
    commissionStatus: string;
    platform: number | null;
};
export type LedgerTotals = { count: number; gci: number; commission: number; platform: number };

const usd = (cents: number | null) =>
    cents === null ? '—' : (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });

/** The totals for the filtered referrals: how many, GCI received, referral commission and platform earnings. */
export function LedgerTotalsRow({ totals }: { totals: LedgerTotals }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi label="Referrals" value={totals.count.toLocaleString('en-US')} />
            <Kpi label="GCI received" value={usd(totals.gci)} />
            <Kpi label="Referral commission" value={usd(totals.commission)} />
            <Kpi label="Platform earnings" value={usd(totals.platform)} />
        </div>
    );
}

/** The referral ledger table: S/N, date, lead, who referred and who received (team and person), status and the money. */
export function LedgerTable({ rows, showNetwork = true, extra }: { rows: LedgerRow[]; showNetwork?: boolean; extra?: (r: LedgerRow) => ReactNode }) {
    const heads = [
        'S/N',
        'Date',
        'Lead name',
        ...(showNetwork ? ['Network'] : []),
        'City',
        'Referred by',
        'Referred to',
        'Lead status',
        'GCI received',
        'Referral commission',
        'Platform earnings',
        ...(extra ? [''] : []),
    ];
    return (
        <Card className="overflow-x-auto">
            <table className="w-full min-w-[1100px] text-sm">
                <caption className="sr-only">Referrals</caption>
                <thead className="text-left text-muted">
                    <tr>
                        {heads.map((h, i) => (
                            <th key={`${h}${i}`} scope="col" className="px-3 py-2 font-medium">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={heads.length} className="px-3 py-8 text-center text-muted">
                                No referrals match.
                            </td>
                        </tr>
                    )}
                    {rows.map((r) => (
                        <tr key={r.id} className="border-t border-line align-top">
                            <td className="px-3 py-2 text-muted tabular-nums">{r.sn}</td>
                            <td className="px-3 py-2 tabular-nums">{r.date}</td>
                            <td className="px-3 py-2 font-medium">{r.client}</td>
                            {showNetwork && <td className="px-3 py-2">{r.network}</td>}
                            <td className="px-3 py-2">{r.city}</td>
                            <td className="px-3 py-2">
                                {r.sender}
                                {r.senderPerson && <span className="block text-xs text-muted">{r.senderPerson}</span>}
                            </td>
                            <td className="px-3 py-2">
                                {r.receiver}
                                {r.receiverPerson && <span className="block text-xs text-muted">{r.receiverPerson}</span>}
                            </td>
                            <td className="px-3 py-2 capitalize">{r.status}</td>
                            <td className="px-3 py-2 tabular-nums">{usd(r.gci)}</td>
                            <td className="px-3 py-2 tabular-nums">
                                {usd(r.commission)}
                                {r.commissionStatus && <span className="block text-xs text-muted capitalize">{r.commissionStatus}</span>}
                            </td>
                            <td className="px-3 py-2 tabular-nums">{usd(r.platform)}</td>
                            {extra && <td className="px-3 py-2">{extra(r)}</td>}
                        </tr>
                    ))}
                </tbody>
            </table>
        </Card>
    );
}
