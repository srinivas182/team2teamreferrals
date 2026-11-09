import { Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

export default function ReserveConfirmed({
    token,
    email,
    status,
    signedIn,
    seo,
}: {
    token: string;
    email: string;
    status: string;
    signedIn: boolean;
    seo: Seo;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-xl px-5 py-16">
                <Card className="p-8">
                    <CheckCircle2 className="size-10 text-success" aria-hidden />
                    <h1 className="mt-3 font-display text-3xl font-extrabold">
                        {status === 'claimed' ? 'Already claimed' : 'Reservation confirmed'}
                    </h1>
                    <p className="mt-2 text-muted">
                        {status === 'claimed'
                            ? 'You’ve already turned this reservation into your founding application.'
                            : 'Last step: create your account (or sign in) to lock it in as a founding member.'}
                    </p>
                    {errors.reservation && (
                        <Alert tone="danger" className="mt-4">
                            {errors.reservation}
                        </Alert>
                    )}
                    {status !== 'claimed' && (
                        <div className="mt-6">
                            {signedIn ? (
                                <Button size="lg" className="w-full" onClick={() => router.post(`/reserve/claim/${token}`)}>
                                    Lock in my founding seat
                                </Button>
                            ) : (
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <Button size="lg" asChild>
                                        <Link href={`/register?email=${encodeURIComponent(email)}`}>Create account</Link>
                                    </Button>
                                    <Button size="lg" variant="secondary" asChild>
                                        <Link href="/login">Sign in</Link>
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}
                </Card>
            </section>
        </PublicLayout>
    );
}
