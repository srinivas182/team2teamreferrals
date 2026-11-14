import { Link } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Hub = { slug: string; name: string; family: string; summary: string };

/** The list of industry hubs, grouped by family. */
export default function Industries({ hubs, summary, seo }: { hubs: Hub[]; summary: string; seo: Seo }) {
    const families = [...new Set(hubs.map((h) => h.family))];
    return (
        <PublicLayout current="/industries">
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-4xl px-4 py-10">
                <h1 className="font-display text-4xl font-bold">Industries</h1>
                <p className="mt-4 text-lg">{summary}</p>
                {hubs.length === 0 && <p className="mt-6 text-muted">Industry guides are coming soon.</p>}
                {families.map((f) => (
                    <div key={f} className="mt-8">
                        <h2 className="font-display text-2xl font-bold">{f}</h2>
                        <ul className="mt-3 grid gap-3 sm:grid-cols-2">
                            {hubs
                                .filter((h) => h.family === f)
                                .map((h) => (
                                    <li key={h.slug} className="rounded-lg border border-line p-4">
                                        <Link href={`/industries/${h.slug}`} className="font-semibold text-primary">
                                            {h.name}
                                        </Link>
                                        <p className="mt-1 line-clamp-3 text-sm text-muted">{h.summary}</p>
                                    </li>
                                ))}
                        </ul>
                    </div>
                ))}
            </section>
        </PublicLayout>
    );
}
