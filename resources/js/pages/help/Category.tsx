import { Link } from '@inertiajs/react';
import { HelpSearch, StillNeedHelp } from '@/components/help/HelpSearch';
import { SeoHead } from '@/components/seo/SeoHead';
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
            <article className="mx-auto max-w-3xl px-4 py-10">
                <p className="text-sm">
                    <Link href="/help" className="text-primary">
                        Help centre
                    </Link>
                </p>
                <h1 className="mt-2 font-display text-4xl font-bold">{category.name}</h1>
                <p className="mt-3 text-lg">{answer}</p>
                <div className="mt-6">
                    <HelpSearch />
                </div>
                <dl className="mt-8 space-y-5">
                    {articles.map((a) => (
                        <div key={a.href}>
                            <dt>
                                <Link href={a.href} className="font-semibold text-primary">
                                    {a.title}
                                </Link>
                            </dt>
                            <dd className="mt-1">{a.summary}</dd>
                        </div>
                    ))}
                </dl>
                <StillNeedHelp />
            </article>
        </PublicLayout>
    );
}
