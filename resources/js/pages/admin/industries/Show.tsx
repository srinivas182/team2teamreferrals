import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, Download, Lock, ShieldCheck, Unlock, X } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';
import { tierTone } from './Index';

type Rule = { required: boolean; issuingBody: string | null; pattern: string | null; lookupUrl: string | null; notes: string | null };
type StateCov = { id: number; code: string; name: string; rule: Rule | null };
type Props = {
    industry: {
        clientTextingApproved: boolean;
        slug: string;
        name: string;
        family: string;
        tier: 'A' | 'B' | 'C';
        territory: string;
        terminology: { pro: string; pros: string; client: string; clients: string; referral: string; fee: string };
        icon: string;
        description: string | null;
        licenseRequired: boolean;
        status: string;
        approved: boolean;
        approvedAt: string | null;
        approvedBy: string | null;
        note: string | null;
    };
    tierRules: Record<string, { label: string; rules: Record<string, boolean> }>;
    territoryPreview: Record<string, number>;
    coverage: StateCov[];
    fee: { mode: string; percent: string | null; flatCents: number | null; chargeAt: string; enabled: boolean };
    feesUnlocked: boolean;
    families: Record<string, string>;
    territories: Record<string, string>;
    isSuperAdmin: boolean;
    status?: string;
    seo: Seo;
};

const ruleLabels: Record<string, string> = {
    memberReferralFees: 'Members may agree referral fees',
    referralRouting: 'Referrals are routed to members',
    consumerRequests: '“Request a pro” form',
    valueGuarantee: 'Free month when no referral arrives',
    platformFee: 'Platform fee can ever be switched on',
    disclosure: 'Compliance disclosure shown',
};

export default function IndustryShow({
    industry: i,
    tierRules,
    territoryPreview,
    coverage,
    fee,
    feesUnlocked,
    families,
    territories,
    isSuperAdmin,
    status,
    seo,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const basics = useForm({
        name: i.name,
        family: i.family,
        tier: i.tier,
        territory_unit: i.territory,
        terminology: i.terminology,
        icon: i.icon,
        description: i.description ?? '',
        license_required: i.licenseRequired,
    });
    const feeForm = useForm({
        mode: fee.mode,
        percent: fee.percent ?? '',
        flat_cents: fee.flatCents ?? '',
        charge_at: fee.chargeAt,
        enabled: fee.enabled,
    });
    const approve = useForm({ note: '' });
    const unlock = useForm({ confirmation: '' });
    const [editing, setEditing] = useState<StateCov | null>(null);
    const lic = useForm({ required: true, issuing_body: '', number_pattern: '', lookup_url: '', notes: '' });
    const openState = (s: StateCov) => {
        lic.setData({
            required: s.rule?.required ?? true,
            issuing_body: s.rule?.issuingBody ?? '',
            number_pattern: s.rule?.pattern ?? '',
            lookup_url: s.rule?.lookupUrl ?? '',
            notes: s.rule?.notes ?? '',
        });
        setEditing(s);
    };
    const configured = coverage.filter((s) => s.rule).length;
    const tierChanged = basics.data.tier !== i.tier && i.approved;

    return (
        <AdminLayout
            title={i.name}
            actions={
                i.status === 'live' ? (
                    <Button
                        variant="secondary"
                        onClick={() => router.post(`/admin/industries/${i.slug}/status`, { live: false }, { preserveScroll: true })}
                    >
                        Take offline
                    </Button>
                ) : (
                    <Button
                        disabled={!i.approved}
                        onClick={() => router.post(`/admin/industries/${i.slug}/status`, { live: true }, { preserveScroll: true })}
                    >
                        Go live
                    </Button>
                )
            }
        >
            <SeoHead seo={seo} />
            <Link href="/admin/industries" className="text-sm text-muted hover:text-ink">
                All industries
            </Link>
            <div className="mt-2 mb-4 flex flex-wrap gap-2">
                <Link href={`/admin/industries/${i.slug}/form`} className="text-sm font-medium text-primary">
                    Referral form
                </Link>
                <Link href={`/admin/industries/${i.slug}/stages`} className="text-sm font-medium text-primary">
                    Pipeline stages
                </Link>
                <Link href={`/admin/industries/${i.slug}/agreement`} className="text-sm font-medium text-primary">
                    Agreement
                </Link>
                <button
                    className="text-sm font-medium text-primary"
                    onClick={() => {
                        const on = !i.clientTextingApproved;
                        const note = on ? window.prompt('Who approved texting clients in this industry? (e.g. counsel’s name and date)') : '';
                        if (on && !note) return;
                        router.post(`/admin/industries/${i.slug}/client-texting`, { approved: on, note }, { preserveScroll: true });
                    }}
                >
                    {i.clientTextingApproved ? 'Client texting: on' : 'Client texting: off'}
                </button>
                <Link href={`/admin/industries/${i.slug}/training`} className="text-sm font-medium text-primary">
                    Training
                </Link>
                <Link href={`/admin/industries/${i.slug}/messages`} className="mr-2 text-sm font-medium text-primary">
                    Messages
                </Link>
                <Badge tone={tierTone[i.tier]}>Tier {i.tier}</Badge>
                {i.status === 'live' ? <Badge tone="success">Live</Badge> : <Badge>Draft</Badge>}
                {i.approved ? <Badge tone="info">Compliance-approved</Badge> : <Badge tone="warning">Needs compliance approval</Badge>}
            </div>
            {status && (
                <Alert tone="success" className="mb-4 max-w-3xl">
                    {statusMessage(status)}
                </Alert>
            )}
            {(errors.status || errors.enabled) && (
                <Alert tone="danger" className="mb-4 max-w-3xl">
                    {errors.status ?? errors.enabled}
                </Alert>
            )}
            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <div className="space-y-6">
                    <Card className="p-6">
                        <h2 className="font-semibold">Basics and wording</h2>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <Field label="Name" error={basics.errors.name}>
                                <Input value={basics.data.name} onChange={(e) => basics.setData('name', e.target.value)} />
                            </Field>
                            <Field label="Family">
                                <Select value={basics.data.family} onChange={(e) => basics.setData('family', e.target.value)}>
                                    {Object.entries(families).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            {(['pro', 'pros', 'client', 'clients', 'referral', 'fee'] as const).map((k) => (
                                <Field
                                    key={k}
                                    label={
                                        {
                                            pro: 'A professional is called',
                                            pros: 'Plural',
                                            client: 'A client is called',
                                            clients: 'Plural',
                                            referral: 'A referral is called',
                                            fee: 'A referral fee is called',
                                        }[k]
                                    }
                                    error={basics.errors[`terminology.${k}` as keyof typeof basics.errors]}
                                >
                                    <Input
                                        value={basics.data.terminology[k]}
                                        onChange={(e) => basics.setData('terminology', { ...basics.data.terminology, [k]: e.target.value })}
                                    />
                                </Field>
                            ))}
                        </div>
                        <p className="mt-3 text-sm text-muted">
                            Preview: “Send your {basics.data.terminology.client} to a verified {basics.data.terminology.pro} in Austin.” · “New{' '}
                            {basics.data.terminology.referral}: Tom Brennan in Houston.”
                        </p>
                    </Card>

                    <Card className="p-6">
                        <h2 className="font-semibold">Compliance tier</h2>
                        <div className="mt-4 grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Compliance tier">
                            {Object.entries(tierRules).map(([t, def]) => (
                                <button
                                    key={t}
                                    type="button"
                                    role="radio"
                                    aria-checked={basics.data.tier === t}
                                    onClick={() => basics.setData('tier', t as 'A' | 'B' | 'C')}
                                    className={cn(
                                        'rounded-lg border p-4 text-left',
                                        basics.data.tier === t ? 'border-primary ring-2 ring-primary/20' : 'border-line',
                                    )}
                                >
                                    <div className="font-semibold">{def.label}</div>
                                    <ul className="mt-3 space-y-1.5 text-xs">
                                        {Object.entries(def.rules).map(([k, on]) => (
                                            <li key={k} className={cn('flex gap-1.5', on ? 'text-ink' : 'text-subtle')}>
                                                {on ? (
                                                    <Check className="size-3.5 shrink-0 text-success" aria-hidden />
                                                ) : (
                                                    <X className="size-3.5 shrink-0" aria-hidden />
                                                )}
                                                {ruleLabels[k]}
                                            </li>
                                        ))}
                                    </ul>
                                </button>
                            ))}
                        </div>
                        {tierChanged && (
                            <Alert tone="info" className="mt-4">
                                Saving a new tier removes the compliance approval and takes the industry offline until it is approved again.
                            </Alert>
                        )}
                    </Card>

                    <Card className="p-6">
                        <h2 className="font-semibold">Territory: one seat per…</h2>
                        <div className="mt-4 grid gap-3 sm:grid-cols-4" role="radiogroup" aria-label="Territory">
                            {Object.entries(territories).map(([k, v]) => (
                                <button
                                    key={k}
                                    type="button"
                                    role="radio"
                                    aria-checked={basics.data.territory_unit === k}
                                    onClick={() => basics.setData('territory_unit', k)}
                                    className={cn(
                                        'rounded-lg border p-4 text-left',
                                        basics.data.territory_unit === k ? 'border-primary ring-2 ring-primary/20' : 'border-line',
                                    )}
                                >
                                    <div className="font-semibold">{v}</div>
                                    <div className="mt-1 font-display text-xl font-bold tabular">{(territoryPreview[k] ?? 0).toLocaleString()}</div>
                                    <div className="text-xs text-muted">seats nationwide</div>
                                </button>
                            ))}
                        </div>
                        <label className="mt-4 flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={basics.data.license_required}
                                onChange={(e) => basics.setData('license_required', e.target.checked)}
                            />{' '}
                            Members must hold a verified license in the state of each seat
                        </label>
                        <div className="mt-5">
                            <Button loading={basics.processing} onClick={() => basics.put(`/admin/industries/${i.slug}`, { preserveScroll: true })}>
                                Save basics, tier and territory
                            </Button>
                        </div>
                    </Card>

                    {i.licenseRequired && (
                        <Card className="p-6">
                            <div className="flex flex-wrap items-center gap-3">
                                <h2 className="font-semibold">Licensing coverage</h2>
                                <span className="text-sm text-muted tabular">
                                    {configured} of {coverage.length} states configured
                                </span>
                                <Button size="sm" variant="ghost" className="ml-auto" asChild>
                                    <a href={`/admin/industries/${i.slug}/licensing.csv`}>
                                        <Download /> CSV for counsel
                                    </a>
                                </Button>
                            </div>
                            <div className="mt-4 grid grid-cols-6 gap-1.5 sm:grid-cols-9 lg:grid-cols-13">
                                {coverage.map((s) => (
                                    <button
                                        key={s.code}
                                        onClick={() => openState(s)}
                                        title={s.name}
                                        aria-label={`${s.name}: ${s.rule ? (s.rule.required ? 'license required, configured' : 'no license required') : 'not configured'}`}
                                        className={cn(
                                            'rounded-md py-2 text-xs font-semibold',
                                            s.rule
                                                ? s.rule.required
                                                    ? 'bg-cobalt-500 text-white'
                                                    : 'bg-success/15 text-success'
                                                : 'border border-dashed border-line text-muted hover:border-primary',
                                        )}
                                    >
                                        {s.code}
                                    </button>
                                ))}
                            </div>
                            <ul className="mt-3 flex flex-wrap gap-4 text-xs text-muted">
                                <li className="flex items-center gap-1.5">
                                    <span className="size-3 rounded bg-cobalt-500" />
                                    License rule set
                                </li>
                                <li className="flex items-center gap-1.5">
                                    <span className="size-3 rounded bg-success/30" />
                                    No license needed
                                </li>
                                <li className="flex items-center gap-1.5">
                                    <span className="size-3 rounded border border-dashed border-line" />
                                    Not configured yet
                                </li>
                            </ul>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card className="p-6">
                        <ShieldCheck className="text-primary" aria-hidden />
                        <h2 className="mt-3 font-semibold">Compliance approval</h2>
                        {i.approved ? (
                            <>
                                <p className="mt-2 text-sm text-muted">
                                    Approved {i.approvedAt} by {i.approvedBy}.
                                </p>
                                {i.note && <p className="mt-2 rounded-md bg-bg p-3 text-sm">{i.note}</p>}
                                {isSuperAdmin && (
                                    <Button
                                        variant="ghost"
                                        className="mt-3 text-danger"
                                        onClick={() =>
                                            router.post(
                                                `/admin/industries/${i.slug}/revoke`,
                                                { reason: 'Revoked from the admin' },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Revoke approval
                                    </Button>
                                )}
                            </>
                        ) : isSuperAdmin ? (
                            <form
                                className="mt-3 space-y-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    approve.post(`/admin/industries/${i.slug}/approve`, { preserveScroll: true });
                                }}
                            >
                                <Field
                                    label="Sign-off record"
                                    hint="Who approved this tier and on what basis, e.g. counsel, memo date, states reviewed."
                                    error={approve.errors.note}
                                >
                                    <Textarea rows={3} value={approve.data.note} onChange={(e) => approve.setData('note', e.target.value)} />
                                </Field>
                                <Button type="submit" loading={approve.processing}>
                                    Record approval
                                </Button>
                            </form>
                        ) : (
                            <p className="mt-2 text-sm text-muted">A super admin records legal sign-off before this industry can go live.</p>
                        )}
                    </Card>

                    <Card className="p-6">
                        <div className="flex items-center gap-2">
                            <h2 className="font-semibold">Platform referral fee</h2>
                            {!feesUnlocked && (
                                <Badge tone="warning">
                                    <Lock className="size-3" aria-hidden /> Locked
                                </Badge>
                            )}
                        </div>
                        {i.tier !== 'A' ? (
                            <p className="mt-2 text-sm text-muted">Never available for Tier {i.tier} industries.</p>
                        ) : (
                            <div className="mt-4 space-y-4">
                                <Field label="Mode">
                                    <Select value={feeForm.data.mode} onChange={(e) => feeForm.setData('mode', e.target.value)}>
                                        <option value="none">None</option>
                                        <option value="percent">Percentage of the referral commission</option>
                                        <option value="flat">Flat amount per referral</option>
                                    </Select>
                                </Field>
                                {feeForm.data.mode === 'percent' && (
                                    <Field label="Percent" error={feeForm.errors.percent}>
                                        <Input
                                            type="number"
                                            step="0.1"
                                            value={feeForm.data.percent}
                                            onChange={(e) => feeForm.setData('percent', e.target.value)}
                                        />
                                    </Field>
                                )}
                                {feeForm.data.mode === 'flat' && (
                                    <Field label="Amount (cents)" error={feeForm.errors.flat_cents}>
                                        <Input
                                            type="number"
                                            value={feeForm.data.flat_cents}
                                            onChange={(e) => feeForm.setData('flat_cents', e.target.value)}
                                        />
                                    </Field>
                                )}
                                <Field label="Charged at">
                                    <Select value={feeForm.data.charge_at} onChange={(e) => feeForm.setData('charge_at', e.target.value)}>
                                        <option value="closing">Closing</option>
                                        <option value="acceptance">Acceptance</option>
                                    </Select>
                                </Field>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        disabled={!feesUnlocked}
                                        checked={feeForm.data.enabled}
                                        onChange={(e) => feeForm.setData('enabled', e.target.checked)}
                                    />{' '}
                                    Switch this fee on
                                </label>
                                <Button
                                    variant="secondary"
                                    loading={feeForm.processing}
                                    onClick={() => feeForm.put(`/admin/industries/${i.slug}/fee`, { preserveScroll: true })}
                                >
                                    Save fee settings
                                </Button>
                            </div>
                        )}
                        {isSuperAdmin && (
                            <div className="mt-5 border-t border-line pt-4">
                                {feesUnlocked ? (
                                    <Button
                                        variant="ghost"
                                        className="text-danger"
                                        onClick={() => router.post('/admin/industries/fees/lock', {}, { preserveScroll: true })}
                                    >
                                        <Lock /> Lock all platform fees
                                    </Button>
                                ) : (
                                    <form
                                        className="space-y-3"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            unlock.post('/admin/industries/fees/unlock', { preserveScroll: true });
                                        }}
                                    >
                                        <Field
                                            label="Unlock platform fees (all industries)"
                                            hint="Only after brokerage licensing and fund routing are in place. Describe what was approved."
                                            error={unlock.errors.confirmation}
                                        >
                                            <Textarea
                                                rows={2}
                                                value={unlock.data.confirmation}
                                                onChange={(e) => unlock.setData('confirmation', e.target.value)}
                                            />
                                        </Field>
                                        <Button type="submit" variant="secondary" loading={unlock.processing}>
                                            <Unlock /> Unlock
                                        </Button>
                                    </form>
                                )}
                            </div>
                        )}
                    </Card>
                </div>
            </div>

            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent title={`Licensing in ${editing?.name ?? ''}`}>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (editing)
                                lic.put(`/admin/industries/${i.slug}/licensing/${editing.id}`, {
                                    preserveScroll: true,
                                    onSuccess: () => setEditing(null),
                                });
                        }}
                    >
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={lic.data.required} onChange={(e) => lic.setData('required', e.target.checked)} /> A
                            license is required in this state
                        </label>
                        <Field label="Issuing body" error={lic.errors.issuing_body}>
                            <Input
                                value={lic.data.issuing_body}
                                onChange={(e) => lic.setData('issuing_body', e.target.value)}
                                placeholder="e.g. Texas Real Estate Commission"
                            />
                        </Field>
                        <Field
                            label="License number format (regular expression)"
                            hint="Optional. e.g. \d{6,7} for six or seven digits."
                            error={lic.errors.number_pattern}
                        >
                            <Input value={lic.data.number_pattern} onChange={(e) => lic.setData('number_pattern', e.target.value)} />
                        </Field>
                        <Field label="Public lookup page" error={lic.errors.lookup_url}>
                            <Input type="url" value={lic.data.lookup_url} onChange={(e) => lic.setData('lookup_url', e.target.value)} />
                        </Field>
                        <Field label="Notes">
                            <Textarea rows={2} value={lic.data.notes} onChange={(e) => lic.setData('notes', e.target.value)} />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={lic.processing}>
                                Save
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
