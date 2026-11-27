import { useForm, usePage } from '@inertiajs/react';
import { Check, MapPin } from 'lucide-react';
import { useState } from 'react';
import { CardForm } from '@/components/billing/CardForm';
import { CityPicker, type CityOption } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { money } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    group: {
        slug: string;
        name: string;
        visibility: string;
        industry: string;
        territory: string;
        licenseRequired: boolean;
        terms: Record<string, string>;
        pricing: { seat_price: number; trial_months: number; value_guarantee: boolean };
    };
    city?: { id: number; name: string } | null;
    openCities?: string | null;
    billing: {
        stripeKey: string | null;
        card: { brand: string; last4: string; payer: string } | null;
        trialEnds: string;
        firstCharge: string;
        extension: boolean;
    };
    seo: Seo;
};

const unitWord: Record<string, string> = { city: 'city', county: 'county', metro: 'metro area', state: 'state' };

export default function Apply({ group, city: claimed, openCities, billing, seo }: Props) {
    const [changingCard, setChangingCard] = useState(false);
    const needsCard = billing.stripeKey !== null && billing.card === null;
    const { auth } = usePage<SharedProps>().props;
    const [city, setCity] = useState<CityOption | null>(
        claimed ? { id: claimed.id, name: claimed.name, state: '', county: null, population: 0, label: claimed.name } : null,
    );
    const form = useForm({ city_id: claimed?.id ?? 0, license_number: '' });
    const errors = form.errors as Record<string, string>;
    return (
        <PortalLayout title={`Apply to ${group.name}`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="grid max-w-5xl gap-6 lg:grid-cols-[1fr_320px]">
                <Card className="space-y-5 p-6">
                    {group.visibility === 'invite_only' && <Alert tone="info">This group is invite only. Ask its owner for an invitation.</Alert>}
                    {openCities && <Alert tone="info">{openCities}</Alert>}
                    <Field
                        label="Your city"
                        hint={
                            group.territory === 'city'
                                ? 'Each city has one seat in this network.'
                                : `Seats here cover a whole ${unitWord[group.territory]}; we’ll find yours from your city.`
                        }
                        error={errors.city ?? errors.city_id}
                    >
                        <CityPicker
                            onPick={(c) => {
                                setCity(c);
                                form.setData('city_id', c.id);
                            }}
                        />
                    </Field>
                    {city && (
                        <p className="flex items-center gap-2 text-sm text-muted">
                            <MapPin className="size-4" aria-hidden /> {city.name}
                        </p>
                    )}
                    {group.licenseRequired && (
                        <Field
                            label="License number"
                            hint="For the state your seat is in. We check the format now and verify it before you go live."
                            error={errors.license_number}
                        >
                            <Input value={form.data.license_number} onChange={(e) => form.setData('license_number', e.target.value)} />
                        </Field>
                    )}
                    {billing.stripeKey && (
                        <section aria-labelledby="pay" className="space-y-2 rounded-lg border border-line p-4">
                            <h2 id="pay" className="font-semibold">
                                Payment card
                            </h2>
                            <p className="text-sm text-muted">
                                Nothing is charged today. After your {group.pricing.trial_months}-month free trial it’s{' '}
                                {money(group.pricing.seat_price)} per {unitWord[group.territory]} per month, billed to this card. If you’re approved
                                today, your first charge is on {billing.firstCharge}. Cancel any time before then and you pay nothing.
                                {billing.extension && ' Any month you’re offered no referrals is free (a free extension).'}
                            </p>
                            {billing.card && !changingCard ? (
                                <p className="flex flex-wrap items-center gap-3 text-sm">
                                    <span>
                                        {billing.card.payer === 'company' ? 'Your company’s card' : 'Your card'}: {billing.card.brand.toUpperCase()}{' '}
                                        ending {billing.card.last4}
                                    </span>
                                    {billing.card.payer === 'team' && (
                                        <button type="button" className="font-medium text-primary" onClick={() => setChangingCard(true)}>
                                            Use a different card
                                        </button>
                                    )}
                                </p>
                            ) : (
                                <CardForm
                                    setupUrl="/app/billing/team/setup-intent"
                                    saveUrl="/app/billing/team/card"
                                    stripeKey={billing.stripeKey}
                                    keepPage
                                    label="Add a card"
                                    onDone={() => setChangingCard(false)}
                                />
                            )}
                            {errors.card && <p className="text-sm text-danger">{errors.card}</p>}
                        </section>
                    )}
                    {errors.group && <Alert tone="danger">{errors.group}</Alert>}
                    <Button
                        disabled={!city || group.visibility === 'invite_only' || needsCard}
                        loading={form.processing}
                        onClick={() => form.post(`/app/groups/${group.slug}/apply`)}
                    >
                        Apply for this {unitWord[group.territory]}
                    </Button>
                </Card>
                <Card className="h-fit p-6">
                    <h2 className="font-semibold">{group.name}</h2>
                    <p className="text-sm text-muted">{group.industry}</p>
                    <ul className="mt-4 space-y-2 text-sm">
                        {[
                            `${money(group.pricing.seat_price)} per ${unitWord[group.territory]} per month`,
                            `First ${group.pricing.trial_months} months free`,
                            billing.stripeKey ? 'Card saved now, charged only after the trial' : 'No card needed during the trial',
                            ...(billing.extension ? ['Free extension: any month with no referral offered is free'] : []),
                            'If the seat is taken, you can back it up or join the waitlist',
                        ].map((t) => (
                            <li key={t} className="flex gap-2">
                                <Check className="size-4 shrink-0 text-success" aria-hidden />
                                {t}
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>
        </PortalLayout>
    );
}
