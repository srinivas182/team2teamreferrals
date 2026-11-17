import { Link, useForm, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import { CityPicker, type CityOption } from '@/components/CityPicker';
import { Honeypot, useStartToken } from '@/components/partners/FormExtras';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Category = { slug: string; name: string; licensed: boolean; licenseLabel: string | null };

/** "Advertise with us": a short application. Nothing is charged until staff approve the listing. */
export default function PartnersAdvertise({
    categories,
    price,
    howItWorks,
    seo,
}: {
    categories: Category[];
    price: number;
    howItWorks: string;
    seo: Seo;
}) {
    const { status } = usePage<{ status?: string }>().props;
    const [cities, setCities] = useState<CityOption[]>([]);
    const form = useForm({
        category: categories[0]?.slug ?? '',
        business_name: '',
        contact_name: '',
        email: '',
        phone: '',
        website: '',
        description: '',
        license_number: '',
        license_state: '',
        offer_title: '',
        offer_details: '',
        offer_expires_on: '',
        cities: [] as number[],
        logo: null as File | null,
        consent: false,
        website_url: '',
        started: '',
    });
    useStartToken((t) => form.setData('started', t));
    const cat = categories.find((c) => c.slug === form.data.category);
    const e = form.errors as Record<string, string | undefined>;
    const setCityList = (list: CityOption[]) => {
        setCities(list);
        form.setData(
            'cities',
            list.map((c) => c.id),
        );
    };
    if (status === 'partner-applied') {
        return (
            <PublicLayout current="/partners">
                <SeoHead seo={seo} />
                <div className="mx-auto max-w-2xl px-4 py-16">
                    <Alert tone="success">{statusMessage(status)}</Alert>
                    <p className="mt-4">
                        <Link href="/partners" className="text-primary">
                            Back to local partners
                        </Link>
                    </p>
                </div>
            </PublicLayout>
        );
    }
    return (
        <PublicLayout current="/partners">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-2xl px-4 py-10">
                <h1 className="font-display text-4xl font-bold">Advertise to local home buyers and sellers</h1>
                <p className="mt-4 text-lg">
                    List your business on the city pages people use to find verified agents and pros. ${price} a month per city, with 10% off a
                    12-month plan. No per-lead fees, no contracts, and every enquiry is yours alone.
                </p>
                <ul className="mt-4 list-disc space-y-1 pl-5">
                    <li>We review every application by hand and check licenses with the regulator.</li>
                    <li>You pay nothing until you’re approved.</li>
                    <li>Everyone in a city takes the top spot in turn: fair placement, no bidding.</li>
                </ul>
                <details className="mt-3 text-sm text-muted">
                    <summary className="cursor-pointer">How the list works</summary>
                    <p className="mt-2">{howItWorks}</p>
                </details>
                <form
                    className="mt-8 space-y-4"
                    onSubmit={(ev) => {
                        ev.preventDefault();
                        form.post('/partners/advertise', { forceFormData: true, preserveScroll: true });
                    }}
                >
                    <Field label="What kind of business?" error={e.category}>
                        <Select value={form.data.category} onChange={(ev) => form.setData('category', ev.target.value)}>
                            {categories.map((c) => (
                                <option key={c.slug} value={c.slug}>
                                    {c.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Business name" error={e.business_name}>
                        <Input
                            value={form.data.business_name}
                            onChange={(ev) => form.setData('business_name', ev.target.value)}
                            autoComplete="organization"
                            required
                        />
                    </Field>
                    <Field
                        label="Describe your business"
                        hint="What you do, who you help and what makes you good at it. 40 to 2,000 characters."
                        error={e.description}
                    >
                        <Textarea rows={5} value={form.data.description} onChange={(ev) => form.setData('description', ev.target.value)} required />
                    </Field>
                    {(cat?.licenseLabel || cat?.licensed) && (
                        <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
                            <Field
                                label={`${cat.licenseLabel ?? 'License number'}${cat.licensed ? '' : ' (optional)'}`}
                                hint={
                                    cat.licensed
                                        ? 'Required: we check it before your listing goes live.'
                                        : 'Shown with a “checked” badge once we verify it.'
                                }
                                error={e.license_number}
                            >
                                <Input
                                    value={form.data.license_number}
                                    onChange={(ev) => form.setData('license_number', ev.target.value)}
                                    required={cat.licensed}
                                />
                            </Field>
                            <Field label="State (optional)" error={e.license_state}>
                                <Input
                                    maxLength={2}
                                    value={form.data.license_state}
                                    onChange={(ev) => form.setData('license_state', ev.target.value.toUpperCase())}
                                />
                            </Field>
                        </div>
                    )}
                    <div>
                        <Field label="Cities you serve" hint="Your listing appears on these city pages. Up to 25." error={e.cities}>
                            <CityPicker onPick={(c) => !cities.some((x) => x.id === c.id) && cities.length < 25 && setCityList([...cities, c])} />
                        </Field>
                        {cities.length > 0 && (
                            <ul className="mt-2 flex flex-wrap gap-2">
                                {cities.map((c) => (
                                    <li key={c.id} className="flex items-center gap-1 rounded-full border border-line px-3 py-1 text-sm">
                                        {c.label}
                                        <button
                                            type="button"
                                            aria-label={`Remove ${c.label}`}
                                            onClick={() => setCityList(cities.filter((x) => x.id !== c.id))}
                                        >
                                            <X className="size-3.5" aria-hidden />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Your name" error={e.contact_name}>
                            <Input
                                value={form.data.contact_name}
                                onChange={(ev) => form.setData('contact_name', ev.target.value)}
                                autoComplete="name"
                                required
                            />
                        </Field>
                        <Field label="Email" hint="Enquiries come here." error={e.email}>
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(ev) => form.setData('email', ev.target.value)}
                                autoComplete="email"
                                required
                            />
                        </Field>
                        <Field label="Phone (shown on your listing)" error={e.phone}>
                            <Input type="tel" value={form.data.phone} onChange={(ev) => form.setData('phone', ev.target.value)} autoComplete="tel" />
                        </Field>
                        <Field label="Website" error={e.website}>
                            <Input
                                type="url"
                                placeholder="https://"
                                value={form.data.website}
                                onChange={(ev) => form.setData('website', ev.target.value)}
                                autoComplete="url"
                            />
                        </Field>
                    </div>
                    <Field label="Logo (optional)" hint="JPEG, PNG or WebP, up to 2 MB." error={e.logo}>
                        <Input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={(ev) => form.setData('logo', ev.target.files?.[0] ?? null)}
                            className="py-2"
                        />
                    </Field>
                    <details className="rounded-lg border border-line p-4">
                        <summary className="cursor-pointer font-medium">Add an offer (optional)</summary>
                        <div className="mt-3 space-y-4">
                            <Field label="Offer" hint="For example: “$500 off closing costs”." error={e.offer_title}>
                                <Input value={form.data.offer_title} onChange={(ev) => form.setData('offer_title', ev.target.value)} />
                            </Field>
                            <Field label="Details and conditions" error={e.offer_details}>
                                <Textarea
                                    rows={2}
                                    value={form.data.offer_details}
                                    onChange={(ev) => form.setData('offer_details', ev.target.value)}
                                />
                            </Field>
                            <Field label="Ends on (optional)" error={e.offer_expires_on}>
                                <Input
                                    type="date"
                                    value={form.data.offer_expires_on}
                                    onChange={(ev) => form.setData('offer_expires_on', ev.target.value)}
                                />
                            </Field>
                        </div>
                    </details>
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={form.data.consent}
                            onChange={(ev) => form.setData('consent', ev.target.checked)}
                        />
                        <span>
                            I agree this is advertising only: I won’t pay or be paid for referrals through this site, and my listing is shown in
                            rotation with other advertisers.
                        </span>
                    </label>
                    {e.consent && <p className="text-sm text-danger">{e.consent}</p>}
                    <Honeypot value={form.data.website_url} onChange={(v) => form.setData('website_url', v)} />
                    <Button type="submit" loading={form.processing}>
                        Send application
                    </Button>
                </form>
            </article>
        </PublicLayout>
    );
}
