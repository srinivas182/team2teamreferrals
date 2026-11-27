import { Link } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, Tags, TrendingUp } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import { Input as TextInput } from '@/components/ui/field';
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
import { useUrlFilters } from '@/lib/filters';
import { cn, money } from '@/lib/utils';
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
    pendingApplications: number;
    referrals: number;
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
    const f = useUrlFilters({ q: '', status: 'live', industry: '', type: '', tier: '' });
    const [creating, setCreating] = useState(false);
    const pendingTotal = groups.reduce((n, g) => n + g.pendingApplications, 0);
    const [selected, setSelected] = useState<string[]>([]);
    const [pricing, setPricing] = useState(false);
    const note = usePage<{ flash: { note?: string | null } }>().props.flash.note;
    const bulk = useForm({
        groups: [] as string[],
        when: 'now',
        effective_on: '',
        values: {
            seat_price: 10,
            founding_price: 10,
            trial_months: 3,
            value_guarantee: true,
            annual_months_free: 2,
            discounts: { '2': 0, '5': 0, '20': 0 },
        },
    });
    const toggle = (slug: string) => setSelected((s) => (s.includes(slug) ? s.filter((x) => x !== slug) : [...s, slug]));
    const priv = useForm({ name: '', industry: industries[0]?.slug ?? '', owner_email: '' });
    const shown = groups.filter(
        (g) =>
            (f.values.q === '' || `${g.name} ${g.industry.name} ${g.region ?? ''}`.toLowerCase().includes(f.values.q.toLowerCase())) &&
            (f.values.status === 'all' || g.status === f.values.status) &&
            (f.values.industry === '' || g.industry.slug === f.values.industry) &&
            (f.values.tier === '' || g.industry.tier === f.values.tier) &&
            (f.values.type === '' ||
                (f.values.type === 'public' && !g.private) ||
                (f.values.type === 'private' && g.private) ||
                (f.values.type === 'launch' && g.launch)),
    );
    const count = (st: string) => groups.filter((g) => g.status === st).length;
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
                    <Button variant="secondary" disabled={selected.length === 0} onClick={() => setPricing(true)}>
                        <Tags /> Set pricing{selected.length ? ` for ${selected.length}` : ''}
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
                    {statusMessage(status)} {note}
                </Alert>
            )}
            <Dialog open={pricing} onOpenChange={setPricing}>
                <DialogContent
                    title={`Set pricing for ${selected.length} groups`}
                    description="Each group gets this pricing. Tier C groups always keep the value guarantee off."
                >
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Seat price / month">
                                <TextInput
                                    type="number"
                                    value={bulk.data.values.seat_price}
                                    onChange={(e) => bulk.setData('values', { ...bulk.data.values, seat_price: +e.target.value })}
                                />
                            </Field>
                            <Field label="Founding price">
                                <TextInput
                                    type="number"
                                    value={bulk.data.values.founding_price}
                                    onChange={(e) => bulk.setData('values', { ...bulk.data.values, founding_price: +e.target.value })}
                                />
                            </Field>
                            <Field label="Free trial (months)">
                                <TextInput
                                    type="number"
                                    value={bulk.data.values.trial_months}
                                    onChange={(e) => bulk.setData('values', { ...bulk.data.values, trial_months: +e.target.value })}
                                />
                            </Field>
                            <Field label="Annual: months free">
                                <TextInput
                                    type="number"
                                    value={bulk.data.values.annual_months_free}
                                    onChange={(e) => bulk.setData('values', { ...bulk.data.values, annual_months_free: +e.target.value })}
                                />
                            </Field>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={bulk.data.values.value_guarantee}
                                onChange={(e) => bulk.setData('values', { ...bulk.data.values, value_guarantee: e.target.checked })}
                            />{' '}
                            Value guarantee (free month when no referral is offered)
                        </label>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="When">
                                <Select value={bulk.data.when} onChange={(e) => bulk.setData('when', e.target.value)}>
                                    <option value="now">Apply now</option>
                                    <option value="scheduled">On a date (members told 30 days ahead)</option>
                                </Select>
                            </Field>
                            {bulk.data.when === 'scheduled' && (
                                <Field label="Takes effect on" error={bulk.errors.effective_on}>
                                    <TextInput
                                        type="date"
                                        value={bulk.data.effective_on}
                                        onChange={(e) => bulk.setData('effective_on', e.target.value)}
                                    />
                                </Field>
                            )}
                        </div>
                        {Object.values(bulk.errors).length > 0 && <Alert tone="danger">{Object.values(bulk.errors)[0]}</Alert>}
                        <div className="flex justify-end gap-2">
                            <Button variant="ghost" onClick={() => setPricing(false)}>
                                Cancel
                            </Button>
                            <Button
                                loading={bulk.processing}
                                onClick={() => {
                                    bulk.transform((d) => ({ ...d, groups: selected }));
                                    bulk.post('/admin/groups/bulk-pricing', {
                                        preserveScroll: true,
                                        onSuccess: () => (setPricing(false), setSelected([])),
                                    });
                                }}
                            >
                                Apply to {selected.length} groups
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
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
            {/* Status tabs: live groups first. */}
            <nav aria-label="Group status" className="mb-4 flex w-fit flex-wrap rounded-lg border border-line bg-surface p-1 text-sm">
                {(
                    [
                        ['live', 'Live and active', count('live')],
                        ['draft', 'Draft', count('draft')],
                        ['closed', 'Closed', count('closed')],
                        ['all', 'All', groups.length],
                    ] as const
                ).map(([key, label, n]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => f.set('status', key)}
                        aria-pressed={f.values.status === key}
                        className={cn(
                            'rounded-md px-3 py-1.5 font-medium',
                            f.values.status === key ? 'bg-primary text-white' : 'text-muted hover:text-ink',
                        )}
                    >
                        {label} <span className="tabular-nums">({n})</span>
                    </button>
                ))}
            </nav>
            <div className="mb-4 flex flex-wrap items-end gap-3" role="search" aria-label="Filter groups">
                <Input
                    aria-label="Search groups"
                    placeholder="Search groups, industries or regions"
                    className="w-full sm:max-w-64"
                    value={f.values.q}
                    onChange={(e) => f.set('q', e.target.value)}
                />
                <Select
                    aria-label="Industry"
                    className="w-auto max-w-56"
                    value={f.values.industry}
                    onChange={(e) => f.set('industry', e.target.value)}
                >
                    <option value="">All industries</option>
                    {industries.map((i) => (
                        <option key={i.slug} value={i.slug}>
                            {i.name}
                        </option>
                    ))}
                </Select>
                <Select aria-label="Compliance tier" className="w-auto" value={f.values.tier} onChange={(e) => f.set('tier', e.target.value)}>
                    <option value="">All tiers</option>
                    <option value="A">Tier A</option>
                    <option value="B">Tier B</option>
                    <option value="C">Tier C</option>
                </Select>
                <Select aria-label="Type" className="w-auto" value={f.values.type} onChange={(e) => f.set('type', e.target.value)}>
                    <option value="">All types</option>
                    <option value="public">Public</option>
                    <option value="private">Private</option>
                    <option value="launch">Launch networks</option>
                </Select>
                <p className="text-sm text-muted" aria-live="polite">
                    {shown.length === groups.length ? `${groups.length} groups` : `${shown.length} of ${groups.length}`}
                </p>
                {f.active > 0 && (
                    <Button variant="ghost" size="sm" onClick={f.clear}>
                        Clear filters
                    </Button>
                )}
            </div>
            {pendingTotal > 0 && (
                <Alert tone="info" className="mb-4">
                    {pendingTotal} seat {pendingTotal === 1 ? 'application is' : 'applications are'} waiting for approval:{' '}
                    {groups
                        .filter((g) => g.pendingApplications > 0)
                        .map((g, i) => (
                            <Fragment key={g.slug}>
                                {i > 0 && ', '}
                                <Link href={`/admin/groups/${g.slug}/applications`} className="font-semibold underline">
                                    {g.name} ({g.pendingApplications})
                                </Link>
                            </Fragment>
                        ))}
                </Alert>
            )}
            <Card className="overflow-x-auto">
                <table className="w-full min-w-[980px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            <th scope="col" className="w-10 px-4 py-3">
                                <input
                                    type="checkbox"
                                    aria-label="Select all shown"
                                    checked={shown.length > 0 && shown.every((g) => selected.includes(g.slug))}
                                    onChange={(e) => setSelected(e.target.checked ? shown.map((g) => g.slug) : [])}
                                />
                            </th>
                            {['Group', 'Industry', 'Referrals', 'Seat price', 'Trial', 'Value guarantee', 'Rules', 'Status'].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {shown.map((g) => (
                            <tr key={g.slug} className="border-t border-line">
                                <td className="px-4 py-3">
                                    <input
                                        type="checkbox"
                                        aria-label={`Select ${g.name}`}
                                        checked={selected.includes(g.slug)}
                                        onChange={() => toggle(g.slug)}
                                    />
                                </td>
                                <td className="px-4 py-3 font-medium">
                                    <Link href={`/admin/groups/${g.slug}`} className="hover:text-primary">
                                        {g.name}
                                    </Link>{' '}
                                    {g.launch && <Badge tone="sender">Launch</Badge>} {g.private && <Badge tone="info">Private</Badge>}{' '}
                                    {g.region && <Badge>{g.region}</Badge>}{' '}
                                    {g.pendingApplications > 0 && (
                                        <Link href={`/admin/groups/${g.slug}/applications`} className="hover:underline">
                                            <Badge tone="warning">{g.pendingApplications} to approve</Badge>
                                        </Link>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    <Link href={`/admin/industries/${g.industry.slug}`} className="hover:text-primary">
                                        {g.industry.name}
                                    </Link>{' '}
                                    <Badge tone={g.industry.tier === 'A' ? 'success' : g.industry.tier === 'B' ? 'warning' : 'neutral'}>
                                        Tier {g.industry.tier}
                                    </Badge>
                                </td>
                                <td className="px-4 py-3 tabular-nums">
                                    <Link href={`/admin/groups/${g.slug}/referrals`} className="font-medium text-primary hover:underline">
                                        {g.referrals}
                                    </Link>
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
                                <td className="px-4 py-3">
                                    {g.status === 'live' ? (
                                        <Badge tone="success">Live</Badge>
                                    ) : g.status === 'closed' ? (
                                        <Badge tone="danger">Closed</Badge>
                                    ) : (
                                        <Badge>Draft</Badge>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {shown.length === 0 && (
                            <tr>
                                <td colSpan={8} className="px-4 py-10 text-center text-muted">
                                    {groups.length === 0 ? 'No groups yet.' : 'No group matches these filters.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </Card>
        </AdminLayout>
    );
}
