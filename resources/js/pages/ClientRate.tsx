import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Stars } from '@/components/referrals/ClosingPanel';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

/** One optional question for the client, from their email or text. */
export default function ClientRate({ pro, token, seo }: { pro: string | null; token: string | null; seo: Seo }) {
    const { flash } = usePage<{ flash: { status?: string } }>().props;
    const [stars, setStars] = useState(0);
    const [comment, setComment] = useState('');
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-md px-5 py-14">
                {flash?.status === 'client-rated' ? (
                    <Alert tone="success">Thank you! Your rating helps other people choose well.</Alert>
                ) : !token ? (
                    <Alert tone="info">This link has already been used or has expired.</Alert>
                ) : (
                    <Card className="space-y-4 p-6">
                        <h1 className="font-display text-2xl font-extrabold">How was your experience with {pro}?</h1>
                        <Stars value={stars} onChange={setStars} label="Your rating" />
                        <Input
                            aria-label="Anything to add? (optional)"
                            placeholder="Anything to add? (optional)"
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                        />
                        <Button className="w-full" disabled={stars === 0} onClick={() => router.post(`/rate/${token}`, { stars, comment })}>
                            Send rating
                        </Button>
                    </Card>
                )}
            </section>
        </PublicLayout>
    );
}
