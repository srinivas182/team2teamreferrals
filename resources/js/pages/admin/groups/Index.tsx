import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, TrendingUp } from 'lucide-react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Field, Select } from '@/components/ui/field';
import { statusMessage } from '@/lib/status';
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
    private: boolean;
    region: string | null;
    industry: { slug: string | null; name: string | null; tier: string | null };
    pricing: { seat_price: number; founding_price: number; trial_months: number; value_guarantee: boolean; annual_months_free: number };
    rules: { accept_hours: number; company_cap_pct: number; challenge_days: number };
};

/** The launch groups with their pricing and rules. Editing groups, owners and seats arrives in Sprint 10. */
export default function GroupsIndex({
    groups,
    industries,
    status,
    seo,
}: {
    groups: Group[];
    industries: { slug: string; name: string }[];
    status?: string;
    seo: Seo;
}) {
    const [q, setQ] = useState('');
    const [creating, setCreating] = useState(false);
    const priv = useForm({ name: '', industry: industries[0]?.slug ?? '', owner_email: '' });
    const shown = groups.filter((g) => `${g.name} ${g.industry.name}`.toLowerCase().includes(q.toLowerCase()));
    return (
        <AdminLayout
            title="Groups"
            actions={
                <>
                    <Button variant="secondary" asChild>
                        <Link href="/admin/groups/overflow">
                            <TrendingUp /> Overflow demand
                        </Link>
                    </Button>
                    <Button onClick={() => setCreating(true)}>
                        <Plus /> New private network
                    </Button>
                </>
            }
        >
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4">
                    {statusMessage(status)}
                </Alert>
            )}
            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent
                    title="New private network"
                    description="A company’s own network for passing clients between its branches. It has no public seats and is never listed."
                >
                    <div className="space-y-4">
                        <Field label="Name" error={priv.errors.name}>
                            <Input
                                value={priv.data.name}
                                onChange={(e) => priv.setData('name', e.target.value)}
                                placeholder="e.g. SunPath Solar Branch Network"
                            />
                        </Field>
                        <Field label="Industry" error={priv.errors.industry}>
                            <Select value={priv.data.industry} onChange={(e) => priv.setData('industry', e.target.value)}>
                                {industries.map((i) => (
                                    <option key={i.slug} value={i.slug}>
                                        {i.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Owner’s email" hint="They must already have an account." error={priv.errors.owner_email}>
                            <Input type="email" value={priv.data.owner_email} onChange={(e) => priv.setData('owner_email', e.target.value)} />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <Button variant="ghost" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                            <Button loading={priv.processing} onClick={() => priv.post('/admin/groups/private')}>
                                Create
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
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
                                    {g.launch && <Badge tone="sender">Launch</Badge>} {g.private && <Badge tone="info">Private</Badge>}{' '}
                                    {g.region && <Badge>{g.region}</Badge>}
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
