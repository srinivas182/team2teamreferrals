import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { PostCover } from '@/components/site/PostCover';
import { CtaBand, PageHero, Section } from '@/components/site/Site';
import { PublicLayout } from '@/layouts/PublicLayout';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Post = {
    slug: string;
    title: string;
    summary: string;
    date: string;
    image: string | null;
    alt: string;
    category: string | null;
    categorySlug: string | null;
};
type Props = {
    posts: Post[];
    intro: string;
    page: number;
    pages: number;
    category: string | null;
    categorySlug: string | null;
    categories: { slug: string; name: string }[];
    seo: Seo;
};

const day = (d: string) => {
    const t = new Date(`${d}T00:00:00Z`);
    return Number.isNaN(t.getTime()) ? d : t.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
};

function PostCard({ p, featured = false }: { p: Post; featured?: boolean }) {
    return (
        <article
            className={cn(
                'group relative flex overflow-hidden rounded-2xl border border-line bg-surface shadow-sm transition hover:-translate-y-0.5 hover:shadow-md',
                featured ? 'flex-col md:flex-row' : 'flex-col',
            )}
        >
            <div className={cn('overflow-hidden', featured ? 'aspect-[16/9] md:aspect-auto md:w-1/2' : 'aspect-[16/9]')}>
                <PostCover image={p.image} alt={p.alt} category={p.category} className="transition group-hover:scale-[1.02]" />
            </div>
            <div className={cn('flex flex-1 flex-col p-6', featured && 'md:p-8')}>
                {p.category && <p className="text-xs font-semibold tracking-wide text-primary uppercase">{p.category}</p>}
                <h2 className={cn('mt-2 font-display leading-tight font-bold', featured ? 'text-3xl' : 'text-xl')}>
                    {/* The whole card opens the post; the title is the link for screen readers. */}
                    <Link href={`/blog/${p.slug}`} className="after:absolute after:inset-0">
                        {p.title}
                    </Link>
                </h2>
                <p className={cn('mt-2 text-muted', featured ? 'line-clamp-4 text-lg' : 'line-clamp-3')}>{p.summary}</p>
                <div className="mt-auto flex items-center justify-between pt-5 text-sm text-muted">
                    <time dateTime={p.date}>{day(p.date)}</time>
                    <span className="inline-flex items-center gap-1 font-medium text-primary">
                        Read <ArrowRight className="size-4" aria-hidden />
                    </span>
                </div>
            </div>
        </article>
    );
}

/** The blog: newest first, each post with the answer it gives. */
export default function Blog({ posts, intro, page, pages, category, categorySlug, categories, seo }: Props) {
    const [first, ...rest] = posts;
    const feature = page === 1 && !category && first;
    const grid = feature ? rest : posts;
    return (
        <PublicLayout current="/blog">
            <SeoHead seo={seo} />
            <PageHero eyebrow={category ? 'Blog category' : 'Blog'} title={category ?? 'Guides and news'} lead={intro}>
                {categories.length > 0 && (
                    <nav aria-label="Categories" className="flex flex-wrap gap-2">
                        {[{ slug: '', name: 'All posts' }, ...categories].map((c) => {
                            const on = c.slug === '' ? !categorySlug : c.slug === categorySlug;
                            return (
                                <Link
                                    key={c.slug || 'all'}
                                    href={c.slug ? `/blog/category/${c.slug}` : '/blog'}
                                    aria-current={on ? 'page' : undefined}
                                    className={cn(
                                        'inline-flex min-h-10 items-center rounded-full border px-4 text-sm font-medium',
                                        on ? 'border-primary bg-primary text-white' : 'border-line bg-surface hover:border-primary',
                                    )}
                                >
                                    {c.name}
                                </Link>
                            );
                        })}
                    </nav>
                )}
            </PageHero>

            <Section>
                {posts.length === 0 && (
                    <div className="rounded-2xl border border-dashed border-line bg-surface px-6 py-12 text-center text-muted">
                        No posts here yet.
                    </div>
                )}
                {feature && <PostCard p={feature} featured />}
                {grid.length > 0 && (
                    <div className={cn('grid gap-6 sm:grid-cols-2 lg:grid-cols-3', feature && 'mt-8')}>
                        {grid.map((p) => (
                            <PostCard key={p.slug} p={p} />
                        ))}
                    </div>
                )}
                {pages > 1 && (
                    <nav aria-label="Pages" className="mt-10 flex items-center justify-between">
                        {page > 1 ? (
                            <Link
                                href={`?page=${page - 1}`}
                                className="inline-flex h-11 items-center gap-2 rounded-md border border-line bg-surface px-4 font-medium"
                            >
                                <ArrowLeft className="size-4" aria-hidden /> Newer posts
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span className="text-sm text-muted">
                            Page {page} of {pages}
                        </span>
                        {page < pages ? (
                            <Link
                                href={`?page=${page + 1}`}
                                className="inline-flex h-11 items-center gap-2 rounded-md border border-line bg-surface px-4 font-medium"
                            >
                                Older posts <ArrowRight className="size-4" aria-hidden />
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}
                <div className="mt-12">
                    <CtaBand
                        title="Put these ideas to work"
                        text="Claim the seat for your city and start trading referrals with verified teams."
                        action="See the networks"
                        href="/groups"
                    />
                </div>
            </Section>
        </PublicLayout>
    );
}
