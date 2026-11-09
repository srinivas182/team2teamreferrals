import { Link, router, useForm } from '@inertiajs/react';
import { CalendarClock, Check, Info, Trash2, UserPlus } from 'lucide-react';
import { Fragment, useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Kpi, Segmented } from '@/components/ui/data';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { money } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Pricing = {
    seat_price: number;
    founding_price: number;
    trial_months: number;
    value_guarantee: boolean;
    annual_months_free: number;
    discounts: Record<string, number>;
};
type Rules = {
    accept_hours: number;
    first_contact_hours: number;
    update_days: number;
    challenge_days: number;
    challenge_threshold: number;
    company_cap_pct: number;
    waitlist: string;
    grace_days: number;
    release_days: number;
    offer_hours: number;
    auto_approve: boolean;
};
type SeatRow = {
    id: number;
    territory: string;
    status: string;
    primary: string | null;
    backup: string | null;
    waitlist: number;
    trialEnds: string | null;
    founding: boolean;
    history: { event: string; when: string; team: string | null }[];
};

type Props = {
    group: {
        slug: string;
        name: string;
        description: string | null;
        status: string;
        visibility: string;
        photo: string | null;
        launch: boolean;
        private: boolean;
        region: string | null;
        pricing: Pricing;
        rules: Rules;
        industry: { slug: string; name: string; tier: string; guaranteeAllowed: boolean; terms: Record<string, string> };
    };
    owners: { id: string; name: string; email: string }[];
    scheduled: { id: number; section: string; values: Record<string, unknown>; effectiveOn: string; notified: boolean }[];
    overrides: { kind: string; label: string; version: number | null }[];
    seats: SeatRow[];
    pendingApplications: number;
    affected: { seats: number; trial: number; paying: number };
    health: { owners: number; seats: number; referralsThisMonth: number | null; avgAcceptMinutes: number | null; quietSeats: number | null };
    status?: string;
    seo: Seo;
};

function When({
    when,
    date,
    setWhen,
    setDate,
    error,
}: {
    when: string;
    date: string;
    setWhen: (v: 'now' | 'scheduled') => void;
    setDate: (v: string) => void;
    error?: string;
}) {
    return (
        <div className="flex flex-wrap items-end gap-3">
            <Segmented
                label="When"
                value={when as 'now' | 'scheduled'}
                onChange={setWhen}
                options={[
                    ['now', 'Apply now'],
                    ['scheduled', 'Schedule'],
                ]}
            />
            {when === 'scheduled' && (
                <Field label="Takes effect on" error={error}>
                    <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                </Field>
            )}
        </div>
    );
}

export default function GroupShow({ group, owners, scheduled, overrides, seats, pendingApplications, affected, health, status, seo }: Props) {
    const g = group;
    const details = useForm<{ name: string; description: string; status: string; visibility: string; photo: File | null }>({
        name: g.name,
        description: g.description ?? '',
        status: g.status,
        visibility: g.visibility,
        photo: null,
    });
    const pricing = useForm({ when: 'now', effective_on: '', values: g.pricing });
    const rules = useForm({
        when: 'now',
        effective_on: '',
        values: g.rules,
    });
    const [openSeat, setOpenSeat] = useState<number | null>(null);
    const owner = useForm({ email: '' });
    const [tab, setTab] = useState<'details' | 'seats' | 'pricing' | 'rules' | 'overrides' | 'owners'>('details');
    const p = pricing.data.values;
    const errs = (f: { errors: Record<string, string> }, k: string) => f.errors[`values.${k}`] ?? f.errors[k];
    const setP = (k: keyof Pricing, v: unknown) => pricing.setData('values', { ...p, [k]: v });
    const setR = (k: keyof Rules, v: unknown) => rules.setData('values', { ...rules.data.values, [k]: v });

    return (
        <AdminLayout title={g.name}>
            <SeoHead seo={seo} />
            <Link href="/admin/groups" className="text-sm text-muted hover:text-ink">
                All groups
            </Link>
            <div className="mt-2 mb-5 flex flex-wrap gap-2">
                <Badge>{g.industry.name}</Badge>
                <Badge tone={g.industry.tier === 'A' ? 'success' : g.industry.tier === 'B' ? 'warning' : 'neutral'}>Tier {g.industry.tier}</Badge>
                {g.status === 'live' ? (
                    <Badge tone="success">Live</Badge>
                ) : g.status === 'closed' ? (
                    <Badge tone="danger">Closed</Badge>
                ) : (
                    <Badge>Draft</Badge>
                )}
                <Badge>{g.visibility === 'open' ? 'Open to applications' : 'Invite only'}</Badge>
                {g.launch && <Badge tone="sender">Launch group</Badge>}
                {g.private && <Badge tone="info">Private network</Badge>}
                {g.region && <Badge>{g.region[0]!.toUpperCase() + g.region.slice(1)} region</Badge>}
            </div>
            {status && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi label="Owners" value={String(health.owners)} />
                <Kpi label="Seats" value={String(health.seats)} hint="Seats open in the next sprint." />
                <Kpi label="Referrals this month" value={health.referralsThisMonth === null ? '—' : String(health.referralsThisMonth)} />
                <Kpi label="Average time to accept" value={health.avgAcceptMinutes === null ? '—' : `${health.avgAcceptMinutes} min`} />
            </div>

            <div className="mb-5 flex gap-1 border-b border-line" role="tablist">
                {(['details', 'seats', 'pricing', 'rules', 'overrides', 'owners'] as const).map((t) => (
                    <button
                        key={t}
                        role="tab"
                        aria-selected={tab === t}
                        onClick={() => setTab(t)}
                        className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-medium capitalize ${tab === t ? 'border-primary' : 'border-transparent text-muted hover:text-ink'}`}
                    >
                        {t === 'pricing' ? 'Pricing' : t}
                    </button>
                ))}
            </div>

            {tab === 'details' && (
                <Card className="max-w-3xl space-y-4 p-6">
                    <Field label="Name" error={details.errors.name}>
                        <Input value={details.data.name} onChange={(e) => details.setData('name', e.target.value)} />
                    </Field>
                    <Field label="Description" error={details.errors.description}>
                        <Textarea rows={3} value={details.data.description} onChange={(e) => details.setData('description', e.target.value)} />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Status" error={details.errors.status}>
                            <Select value={details.data.status} onChange={(e) => details.setData('status', e.target.value)}>
                                <option value="draft">Draft</option>
                                <option value="live">Live</option>
                                <option value="closed">Closed</option>
                            </Select>
                        </Field>
                        <Field label="Who can join">
                            <Select value={details.data.visibility} onChange={(e) => details.setData('visibility', e.target.value)}>
                                <option value="open">Open: anyone can apply</option>
                                <option value="invite_only">Invite only</option>
                            </Select>
                        </Field>
                    </div>
                    <Field label="Photo" hint="Shown on the network page. JPG, PNG or WebP, up to 4 MB." error={details.errors.photo}>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={(e) => details.setData('photo', e.target.files?.[0] ?? null)}
                        />
                    </Field>
                    {g.photo && <img src={g.photo} alt="" className="h-24 rounded-md object-cover" />}
                    <Button
                        loading={details.processing}
                        onClick={() => details.post(`/admin/groups/${g.slug}`, { forceFormData: true, preserveScroll: true })}
                    >
                        Save details
                    </Button>
                </Card>
            )}

            {tab === 'pricing' && (
                <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                    <Card className="space-y-5 p-6">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field label="Seat price / month" error={errs(pricing, 'seat_price')}>
                                <Input type="number" value={p.seat_price} onChange={(e) => setP('seat_price', +e.target.value)} />
                            </Field>
                            <Field label="Founding price" error={errs(pricing, 'founding_price')}>
                                <Input type="number" value={p.founding_price} onChange={(e) => setP('founding_price', +e.target.value)} />
                            </Field>
                            <Field label="Free trial (months)" error={errs(pricing, 'trial_months')}>
                                <Input type="number" value={p.trial_months} onChange={(e) => setP('trial_months', +e.target.value)} />
                            </Field>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {['2', '5', '20'].map((k) => (
                                <Field
                                    key={k}
                                    label={`${k === '2' ? '2–4' : k === '5' ? '5–19' : '20+'} seats: % off`}
                                    error={errs(pricing, `discounts.${k}`)}
                                >
                                    <Input
                                        type="number"
                                        value={p.discounts[k]}
                                        onChange={(e) => setP('discounts', { ...p.discounts, [k]: +e.target.value })}
                                    />
                                </Field>
                            ))}
                        </div>
                        {errs(pricing, 'discounts') && <p className="text-sm text-danger">{errs(pricing, 'discounts')}</p>}
                        <Field label="Annual billing: months free" error={errs(pricing, 'annual_months_free')}>
                            <Input
                                type="number"
                                value={p.annual_months_free}
                                onChange={(e) => setP('annual_months_free', +e.target.value)}
                                className="max-w-32"
                            />
                        </Field>
                        <label className="flex items-start gap-3 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1"
                                disabled={!g.industry.guaranteeAllowed}
                                checked={p.value_guarantee && g.industry.guaranteeAllowed}
                                onChange={(e) => setP('value_guarantee', e.target.checked)}
                            />
                            <span>
                                <strong>Value guarantee:</strong> any month with no referral offered to a seat is free.
                                {!g.industry.guaranteeAllowed && (
                                    <span className="block text-muted">
                                        Locked off for Tier C: fees must be the same for everyone and not depend on referrals.
                                    </span>
                                )}
                            </span>
                        </label>
                        {errs(pricing, 'value_guarantee') && <p className="text-sm text-danger">{errs(pricing, 'value_guarantee')}</p>}
                        <When
                            when={pricing.data.when}
                            date={pricing.data.effective_on}
                            setWhen={(v) => pricing.setData('when', v)}
                            setDate={(v) => pricing.setData('effective_on', v)}
                            error={pricing.errors.effective_on}
                        />
                        <Button loading={pricing.processing} onClick={() => pricing.put(`/admin/groups/${g.slug}/pricing`, { preserveScroll: true })}>
                            {pricing.data.when === 'now' ? 'Save pricing' : 'Schedule pricing change'}
                        </Button>
                    </Card>
                    <div className="space-y-6">
                        <Card className="p-6">
                            <h2 className="font-semibold">What members see</h2>
                            <div className="mt-3 rounded-lg border border-line p-5">
                                <div className="font-display text-3xl font-extrabold">
                                    {money(p.seat_price)}
                                    <span className="text-base font-normal text-muted">
                                        {' '}
                                        / {g.industry.terms.referral === 'referral' ? 'city' : 'seat'} / month
                                    </span>
                                </div>
                                <ul className="mt-3 space-y-1.5 text-sm">
                                    {[
                                        `First ${p.trial_months} months free`,
                                        p.value_guarantee && g.industry.guaranteeAllowed
                                            ? 'Free for any month without a referral'
                                            : 'Flat monthly listing fee',
                                        `Founding members: ${money(p.founding_price)}, locked`,
                                        `Annual billing: ${p.annual_months_free} months free`,
                                    ].map((t) => (
                                        <li key={t} className="flex gap-2">
                                            <Check className="size-4 text-success" aria-hidden />
                                            {t}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </Card>
                        <Card className="flex gap-3 p-6 text-sm">
                            <Info className="size-5 shrink-0 text-info" aria-hidden />
                            <div>
                                <h2 className="font-semibold">Who this change affects</h2>
                                <p className="mt-1 text-muted">
                                    {affected.seats === 0
                                        ? 'No seats yet, so nobody is affected. Seats open in the next sprint; this will then show trial and paying seats.'
                                        : `${affected.seats} seats: ${affected.trial} on trial, ${affected.paying} paying. New prices apply from each seat’s next billing date.`}
                                </p>
                                <p className="mt-2 text-muted">Projected monthly revenue after trials: {money(affected.paying * p.seat_price)}</p>
                            </div>
                        </Card>
                    </div>
                </div>
            )}

            {tab === 'rules' && (
                <Card className="max-w-3xl space-y-4 p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        {(
                            [
                                ['accept_hours', 'Accept or decline within (hours)'],
                                ['first_contact_hours', 'First contact within (hours)'],
                                ['update_days', 'Status update every (days)'],
                                ['challenge_days', 'Seat challenge after (days below threshold)'],
                                ['challenge_threshold', 'Challenge threshold (trust score)'],
                                ['company_cap_pct', 'Company cap (% of a state’s seats)'],
                                ['grace_days', 'Grace after a failed payment (days)'],
                                ['release_days', 'Release an unpaid seat after (days)'],
                                ['offer_hours', 'Time to claim an offered seat (hours)'],
                            ] as [keyof Rules, string][]
                        ).map(([k, l]) => (
                            <Field key={k} label={l} error={errs(rules, k)}>
                                <Input type="number" value={rules.data.values[k] as number} onChange={(e) => setR(k, +e.target.value)} />
                            </Field>
                        ))}
                        <Field label="Waitlist ranking">
                            <Select value={rules.data.values.waitlist} onChange={(e) => setR('waitlist', e.target.value)}>
                                <option value="trust">Trust score and profile strength</option>
                                <option value="date">Date joined</option>
                            </Select>
                        </Field>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={!!rules.data.values.auto_approve} onChange={(e) => setR('auto_approve', e.target.checked)} />{' '}
                        Approve applications automatically when the license format checks out (open groups only)
                    </label>
                    <When
                        when={rules.data.when}
                        date={rules.data.effective_on}
                        setWhen={(v) => rules.setData('when', v)}
                        setDate={(v) => rules.setData('effective_on', v)}
                        error={rules.errors.effective_on}
                    />
                    <Button loading={rules.processing} onClick={() => rules.put(`/admin/groups/${g.slug}/rules`, { preserveScroll: true })}>
                        {rules.data.when === 'now' ? 'Save rules' : 'Schedule rule change'}
                    </Button>
                </Card>
            )}

            {tab === 'seats' && (
                <Card className="overflow-x-auto">
                    <div className="flex items-center gap-3 border-b border-line p-4 text-sm">
                        <span className="text-muted">{seats.length} seats with a holder, backup or waitlist</span>
                        <Link href={`/admin/groups/${g.slug}/applications`} className="ml-auto font-medium text-primary">
                            Applications{pendingApplications ? ` (${pendingApplications} pending)` : ''}
                        </Link>
                    </div>
                    <table className="w-full min-w-[820px] text-sm">
                        <thead className="text-left text-muted">
                            <tr>
                                {['Territory', 'Holder', 'Backup', 'Waitlist', 'Status', 'Trial ends', ''].map((h) => (
                                    <th key={h} scope="col" className="px-4 py-3 font-medium">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {seats.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-10 text-center text-muted">
                                        No seats yet. They appear as teams apply.
                                    </td>
                                </tr>
                            )}
                            {seats.map((s) => (
                                <Fragment key={s.id}>
                                    <tr key={s.id} className="border-t border-line">
                                        <td className="px-4 py-3 font-medium">
                                            {s.territory} {s.founding && <Badge tone="sender">Founding</Badge>}
                                        </td>
                                        <td className="px-4 py-3">{s.primary ?? '—'}</td>
                                        <td className="px-4 py-3">{s.backup ?? '—'}</td>
                                        <td className="px-4 py-3 tabular">{s.waitlist}</td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                tone={
                                                    s.status === 'active' || s.status === 'trial'
                                                        ? 'success'
                                                        : s.status === 'grace'
                                                          ? 'warning'
                                                          : s.status === 'inactive'
                                                            ? 'danger'
                                                            : 'neutral'
                                                }
                                            >
                                                {s.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-muted">{s.trialEnds ?? '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <Button size="sm" variant="ghost" onClick={() => setOpenSeat(openSeat === s.id ? null : s.id)}>
                                                History
                                            </Button>
                                        </td>
                                    </tr>
                                    {openSeat === s.id && (
                                        <tr key={`${s.id}-h`} className="bg-bg">
                                            <td colSpan={7} className="px-4 py-3">
                                                <ol className="space-y-1 text-xs">
                                                    {s.history.map((e, i) => (
                                                        <li key={i}>
                                                            <span className="text-muted">
                                                                {new Date(e.when.replace(' ', 'T') + 'Z').toLocaleString()}
                                                            </span>{' '}
                                                            · {e.event.replace('_', ' ')}
                                                            {e.team ? ` · ${e.team}` : ''}
                                                        </li>
                                                    ))}
                                                </ol>
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}

            {tab === 'overrides' && (
                <Card className="max-w-3xl">
                    <p className="border-b border-line p-5 text-sm text-muted">
                        By default this group uses its industry’s referral form, pipeline and agreement. Customise any of them for this group only. A
                        group agreement needs its own approval; until then members sign the industry’s approved agreement.
                    </p>
                    <ul className="divide-y divide-line">
                        {overrides.map((o) => (
                            <li key={o.kind} className="flex items-center gap-3 p-5">
                                <span className="flex-1 font-medium">{o.label}</span>
                                {o.version ? <Badge tone="info">Customised · v{o.version}</Badge> : <Badge>Uses {g.industry.name}’s</Badge>}
                                <Button variant="secondary" size="sm" asChild>
                                    <Link href={`/admin/groups/${g.slug}/${o.kind}`}>{o.version ? 'Edit' : 'Customise'}</Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {tab === 'owners' && (
                <Card className="max-w-3xl p-6">
                    <p className="text-sm text-muted">Owners approve applications and run the group. They can see pricing but never change it.</p>
                    <ul className="mt-4 divide-y divide-line rounded-md border border-line">
                        {owners.length === 0 && <li className="p-4 text-sm text-muted">No owners yet.</li>}
                        {owners.map((o) => (
                            <li key={o.id} className="flex items-center gap-3 p-3 text-sm">
                                <div className="flex-1">
                                    <div className="font-medium">{o.name}</div>
                                    <div className="text-muted">{o.email}</div>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove ${o.name}`}
                                    onClick={() => router.delete(`/admin/groups/${g.slug}/owners/${o.id}`, { preserveScroll: true })}
                                >
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-4 flex flex-wrap items-end gap-3">
                        <Field label="Add an owner by email" error={owner.errors.email} className="flex-1">
                            <Input type="email" value={owner.data.email} onChange={(e) => owner.setData('email', e.target.value)} />
                        </Field>
                        <Button
                            variant="secondary"
                            loading={owner.processing}
                            onClick={() => owner.post(`/admin/groups/${g.slug}/owners`, { preserveScroll: true, onSuccess: () => owner.reset() })}
                        >
                            <UserPlus /> Add owner
                        </Button>
                    </div>
                </Card>
            )}

            {scheduled.length > 0 && (
                <Card className="mt-6 max-w-4xl p-6">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <CalendarClock className="size-5 text-primary" aria-hidden /> Scheduled changes
                    </h2>
                    <ul className="mt-3 divide-y divide-line text-sm">
                        {scheduled.map((c) => (
                            <li key={c.id} className="flex flex-wrap items-center gap-3 py-3">
                                <Badge>{c.section === 'pricing' ? 'Pricing' : 'Rules'}</Badge>
                                <span>Takes effect {new Date(c.effectiveOn + 'T00:00').toLocaleDateString()}</span>
                                {c.notified ? <Badge tone="success">Members told</Badge> : <Badge>Notice goes out 30 days before</Badge>}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="ml-auto"
                                    onClick={() => router.delete(`/admin/groups/${g.slug}/scheduled/${c.id}`, { preserveScroll: true })}
                                >
                                    Cancel
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </AdminLayout>
    );
}
