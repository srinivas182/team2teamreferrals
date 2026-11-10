import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Share = { business: string; territory: string; industry: string; group: string; shareId: string; invited: number };

/** What people see when a founding member shares their link; it also works as that member's invite. */
export default function ReserveShare({ share, seo }: { share: Share; seo: Seo }) {
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-3xl px-5 py-14 text-center">
                <img
                    src={`/reserve/c/${share.shareId}/og.png`}
                    alt={`${share.business} reserved ${share.territory}`}
                    className="mx-auto w-full rounded-xl shadow-lg"
                />
                <h1 className="mt-8 font-display text-3xl font-extrabold">
                    {share.business} reserved {share.territory}
                </h1>
                <p className="mt-2 text-muted">
                    A founding member of {share.group}. One verified {share.industry.toLowerCase()} pro per territory, passing tracked referrals
                    across the country.
                </p>
                <Button size="lg" className="mt-6" asChild>
                    <Link href={`/reserve?ref=${share.shareId}`}>
                        Reserve your city <ArrowRight />
                    </Link>
                </Button>
            </section>
        </PublicLayout>
    );
}
