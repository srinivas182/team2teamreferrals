import { router } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

/** The client confirms they want to be contacted (a button, so link previews can't confirm for them). */
export default function ClientConsent({
    request: r,
    seo,
}: {
    request: { sender: string; territory: string; pro: string; firstName: string; token: string } | null;
    seo: Seo;
}) {
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-md px-5 py-14">
                {!r ? (
                    <Alert tone="info">
                        This link has already been used or has expired. If you have questions, contact the person who referred you.
                    </Alert>
                ) : (
                    <Card className="space-y-4 p-6">
                        <h1 className="font-display text-2xl font-extrabold">Hi {r.firstName},</h1>
                        <p>
                            {r.sender} would like to connect you with a verified {r.pro} in {r.territory}. Is it OK for them to contact you?
                        </p>
                        <Button size="lg" className="w-full" onClick={() => router.post(`/c/${r.token}`)}>
                            Yes, they can contact me
                        </Button>
                        <p className="text-xs text-muted">
                            Your details are shared only with that {r.pro}, never publicly. You can reply STOP to any text to opt out.
                        </p>
                    </Card>
                )}
            </section>
        </PublicLayout>
    );
}
