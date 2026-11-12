import { Link } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import { show } from '@/pages/app/Dashboard';
import type { Seo } from '@/types/seo';

type Row = { id: string; client: string; with: string; status: string; date: string; link: string };
type Props = {
    kpi: { key: string; label: string; kind: string; value: number | null };
    rows: Row[];
    period: { preset: string; from: string; to: string; label: string };
    seo: Seo;
};

/** The records behind one network number. */
export default function NetworkDrill({ kpi, rows, period, seo }: Props) {
    return (
        <AdminLayout title={kpi.label}>
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-3">
                <Link href={`/admin/network?preset=${period.preset}`} className="text-sm text-primary">
                    ← Network dashboard
                </Link>
                <p className="font-display text-2xl font-bold">
                    {show(kpi.value, kpi.kind)} <span className="text-base font-normal text-muted">· {rows.length} records</span>
                </p>
                <Card className="overflow-x-auto">
                    <table className="w-full min-w-[520px] text-sm">
                        <thead className="text-left text-muted">
                            <tr>
                                {['Date', 'Client', 'Who', 'Status'].map((h) => (
                                    <th key={h} scope="col" className="px-4 py-2 font-medium">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.id} className="border-t border-line">
                                    <td className="px-4 py-2 tabular">{r.date}</td>
                                    <td className="px-4 py-2">{r.client}</td>
                                    <td className="px-4 py-2">
                                        <Link href={r.link} className="text-primary hover:underline">
                                            {r.with || 'Open'}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2">{r.status}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            </div>
        </AdminLayout>
    );
}
