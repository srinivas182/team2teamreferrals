import { Link } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

/** After a usability test ends. */
export default function UsabilityThanks({ seo }: { seo: Seo }) {
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-2xl px-4 py-16 text-center">
                <h1 className="font-display text-3xl font-bold">Thank you</h1>
                <p className="mt-4 text-lg">
                    The test has ended and nothing more is being recorded. Everything you found goes straight onto our list of things to fix before
                    launch.
                </p>
                <Link href="/" className="mt-6 inline-block text-primary">
                    Back to the home page
                </Link>
            </article>
        </PublicLayout>
    );
}
