/** Website blocks as shown on public pages. Rich text is HTML already cleaned on the server. */
export type Block = { type: string } & Record<string, unknown>;
type Item = Record<string, string>;
const items = (b: Block, k = 'items') => (Array.isArray(b[k]) ? (b[k] as Item[]) : []);
const s = (v: unknown) => (typeof v === 'string' ? v : '');

export function CmsBlock({ b }: { b: Block }) {
    switch (b.type) {
        case 'hero':
            return (
                <section className="py-12 text-center">
                    <h2 className="font-display text-3xl font-bold sm:text-4xl">{s(b.heading)}</h2>
                    {s(b.subheading) && <p className="mx-auto mt-3 max-w-2xl text-lg text-muted">{s(b.subheading)}</p>}
                    {s(b.cta_label) && s(b.cta_url) && (
                        <a href={s(b.cta_url)} className="mt-6 inline-flex h-11 items-center rounded-md bg-primary px-6 font-semibold text-white">
                            {s(b.cta_label)}
                        </a>
                    )}
                    {s(b.image) && <img src={s(b.image)} alt={s(b.image_alt)} className="mx-auto mt-8 max-w-full rounded-lg" loading="lazy" />}
                </section>
            );
        case 'features':
        case 'steps': {
            const list = items(b);
            const Tag = b.type === 'steps' ? 'ol' : 'ul';
            return (
                <section className="py-8">
                    {s(b.heading) && <h2 className="mb-5 font-display text-2xl font-bold">{s(b.heading)}</h2>}
                    <Tag className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {list.map((i, n) => (
                            <li key={n} className="rounded-lg border border-line p-4">
                                <h3 className="font-semibold">
                                    {b.type === 'steps' && <span className="mr-2 text-primary">{n + 1}.</span>}
                                    {i.title}
                                </h3>
                                <p className="mt-1 text-sm text-muted">{i.text}</p>
                            </li>
                        ))}
                    </Tag>
                </section>
            );
        }
        case 'stats':
            return (
                <section className="py-8">
                    <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        {items(b).map((i, n) => (
                            <div key={n} className="text-center">
                                <dt className="text-sm text-muted">{i.label}</dt>
                                <dd className="font-display text-3xl font-bold">{i.value}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            );
        case 'testimonials':
            return (
                <section className="grid gap-4 py-8 sm:grid-cols-2">
                    {items(b).map((i, n) => (
                        <figure key={n} className="rounded-lg border border-line p-5">
                            <blockquote className="text-lg">“{i.quote}”</blockquote>
                            <figcaption className="mt-2 text-sm text-muted">
                                {i.author}
                                {i.business ? `, ${i.business}` : ''}
                            </figcaption>
                        </figure>
                    ))}
                </section>
            );
        case 'faq':
            return (
                <section className="py-8">
                    <h2 className="mb-4 font-display text-2xl font-bold">{s(b.heading) || 'Questions and answers'}</h2>
                    <div className="divide-y divide-line">
                        {items(b).map((i, n) => (
                            <details key={n} className="py-3" open={n === 0}>
                                <summary className="cursor-pointer font-semibold">{i.q}</summary>
                                <p className="mt-2 text-muted">{i.a}</p>
                            </details>
                        ))}
                    </div>
                </section>
            );
        case 'pricing':
            return (
                <section className="py-8">
                    {s(b.heading) && <h2 className="mb-5 font-display text-2xl font-bold">{s(b.heading)}</h2>}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {(Array.isArray(b.plans) ? (b.plans as (Item & { features?: string[] })[]) : []).map((p, n) => (
                            <div key={n} className="rounded-lg border border-line p-5">
                                <h3 className="font-semibold">{p.name}</h3>
                                <p className="mt-2 font-display text-3xl font-bold">
                                    {p.price}
                                    {p.period && <span className="text-base font-normal text-muted"> / {p.period}</span>}
                                </p>
                                <ul className="mt-3 space-y-1 text-sm">
                                    {(p.features ?? []).map((f) => (
                                        <li key={f}>✓ {f}</li>
                                    ))}
                                </ul>
                                {p.cta_label && p.cta_url && (
                                    <a
                                        href={p.cta_url}
                                        className="mt-4 inline-flex h-10 items-center rounded-md bg-primary px-4 font-semibold text-white"
                                    >
                                        {p.cta_label}
                                    </a>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            );
        case 'cta':
            return (
                <section className="my-8 rounded-lg bg-primary/10 p-8 text-center">
                    <h2 className="font-display text-2xl font-bold">{s(b.heading)}</h2>
                    {s(b.text) && <p className="mt-2 text-muted">{s(b.text)}</p>}
                    {s(b.label) && s(b.url) && (
                        <a href={s(b.url)} className="mt-4 inline-flex h-11 items-center rounded-md bg-primary px-6 font-semibold text-white">
                            {s(b.label)}
                        </a>
                    )}
                </section>
            );
        case 'rich_text':
            return <section className="prose max-w-none py-6" dangerouslySetInnerHTML={{ __html: s(b.html) }} />;
        case 'media':
            return (
                <figure className="py-6">
                    {s(b.video) ? (
                        <div className="aspect-video">
                            <iframe
                                src={s(b.video)}
                                title={s(b.caption) || 'Video'}
                                className="size-full rounded-lg"
                                allow="encrypted-media; picture-in-picture"
                                allowFullScreen
                                loading="lazy"
                            />
                        </div>
                    ) : (
                        <img src={s(b.image)} alt={s(b.alt)} className="max-w-full rounded-lg" loading="lazy" />
                    )}
                    {s(b.caption) && <figcaption className="mt-2 text-sm text-muted">{s(b.caption)}</figcaption>}
                </figure>
            );
        default:
            return null;
    }
}
