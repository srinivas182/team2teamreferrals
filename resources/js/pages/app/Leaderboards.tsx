import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Row = { team: string; sent?: number; closed?: number; score: number | null; responseMinutes?: number | null; updatesOnTime?: number | null };
type Props = {
    groups: { slug: string; name: string }[];
    group: { slug: string; name: string; serviceOnly: boolean } | null;
    monthly: { period: string; rows: Row[] } | null;
    allTime: Row[];
    seo: Seo;
};

/** Portal → Leaderboards: last month (frozen on the 1st) and all-time. Restricted industries rank service only. */
export default function Leaderboards({ groups, group, monthly, allTime, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [view, setView] = useState<'month' | 'all'>(monthly ? 'month' : 'all');
    const rows = view === 'month' ? (monthly?.rows ?? []) : allTime;
    const cols: [string, (r: Row) => string][] = group?.serviceOnly
        ? [
              ['Service score', (r) => (r.score === null ? '—' : String(r.score))],
              [
                  'Typical response',
                  (r) =>
                      r.responseMinutes == null
                          ? '—'
                          : r.responseMinutes < 60
                            ? `${r.responseMinutes} min`
                            : `${Math.round(r.responseMinutes / 60)} h`,
              ],
              ['Updates on time', (r) => (r.updatesOnTime == null ? '—' : `${r.updatesOnTime}%`)],
          ]
        : [
              ['Referrals sent', (r) => String(r.sent ?? 0)],
              ['Closed', (r) => String(r.closed ?? 0)],
              ['Service score', (r) => (r.score === null ? '—' : String(r.score))],
          ];
    return (
        <PortalLayout title="Leaderboards" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {!group && <Card className="max-w-3xl p-6 text-muted">Hold a seat to see your network’s leaderboard.</Card>}
            {group && (
                <div className="max-w-3xl space-y-4">
                    <div className="flex flex-wrap items-center gap-2">
                        {groups.length > 1 && (
                            <select
                                aria-label="Network"
                                className="h-10 rounded-md border border-line bg-surface px-2"
                                value={group.slug}
                                onChange={(e) => router.get('/app/leaderboards', { group: e.target.value })}
                            >
                                {groups.map((g) => (
                                    <option key={g.slug} value={g.slug}>
                                        {g.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <div className="ml-auto flex gap-1" role="tablist">
                            {(
                                [
                                    [
                                        'month',
                                        monthly
                                            ? new Date(monthly.period + '-01T00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
                                            : 'Last month',
                                    ],
                                    ['all', 'All time'],
                                ] as ['month' | 'all', string][]
                            ).map(([k, l]) => (
                                <button
                                    key={k}
                                    role="tab"
                                    aria-selected={view === k}
                                    disabled={k === 'month' && !monthly}
                                    onClick={() => setView(k)}
                                    className={`h-9 rounded-full px-3 text-sm ${view === k ? 'bg-primary text-white' : 'bg-bg'} disabled:opacity-50`}
                                >
                                    {l}
                                </button>
                            ))}
                        </div>
                    </div>
                    {group.serviceOnly && <p className="text-sm text-muted">In this industry, the board ranks service quality only.</p>}
                    <Card className="overflow-x-auto">
                        <table className="w-full min-w-[480px] text-sm">
                            <thead className="text-left text-muted">
                                <tr>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        #
                                    </th>
                                    <th scope="col" className="px-4 py-3 font-medium">
                                        Team
                                    </th>
                                    {cols.map(([h]) => (
                                        <th key={h} scope="col" className="px-4 py-3 font-medium">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r, i) => (
                                    <tr key={r.team} className="border-t border-line">
                                        <td className="px-4 py-2.5 tabular">{i + 1}</td>
                                        <td className="px-4 py-2.5 font-medium">{r.team}</td>
                                        {cols.map(([h, v]) => (
                                            <td key={h} className="px-4 py-2.5 tabular">
                                                {v(r)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                </div>
            )}
        </PortalLayout>
    );
}
