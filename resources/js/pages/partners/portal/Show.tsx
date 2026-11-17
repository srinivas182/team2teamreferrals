import { Link, router, useForm } from '@inertiajs/react';
import { Download, X } from 'lucide-react';
import { useState } from 'react';
import { CardForm, payInvoice } from '@/components/billing/CardForm';
import { CityPicker, type CityOption } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Segmented } from '@/components/ui/data';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import { statusMessage } from '@/lib/status';
import { Tour } from '@/components/tour/Tour';
import { PARTNER_TOUR } from '@/lib/tours';
import type { Seo } from '@/types/seo';

type Details = {
    business_name: string;
    description: string;
    phone: string | null;
    website: string | null;
    license_number: string | null;
    license_state: string | null;
    offer_title: string | null;
    offer_details: string | null;
    offer_expires_on: string | null;
};
type Stat = { views: number; clicks: number; enquiries: number; shares: number };
type Props = {
    partner: {
        id: string;
        slug: string;
        name: string;
        category: string;
        licensed: boolean;
        licenseLabel: string | null;
        status: string;
        statusLabel: string;
        reason: string | null;
        live: boolean;
        details: Details;
        logo: string | null;
        pending: (Partial<Details> & { logo: boolean }) | null;
    };
    billing: {
        status: string;
        statusLabel: string;
        cycle: 'monthly' | 'annual';
        trialEnds: string | null;
        paidUntil: string | null;
        cancelAt: string | null;
        monthly: number;
        annual: number;
        price: number;
        discount: number;
        card: { brand: string; last4: string; exp: string } | null;
        invoices: { id: number; number: string; date: string; total: number; status: string; failure: string | null; canPay: boolean }[];
    };
    stripeKey: string | null;
    listings: { id: number; city: string; status: string; position: number | null; href: string }[];
    cap: number;
    stats: { totals: Stat; cities: (Stat & { city: string })[]; days: (Stat & { day: string })[]; spentCents: number; costPerEnquiry: number | null };
    enquiries: { name: string; email: string; phone: string | null; message: string; created_at: string; city: string | null }[];
    status?: string;
    seo: Seo;
};
type Tab = 'overview' | 'listing' | 'cities' | 'billing' | 'enquiries';
const money = (n: number) => `$${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** The partner account: how the listing is doing, and everything a partner can change themselves. */
export default function PartnerPortal(props: Props) {
    const { partner, billing, status, seo } = props;
    const [tab, setTab] = useState<Tab>('overview');
    // The partner tour, once per browser (partners have no account settings to remember it in).
    const [tour, setTour] = useState(() => {
        if (typeof window === 'undefined') return false;
        try {
            return !window.localStorage.getItem('t2t-partner-tour') && !window.location.search.includes('tour=off');
        } catch {
            return false;
        }
    });
    const endTour = () => {
        setTour(false);
        try {
            window.localStorage.setItem('t2t-partner-tour', '1');
        } catch {
            // private browsing: the tour may show again next time
        }
    };
    const base = `/partner-portal/${partner.id}`;
    return (
        <PublicLayout current="/partners">
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-4xl px-4 py-10">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl font-bold">{partner.name}</h1>
                        <p className="text-muted">
                            {partner.category} · {partner.statusLabel}
                            {partner.status === 'approved' && ` · ${billing.statusLabel}`}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {partner.live && (
                            <Button variant="secondary" size="sm" asChild data-tour="partner-listing">
                                <a href={`/partner/${partner.slug}`} target="_blank" rel="noreferrer">
                                    See my listing
                                </a>
                            </Button>
                        )}
                        <Button variant="ghost" size="sm" onClick={() => router.post('/partner-portal/logout')}>
                            Sign out
                        </Button>
                    </div>
                </div>
                {status && (
                    <Alert tone="success" className="mt-4">
                        {statusMessage(status)}
                    </Alert>
                )}
                {partner.reason && partner.status !== 'approved' && (
                    <Alert tone="danger" className="mt-4">
                        Reason given: {partner.reason}
                    </Alert>
                )}
                {['past_due', 'paused'].includes(billing.status) && (
                    <Alert tone="danger" className="mt-4">
                        {billing.status === 'paused'
                            ? 'Your listing is paused because payment failed. Add a card under Billing and it comes straight back.'
                            : 'Your last payment failed. Update your card under Billing to keep your listing up.'}
                    </Alert>
                )}
                <div className="mt-6" data-tour="partner-tabs">
                    <Segmented
                        label="Section"
                        value={tab}
                        onChange={setTab}
                        options={[
                            ['overview', 'Overview'],
                            ['listing', 'Listing'],
                            ['cities', 'Cities'],
                            ['billing', 'Billing'],
                            ['enquiries', props.enquiries.length > 0 ? `Enquiries (${props.enquiries.length})` : 'Enquiries'],
                        ]}
                    />
                </div>
                <div className="mt-6">
                    {tab === 'overview' && <Overview {...props} base={base} />}
                    {tab === 'listing' && <Listing {...props} base={base} />}
                    {tab === 'cities' && <Cities {...props} base={base} />}
                    {tab === 'billing' && <Billing {...props} base={base} />}
                    {tab === 'enquiries' && <Enquiries {...props} />}
                </div>
            </div>
            {tour && <Tour steps={PARTNER_TOUR} onEnd={endTour} />}
        </PublicLayout>
    );
}

function Overview({ stats, base }: Props & { base: string }) {
    const t = stats.totals;
    return (
        <div className="space-y-4">
            <h2 className="font-display text-xl font-bold">Last 30 days</h2>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                {(
                    [
                        ['Views', t.views],
                        ['Clicks', t.clicks],
                        ['Enquiries', t.enquiries],
                        ['Member shares', t.shares],
                        ['Cost per enquiry', stats.costPerEnquiry !== null ? money(stats.costPerEnquiry) : '–'],
                    ] as [string, number | string][]
                ).map(([label, v]) => (
                    <Card key={label} className="p-4">
                        <div className="text-sm text-muted">{label}</div>
                        <div className="mt-1 font-display text-2xl font-bold">{v}</div>
                    </Card>
                ))}
            </div>
            <p className="text-sm text-muted">
                Views count each visitor once a day when your listing is shown; clicks are calls and website visits. Search engine robots are left
                out. Paid in this time: {money(stats.spentCents / 100)}.
            </p>
            {stats.cities.length > 0 && (
                <table className="w-full text-sm">
                    <caption className="sr-only">By city</caption>
                    <thead>
                        <tr className="text-left text-muted">
                            <th className="py-1">City</th>
                            <th>Views</th>
                            <th>Clicks</th>
                            <th>Enquiries</th>
                            <th>Shares</th>
                        </tr>
                    </thead>
                    <tbody>
                        {stats.cities.map((c) => (
                            <tr key={c.city} className="border-t border-line">
                                <td className="py-1">{c.city}</td>
                                <td>{c.views}</td>
                                <td>{c.clicks}</td>
                                <td>{c.enquiries}</td>
                                <td>{c.shares}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            <Button variant="secondary" size="sm" asChild>
                <a href={`${base}/stats.csv`}>
                    <Download /> Download 90 days (CSV)
                </a>
            </Button>
        </div>
    );
}

function Listing({ partner, base }: Props & { base: string }) {
    const d = { ...partner.details, ...(partner.pending ?? {}) };
    const f = useForm({
        business_name: d.business_name ?? '',
        description: d.description ?? '',
        phone: d.phone ?? '',
        website: d.website ?? '',
        license_number: d.license_number ?? '',
        license_state: d.license_state ?? '',
        offer_title: d.offer_title ?? '',
        offer_details: d.offer_details ?? '',
        offer_expires_on: d.offer_expires_on ?? '',
        logo: null as File | null,
    });
    const e = f.errors as Record<string, string | undefined>;
    return (
        <form
            className="space-y-4"
            onSubmit={(ev) => {
                ev.preventDefault();
                f.post(`${base}/details`, { forceFormData: true, preserveScroll: true });
            }}
        >
            {partner.pending && (
                <Alert tone="info">Your latest changes are waiting for review. Your listing shows the previous version until then.</Alert>
            )}
            {partner.status === 'approved' && (
                <p className="text-sm text-muted">Changes are checked by our team before they show, usually within a business day.</p>
            )}
            <Field label="Business name" error={e.business_name}>
                <Input value={f.data.business_name} onChange={(ev) => f.setData('business_name', ev.target.value)} />
            </Field>
            <Field label="Description" error={e.description}>
                <Textarea rows={5} value={f.data.description} onChange={(ev) => f.setData('description', ev.target.value)} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Phone" error={e.phone}>
                    <Input type="tel" value={f.data.phone} onChange={(ev) => f.setData('phone', ev.target.value)} />
                </Field>
                <Field label="Website" error={e.website}>
                    <Input type="url" value={f.data.website} onChange={(ev) => f.setData('website', ev.target.value)} />
                </Field>
                {(partner.licensed || partner.licenseLabel) && (
                    <>
                        <Field
                            label={partner.licenseLabel ?? 'License number'}
                            hint="A new number is checked again before the badge shows."
                            error={e.license_number}
                        >
                            <Input value={f.data.license_number} onChange={(ev) => f.setData('license_number', ev.target.value)} />
                        </Field>
                        <Field label="License state" error={e.license_state}>
                            <Input
                                maxLength={2}
                                value={f.data.license_state}
                                onChange={(ev) => f.setData('license_state', ev.target.value.toUpperCase())}
                            />
                        </Field>
                    </>
                )}
                <Field label="Offer" error={e.offer_title}>
                    <Input value={f.data.offer_title} onChange={(ev) => f.setData('offer_title', ev.target.value)} />
                </Field>
                <Field label="Offer ends" error={e.offer_expires_on}>
                    <Input type="date" value={f.data.offer_expires_on} onChange={(ev) => f.setData('offer_expires_on', ev.target.value)} />
                </Field>
            </div>
            <Field label="Offer details" error={e.offer_details}>
                <Textarea rows={2} value={f.data.offer_details} onChange={(ev) => f.setData('offer_details', ev.target.value)} />
            </Field>
            <Field label="New logo (optional)" hint="JPEG, PNG or WebP, up to 2 MB." error={e.logo}>
                <Input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="py-2"
                    onChange={(ev) => f.setData('logo', ev.target.files?.[0] ?? null)}
                />
            </Field>
            <Button type="submit" loading={f.processing}>
                {partner.status === 'approved' ? 'Send changes for review' : 'Save'}
            </Button>
        </form>
    );
}

function Cities({ listings, cap, billing, base }: Props & { base: string }) {
    const [picked, setPicked] = useState<CityOption[]>([]);
    return (
        <div className="space-y-4">
            <p className="text-sm text-muted">
                ${billing.price} a month for each city you’re listed in. Each city shows at most {cap} businesses per category; when it’s full you
                wait for a spot and pay nothing for that city. Changes to your cities apply to your next bill.
            </p>
            <ul className="divide-y divide-line rounded-lg border border-line">
                {listings.map((l) => (
                    <li key={l.id} className="flex flex-wrap items-center gap-3 p-3">
                        <Link href={l.href} className="flex-1 text-primary">
                            {l.city}
                        </Link>
                        {l.status === 'active' ? <Badge tone="success">Listed</Badge> : <Badge tone="warning">Waitlist #{l.position ?? '–'}</Badge>}
                        {listings.length > 1 && (
                            <Button variant="ghost" size="sm" onClick={() => router.delete(`${base}/cities/${l.id}`, { preserveScroll: true })}>
                                Remove
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
            <Field label="Add cities">
                <CityPicker onPick={(c) => !picked.some((x) => x.id === c.id) && setPicked([...picked, c])} />
            </Field>
            {picked.length > 0 && (
                <>
                    <ul className="flex flex-wrap gap-2">
                        {picked.map((c) => (
                            <li key={c.id} className="flex items-center gap-1 rounded-full border border-line px-3 py-1 text-sm">
                                {c.label}
                                <button type="button" aria-label={`Remove ${c.label}`} onClick={() => setPicked(picked.filter((x) => x.id !== c.id))}>
                                    <X className="size-3.5" aria-hidden />
                                </button>
                            </li>
                        ))}
                    </ul>
                    <Button
                        onClick={() =>
                            router.post(
                                `${base}/cities`,
                                { cities: picked.map((c) => c.id) },
                                { preserveScroll: true, onSuccess: () => setPicked([]) },
                            )
                        }
                    >
                        Add {picked.length} {picked.length === 1 ? 'city' : 'cities'}
                    </Button>
                </>
            )}
        </div>
    );
}

function Billing({ partner, billing, stripeKey, base }: Props & { base: string }) {
    const [error, setError] = useState<string | null>(null);
    const [cycle, setCycle] = useState(billing.cycle);
    if (partner.status !== 'approved') {
        return <p>Billing starts once your listing is approved. Nothing is charged before then.</p>;
    }
    return (
        <div className="space-y-6">
            <Card className="space-y-2 p-5">
                <h2 className="font-semibold">Your plan</h2>
                <p>
                    {money(billing.monthly)} a month, or {money(billing.annual)} a year ({billing.discount}% less).
                </p>
                {billing.status === 'trial' && billing.trialEnds && (
                    <p>Free trial until {billing.trialEnds}. Add a card before then to stay listed.</p>
                )}
                {billing.paidUntil && <p>Paid until {billing.paidUntil}.</p>}
                {billing.cancelAt && <p>Ends on {billing.cancelAt}; nothing more will be charged.</p>}
                <div className="flex flex-wrap items-end gap-2">
                    <Field label="Pay">
                        <Select value={cycle} onChange={(e) => setCycle(e.target.value as 'monthly' | 'annual')}>
                            <option value="monthly">Monthly</option>
                            <option value="annual">Yearly ({billing.discount}% less)</option>
                        </Select>
                    </Field>
                    {cycle !== billing.cycle && (
                        <Button size="sm" onClick={() => router.post(`${base}/cycle`, { cycle }, { preserveScroll: true })}>
                            Switch from my next bill
                        </Button>
                    )}
                </div>
                <div className="flex flex-wrap gap-2 pt-2">
                    {['cancelled', 'paused'].includes(billing.status) ? (
                        <Button size="sm" onClick={() => router.post(`${base}/plan/restart`, {}, { preserveScroll: true })}>
                            Start my listing again
                        </Button>
                    ) : billing.cancelAt ? (
                        <Button size="sm" onClick={() => router.post(`${base}/plan/resume`, {}, { preserveScroll: true })}>
                            Keep my listing
                        </Button>
                    ) : (
                        <Button variant="danger" size="sm" onClick={() => router.post(`${base}/plan/cancel`, {}, { preserveScroll: true })}>
                            Cancel my listing
                        </Button>
                    )}
                </div>
            </Card>
            <Card className="space-y-3 p-5">
                <h2 className="font-semibold">Card</h2>
                {billing.card ? (
                    <p>
                        {billing.card.brand} ending {billing.card.last4}, expires {billing.card.exp}
                    </p>
                ) : (
                    <p>No card yet.</p>
                )}
                {stripeKey ? (
                    <CardForm setupUrl={`${base}/setup-intent`} saveUrl={`${base}/card`} stripeKey={stripeKey} onDone={() => undefined} />
                ) : (
                    <p className="text-sm text-muted">Card payments aren’t set up yet. We’ll be in touch.</p>
                )}
            </Card>
            <Card className="p-5">
                <h2 className="font-semibold">Invoices</h2>
                {error && <p className="mt-2 text-sm text-danger">{error}</p>}
                {billing.invoices.length === 0 ? (
                    <p className="mt-2 text-muted">None yet.</p>
                ) : (
                    <ul className="mt-2 divide-y divide-line">
                        {billing.invoices.map((i) => (
                            <li key={i.id} className="flex flex-wrap items-center gap-3 py-2 text-sm">
                                <span className="font-mono">{i.number}</span>
                                <span>{i.date}</span>
                                <span>{money(i.total)}</span>
                                <Badge tone={i.status === 'paid' || i.status === 'zero' ? 'success' : 'danger'}>{i.status.replace('_', ' ')}</Badge>
                                {i.failure && <span className="text-muted">{i.failure}</span>}
                                <span className="flex-1" />
                                {i.canPay && (
                                    <Button size="sm" onClick={() => void payInvoice(`${base}/invoices/${i.id}/pay`, stripeKey, setError)}>
                                        Pay now
                                    </Button>
                                )}
                                <a href={`${base}/invoices/${i.id}.pdf`} className="text-primary">
                                    PDF
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}

function Enquiries({ enquiries }: Props) {
    if (enquiries.length === 0) return <p className="text-muted">No enquiries yet. Each one is also emailed to you as it arrives.</p>;
    return (
        <ul className="space-y-3">
            {enquiries.map((e, i) => (
                <Card key={i} className="p-4">
                    <div className="font-semibold">
                        {e.name} <span className="text-sm font-normal text-muted">· {e.created_at}</span>
                        {e.city && <span className="text-sm font-normal text-muted"> · {e.city}</span>}
                    </div>
                    <p className="mt-1 whitespace-pre-line">{e.message}</p>
                    <p className="mt-2 text-sm">
                        <a href={`mailto:${e.email}`} className="text-primary">
                            {e.email}
                        </a>
                        {e.phone && <> · {e.phone}</>}
                    </p>
                </Card>
            ))}
        </ul>
    );
}
