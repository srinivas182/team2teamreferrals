import { Link } from '@inertiajs/react';
import { BadgeCheck, BookOpen, CalendarDays, ChevronRight, Layers, UserRound } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { CtaBand, FeatureCard, PageHero, Section } from '@/components/site/Site';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    post: {
        title: string;
        summary: string;
        body: string;
        author: string;
        reviewer: string | null;
        published: string;
        updated: string;
        category: string | null;
        categorySlug: string | null;
        image: string | null;
        alt: string;
    };
    related: { industries: { name: string; url: string }[]; articles: { title: string; url: string }[] };
    seo: Seo;
};

/** A blog post: the answer first, then the article. Its body is HTML cleaned on the server. */
export default function BlogPost({ post, related, seo }: Props) {
    const hasRelated = related.industries.length > 0 || related.articles.length > 0;
    return (
        <PublicLayout current="/blog">
            <SeoHead seo={seo} />
            <article>
                <PageHero
                    narrow
                    eyebrow={
                        <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1 normal-case">
                            <Link href="/blog" className="hover:underline">
                                Blog
                            </Link>
                            {post.category && post.categorySlug && (
                                <>
                                    <ChevronRight className="size-4" aria-hidden />
                                    <Link href={`/blog/category/${post.categorySlug}`} className="hover:underline">
                                        {post.category}
                                    </Link>
                                </>
                            )}
                        </nav>
                    }
                    title={post.title}
                    lead={post.summary}
                    meta={
                        <span className="flex flex-col gap-1 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4">
                            <span className="inline-flex items-center gap-1.5">
                                <UserRound className="size-4" aria-hidden /> By {post.author}
                            </span>
                            {post.reviewer && (
                                <span className="inline-flex items-center gap-1.5">
                                    <BadgeCheck className="size-4" aria-hidden /> Reviewed by {post.reviewer}
                                </span>
                            )}
                            <span className="inline-flex items-center gap-1.5">
                                <CalendarDays className="size-4" aria-hidden /> Published <time dateTime={post.published}>{post.published}</time>
                                {post.updated !== post.published && (
                                    <>
                                        {' '}
                                        · Updated <time dateTime={post.updated}>{post.updated}</time>
                                    </>
                                )}
                            </span>
                        </span>
                    }
                />
                <div className="mx-auto max-w-4xl px-5 py-10 sm:px-8">
                    {post.image && <img src={post.image} alt={post.alt} className="mb-10 w-full rounded-2xl shadow-sm" />}
                    <div className="prose prose-lg max-w-3xl" dangerouslySetInnerHTML={{ __html: post.body }} />
                </div>
            </article>

            {hasRelated && (
                <Section tinted title="Keep reading" id="related">
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {related.articles.map((l) => (
                            <FeatureCard key={l.url} icon={BookOpen} title={l.title} href={l.url}>
                                Read next
                            </FeatureCard>
                        ))}
                        {related.industries.map((l) => (
                            <FeatureCard key={l.url} icon={Layers} title={l.name} href={l.url}>
                                Costs, licensing and referrals
                            </FeatureCard>
                        ))}
                    </div>
                </Section>
            )}
            <Section>
                <CtaBand
                    title="Ready to trade referrals?"
                    text="Claim the seat for your city in your industry’s network."
                    action="Find your network"
                    href="/groups"
                />
            </Section>
        </PublicLayout>
    );
}
