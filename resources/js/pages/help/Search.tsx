import { Link } from '@inertiajs/react';
import { HelpSearch, StillNeedHelp } from '@/components/help/HelpSearch';
import { SeoHead } from '@/components/seo/SeoHead';
import { PageHero } from '@/components/site/Site';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

/** Search results; nothing found leads straight to contacting support with the question filled in. */
export default function HelpSearchPage({ q, results, seo }: { q: string; results: { title: string; href: string; summary: string }[]; seo: Seo }) {
    return (
        <PublicLayout current="/help">
            <SeoHead seo={seo} />
            <PageHero
                narrow
                eyebrow={
                    <Link href="/help" className="normal-case hover:underline">
                        Help centre
                    </Link>
                }
                title="Search help"
            />
            <div className="mx-auto max-w-3xl px-5 py-10 sm:px-8">
                <div>
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
