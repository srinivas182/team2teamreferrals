import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { money } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Group = {
    slug: string;
    name: string;
    status: string;
    launch: boolean;
    industry: { slug: string | null; name: string | null; tier: string | null };
    pricing: { seat_price: number; founding_price: number; trial_months: number; value_guarantee: boolean; annual_months_free: number };
    rules: { accept_hours: number; company_cap_pct: number; challenge_days: number };
};

/** The launch groups with their pricing and rules. Editing groups, owners and seats arrives in Sprint 10. */
export default function GroupsIndex({ groups, seo }: { groups: Group[]; seo: Seo }) {
    const [q, setQ] = useState('');
    const shown = groups.filter((g) => `${g.name} ${g.industry.name}`.toLowerCase().includes(q.toLowerCase()));
    return (
        <AdminLayout title="Groups">
            <SeoHead seo={seo} />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <p className="flex-1 text-sm text-muted">
                    One launch group per industry, with sample pricing. Owners, seats and editing arrive in the next sprint.
                </p>
                <Input
                    aria-label="Search groups"
                    placeholder="Search groups or industries"
                    className="max-w-xs"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                />
            </div>
            <Card className="overflow-x-auto">
                <table className="w-full min-w-[900px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['Group', 'Industry', 'Seat price', 'Trial', 'Value guarantee', 'Rules', 'Status'].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((g) => (
                            <tr key={g.slug} className="border-t border-line">
                                <td className="px-4 py-3 font-medium">
                                    <Link href={`/admin/groups/${g.slug}`} className="hover:text-primary">
                                        {g.name}
                                    </Link>{' '}
                                    {g.launch && <Badge tone="sender">Launch</Badge>}
                                </td>
                                <td className="px-4 py-3">
                                    <Link href={`/admin/industries/${g.industry.slug}`} className="hover:text-primary">
                                        {g.industry.name}
                                    </Link>{' '}
                                    <Badge tone={g.industry.tier === 'A' ? 'success' : g.industry.tier === 'B' ? 'warning' : 'neutral'}>
                                        Tier {g.industry.tier}
                                    </Badge>
                                </td>
                                <td className="px-4 py-3 tabular">
                                    {money(g.pricing.seat_price)} <span className="text-muted">· founding {money(g.pricing.founding_price)}</span>
                                </td>
                                <td className="px-4 py-3">{g.pricing.trial_months} months</td>
                                <td className="px-4 py-3">
                                    {g.pricing.value_guarantee ? <Badge tone="success">On</Badge> : <Badge>Off (Tier C)</Badge>}
                                </td>
                                <td className="px-4 py-3 text-muted">
                                    Accept in {g.rules.accept_hours} h · cap {g.rules.company_cap_pct}% · challenge after {g.rules.challenge_days}{' '}
                                    days
                                </td>
                                <td className="px-4 py-3">{g.status === 'live' ? <Badge tone="success">Live</Badge> : <Badge>Draft</Badge>}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AdminLayout>
    );
}
