import { router, useForm } from '@inertiajs/react';
import { BadgeCheck, Globe, Phone, Star } from 'lucide-react';
import { Credentials, Ratings, RecordFacts, type Credential, type Reviews } from '@/components/directory/ProfileRecord';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    team: {
        name: string;
        slug: string;
        specialties: string[];
        languages: string[];
        bio: string | null;
        website: string | null;
        phone: string | null;
        serviceArea: string | null;
        foundedYear: number | null;
        logo: string | null;
    };
    cities: string[];
    groups: string[];
    licenses: string[];
    serviceScore: number | null;
    record: {
        closed: number;
        rating: number | null;
        ratings: number;
        thanks: { from: string; note: string }[];
        topGiverIn: string[];
        builder: boolean;
        badges: { key: string; name: string; group: string | null }[];
        trust?: { score: number | null; status: string } | null;
    };
    response: { hours: number | null; count: number };
    lastYear: { received: number; closed: number };
    reviews: Reviews;
    credentials: Credential[];
    viewer: 'guest' | 'member' | 'self';
    sendUrl: string | null;
    started: string;
    status?: string;
    seo: Seo;
};

const done: Record<string, string> = {
    'profile-lead-check-email': 'Almost there: check your email and confirm, and we’ll pass your referral on.',
    'profile-lead-confirmed': 'Thank you! Your referral has been passed on. Want referrals sent your way too? Join Team to Team Referrals.',
    'profile-lead-duplicate': 'This client was already referred to this team recently, so we didn’t send it twice.',
};

/** A team's public "refer to me" profile: who they are, their record, and a way to refer a client. */
export default function TeamPublic({
    team,
    cities,
    groups,
    licenses,
    serviceScore,
    record,
    viewer,
    sendUrl,
    started,
    status,
    seo,
    response,
    lastYear,
    reviews,
    credentials,
}: Props) {
    const form = useForm({
        referrer_name: '',
        referrer_email: '',
        referrer_business: '',
        client_name: '',
        client_phone: '',
        client_email: '',
        need: '',
        consent: false,
        website_url: '',
        started,
    });
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-3xl px-5 py-12">
                <div className="flex items-center gap-4">
                    {team.logo && <img src={team.logo} alt="" className="size-16 rounded-xl object-cover" />}
                    <div>
                        <h1 className="font-display text-3xl font-extrabold">{team.name}</h1>
                        <p className="text-muted">
                            {groups.join(' · ')} · {cities.join(', ')}
                        </p>
                    </div>
                </div>
                <div className="mt-4 flex flex-wrap gap-2">
                    {licenses.map((l) => (
                        <Badge key={l} tone="success">
                            <BadgeCheck className="size-3" aria-hidden /> {l} license verified
                        </Badge>
                    ))}
                    {record.trust && <Badge tone="info">{record.trust.status === 'new' ? 'New member' : `Trust score ${record.trust.score}`}</Badge>}
                    {record.badges.map((b) => (
                        <Badge key={b.key + (b.group ?? '')} tone="sender">
                            {b.name}
                        </Badge>
                    ))}
                    {serviceScore !== null && <Badge tone="info">Service score {serviceScore}</Badge>}
                    {record.rating !== null && (
                        <Badge tone="neutral">
                            <Star className="size-3" aria-hidden /> {record.rating} from {record.ratings} ratings
                        </Badge>
                    )}
                </div>
                {team.bio && <p className="mt-5">{team.bio}</p>}
                {team.specialties.length > 0 && <p className="mt-3 text-sm">Specialties: {team.specialties.join(', ')}</p>}
                {team.languages.length > 0 && <p className="mt-1 text-sm">Languages: {team.languages.join(', ')}</p>}
                <p className="mt-3 text-sm text-muted">
                    {record.closed} referrals closed{team.serviceArea ? ` · serving ${team.serviceArea}` : ''}
                    {team.foundedYear ? ` · since ${team.foundedYear}` : ''}
                </p>
                <div className="mt-4 flex flex-wrap gap-2">
                    {team.phone && (
                        <a
                            href={`tel:${team.phone}`}
                            className="inline-flex h-10 items-center gap-2 rounded-md border border-line px-4 text-sm font-semibold"
                        >
                            <Phone className="size-4" aria-hidden /> {team.phone}
                        </a>
                    )}
                    {team.website && (
                        <a
                            href={team.website}
                            rel="noopener noreferrer"
                            target="_blank"
                            className="inline-flex h-10 items-center gap-2 rounded-md border border-line px-4 text-sm font-semibold"
                        >
                            <Globe className="size-4" aria-hidden /> Website
                        </a>
                    )}
                </div>
                <RecordFacts response={response} lastYear={lastYear} foundedYear={team.foundedYear} />
                <Credentials items={credentials} />
                <Ratings reviews={reviews} />
                {record.thanks.map((t) => (
                    <blockquote key={t.note} className="mt-4 rounded-md bg-bg p-4 text-sm">
                        “{t.note}” <span className="text-muted">· {t.from}</span>
                    </blockquote>
                ))}

                <Card id="refer" className="mt-8 scroll-mt-20 p-6">
                    <h2 className="font-display text-xl font-bold">Refer a client to {team.name}</h2>
                    {status && done[status] && (
                        <Alert tone="success" className="mt-3">
                            {done[status]}
                        </Alert>
                    )}
                    {viewer === 'member' && sendUrl && (
                        <div className="mt-3">
                            <p className="text-sm text-muted">You’re a member: send it through the network so it’s tracked, agreed and credited.</p>
                            <Button className="mt-3" onClick={() => router.visit(sendUrl)}>
                                Send a referral
                            </Button>
                        </div>
                    )}
                    {viewer === 'self' && <p className="mt-3 text-sm text-muted">This is how others see your profile.</p>}
                    {viewer === 'guest' && status !== 'profile-lead-check-email' && (
                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            <Field label="Your name" error={form.errors.referrer_name}>
                                <Input value={form.data.referrer_name} onChange={(e) => form.setData('referrer_name', e.target.value)} />
                            </Field>
                            <Field label="Your email" error={form.errors.referrer_email}>
                                <Input
                                    type="email"
                                    value={form.data.referrer_email}
                                    onChange={(e) => form.setData('referrer_email', e.target.value)}
                                />
                            </Field>
                            <Field label="Your business (optional)">
                                <Input value={form.data.referrer_business} onChange={(e) => form.setData('referrer_business', e.target.value)} />
                            </Field>
                            <Field label="Client’s name" error={form.errors.client_name}>
                                <Input value={form.data.client_name} onChange={(e) => form.setData('client_name', e.target.value)} />
                            </Field>
                            <Field label="Client’s phone" error={form.errors.client_phone}>
                                <Input type="tel" value={form.data.client_phone} onChange={(e) => form.setData('client_phone', e.target.value)} />
                            </Field>
                            <Field label="or client’s email" error={form.errors.client_email}>
                                <Input type="email" value={form.data.client_email} onChange={(e) => form.setData('client_email', e.target.value)} />
                            </Field>
                            <div className="sm:col-span-2">
                                <Field label="What do they need?" error={form.errors.need}>
                                    <Input value={form.data.need} onChange={(e) => form.setData('need', e.target.value)} />
                                </Field>
                            </div>
                            {/* Hidden from people; bots fill it in. */}
                            <input
                                type="text"
                                name="website_url"
                                tabIndex={-1}
                                autoComplete="off"
                                className="hidden"
                                aria-hidden
                                value={form.data.website_url}
                                onChange={(e) => form.setData('website_url', e.target.value)}
                            />
                            <label className="flex items-start gap-2 text-sm sm:col-span-2">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={form.data.consent}
                                    onChange={(e) => form.setData('consent', e.target.checked)}
                                />{' '}
                                The client agreed to be contacted by {team.name}.
                            </label>
                            {form.errors.consent && <p className="text-sm text-danger sm:col-span-2">{form.errors.consent}</p>}
                            <Button
                                className="sm:col-span-2"
                                loading={form.processing}
                                onClick={() => form.post(`/t/${team.slug}/refer`, { preserveScroll: true })}
                            >
                                Send referral
                            </Button>
                            <p className="text-xs text-muted sm:col-span-2">We’ll email you a link to confirm before anything is passed on.</p>
                        </div>
                    )}
                </Card>
            </section>
        </PublicLayout>
    );
}
