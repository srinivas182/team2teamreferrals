import { router } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

/** The unsubscribe page linked from every prospect email. */
export default function GrowthUnsubscribe({ done, action, seo }: { done: boolean; action: string | null; seo: Seo }) {
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-lg px-4 py-16 text-center">
                {done ? (
                    <>
                        <h1 className="font-display text-3xl font-bold">You’re unsubscribed</h1>
                        <p className="mt-3 text-muted">We won’t send you these emails again.</p>
                    </>
                ) : (
                    <>
                        <h1 className="font-display text-3xl font-bold">Unsubscribe?</h1>
                        <p className="mt-3 text-muted">You won’t get any more emails about joining a Team to Team Referrals network.</p>
                        <Button className="mt-6" onClick={() => action && router.post(action)}>
                            Unsubscribe
                        </Button>
                    </>
                )}
            </div>
        </PublicLayout>
    );
}
