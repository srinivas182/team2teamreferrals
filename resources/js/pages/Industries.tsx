import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { IndustryIcon } from '@/components/directory/IndustryIcon';
import { SeoHead } from '@/components/seo/SeoHead';
import { CtaBand, PageHero, Section } from '@/components/site/Site';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Hub = { slug: string; name: string; family: string; familyKey: string; icon: string; summary: string };

/** The list of industry guides, grouped by family, each with its icon. */
export default function Industries({ hubs, summary, seo }: { hubs: Hub[]; summary: string; seo: Seo }) {
    const families = [...new Set(hubs.map((h) => h.family))];
    return (
        <PublicLayout current="/industries">
            <SeoHead seo={seo} />
            <PageHero eyebrow="Industry guides" title="Industries" lead={summary}>
                {families.length > 1 && (
                    <nav aria-label="Families" className="flex flex-wrap gap-2">
                        {families.map((f) => (
                            <a
                                key={f}
                                href={`#${f.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`}
                                className="inline-flex min-h-10 items-center rounded-full border border-line bg-surface px-4 text-sm font-medium hover:border-primary"
                            >
                                {f}
                            </a>
                        ))}
                    </nav>
                )}
            </PageHero>
            {hubs.length === 0 && (
                <Section>
                    <div className="rounded-2xl border border-dashed border-line bg-surface px-6 py-12 text-center text-muted">
                        Industry guides are coming soon.
                    </div>
                </Section>
            )}
            {families.map((f, n) => {
                const id = f.toLowerCase().replace(/[^a-z0-9]+/g, '-');
                return (
                    <Section key={f} title={f} id={id} tinted={n % 2 === 1}>
                        <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {hubs
                                .filter((h) => h.family === f)
                                .map((h) => (
                                    <li key={h.slug}>
                                        <Link
                                            href={`/industries/${h.slug}`}
                                            className="group flex h-full flex-col rounded-2xl border border-line bg-surface p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-primary hover:shadow-md"
                                        >
                                            <span className="inline-flex size-11 items-center justify-center rounded-full bg-cobalt-50 text-primary dark:bg-cobalt-500/20">
                                                <IndustryIcon name={h.icon} className="size-5" />
                                            </span>
                                            <span className="mt-4 text-lg font-semibold">{h.name}</span>
                                            <span className="mt-1 line-clamp-3 text-muted">{h.summary}</span>
                                            <span className="mt-auto inline-flex items-center gap-1 pt-4 text-sm font-medium text-primary">
                                                Costs, licensing and referrals <ArrowRight className="size-4" aria-hidden />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                        </ul>
                    </Section>
                );
            })}
            <Section>
                <CtaBand
                    title="Work in one of these industries?"
                    text="Each has a referral network with one verified team per city."
                    action="Find your network"
                    href="/groups"
                />
            </Section>
        </PublicLayout>
    );
}
