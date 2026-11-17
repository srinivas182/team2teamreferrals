import { useForm, usePage } from '@inertiajs/react';
import { Share2 } from 'lucide-react';
import { useState } from 'react';
import { AdNote, PartnerCard, type PartnerCardData } from '@/components/partners/PartnerCard';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Textarea } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type CityBlock = { id: number; name: string; groups: { category: string; partners: PartnerCardData[] }[] };

/** Send a partner's page to a client, from you. Their address is used for this one email and not kept. */
function ShareForm({ slug, name, onDone }: { slug: string; name: string; onDone: () => void }) {
    const f = useForm({ name: '', email: '', note: '' });
    return (
        <form
            className="mt-3 space-y-3 rounded-lg border border-line p-4"
            onSubmit={(e) => {
                e.preventDefault();
                f.post(`/app/partners/${slug}/share`, { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <p className="text-sm font-medium">Share {name} with a client</p>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Client’s first name" error={f.errors.name}>
                    <Input value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} required />
                </Field>
                <Field label="Client’s email" error={f.errors.email}>
                    <Input type="email" value={f.data.email} onChange={(e) => f.setData('email', e.target.value)} required />
                </Field>
            </div>
            <Field label="Note (optional)" error={f.errors.note}>
                <Textarea rows={2} maxLength={500} value={f.data.note} onChange={(e) => f.setData('note', e.target.value)} />
            </Field>
            <p className="text-xs text-muted">
                Sent from the platform with your name; replies come to you. You and the platform receive nothing for it.
            </p>
            <Button type="submit" size="sm" loading={f.processing}>
                Send
            </Button>
        </form>
    );
}

/** Portal → Local partners: advertisers in your cities, to pass on to clients who need them. */
export default function MemberPartners({ cities, note, status, seo }: { cities: CityBlock[]; note: string; status?: string; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    const [open, setOpen] = useState<string | null>(null);
    return (
        <PortalLayout title="Local partners" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-3xl space-y-6">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <p>Mortgage lenders, title companies, inspectors and other businesses advertising in the cities you serve.</p>
                <AdNote note={note} />
                {cities.length === 0 && <Card className="p-8 text-center text-muted">No partners advertise in your cities yet.</Card>}
                {cities.map((c) => (
                    <section key={c.id} aria-labelledby={`city-${c.id}`}>
                        <h2 id={`city-${c.id}`} className="font-display text-xl font-bold">
                            {c.name}
                        </h2>
                        {c.groups.length === 0 && <p className="mt-2 text-muted">No partners here yet.</p>}
                        {c.groups.map((g) => (
                            <div key={g.category} className="mt-4">
                                <h3 className="font-semibold">{g.category}</h3>
                                <div className="mt-2 space-y-3">
                                    {g.partners.map((p) => (
                                        <div key={p.slug}>
                                            <PartnerCard partner={p} cityId={c.id} />
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                className="mt-2"
                                                onClick={() => setOpen(open === `${c.id}:${p.slug}` ? null : `${c.id}:${p.slug}`)}
                                            >
                                                <Share2 /> Share with my client
                                            </Button>
                                            {open === `${c.id}:${p.slug}` && <ShareForm slug={p.slug} name={p.name} onDone={() => setOpen(null)} />}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </section>
                ))}
            </div>
        </PortalLayout>
    );
}
