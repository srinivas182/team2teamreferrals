import { Link } from '@inertiajs/react';
import { HelpSearch, StillNeedHelp } from '@/components/help/HelpSearch';
import { SeoHead } from '@/components/seo/SeoHead';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    categories: { slug: string; name: string; description: string | null; count: number }[];
    popular: { title: string; href: string; summary: string }[];
    answer: string;
    seo: Seo;
};

/** The help centre: search, topics and the most-read answers. */
export default function HelpIndex({ categories, popular, answer, seo }: Props) {
    return (
        <PublicLayout current="/help">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-3xl px-4 py-10">
                <h1 className="font-display text-4xl font-bold">Help centre</h1>
                <p className="mt-4 text-lg">{answer}</p>
                <div className="mt-6">
                    <HelpSearch large />
                </div>
                <section aria-labelledby="topics" className="mt-10">
                    <h2 id="topics" className="font-display text-xl font-bold">
                        Topics
                    </h2>
                    <ul className="mt-3 grid gap-3 sm:grid-cols-2">
                        {categories.map((c) => (
                            <li key={c.slug} className="rounded-lg border border-line p-4">
                                <Link href={`/help/topic/${c.slug}`} className="font-semibold text-primary">
                                    {c.name}
                                </Link>
                                {c.description && <p className="mt-1 text-sm text-muted">{c.description}</p>}
                                <p className="mt-1 text-xs text-subtle">
                                    {c.count} {c.count === 1 ? 'article' : 'articles'}
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>
                {popular.length > 0 && (
                    <section aria-labelledby="popular" className="mt-10">
                        <h2 id="popular" className="font-display text-xl font-bold">
                            Popular questions
                        </h2>
                        <dl className="mt-3 space-y-4">
                            {popular.map((a) => (
                                <div key={a.href}>
                                    <dt>
                                        <Link href={a.href} className="font-semibold text-primary">
                                            {a.title}
                                        </Link>
                                    </dt>
                                    <dd className="mt-1 text-sm">{a.summary}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                )}
                <StillNeedHelp />
            </article>
        </PublicLayout>
    );
}
