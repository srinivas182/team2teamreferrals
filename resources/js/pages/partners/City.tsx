import { Link } from '@inertiajs/react';
import { Faq } from '@/components/partners/FormExtras';
import { AdNote, PartnerCard, type PartnerCardData } from '@/components/partners/PartnerCard';
import { SeoHead } from '@/components/seo/SeoHead';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    category: { slug: string; name: string; singular: string };
    city: { id: number; name: string; slug: string };
    state: { code: string; name: string };
    partners: PartnerCardData[];
    answer: string;
    howItWorks: string;
    faq: { q: string; a: string }[];
    others: { name: string; href: string }[];
    seo: Seo;
};

/** Everyone in one category advertising in one city, in today's rotation. */
export default function PartnersCity({ category, city, state, partners, answer, howItWorks, faq, others, seo }: Props) {
    return (
        <PublicLayout current="/partners">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-3xl px-4 py-10">
                <p className="text-sm">
                    <Link href="/partners" className="text-primary">
                        Local partners
                    </Link>
                    {' · '}
                    {state.name}
                </p>
                <h1 className="mt-2 font-display text-4xl font-bold">
                    {category.name} in {city.name}, {state.code}
                </h1>
                <p className="mt-4 text-lg">{answer}</p>
                <AdNote note={howItWorks} />
                <div className="mt-6 grid gap-3">
                    {partners.map((p) => (
                        <PartnerCard key={p.slug} partner={p} cityId={city.id} />
                    ))}
                </div>
                {partners.length === 0 && (
                    <p className="mt-6">
                        Are you {category.singular.match(/^[aeiou]/i) ? 'an' : 'a'} {category.singular} serving {city.name}?{' '}
                        <Link href="/partners/advertise" className="text-primary">
                            Advertise here
                        </Link>
                        .
                    </p>
                )}
                {others.length > 0 && (
                    <section aria-labelledby="others" className="mt-8">
                        <h2 id="others" className="font-display text-xl font-bold">
                            Other partners in {city.name}
                        </h2>
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                            {others.map((o) => (
                                <li key={o.href}>
                                    <Link href={o.href} className="text-primary">
                                        {o.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <Faq items={faq} />
            </article>
        </PublicLayout>
    );
}
