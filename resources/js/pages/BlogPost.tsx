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
    seo: Seo;
};

/** A blog post: the answer first, then the article. Its body is HTML cleaned on the server. */
export default function BlogPost({ post, seo }: Props) {
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
            </article>
        </PublicLayout>
    );
}
