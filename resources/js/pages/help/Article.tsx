import { Link } from '@inertiajs/react';
import { ThumbsDown, ThumbsUp, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { StillNeedHelp } from '@/components/help/HelpSearch';
import { SeoHead } from '@/components/seo/SeoHead';
import { PageHero } from '@/components/site/Site';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    article: { title: string; slug: string; summary: string; html: string; updated: string; audience: string };
    category: { slug: string | null; name: string | null };
    related: { title: string; href: string }[];
    seo: Seo;
};

const post = (url: string, body: object) =>
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
        keepalive: true,
    }).catch(() => undefined);

/** A help article: the short answer first, then the detail, "was this helpful?" and related questions. */
export default function HelpArticle({ article, category, related, seo }: Props) {
    const [vote, setVote] = useState<boolean | null>(null);
    const [comment, setComment] = useState('');
    const [sent, setSent] = useState(false);
    useEffect(() => {
        void post(`/help/${article.slug}/seen`, {});
    }, [article.slug]);
    const send = (helpful: boolean, text?: string) => void post(`/help/${article.slug}/feedback`, { helpful, comment: text ?? null });
    return (
        <PublicLayout current="/help">
            <SeoHead seo={seo} />
            <article>
                <PageHero
                    narrow
                    eyebrow={
                        <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1 normal-case">
                            <Link href="/help" className="hover:underline">
                                Help centre
                            </Link>
                            {category.slug && (
                                <>
                                    <ChevronRight className="size-4" aria-hidden />
                                    <Link href={`/help/topic/${category.slug}`} className="hover:underline">
                                        {category.name}
                                    </Link>
                                </>
                            )}
                        </nav>
                    }
                    title={article.title}
                />
                <div className="mx-auto max-w-4xl px-5 py-10 sm:px-8">
                    <p className="max-w-3xl rounded-2xl border-l-4 border-primary bg-surface p-5 text-lg shadow-sm">{article.summary}</p>
                    <div className="prose prose-lg mt-8 max-w-3xl" dangerouslySetInnerHTML={{ __html: article.html }} />
                    <p className="mt-6 text-sm text-muted">
                        Updated <time dateTime={article.updated}>{article.updated}</time>
                    </p>
                    <section aria-labelledby="helpful" className="mt-10 max-w-3xl rounded-2xl border border-line bg-surface p-5 shadow-sm">
                        <h2 id="helpful" className="font-semibold">
                            Was this helpful?
                        </h2>
                        {vote === null ? (
                            <div className="mt-2 flex gap-2">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => {
                                        setVote(true);
                                        send(true);
                                    }}
                                >
                                    <ThumbsUp /> Yes
                                </Button>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => {
                                        setVote(false);
                                        send(false);
                                    }}
                                >
                                    <ThumbsDown /> No
                                </Button>
                            </div>
                        ) : vote || sent ? (
                            <p className="mt-2 text-sm">Thanks for letting us know.</p>
                        ) : (
                            <div className="mt-2 space-y-2">
                                <label htmlFor="missing" className="text-sm">
                                    What were you looking for? (optional)
                                </label>
                                <Textarea id="missing" rows={2} maxLength={1000} value={comment} onChange={(e) => setComment(e.target.value)} />
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        if (comment.trim()) send(false, comment.trim());
                                        setSent(true);
                                    }}
                                >
                                    Send
                                </Button>
                            </div>
                        )}
                    </section>
                    {related.length > 0 && (
                        <section aria-labelledby="related" className="mt-8">
                            <h2 id="related" className="font-display text-xl font-bold">
                                Related questions
                            </h2>
                            <ul className="mt-3 space-y-2">
                                {related.map((r) => (
                                    <li key={r.href}>
                                        <Link href={r.href} className="text-primary">
                                            {r.title}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                    <StillNeedHelp article={article.slug} />
                </div>
            </article>
        </PublicLayout>
    );
}
