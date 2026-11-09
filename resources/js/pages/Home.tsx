import { SeoHead } from '@/components/seo/SeoHead';
import type { Seo } from '@/types/seo';

/**
 * Sprint 3a placeholder. The approved home page design from the prototype
 * is rebuilt here on the Sprint 3b design system.
 */
export default function Home({ seo }: { seo: Seo }) {
    return (
        <>
            <SeoHead seo={seo} />
            <main className="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-6 py-20">
                <h1 className="text-4xl font-extrabold tracking-tight text-balance sm:text-6xl">
                    Your out-of-area leads, handed to teams you trust.
                </h1>
                <p className="mt-6 text-lg text-pretty opacity-80">
                    When a buyer calls about a city you don’t serve, send them to a verified team there in under a minute. Track every step, and
                    collect your referral fee when they close.
                </p>
            </main>
        </>
    );
}
