import { router } from '@inertiajs/react';
import { GrowthTabs } from '@/components/growth/GrowthTabs';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';
import { pct } from './Campaigns';

type Props = {
    days: number;
    touch: 'first' | 'last';
    rows: { source: string; medium: string | null; campaign: string | null; signups: number; joined: number }[];
    byCampaign: { name: string; signups: number }[];
    total: number;
    tracked: number;
    seo: Seo;
};

/** Admin → Growth → Sign-up sources: where new accounts came from, and how many of them joined. */
export default function Sources({ days, touch, rows, byCampaign, total, tracked, seo }: Props) {
    const go = (next: Partial<{ days: number; touch: string }>) =>
        router.get('/admin/growth/sources', { days, touch, ...next }, { preserveState: true });
    return (
        <AdminLayout title="Growth">
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-5">
                <GrowthTabs current="/admin/growth/sources" />
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <Select className="w-40" value={String(days)} onChange={(e) => go({ days: Number(e.target.value) })} aria-label="Period">
                        {[7, 30, 90, 365].map((d) => (
                            <option key={d} value={d}>
                                Last {d} days
                            </option>
                        ))}
                    </Select>
                    <Select className="w-48" value={touch} onChange={(e) => go({ touch: e.target.value })} aria-label="Credit">
                        <option value="first">By first visit</option>
                        <option value="last">By last tagged visit</option>
                    </Select>
                    <span className="text-muted">
                        {total} sign-ups, {tracked} with a known source
                    </span>
                </div>
                <Card className="overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs text-muted">
                            <tr>
                                <th className="p-3 font-medium">Source</th>
                                <th className="p-3 font-medium">Medium</th>
                                <th className="p-3 font-medium">Campaign</th>
                                <th className="p-3 font-medium">Sign-ups</th>
                                <th className="p-3 font-medium">Joined</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {rows.map((r, i) => (
                                <tr key={i}>
                                    <td className="p-3">{r.source}</td>
                                    <td className="p-3">{r.medium ?? '—'}</td>
                                    <td className="p-3">{r.campaign ?? '—'}</td>
                                    <td className="p-3">{r.signups}</td>
                                    <td className="p-3">
                                        {r.joined} ({pct(r.joined, r.signups)})
                                    </td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted">
                                        No sign-ups with a known source yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </Card>
                {byCampaign.length > 0 && (
                    <Card className="space-y-2 p-4 text-sm">
                        <h2 className="font-semibold">Sign-ups after clicking a campaign email</h2>
                        {byCampaign.map((c) => (
                            <p key={c.name}>
                                {c.name}: {c.signups}
                            </p>
                        ))}
                    </Card>
                )}
            </div>
        </AdminLayout>
    );
}
