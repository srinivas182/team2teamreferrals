import { Link, useForm } from '@inertiajs/react';
import { BadgeCheck, ExternalLink, ShieldAlert } from 'lucide-react';
import { article } from '@/components/directory/TeamCard';
import { EmbedMapCode } from '@/components/map/EmbedMapCode';
import { NetworkMap } from '@/components/map/NetworkMap';
import type { MapData } from '@/components/map/types';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, Input, Textarea } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Row = { name: string; url: string };
type Content = {
    summary: string;
    intro: string;
    how: string;
    request: string;
    facts: { covers: string; cost: string; licensing: string; fees: string; tier: string };
    cost_note: string;
    lookups: Row[];
    hire_questions: string[];
    faqs: { q: string; a: string }[];
    glossary: { term: string; definition: string }[];
    sources: { title: string; url: string }[];
    checked: string;
};
type Props = {
    industry: { slug: string; name: string; tier: string; consumerRequests: boolean; terms: Record<string, string> };
    content: Content;
    disclosure: string | null;
    related: { slug: string; name: string }[];
    states: { code: string; name: string }[];
    map: MapData;
    reviewer: string | null;
    reviewedOn: string | null;
    updatedOn: string | null;
    preview: boolean;
    formStarted: string;
    status?: string;
    seo: Seo;
};

const paragraphs = (text: string) =>
    text
        .split(/\n{2,}/)
        .filter(Boolean)
        .map((p, i) => (
            <p key={i} className="mt-3">
                {p}
            </p>
        ));

/** An industry hub: the answer first, the facts sheet, how referrals work, what to ask, FAQs, glossary and sources. */
export default function IndustryHub({
    industry,
    content: c,
    disclosure,
    related,
    states,
    map,
    reviewer,
    reviewedOn,
    preview,
    formStarted,
    status,
    seo,
}: Props) {
    const form = useForm({ name: '', email: '', message: '', website_url: '', started: formStarted });
    const facts: [string, string][] = [
        ['What it covers', c.facts.covers],
        ['Typical cost', c.facts.cost],
        ['Licensing', c.facts.licensing],
        ['Referrals and fees', c.facts.fees],
        ['On this network', c.facts.tier],
    ];
    return (
        <PublicLayout current="/industries">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-3xl px-4 py-10">
                {preview && <Alert tone="info">Preview: this hub is not public yet.</Alert>}
                <p className="text-sm">
                    <Link href="/industries" className="text-primary">
                        Industries
                    </Link>
                </p>
                <h1 className="mt-2 font-display text-4xl font-bold">{industry.name}</h1>
                <p className="mt-4 text-lg">{c.summary}</p>
                <p className="mt-3 text-sm text-muted">
                    {reviewer ? <>Reviewed by {reviewer}</> : <>Review pending</>}
                    {reviewedOn && (
                        <>
                            {' '}
                            · Last reviewed <time dateTime={reviewedOn}>{reviewedOn}</time>
                        </>
                    )}{' '}
                    ·{' '}
                    <Link href="/editorial-standards" className="text-primary">
                        How we review content
                    </Link>
                </p>
                {disclosure && (
                    <aside role="note" className="mt-5 flex gap-3 rounded-lg border border-line bg-surface p-4 text-sm">
                        <ShieldAlert className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
                        <p>{disclosure}</p>
                    </aside>
                )}

                <section aria-labelledby="facts" className="mt-8">
                    <h2 id="facts" className="font-display text-2xl font-bold">
                        Facts at a glance
                    </h2>
                    <dl className="mt-3 divide-y divide-line rounded-lg border border-line">
                        {facts
                            .filter(([, v]) => v)
                            .map(([k, v]) => (
                                <div key={k} className="grid gap-1 p-3 sm:grid-cols-[11rem_1fr]">
                                    <dt className="font-semibold">{k}</dt>
                                    <dd>
                                        {v}
                                        {k === 'Typical cost' && c.cost_note && <span className="mt-1 block text-sm text-muted">{c.cost_note}</span>}
                                    </dd>
                                </div>
                            ))}
                    </dl>
                    {!c.facts.cost && c.cost_note && <p className="mt-2 text-sm text-muted">{c.cost_note}</p>}
                    <p className="mt-2 text-xs text-muted">
                        <Link href="/cost-data" className="text-primary">
                            How we get our cost data
                        </Link>
                    </p>
                </section>

                <section aria-labelledby="about" className="mt-8">
                    <h2 id="about" className="font-display text-2xl font-bold">
                        About {industry.name.toLowerCase()} on our network
                    </h2>
                    {paragraphs(c.intro)}
                    <h3 className="mt-5 text-lg font-semibold">How it works</h3>
                    {paragraphs(c.how)}
                </section>

                {c.lookups.length > 0 && (
                    <section aria-labelledby="license" className="mt-8">
                        <h2 id="license" className="font-display text-2xl font-bold">
                            How to check a license
                        </h2>
                        <ul className="mt-3 space-y-2">
                            {c.lookups.map((l) => (
                                <li key={l.url} className="flex items-center gap-2">
                                    <BadgeCheck className="size-4 text-success" aria-hidden />
                                    <a href={l.url} rel="noopener noreferrer" target="_blank" className="text-primary">
                                        {l.name} <ExternalLink className="inline size-3" aria-hidden />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {states.length > 0 && (
                    <section aria-labelledby="by-state" className="mt-8">
                        <h2 id="by-state" className="font-display text-2xl font-bold">
                            Find a verified {industry.terms.pro} by state
                        </h2>
                        <ul className="mt-3 flex flex-wrap gap-2">
                            {states.map((s) => (
                                <li key={s.code}>
                                    <Link
                                        href={`/industries/${industry.slug}/${s.code}`}
                                        className="inline-flex rounded-full border border-line px-3 py-1 text-sm"
                                    >
                                        {s.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {map.points.length > 0 && (
                    <section aria-labelledby="network-map" className="mt-8">
                        <h2 id="network-map" className="font-display text-2xl font-bold">
                            Where the network is
                        </h2>
                        <p className="mt-1 text-muted">
                            {map.counts.held} {map.counts.held === 1 ? 'place has' : 'places have'} a verified {industry.terms.pro}; open places can
                            be claimed by one team each.
                        </p>
                        <NetworkMap initial={map} src={`/map/industry/${industry.slug}.json`} />
                        <EmbedMapCode path={`industry/${industry.slug}`} title={industry.name} />
                    </section>
                )}

                <section aria-labelledby="ask" className="mt-8">
                    <h2 id="ask" className="font-display text-2xl font-bold">
                        Questions to ask before you hire
                    </h2>
                    <ol className="mt-3 list-decimal space-y-1 pl-6">
                        {c.hire_questions.map((q) => (
                            <li key={q}>{q}</li>
                        ))}
                    </ol>
                </section>

                <section className="mt-8 rounded-lg bg-surface p-5">
                    <p className="font-semibold">{c.request}</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button asChild>
                            <Link href="/contact">{industry.consumerRequests ? 'Request a pro' : 'Ask us a question'}</Link>
                        </Button>
                        <Button asChild variant="secondary">
                            <Link href="/reserve">
                                Are you {article(industry.terms.pro)} {industry.terms.pro}? Claim your city
                            </Link>
                        </Button>
                    </div>
                </section>

                <section aria-labelledby="faq" className="mt-8">
                    <h2 id="faq" className="font-display text-2xl font-bold">
                        Questions and answers
                    </h2>
                    {c.faqs.map((f) => (
                        <details key={f.q} className="mt-3 rounded-lg border border-line p-3" open>
                            <summary className="cursor-pointer font-semibold">{f.q}</summary>
                            <p className="mt-2">{f.a}</p>
                        </details>
                    ))}
                </section>

                {c.glossary.length > 0 && (
                    <section aria-labelledby="glossary" id="glossary-terms" className="mt-8">
                        <h2 id="glossary" className="font-display text-2xl font-bold">
                            Glossary
                        </h2>
                        <dl className="mt-3 space-y-2">
                            {c.glossary.map((g) => (
                                <div key={g.term}>
                                    <dt className="font-semibold">{g.term}</dt>
                                    <dd className="text-muted">{g.definition}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                )}

                {related.length > 0 && (
                    <section aria-labelledby="related" className="mt-8">
                        <h2 id="related" className="font-display text-2xl font-bold">
                            Related industries
                        </h2>
                        <ul className="mt-3 flex flex-wrap gap-2">
                            {related.map((r) => (
                                <li key={r.slug}>
                                    <Link href={`/industries/${r.slug}`} className="inline-flex rounded-full border border-line px-3 py-1 text-sm">
                                        {r.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {c.sources.length > 0 && (
                    <section aria-labelledby="sources" className="mt-8 text-sm">
                        <h2 id="sources" className="font-display text-xl font-bold">
                            Sources
                        </h2>
                        <ul className="mt-2 list-disc space-y-1 pl-6">
                            {c.sources.map((s) => (
                                <li key={s.url}>
                                    <a href={s.url} rel="noopener noreferrer" target="_blank" className="text-primary">
                                        {s.title}
                                    </a>
                                </li>
                            ))}
                        </ul>
                        {c.checked && <p className="mt-2 text-muted">Facts checked {c.checked}.</p>}
                    </section>
                )}

                <section aria-labelledby="correction" className="mt-10 border-t border-line pt-6">
                    <h2 id="correction" className="text-lg font-semibold">
                        Suggest a correction
                    </h2>
                    {status === 'correction-sent' ? (
                        <Alert tone="success">{statusMessage(status)}</Alert>
                    ) : (
                        <form
                            className="mt-3 grid gap-3 sm:grid-cols-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(`/industries/${industry.slug}/correction`, { preserveScroll: true });
                            }}
                        >
                            <Field label="Your name" error={form.errors.name}>
                                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            </Field>
                            <Field label="Email" error={form.errors.email}>
                                <Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />
                            </Field>
                            <Field label="What should change? A source helps." error={form.errors.message} className="sm:col-span-2">
                                <Textarea rows={4} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} required />
                            </Field>
                            <input
                                type="text"
                                name="website_url"
                                tabIndex={-1}
                                autoComplete="off"
                                aria-hidden
                                className="hidden"
                                value={form.data.website_url}
                                onChange={(e) => form.setData('website_url', e.target.value)}
                            />
                            <div>
                                <Button type="submit" variant="secondary" loading={form.processing}>
                                    Send
                                </Button>
                            </div>
                        </form>
                    )}
                </section>
            </article>
        </PublicLayout>
    );
}
