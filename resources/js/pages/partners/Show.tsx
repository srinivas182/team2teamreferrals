import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, Gift, Phone, ThumbsUp } from 'lucide-react';
import { useState } from 'react';
import { Honeypot, useStartToken } from '@/components/partners/FormExtras';
import { AdNote, LicenseBadge, type PartnerCardData } from '@/components/partners/PartnerCard';
import { track, useTrackViews } from '@/components/partners/track';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, Input, Textarea } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Props = {
    partner: PartnerCardData & { description: string; categorySlug: string; categoryName: string };
    cities: { id: number; name: string; href: string }[];
    endorsements: { team: string; slug: string; note: string | null; since: string }[];
    answer: string;
    howItWorks: string;
    member: boolean;
    endorsed: boolean;
    status?: string;
    seo: Seo;
};

/** One partner: what they do, where, their checked license, member endorsements and a message form that reaches only them. */
export default function PartnerShow({ partner, cities, endorsements, answer, howItWorks, member, endorsed, status, seo }: Props) {
    const { url } = usePage();
    const cityParam = Number(new URLSearchParams(url.split('?')[1] ?? '').get('city')) || null;
    const form = useForm({ name: '', email: '', phone: '', message: '', city: cityParam, consent: false, website_url: '', started: '' });
    useStartToken((t) => form.setData('started', t));
    const [note, setNote] = useState('');
    const e = form.errors as Record<string, string | undefined>;
    useTrackViews([partner.slug], cityParam);
    return (
        <PublicLayout current="/partners">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-3xl px-4 py-10">
                <p className="text-sm">
                    <Link href="/partners" className="text-primary">
                        Local partners
                    </Link>
                    {' · '}
                    {partner.categoryName}
                </p>
                <div className="mt-3 flex items-center gap-4">
                    {partner.logo && (
                        <img src={partner.logo} alt={`${partner.name} logo`} width={72} height={72} className="size-18 rounded-md object-contain" />
                    )}
                    <h1 className="font-display text-4xl font-bold">{partner.name}</h1>
                </div>
                <p className="mt-4 text-lg">{answer}</p>
                <AdNote note={howItWorks} />
                {status && status !== 'partner-enquiry-sent' && (
                    <Alert tone="success" className="mt-4">
                        {statusMessage(status)}
                    </Alert>
                )}
                <section aria-labelledby="about" className="mt-8">
                    <h2 id="about" className="font-display text-xl font-bold">
                        About {partner.name}
                    </h2>
                    <p className="mt-2 whitespace-pre-line">{partner.description}</p>
                    <ul className="mt-4 space-y-2">
                        {partner.license && (
                            <li>
                                <LicenseBadge license={partner.license} />
                            </li>
                        )}
                        {partner.phone && (
                            <li>
                                <a
                                    href={`tel:${partner.phone.replace(/[^0-9+]/g, '')}`}
                                    onClick={() => track('click', [partner.slug], cityParam)}
                                    className="flex items-center gap-1 text-primary"
                                >
                                    <Phone className="size-4" aria-hidden /> {partner.phone}
                                </a>
                            </li>
                        )}
                        {partner.website && (
                            <li>
                                <a
                                    href={partner.website}
                                    target="_blank"
                                    rel="sponsored noopener noreferrer"
                                    onClick={() => track('click', [partner.slug], cityParam)}
                                    className="flex items-center gap-1 text-primary"
                                >
                                    {partner.website.replace(/^https:\/\//, '')} <ExternalLink className="size-3" aria-hidden />
                                </a>
                            </li>
                        )}
                    </ul>
                </section>
                {partner.offer && (
                    <section aria-labelledby="offer" className="mt-6 rounded-lg border border-line bg-surface p-4">
                        <h2 id="offer" className="flex items-center gap-2 font-semibold">
                            <Gift className="size-5 text-accent" aria-hidden /> {partner.offer.title}
                        </h2>
                        {partner.offer.details && <p className="mt-1 text-sm">{partner.offer.details}</p>}
                        {partner.offer.expires && (
                            <p className="mt-1 text-xs text-muted">
                                Ends {partner.offer.expires}. Offered by {partner.name}, not by us.
                            </p>
                        )}
                    </section>
                )}
                {cities.length > 0 && (
                    <section aria-labelledby="areas" className="mt-8">
                        <h2 id="areas" className="font-display text-xl font-bold">
                            Areas served
                        </h2>
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                            {cities.map((c) => (
                                <li key={c.id}>
                                    <Link href={c.href} className="text-primary">
                                        {c.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <section aria-labelledby="endorsements" className="mt-8">
                    <h2 id="endorsements" className="font-display text-xl font-bold">
                        Endorsed by network members
                    </h2>
                    <p className="mt-1 text-sm text-muted">
                        Members vouch for partners they have worked with. They are not paid for it, and it doesn’t change the order.
                    </p>
                    {endorsements.length === 0 ? (
                        <p className="mt-3">No endorsements yet.</p>
                    ) : (
                        <ul className="mt-3 space-y-2">
                            {endorsements.map((x) => (
                                <li key={x.slug} className="flex gap-2">
                                    <ThumbsUp className="mt-1 size-4 shrink-0 text-muted" aria-hidden />
                                    <span>
                                        <Link href={`/t/${x.slug}`} className="text-primary">
                                            {x.team}
                                        </Link>
                                        {x.note && <span className="block text-sm">“{x.note}”</span>}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {member &&
                        (endorsed ? (
                            <Button
                                variant="secondary"
                                className="mt-3"
                                onClick={() => router.delete(`/partner/${partner.slug}/endorse`, { preserveScroll: true })}
                            >
                                Withdraw my endorsement
                            </Button>
                        ) : (
                            <div className="mt-3 space-y-2">
                                <Field label="Endorse as your team (optional note)" hint="Up to 280 characters. Shown with your team name.">
                                    <Input maxLength={280} value={note} onChange={(ev) => setNote(ev.target.value)} />
                                </Field>
                                <Button
                                    variant="secondary"
                                    onClick={() => router.post(`/partner/${partner.slug}/endorse`, { note }, { preserveScroll: true })}
                                >
                                    Endorse {partner.name}
                                </Button>
                            </div>
                        ))}
                </section>
                <section id="enquire" aria-labelledby="enquire-title" className="mt-10 scroll-mt-20 rounded-lg border border-line bg-surface p-5">
                    <h2 id="enquire-title" className="font-display text-xl font-bold">
                        Send {partner.name} a message
                    </h2>
                    <p className="mt-1 text-sm text-muted">Your message goes to {partner.name} only. We never sell or share your details.</p>
                    {status === 'partner-enquiry-sent' ? (
                        <Alert tone="success" className="mt-4">
                            {statusMessage(status)}
                        </Alert>
                    ) : (
                        <form
                            className="mt-4 space-y-4"
                            onSubmit={(ev) => {
                                ev.preventDefault();
                                form.post(`/partner/${partner.slug}/enquire`, { preserveScroll: true });
                            }}
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Your name" error={e.name}>
                                    <Input
                                        value={form.data.name}
                                        onChange={(ev) => form.setData('name', ev.target.value)}
                                        autoComplete="name"
                                        required
                                    />
                                </Field>
                                <Field label="Email" error={e.email}>
                                    <Input
                                        type="email"
                                        value={form.data.email}
                                        onChange={(ev) => form.setData('email', ev.target.value)}
                                        autoComplete="email"
                                        required
                                    />
                                </Field>
                                <Field label="Phone (optional)" error={e.phone}>
                                    <Input
                                        type="tel"
                                        value={form.data.phone}
                                        onChange={(ev) => form.setData('phone', ev.target.value)}
                                        autoComplete="tel"
                                    />
                                </Field>
                            </div>
                            <Field label="Message" error={e.message}>
                                <Textarea rows={4} value={form.data.message} onChange={(ev) => form.setData('message', ev.target.value)} required />
                            </Field>
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={form.data.consent}
                                    onChange={(ev) => form.setData('consent', ev.target.checked)}
                                />
                                <span>Send my name, email, phone and message to {partner.name} so they can reply.</span>
                            </label>
                            {e.consent && <p className="text-sm text-danger">{e.consent}</p>}
                            <Honeypot value={form.data.website_url} onChange={(v) => form.setData('website_url', v)} />
                            <Button type="submit" loading={form.processing}>
                                Send message
                            </Button>
                        </form>
                    )}
                </section>
            </article>
        </PublicLayout>
    );
}
