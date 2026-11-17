import { Link } from '@inertiajs/react';
import { Faq } from '@/components/partners/FormExtras';
import { AdNote } from '@/components/partners/PartnerCard';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    categories: { slug: string; name: string; partners: number; cities: number }[];
    places: { label: string; href: string; count: number }[];
    answer: string;
    howItWorks: string;
    faq: { q: string; a: string }[];
    seo: Seo;
};

/** The partner directory hub: what it is, where partners advertise, and how to join. */
export default function PartnersIndex({ categories, places, answer, howItWorks, faq, seo }: Props) {
    return (
        <PublicLayout current="/partners">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-3xl px-4 py-10">
                <h1 className="font-display text-4xl font-bold">Local partners for home buyers and sellers</h1>
                <p className="mt-4 text-lg">{answer}</p>
                <AdNote note={howItWorks} />
                <section aria-labelledby="kinds" className="mt-8">
                    <h2 id="kinds" className="font-display text-xl font-bold">
                        Kinds of partner
                    </h2>
                    <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                        {categories.map((c) => (
                            <li key={c.slug} className="rounded-lg border border-line p-3">
                                <span className="font-semibold">{c.name}</span>
                                <span className="block text-sm text-muted">
                                    {c.partners > 0
                                        ? `${c.partners} listed in ${c.cities} ${c.cities === 1 ? 'city' : 'cities'}`
                                        : 'No one listed yet'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
                {places.length > 0 && (
                    <section aria-labelledby="where" className="mt-8">
                        <h2 id="where" className="font-display text-xl font-bold">
                            Where partners advertise
                        </h2>
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                            {places.map((p) => (
                                <li key={p.href}>
                                    <Link href={p.href} className="text-primary">
                                        {p.label}
                                    </Link>{' '}
                                    <span className="text-sm text-muted">· {p.count}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <section className="mt-10 rounded-lg border border-line bg-surface p-5">
                    <h2 className="font-display text-xl font-bold">Serve home buyers and sellers?</h2>
                    <p className="mt-2">
                        Advertise on the city pages people use to find verified pros. Flat monthly price, no per-lead fees, every enquiry yours alone.
                    </p>
                    <Button asChild className="mt-3">
                        <Link href="/partners/advertise">Advertise with us</Link>
                    </Button>
                </section>
                <Faq items={faq} />
            </article>
        </PublicLayout>
    );
}
