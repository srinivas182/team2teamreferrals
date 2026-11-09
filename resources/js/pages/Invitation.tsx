import { Link, router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import { money } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Invite = { token: string; email: string; territory: string; group: string; industry: string; price: number; trial: number; expires: string };

export default function Invitation({ invite, seo }: { invite: Invite | null; seo: Seo }) {
    const { auth, errors } = usePage<SharedProps & { errors: Record<string, string> }>().props;
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-xl px-5 py-16">
                {!invite ? (
                    <Alert tone="info">This invitation has expired or was already used. Ask the group owner for a new one.</Alert>
                ) : (
                    <Card className="p-8">
                        <p className="text-sm font-medium text-primary">{invite.group}</p>
                        <h1 className="mt-1 font-display text-3xl font-extrabold">You’re invited to hold {invite.territory}</h1>
                        <ul className="mt-6 space-y-2 text-sm">
                            {[
                                `One verified ${invite.industry.toLowerCase()} pro per territory`,
                                `${invite.trial} months free, then ${money(invite.price)} a month`,
                                'Send and receive tracked referrals across the country',
                            ].map((t) => (
                                <li key={t} className="flex gap-2">
                                    <Check className="size-4 text-success" aria-hidden />
                                    {t}
                                </li>
                            ))}
                        </ul>
                        {errors.invite && (
                            <Alert tone="danger" className="mt-4">
                                {errors.invite}
                            </Alert>
                        )}
                        <div className="mt-8">
                            {auth.user ? (
                                <Button size="lg" className="w-full" onClick={() => router.post(`/invites/${invite.token}/accept`)}>
                                    Accept and claim {invite.territory}
                                </Button>
                            ) : (
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <Button size="lg" asChild>
                                        <Link href={`/register?email=${encodeURIComponent(invite.email)}&redirect=/invites/${invite.token}`}>
                                            Create account
                                        </Link>
                                    </Button>
                                    <Button size="lg" variant="secondary" asChild>
                                        <Link href="/login">Sign in</Link>
                                    </Button>
                                </div>
                            )}
                            <p className="mt-3 text-center text-xs text-muted">
                                Sent to {invite.email}. Valid until {new Date(invite.expires.replace(' ', 'T') + 'Z').toLocaleDateString()}.
                            </p>
                        </div>
                    </Card>
                )}
            </section>
        </PublicLayout>
    );
}
