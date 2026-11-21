import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { HelpSearch, StillNeedHelp } from '@/components/help/HelpSearch';
import { SeoHead } from '@/components/seo/SeoHead';
import { iconFor, PageHero, Section } from '@/components/site/Site';
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
            <article>
                <PageHero eyebrow="Help centre" title="How can we help?" lead={answer}>
                    <div className="max-w-2xl">
                        <HelpSearch large />
                    </div>
                </PageHero>
                <Section title="Topics" id="topics">
                    <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {categories.map((c) => {
                            const Icon = iconFor(c.name, c.description ?? '');
                            return (
                                <li key={c.slug}>
                                    <Link
                                        href={`/help/topic/${c.slug}`}
                                        className="flex h-full flex-col rounded-2xl border border-line bg-surface p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-primary hover:shadow-md"
                                    >
                                        <span className="inline-flex size-11 items-center justify-center rounded-full bg-cobalt-50 text-primary dark:bg-cobalt-500/20">
                                            <Icon className="size-5" aria-hidden />
                                        </span>
                                        <span className="mt-4 text-lg font-semibold">{c.name}</span>
                                        {c.description && <span className="mt-1 text-muted">{c.description}</span>}
                                        <span className="mt-auto pt-4 text-sm text-muted">
                                            {c.count} {c.count === 1 ? 'article' : 'articles'}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </Section>
                {popular.length > 0 && (
                    <Section tinted title="Popular questions" id="popular">
                        <dl className="grid gap-4 md:grid-cols-2">
                            {popular.map((a) => (
                                <div key={a.href} className="relative rounded-2xl border border-line bg-bg p-5 transition hover:border-primary">
                                    <dt>
                                        <Link href={a.href} className="font-semibold after:absolute after:inset-0">
                                            {a.title}
                                        </Link>
                                    </dt>
                                    <dd className="mt-1 line-clamp-3 text-sm text-muted">{a.summary}</dd>
                                    <dd className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary">
                                        Read the answer <ArrowRight className="size-4" aria-hidden />
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Section>
                )}
                <Section narrow>
                    <StillNeedHelp />
                </Section>
            </article>
        </PublicLayout>
    );
}
