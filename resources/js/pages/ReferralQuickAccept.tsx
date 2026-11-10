import { router } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type R = { client: string; territory: string; sender: string; group: string; respondBy: string; action: string; view: string };

/** One tap from the email or text: confirm, and it's accepted. */
export default function ReferralQuickAccept({ referral: r, seo }: { referral: R | null; seo: Seo }) {
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-md px-5 py-14">
                {!r ? (
                    <Alert tone="info">
                        This referral is no longer waiting for you. It may have been accepted, declined, or passed on after the deadline.
                    </Alert>
                ) : (
                    <Card className="space-y-4 p-6">
                        <h1 className="font-display text-2xl font-extrabold">
                            {r.client} · {r.territory}
                        </h1>
                        <p className="text-muted">
                            From {r.sender} in {r.group}. Decide by {new Date(r.respondBy).toLocaleString()}.
                        </p>
                        <Button size="lg" className="w-full" onClick={() => router.post(r.action)}>
                            Accept referral
                        </Button>
                        <a href={r.view} className="block text-center text-sm font-medium text-primary">
                            See the details first, or decline
                        </a>
                    </Card>
                )}
            </section>
        </PublicLayout>
    );
}
