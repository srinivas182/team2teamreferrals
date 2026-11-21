import { Link } from '@inertiajs/react';
import { HelpSearch, StillNeedHelp } from '@/components/help/HelpSearch';
import { SeoHead } from '@/components/seo/SeoHead';
import { PageHero } from '@/components/site/Site';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    category: { slug: string; name: string; description: string | null };
    answer: string;
    articles: { title: string; href: string; summary: string }[];
    seo: Seo;
};

/** One help topic: each question with its short answer. */
export default function HelpCategory({ category, answer, articles, seo }: Props) {
    return (
        <PublicLayout current="/help">
            <SeoHead seo={seo} />
            <article>
                <PageHero
                    narrow
                    eyebrow={
                        <Link href="/help" className="normal-case hover:underline">
                            Help centre
                        </Link>
                    }
                    title={category.name}
                    lead={answer}
                >
                    <div className="max-w-2xl">
                        <HelpSearch />
                    </div>
                </PageHero>
                <div className="mx-auto max-w-4xl px-5 py-10 sm:px-8">
                    <dl className="grid gap-4">
                        {articles.map((a) => (
                            <div
                                key={a.href}
                                className="relative rounded-2xl border border-line bg-surface p-5 shadow-sm transition hover:border-primary"
                            >
                                <dt>
                                    <Link href={a.href} className="font-semibold after:absolute after:inset-0">
                                        {a.title}
                                    </Link>
                                </dt>
                                <dd className="mt-1 text-muted">{a.summary}</dd>
                            </div>
                        ))}
                    </dl>
                    <StillNeedHelp />
                </div>
            </article>
        </PublicLayout>
    );
}
