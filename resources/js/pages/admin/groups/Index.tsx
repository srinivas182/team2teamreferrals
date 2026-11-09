import { Link } from '@inertiajs/react';
import { useState } from 'react';
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
                            <th scope="col" className="w-10 px-4 py-3">
                                <input
                                    type="checkbox"
                                    aria-label="Select all shown"
                                    checked={shown.length > 0 && shown.every((g) => selected.includes(g.slug))}
                                    onChange={(e) => setSelected(e.target.checked ? shown.map((g) => g.slug) : [])}
                                />
                            </th>
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
