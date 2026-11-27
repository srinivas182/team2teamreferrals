import { Link, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { Kpi } from '@/components/ui/data';
import { PortalLayout } from '@/layouts/PortalLayout';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Row = {
    name: string;
    role: string;
    accepted: number;
    medianMinutes: number | null;
    won: number;
    conversion: number | null;
    open: number;
    closedValueCents: number;
    sent: number;
    feesCents: number;
};

type Props = {
    days: number;
    periods: number[];
    team: string;
    totals: { offered: number; accepted: number; missed: number; waiting: number };
    rows: Row[];
    seo: Seo;
};

const usd = (cents: number) => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });

/** "12 min", "3 h 20 min", "2 days": how long a referral waited before this person accepted it. */
function wait(min: number | null): string {
    if (min === null) return '—';
    if (min < 60) return `${min} min`;
    if (min < 48 * 60) return `${Math.floor(min / 60)} h${min % 60 ? ` ${min % 60} min` : ''}`;
    return `${Math.round(min / 1440)} days`;
}

/** Team performance (leaders and assistants): how the team shares and works the referrals it gets. */
export default function TeamPerformance({ days, periods, team, totals, rows, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const share = totals.offered > 0 ? Math.round((totals.accepted / totals.offered) * 100) : null;
    return (
        <PortalLayout title="Team performance" user={{ name: auth.user?.name ?? '', team }}>
            <SeoHead seo={seo} />
            <nav aria-label="Period" className="mb-5 flex w-fit flex-wrap rounded-lg border border-line bg-surface p-1 text-sm">
                {periods.map((p) => (
                    <Link
                        key={p}
                        href={`/app/team/performance?days=${p}`}
                        preserveScroll
                        aria-current={p === days ? 'page' : undefined}
                        className={cn('rounded-md px-3 py-1.5 font-medium', p === days ? 'bg-primary text-white' : 'text-muted hover:text-ink')}
                    >
                        Last {p === 365 ? '12 months' : `${p} days`}
                    </Link>
                ))}
            </nav>

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi label="Referrals offered to the team" value={String(totals.offered)} />
                <Kpi label="Accepted" value={String(totals.accepted)} hint={share !== null ? `${share}% of offers` : undefined} />
                <Kpi label="Waiting for someone to accept" value={String(totals.waiting)} />
                <Kpi label="Missed or declined" value={String(totals.missed)} />
            </div>

            <Card className="overflow-x-auto">
                <table className="w-full min-w-[860px] text-sm">
                    <caption className="sr-only">How each team member is doing</caption>
                    <thead className="border-b border-line text-left text-muted">
                        <tr>
                            {[
                                'Member',
                                'Accepted',
                                'Time to accept',
                                'Still open',
                                'Closed won',
                                'Closing rate',
                                'Value closed',
                                'Sent',
                                'Fees earned',
                            ].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {rows.map((r) => (
                            <tr key={r.name}>
                                <th scope="row" className="px-4 py-3 text-left font-medium">
                                    {r.name}
                                    <span className="block text-xs font-normal text-muted capitalize">{r.role}</span>
                                </th>
                                <td className="px-4 py-3 tabular-nums">{r.accepted}</td>
                                <td className="px-4 py-3 tabular-nums">{wait(r.medianMinutes)}</td>
                                <td className="px-4 py-3 tabular-nums">{r.open}</td>
                                <td className="px-4 py-3 tabular-nums">{r.won}</td>
                                <td className="px-4 py-3 tabular-nums">{r.conversion === null ? '—' : `${r.conversion}%`}</td>
                                <td className="px-4 py-3 tabular-nums">{usd(r.closedValueCents)}</td>
                                <td className="px-4 py-3 tabular-nums">{r.sent}</td>
                                <td className="px-4 py-3 tabular-nums">{usd(r.feesCents)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
            <p className="mt-3 max-w-3xl text-sm text-muted">
                Time to accept is the typical (median) time from the offer to that person accepting. Closing rate is closed won out of the referrals
                they finished. Fees earned are paid fees on the referrals they sent.
            </p>
        </PortalLayout>
    );
}
