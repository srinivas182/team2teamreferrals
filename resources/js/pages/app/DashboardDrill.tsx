import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { show } from '@/pages/app/Dashboard';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Row = { id: string; client: string; with: string; status: string; date: string; link: string; amount?: number; credits?: number };
type Props = {
    kpi: { key: string; label: string; kind: string; records: string; value: number | null };
    rows: Row[];
    period: { preset: string; from: string; to: string; label: string };
    seo: Seo;
};

/** The records behind one dashboard number. */
export default function DashboardDrill({ kpi, rows, period, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const q = period.preset === 'custom' ? `?preset=custom&from=${period.from}&to=${period.to}` : `?preset=${period.preset}`;
    return (
        <PortalLayout title={kpi.label} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-3">
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <Link href={`/app/dashboard${q}`} className="inline-flex items-center gap-1 text-primary">
                        <ArrowLeft className="size-4" aria-hidden /> Dashboard
                    </Link>
                    <span className="text-muted">
                        {period.label}: {period.from} to {period.to}
                    </span>
                    <a href={`/app/dashboard/kpi/${kpi.key}/export.csv${q}`} className="ml-auto inline-flex items-center gap-1 text-primary">
                        <Download className="size-4" aria-hidden /> CSV
                    </a>
                </div>
                <p className="font-display text-2xl font-bold">
                    {show(kpi.value, kpi.kind)} <span className="text-base font-normal text-muted">· {rows.length} records</span>
                </p>
                <Card className="overflow-x-auto">
                    <table className="w-full min-w-[520px] text-sm">
                        <thead className="text-left text-muted">
                            <tr>
                                {[
                                    'Date',
                                    'Client',
                                    'With',
                                    kpi.records === 'fees' ? 'Amount' : kpi.records === 'credits' ? 'Credits' : 'Status',
                                    kpi.records === 'referrals' ? '' : 'Status',
                                ]
                                    .filter(Boolean)
                                    .map((h) => (
                                        <th key={h} scope="col" className="px-4 py-2 font-medium">
                                            {h}
                                        </th>
                                    ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-6 text-center text-muted">
                                        Nothing in this period.
                                    </td>
                                </tr>
                            )}
                            {rows.map((r) => (
                                <tr key={r.id} className="border-t border-line">
                                    <td className="px-4 py-2 tabular">{r.date}</td>
                                    <td className="px-4 py-2">
                                        <Link href={r.link} className="text-primary hover:underline">
                                            {r.client || 'Open'}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2">{r.with}</td>
                                    <td className="px-4 py-2 tabular">
                                        {r.amount !== undefined ? show(r.amount, 'money') : r.credits !== undefined ? r.credits : r.status}
                                    </td>
                                    {kpi.records !== 'referrals' && <td className="px-4 py-2">{r.status}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            </div>
        </PortalLayout>
    );
}
