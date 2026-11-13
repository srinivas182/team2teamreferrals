import { Link } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Post = { slug: string; title: string; summary: string; date: string; image: string | null; alt: string };
type Props = { posts: Post[]; page: number; pages: number; category: string | null; categories: { slug: string; name: string }[]; seo: Seo };

/** The blog: newest first, each post with the answer it gives. */
export default function Blog({ posts, page, pages, category, categories, seo }: Props) {
    return (
        <PublicLayout current="/blog">
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-5xl px-4 py-10">
                <h1 className="font-display text-4xl font-bold">{category ?? 'Blog'}</h1>
                {categories.length > 0 && (
                    <nav aria-label="Categories" className="mt-4 flex flex-wrap gap-2 text-sm">
                        <Link href="/blog" className="rounded-full border border-line px-3 py-1">
                            All
                        </Link>
                        {categories.map((c) => (
                            <Link key={c.slug} href={`/blog/category/${c.slug}`} className="rounded-full border border-line px-3 py-1">
                                {c.name}
                            </Link>
                        ))}
                    </nav>
                )}
                {posts.length === 0 && <p className="mt-8 text-muted">No posts yet.</p>}
                <div className="mt-8 grid gap-6 sm:grid-cols-2">
                    {posts.map((p) => (
                        <article key={p.slug} className="rounded-lg border border-line p-5">
                            {p.image && <img src={p.image} alt={p.alt} className="mb-3 w-full rounded-md" loading="lazy" />}
                            <h2 className="font-display text-xl font-bold">
                                <Link href={`/blog/${p.slug}`} className="hover:underline">
                                    {p.title}
                                </Link>
                            </h2>
                            <p className="mt-2 text-muted">{p.summary}</p>
                            <time className="mt-2 block text-xs text-muted" dateTime={p.date}>
                                {p.date}
                            </time>
                        </article>
                    ))}
                </div>
                {pages > 1 && (
                    <nav aria-label="Pages" className="mt-8 flex gap-4 text-sm">
                        {page > 1 && <Link href={`?page=${page - 1}`}>← Newer</Link>}
                        {page < pages && <Link href={`?page=${page + 1}`}>Older →</Link>}
                    </nav>
                )}
            </div>
        </PublicLayout>
    );
}
