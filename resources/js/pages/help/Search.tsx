import { Link } from '@inertiajs/react';
import { HelpSearch, StillNeedHelp } from '@/components/help/HelpSearch';
import { SeoHead } from '@/components/seo/SeoHead';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

/** Search results; nothing found leads straight to contacting support with the question filled in. */
export default function HelpSearchPage({ q, results, seo }: { q: string; results: { title: string; href: string; summary: string }[]; seo: Seo }) {
    return (
        <PublicLayout current="/help">
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-3xl px-4 py-10">
                <p className="text-sm">
                    <Link href="/help" className="text-primary">
                        Help centre
                    </Link>
                </p>
                <h1 className="mt-2 font-display text-3xl font-bold">Search help</h1>
                <div className="mt-4">
                    <HelpSearch initial={q} />
                </div>
                {q && (
                    <p className="mt-6 text-sm text-muted" role="status">
                        {results.length === 0
                            ? `Nothing found for “${q}”.`
                            : `${results.length} ${results.length === 1 ? 'answer' : 'answers'} for “${q}”.`}
                    </p>
                )}
                <dl className="mt-4 space-y-5">
                    {results.map((r) => (
                        <div key={r.href}>
                            <dt>
                                <Link href={r.href} className="font-semibold text-primary">
                                    {r.title}
                                </Link>
                            </dt>
                            <dd className="mt-1 text-sm">{r.summary}</dd>
                        </div>
                    ))}
                </dl>
                <StillNeedHelp q={q || undefined} />
            </div>
        </PublicLayout>
    );
}
