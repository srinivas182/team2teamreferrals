import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Segmented } from '@/components/ui/data';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type St = 'pending' | 'approved' | 'rejected' | 'suspended' | 'changes';
type Settings = { price: number; annualDiscount: number; trialDays: number; cap: number };
type Row = {
    id: string;
    slug: string;
    name: string;
    category: string;
    contact: string;
    email: string;
    phone: string | null;
    website: string | null;
    description: string;
    logo: string | null;
    licensed: boolean;
    licenseLabel: string | null;
    licenseNumber: string | null;
    licenseState: string | null;
    licenseLookup: string | null;
    licenseVerified: string | null;
    offer: string | null;
    cities: string[];
    reason: string | null;
    billing: string;
    cycle: string;
    paidUntil: string | null;
    trialEnds: string | null;
    cancelAt: string | null;
    changes: { field: string; from: string | null; to: string | null }[] | null;
    applied: string;
    reviewed: string | null;
    endorsements: number;
    enquiries: number;
};
const LABELS: Record<St, string> = {
    pending: 'Waiting',
    changes: 'Changes to review',
    approved: 'Approved',
    rejected: 'Not approved',
    suspended: 'Suspended',
};

/** Admin → Partners: review applications (license checked first where the field needs one), then manage live listings. */
export default function AdminPartners({
    status,
    counts,
    rows,
    enabled,
    settings,
    canPrice,
    flash,
    seo,
}: {
    status: St;
    counts: Record<St, number>;
    rows: Row[];
    enabled: boolean;
    settings: Settings;
    canPrice: boolean;
    flash?: string;
    seo: Seo;
}) {
    const [reasons, setReasons] = useState<Record<string, string>>({});
    const [checked, setChecked] = useState<Record<string, boolean>>({});
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;
    const act = (id: string, action: string, data: Record<string, string | boolean | undefined> = {}) =>
        router.post(`/admin/partners/${id}/${action}`, data, { preserveScroll: true });
    return (
        <AdminLayout
            title="Partners"
            actions={
                <Button variant="secondary" size="sm" asChild>
                    <Link href="/admin/partners/categories">Categories</Link>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            {!enabled && (
                <Alert tone="info" className="mb-4 max-w-4xl">
                    The partner directory is switched off in System → Modules, so approved listings aren’t shown.
                </Alert>
            )}
            {flash && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(flash)}
                </Alert>
            )}
            {(errors.license || errors.reason) && (
                <Alert tone="danger" className="mb-4 max-w-4xl">
                    {errors.license ?? errors.reason}
                </Alert>
            )}
            <PriceSettings settings={settings} canPrice={canPrice} />
            <div className="mb-4">
                <Segmented
                    label="Show"
                    value={status}
                    onChange={(v) => router.get('/admin/partners', { status: v })}
                    options={(Object.keys(LABELS) as St[]).map((k) => [k, `${LABELS[k]} (${counts[k] ?? 0})`] as [St, string])}
                />
            </div>
            <div className="max-w-5xl space-y-3">
                {rows.length === 0 && <Card className="p-10 text-center text-muted">Nothing here.</Card>}
                {rows.map((r) => (
                    <Card key={r.id} className="p-5">
                        <div className="flex flex-wrap items-start gap-4">
                            {r.logo && <img src={r.logo} alt="" width={48} height={48} className="size-12 rounded-md object-contain" />}
                            <div className="min-w-0 flex-1">
                                <div className="font-semibold">
                                    {status === 'approved' ? (
                                        <a href={`/partner/${r.slug}`} target="_blank" rel="noreferrer" className="text-primary">
                                            {r.name}
                                        </a>
                                    ) : (
                                        r.name
                                    )}
                                </div>
                                <div className="text-sm text-muted">
                                    {r.category} · applied {r.applied}
                                    {r.reviewed ? ` · reviewed ${r.reviewed}` : ''} · {r.contact}, {r.email}
                                    {r.phone ? `, ${r.phone}` : ''}
                                </div>
                                {r.website && (
                                    <a href={r.website} target="_blank" rel="noreferrer nofollow" className="text-sm text-primary">
                                        {r.website}
                                    </a>
                                )}
                                <p className="mt-2 text-sm whitespace-pre-line">{r.description}</p>
                                <p className="mt-2 text-sm">
                                    <span className="font-medium">Cities:</span> {r.cities.join('; ')}
                                </p>
                                {r.offer && (
                                    <p className="mt-1 text-sm">
                                        <span className="font-medium">Offer:</span> {r.offer}
                                    </p>
                                )}
                                <p className="mt-1 text-sm">
                                    <span className="font-medium">{r.licenseLabel ?? 'License'}:</span>{' '}
                                    {r.licenseNumber ? (
                                        <span className="font-mono">
                                            {r.licenseNumber}
                                            {r.licenseState ? ` (${r.licenseState})` : ''}
                                        </span>
                                    ) : (
                                        'none given'
                                    )}
                                    {r.licenseVerified && <Badge className="ml-2">checked {r.licenseVerified}</Badge>}
                                    {r.licensed && !r.licenseNumber && (
                                        <Badge tone="danger" className="ml-2">
                                            <TriangleAlert className="size-3" aria-hidden /> required for this category
                                        </Badge>
                                    )}
                                </p>
                                {status !== 'pending' && (
                                    <p className="mt-1 text-sm text-muted">
                                        {r.enquiries} enquiries · {r.endorsements} endorsements
                                    </p>
                                )}
                                {r.reason && <p className="mt-2 text-sm text-muted">Reason given: {r.reason}</p>}
                                {status === 'approved' && (
                                    <p className="mt-1 text-sm text-muted">
                                        Billing: {r.billing} · {r.cycle}
                                        {r.trialEnds && r.billing === 'Free trial' ? ` · trial ends ${r.trialEnds}` : ''}
                                        {r.paidUntil ? ` · paid until ${r.paidUntil}` : ''}
                                        {r.cancelAt ? ` · ends ${r.cancelAt}` : ''}
                                    </p>
                                )}
                                {r.changes && (
                                    <div className="mt-3 rounded-md border border-line p-3 text-sm">
                                        <div className="font-medium">Changes waiting for review</div>
                                        <ul className="mt-1 space-y-1">
                                            {r.changes.map((c) => (
                                                <li key={c.field}>
                                                    <span className="font-medium">{c.field}:</span>{' '}
                                                    {c.field === 'Logo' ? (
                                                        <span className="inline-flex items-center gap-2">
                                                            {c.from && (
                                                                <img src={c.from} alt="Current logo" width={32} height={32} className="size-8" />
                                                            )}{' '}
                                                            → {c.to && <img src={c.to} alt="New logo" width={32} height={32} className="size-8" />}
                                                        </span>
                                                    ) : (
                                                        <>
                                                            <s className="text-muted">{c.from ?? '(empty)'}</s> → {c.to ?? '(empty)'}
                                                        </>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>
                            {r.licenseLookup && (
                                <Button variant="secondary" size="sm" asChild>
                                    <a href={r.licenseLookup} target="_blank" rel="noreferrer">
                                        <ExternalLink /> Check with the regulator
                                    </a>
                                </Button>
                            )}
                        </div>
                        <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                            {status === 'pending' && r.licenseNumber && !r.licenseVerified && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={checked[r.id] ?? false}
                                        onChange={(e) => setChecked({ ...checked, [r.id]: e.target.checked })}
                                    />
                                    I checked the license
                                </label>
                            )}
                            {status === 'changes' && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={checked[r.id] ?? false}
                                        onChange={(e) => setChecked({ ...checked, [r.id]: e.target.checked })}
                                    />
                                    I checked any new license number
                                </label>
                            )}
                            {(status === 'pending' || status === 'approved' || status === 'changes') && (
                                <Input
                                    aria-label="Reason"
                                    placeholder={
                                        status === 'pending'
                                            ? 'Reason (required to reject; the business sees it)'
                                            : 'Reason (required to suspend; the business sees it)'
                                    }
                                    value={reasons[r.id] ?? ''}
                                    onChange={(e) => setReasons({ ...reasons, [r.id]: e.target.value })}
                                    className="h-9 min-w-64 flex-1"
                                />
                            )}
                            {status === 'pending' && (
                                <>
                                    <Button size="sm" onClick={() => act(r.id, 'approve', { license_checked: checked[r.id] ?? false })}>
                                        Approve
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="danger"
                                        disabled={!reasons[r.id]?.trim()}
                                        onClick={() => act(r.id, 'reject', { reason: reasons[r.id] })}
                                    >
                                        Reject
                                    </Button>
                                </>
                            )}
                            {status === 'approved' && (
                                <Button
                                    size="sm"
                                    variant="danger"
                                    disabled={!reasons[r.id]?.trim()}
                                    onClick={() => act(r.id, 'suspend', { reason: reasons[r.id] })}
                                >
                                    Suspend
                                </Button>
                            )}
                            {status === 'changes' && (
                                <>
                                    <Button size="sm" onClick={() => act(r.id, 'approve-changes', { license_checked: checked[r.id] ?? false })}>
                                        Approve changes
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="danger"
                                        disabled={!reasons[r.id]?.trim()}
                                        onClick={() => act(r.id, 'reject-changes', { reason: reasons[r.id] })}
                                    >
                                        Reject changes
                                    </Button>
                                </>
                            )}
                            {status === 'suspended' && (
                                <Button size="sm" onClick={() => act(r.id, 'reinstate')}>
                                    Reinstate
                                </Button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
        </AdminLayout>
    );
}

/** Price per city, yearly discount, free trial and the per-city cap. Price changes need the billing permission. */
function PriceSettings({ settings, canPrice }: { settings: Settings; canPrice: boolean }) {
    const f = useForm({ price: settings.price, annual_discount: settings.annualDiscount, trial_days: settings.trialDays, cap: settings.cap });
    const e = f.errors as Record<string, string | undefined>;
    return (
        <Card className="mb-4 max-w-5xl p-5">
            <h2 className="font-semibold">Prices and limits</h2>
            <div className="mt-3 grid gap-3 sm:grid-cols-4">
                <Field label="Price per city a month ($)" error={e.price}>
                    <Input
                        type="number"
                        min={1}
                        step="0.01"
                        disabled={!canPrice}
                        value={f.data.price}
                        onChange={(ev) => f.setData('price', Number(ev.target.value))}
                    />
                </Field>
                <Field label="Yearly discount (%)" error={e.annual_discount}>
                    <Input
                        type="number"
                        min={0}
                        max={50}
                        disabled={!canPrice}
                        value={f.data.annual_discount}
                        onChange={(ev) => f.setData('annual_discount', Number(ev.target.value))}
                    />
                </Field>
                <Field label="Free trial (days)" error={e.trial_days}>
                    <Input
                        type="number"
                        min={0}
                        max={90}
                        disabled={!canPrice}
                        value={f.data.trial_days}
                        onChange={(ev) => f.setData('trial_days', Number(ev.target.value))}
                    />
                </Field>
                <Field label="Partners per city and category" error={e.cap}>
                    <Input
                        type="number"
                        min={1}
                        max={50}
                        disabled={!canPrice}
                        value={f.data.cap}
                        onChange={(ev) => f.setData('cap', Number(ev.target.value))}
                    />
                </Field>
            </div>
            {canPrice ? (
                <Button
                    size="sm"
                    className="mt-3"
                    loading={f.processing}
                    onClick={() => f.post('/admin/partners/settings', { preserveScroll: true })}
                >
                    Save
                </Button>
            ) : (
                <p className="mt-2 text-sm text-muted">Changing these needs the billing permission.</p>
            )}
            <p className="mt-2 text-xs text-muted">
                The same price for everyone in a category, never per lead. New prices apply from each partner’s next bill.
            </p>
        </Card>
    );
}
