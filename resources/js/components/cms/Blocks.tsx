import { Check, Quote } from 'lucide-react';
import type { ReactNode } from 'react';
import { CtaBand, Faq, FeatureCard, iconFor } from '@/components/site/Site';
import { cn } from '@/lib/utils';

/** Website blocks as shown on public pages. Rich text is HTML already cleaned on the server. */
export type Block = { type: string } & Record<string, unknown>;
type Item = Record<string, string>;
const items = (b: Block, k = 'items') => (Array.isArray(b[k]) ? (b[k] as Item[]) : []);
const s = (v: unknown) => (typeof v === 'string' ? v : '');

/** Every block sits in the same width and spacing; headings are the same size everywhere. */
function Wrap({ heading, intro, children, className }: { heading?: string; intro?: string; children: ReactNode; className?: string }) {
    return (
        <section className={cn('py-10', className)}>
            {heading && <h2 className="font-display text-3xl font-bold text-balance">{heading}</h2>}
            {intro && <p className="mt-2 max-w-2xl text-muted">{intro}</p>}
            <div className={heading || intro ? 'mt-8' : undefined}>{children}</div>
        </section>
    );
}

export function CmsBlock({ b }: { b: Block }) {
    switch (b.type) {
        case 'hero':
            return (
                <section className="my-10 overflow-hidden rounded-3xl border border-line bg-gradient-to-br from-cobalt-50 to-surface px-6 py-12 text-center sm:px-12 dark:from-navy-900">
                    <h2 className="font-display text-3xl font-bold text-balance sm:text-4xl">{s(b.heading)}</h2>
                    {s(b.subheading) && <p className="mx-auto mt-3 max-w-2xl text-lg text-muted">{s(b.subheading)}</p>}
                    {s(b.cta_label) && s(b.cta_url) && (
                        <a
                            href={s(b.cta_url)}
                            className="mt-6 inline-flex h-11 items-center rounded-md bg-primary px-6 font-semibold text-on-primary"
                        >
                            {s(b.cta_label)}
                        </a>
                    )}
                    {s(b.image) && (
                        <img src={s(b.image)} alt={s(b.image_alt)} className="mx-auto mt-8 max-w-full rounded-2xl shadow-sm" loading="lazy" />
                    )}
                </section>
            );
        case 'features':
        case 'steps': {
            const list = items(b);
            const Tag = b.type === 'steps' ? 'ol' : 'ul';
            return (
                <Wrap heading={s(b.heading)} intro={s(b.intro)}>
                    <Tag className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {list.map((i, n) => (
                            <li key={n}>
                                <FeatureCard icon={iconFor(s(i.title), s(i.text))} title={i.title} step={b.type === 'steps' ? n + 1 : undefined}>
                                    {i.text}
                                </FeatureCard>
                            </li>
                        ))}
                    </Tag>
                </Wrap>
            );
        }
        case 'stats':
            return (
                <Wrap heading={s(b.heading)}>
                    <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        {items(b).map((i, n) => (
                            <div key={n} className="flex flex-col-reverse rounded-2xl border border-line bg-surface p-5">
                                <dt className="text-sm text-muted">{i.label}</dt>
                                <dd className="font-display text-3xl font-bold tabular-nums">{i.value}</dd>
                            </div>
                        ))}
                    </dl>
                </Wrap>
            );
        case 'testimonials':
            return (
                <Wrap heading={s(b.heading)}>
                    <div className="grid gap-6 sm:grid-cols-2">
                        {items(b).map((i, n) => (
                            <figure key={n} className="rounded-2xl border border-line bg-surface p-6 shadow-sm">
                                <Quote className="size-6 text-primary" aria-hidden />
                                <blockquote className="mt-3 text-lg">“{i.quote}”</blockquote>
                                <figcaption className="mt-4 text-sm">
                                    <span className="font-semibold">{i.author}</span>
                                    {i.business && <span className="text-muted">, {i.business}</span>}
                                </figcaption>
                            </figure>
                        ))}
                    </div>
                </Wrap>
            );
        case 'faq':
            return (
                <Wrap heading={s(b.heading) || 'Questions and answers'}>
                    <Faq items={items(b).map((i) => ({ q: i.q, a: i.a }))} />
                </Wrap>
            );
        case 'pricing': {
            const plans = Array.isArray(b.plans) ? (b.plans as (Item & { features?: string[]; highlight?: boolean })[]) : [];
            // Only a plan marked in Admin gets "Most popular"; a lone plan is outlined as the one on offer.
            const marked = plans.findIndex((p) => p.highlight);
            const main = marked >= 0 ? marked : plans.length === 1 ? 0 : -1;
            return (
                <Wrap heading={s(b.heading)} intro={s(b.intro)}>
                    <div className="grid items-stretch gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {plans.map((p, n) => (
                            <div
                                key={n}
                                className={cn(
                                    'relative flex flex-col rounded-2xl border bg-surface p-6 shadow-sm',
                                    n === main ? 'border-primary ring-1 ring-primary' : 'border-line',
                                )}
                            >
                                {n === marked && plans.length > 1 && (
                                    <span className="absolute -top-3 left-6 rounded-full bg-primary px-3 py-0.5 text-xs font-semibold text-on-primary">
                                        Most popular
                                    </span>
                                )}
                                <h3 className="text-lg font-semibold">{p.name}</h3>
                                <p className="mt-3 font-display text-4xl font-bold">
                                    {p.price}
                                    {p.period && <span className="text-base font-normal text-muted"> / {p.period}</span>}
                                </p>
                                <ul className="mt-5 space-y-2 text-sm">
                                    {(p.features ?? []).map((f) => (
                                        <li key={f} className="flex gap-2">
                                            <Check className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
                                            {f}
                                        </li>
                                    ))}
                                </ul>
                                {p.cta_label && p.cta_url && (
                                    <div className="mt-auto pt-6">
                                        <a
                                            href={p.cta_url}
                                            className={cn(
                                                'inline-flex h-11 w-full items-center justify-center rounded-md px-4 font-semibold',
                                                n === main ? 'bg-primary text-on-primary' : 'border border-line hover:border-primary',
                                            )}
                                        >
                                            {p.cta_label}
                                        </a>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </Wrap>
            );
        }
        case 'cta':
            return (
                <section className="py-10">
                    <CtaBand
                        title={s(b.heading)}
                        text={s(b.text) || undefined}
                        action={s(b.label) || 'Get started'}
                        href={s(b.url) || '/register'}
                        external
                    />
                </section>
            );
        case 'rich_text':
            return <section className="prose prose-lg max-w-3xl py-6" dangerouslySetInnerHTML={{ __html: s(b.html) }} />;
        case 'media':
            return (
                <figure className="py-8">
                    {s(b.video) ? (
                        <div className="aspect-video">
                            <iframe
                                src={s(b.video)}
                                title={s(b.caption) || 'Video'}
                                className="size-full rounded-2xl"
                                allow="encrypted-media; picture-in-picture"
                                allowFullScreen
                                loading="lazy"
                            />
                        </div>
                    ) : (
                        <img src={s(b.image)} alt={s(b.alt)} className="max-w-full rounded-2xl shadow-sm" loading="lazy" />
                    )}
                    {s(b.caption) && <figcaption className="mt-2 text-sm text-muted">{s(b.caption)}</figcaption>}
                </figure>
            );
        default:
            return null;
    }
}
