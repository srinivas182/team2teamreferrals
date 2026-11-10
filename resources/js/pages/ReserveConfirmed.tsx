import { Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Copy } from 'lucide-react';
import { useState } from 'react';
import { ShareCards } from '@/components/prelaunch/ShareCard';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Share = { business: string; territory: string; industry: string; group: string; shareId: string; invited: number };

export default function ReserveConfirmed({
    token,
    email,
    status,
    signedIn,
    share,
    seo,
}: {
    token: string;
    email: string;
    status: string;
    signedIn: boolean;
    share: Share | null;
    seo: Seo;
}) {
    const [copied, setCopied] = useState(false);
    // Server-side rendering has no window; the link fills in on the client.
    const link =
        share && typeof window !== 'undefined' ? `${window.location.origin}/reserve/c/${share.shareId}` : share ? `/reserve/c/${share.shareId}` : '';
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-3xl px-5 py-16">
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
                {share && (
                    <Card className="mt-6 space-y-4 p-8">
                        <h2 className="font-display text-xl font-bold">Tell your clients and peers</h2>
                        <p className="text-sm text-muted">
                            Share your link. Every pro who reserves through it and gets their license verified moves you up waitlists
                            {share.invited ? ` (${share.invited} so far)` : ''}.
                        </p>
                        <div className="flex gap-2">
                            <input
                                readOnly
                                value={link}
                                aria-label="Your share link"
                                className="h-10 min-w-0 flex-1 rounded-md border border-line bg-bg px-3 text-sm"
                            />
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    void navigator.clipboard.writeText(link);
                                    setCopied(true);
                                }}
                            >
                                <Copy /> {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                        <div className="flex flex-wrap gap-2 text-sm">
                            <a
                                className="font-medium text-primary"
                                href={`https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(link)}`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Share on LinkedIn
                            </a>
                            <a
                                className="font-medium text-primary"
                                href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(link)}`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Facebook
                            </a>
                            <a
                                className="font-medium text-primary"
                                href={`https://x.com/intent/post?url=${encodeURIComponent(link)}&text=${encodeURIComponent(`We reserved ${share.territory} on Team to Team Referrals.`)}`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                X
                            </a>
                        </div>
                        <ShareCards business={share.business} territory={share.territory} group={share.group} />
                    </Card>
                )}
            </section>
        </PublicLayout>
    );
}
