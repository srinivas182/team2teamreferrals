import { Link } from '@inertiajs/react';
import { AdNote, PartnerCard, type PartnerCardData } from '@/components/partners/PartnerCard';

export type PartnerBlock = { category: { slug: string; name: string }; partners: PartnerCardData[]; total: number; href: string };

/** "Local partners" on a city page: a few advertisers per category in today's rotation, then a link to the rest. */
export function LocalPartners({ blocks, note, city, cityId }: { blocks: PartnerBlock[]; note: string; city: string; cityId: number }) {
    if (blocks.length === 0) return null;
    return (
        <section aria-labelledby="local-partners" className="mt-10 border-t border-line pt-8">
            <h2 id="local-partners" className="font-display text-xl font-bold">
                Local partners in {city}
            </h2>
            <AdNote note={note} />
            {blocks.map((b) => (
                <div key={b.category.slug} className="mt-5">
                    <h3 className="font-semibold">{b.category.name}</h3>
                    <div className="mt-2 grid gap-3">
                        {b.partners.map((p) => (
                            <PartnerCard key={p.slug} partner={p} cityId={cityId} />
                        ))}
                    </div>
                    {b.total > b.partners.length && (
                        <Link href={b.href} className="mt-2 inline-block text-sm text-primary">
                            See all {b.total} {b.category.name.toLowerCase()} in {city}
                        </Link>
                    )}
                </div>
            ))}
            <p className="mt-5 text-sm text-muted">
                Serve home buyers and sellers here?{' '}
                <Link href="/partners/advertise" className="text-primary">
                    Advertise with us
                </Link>
            </p>
        </section>
    );
}
