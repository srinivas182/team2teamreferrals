import { Link, router, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

export default function TeamInvitation({ invite, seo }: { invite: { token: string; email: string; role: string; team: string } | null; seo: Seo }) {
    const { auth, errors } = usePage<SharedProps & { errors: Record<string, string> }>().props;
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-xl px-5 py-16">
                {!invite ? (
                    <Alert tone="info">This invitation has expired or was already used. Ask the team leader for a new one.</Alert>
                ) : (
                    <Card className="p-8">
                        <h1 className="font-display text-3xl font-extrabold">Join {invite.team}</h1>
                        <p className="mt-2 text-muted">You’re invited as a {invite.role} on Team to Team Referrals.</p>
                        {errors.invite && (
                            <Alert tone="danger" className="mt-4">
                                {errors.invite}
                            </Alert>
                        )}
                        <div className="mt-8">
                            {auth.user ? (
                                <Button size="lg" className="w-full" onClick={() => router.post(`/team-invites/${invite.token}/accept`)}>
                                    Join {invite.team}
                                </Button>
                            ) : (
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <Button size="lg" asChild>
                                        <Link href={`/register?email=${encodeURIComponent(invite.email)}`}>Create account</Link>
                                    </Button>
                                    <Button size="lg" variant="secondary" asChild>
                                        <Link href="/login">Sign in</Link>
                                    </Button>
                                </div>
                            )}
                            <p className="mt-3 text-center text-xs text-muted">Sent to {invite.email}.</p>
                        </div>
                    </Card>
                )}
            </section>
        </PublicLayout>
    );
}
