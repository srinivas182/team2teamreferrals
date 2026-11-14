import { Link } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
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
    return (
        <PublicLayout current="/blog">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-3xl px-4 py-10">
                <p className="text-sm">
                    <Link href="/blog" className="text-primary">
                        Blog
                    </Link>
                    {post.category && post.categorySlug && (
                        <>
                            {' · '}
                            <Link href={`/blog/category/${post.categorySlug}`} className="text-primary">
                                {post.category}
                            </Link>
                        </>
                    )}
                </p>
                <h1 className="mt-2 font-display text-4xl font-bold">{post.title}</h1>
                <p className="mt-4 text-lg">{post.summary}</p>
                <p className="mt-3 text-sm text-muted">
                    By {post.author}
                    {post.reviewer && <> · Reviewed by {post.reviewer}</>} · Published <time dateTime={post.published}>{post.published}</time>
                    {post.updated !== post.published && (
                        <>
                            {' '}
                            · Updated <time dateTime={post.updated}>{post.updated}</time>
                        </>
                    )}
                </p>
                {post.image && <img src={post.image} alt={post.alt} className="mt-6 w-full rounded-lg" />}
                <div className="prose mt-8 max-w-none" dangerouslySetInnerHTML={{ __html: post.body }} />
                {(related.industries.length > 0 || related.articles.length > 0) && (
                    <aside aria-labelledby="related" className="mt-10 border-t border-line pt-6">
                        <h2 id="related" className="font-display text-xl font-bold">
                            Related
                        </h2>
                        <div className="mt-3 grid gap-6 sm:grid-cols-2">
                            {related.industries.length > 0 && (
                                <div>
                                    <h3 className="text-sm font-semibold text-muted">Industries in this article</h3>
                                    <ul className="mt-1 space-y-1">
                                        {related.industries.map((l) => (
                                            <li key={l.url}>
                                                <Link href={l.url} className="text-primary">
                                                    {l.name}: costs, licensing and referrals
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                            {related.articles.length > 0 && (
                                <div>
                                    <h3 className="text-sm font-semibold text-muted">Read next</h3>
                                    <ul className="mt-1 space-y-1">
                                        {related.articles.map((l) => (
                                            <li key={l.url}>
                                                <Link href={l.url} className="text-primary">
                                                    {l.title}
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    </aside>
                )}
            </article>
        </PublicLayout>
    );
}
