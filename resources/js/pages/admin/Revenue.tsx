import { router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import { show } from '@/pages/app/Dashboard';
import type { Seo } from '@/types/seo';

type Props = {
    period: { preset: string; from: string; to: string; label: string };
    presets: Record<string, string>;
    revenue: {
        rows: { type: string; label: string; cents: number; previous: number }[];
        net: number;
        previousNet: number;
        refunds: number;
        tax: number;
        invoices: number;
    };
    invoices: { number: string; payer: string; total: number; paid: string }[];
    seo: Seo;
};

/** Admin → Revenue: platform revenue by type, from paid invoices, less refunds. */
export default function Revenue({ period, presets, revenue, invoices, seo }: Props) {
    return (
        <AdminLayout title="Revenue">
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-4">
                <div className="flex items-center gap-2 text-sm">
                    <select
                        aria-label="Period"
                        className="h-9 rounded-md border border-line bg-surface px-2"
                        value={period.preset}
                        onChange={(e) => router.get(`/admin/revenue?preset=${e.target.value}`)}
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
                        {period.from} to {period.to} · {revenue.invoices} paid invoices
                    </span>
                    <a
                        href={`/admin/revenue/export.csv?preset=${period.preset}`}
                        className="ml-auto inline-flex h-9 items-center gap-1 rounded-md border border-line px-3"
                    >
                        <Download className="size-4" aria-hidden /> CSV
                    </a>
                </div>
                <Card className="overflow-x-auto p-4">
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted">
                            <tr>
                                <th scope="col" className="py-2 font-medium">
                                    Type
                                </th>
                                <th scope="col" className="py-2 text-right font-medium">
                                    This period
                                </th>
                                <th scope="col" className="py-2 text-right font-medium">
                                    Previous
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {revenue.rows.map((r) => (
                                <tr key={r.type} className="border-t border-line">
                                    <td className="py-2">{r.label}</td>
                                    <td className="py-2 text-right tabular">{show(r.cents, 'money')}</td>
                                    <td className="py-2 text-right text-muted tabular">{show(r.previous, 'money')}</td>
                                </tr>
                            ))}
                            <tr className="border-t-2 border-line font-semibold">
                                <td className="py-2">Net revenue</td>
                                <td className="py-2 text-right tabular">{show(revenue.net, 'money')}</td>
                                <td className="py-2 text-right text-muted tabular">{show(revenue.previousNet, 'money')}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p className="mt-2 text-xs text-muted">
                        Sales tax collected ({show(revenue.tax, 'money')}) isn’t revenue. Referral fees between teams aren’t platform revenue;
                        platform fees on them are.
                    </p>
                </Card>
                <Card className="overflow-x-auto p-4">
                    <h2 className="mb-2 text-sm font-semibold">Paid invoices</h2>
                    <table className="w-full text-sm">
                        <tbody>
                            {invoices.map((i) => (
                                <tr key={i.number} className="border-t border-line">
                                    <td className="py-1.5">{i.number}</td>
                                    <td className="py-1.5">{i.payer}</td>
                                    <td className="py-1.5 tabular">{i.paid}</td>
                                    <td className="py-1.5 text-right tabular">{show(i.total, 'money')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            </div>
        </AdminLayout>
    );
}
