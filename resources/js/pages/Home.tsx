import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

/**
 * Home page shell on the real design system. The full approved home page from the
 * prototype (relay hero, accountability, network stats…) is built in Sprint 36.
 */
export default function Home({ seo }: { seo: Seo }) {
    return (
        <PublicLayout current="/">
            <SeoHead seo={seo} />
            <section className="border-b border-line bg-surface">
                <div className="mx-auto max-w-content px-5 py-16 sm:px-8 sm:py-24">
                    <h1 className="max-w-3xl font-display text-[40px] leading-[1.08] font-extrabold display sm:text-[52px] lg:text-[60px]">
                        Your out-of-area leads, handed to teams you trust.
                    </h1>
                    <p className="mt-6 max-w-xl text-lg text-muted">
                        When a buyer calls about a city you don’t serve, send them to a verified team there in under a minute. Track every step, and
                        collect your referral fee when they close.
                    </p>
                    <div className="mt-8 flex flex-wrap gap-3">
                        <Button size="lg" asChild>
                            <Link href="/register">
                                Claim your city free <ArrowRight />
                            </Link>
                        </Button>
                        <Button size="lg" variant="secondary" asChild>
                            <Link href="/how-it-works">See how it works</Link>
                        </Button>
                    </div>
                    <p className="mt-6 text-sm text-muted">
                        Free during the pilot. Verified licenses only. We never take a share of your commission.
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
