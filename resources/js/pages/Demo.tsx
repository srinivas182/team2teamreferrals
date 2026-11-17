import { router } from '@inertiajs/react';
import { Building2, Users } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

/** The live demo's front door (only on a demo install): pick who to be. */
export default function Demo({ resetsAt, seo }: { resetsAt: string; seo: Seo }) {
    const time = new Date(resetsAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    return (
        <PublicLayout current="/">
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-3xl px-4 py-14">
                <h1 className="font-display text-4xl font-bold">Explore the live demo</h1>
                <p className="mt-4 text-lg">
                    This is the real product with sample teams, referrals and networks. No sign-up. Nothing you do sends an email or a text, and
                    everything resets at {time}.
                </p>
                <div className="mt-8 grid gap-4 sm:grid-cols-2">
                    {(
                        [
                            ['team', Users, 'A team', 'Send and receive referrals, update the pipeline and see your dashboard.'],
                            ['owner', Building2, 'A network owner', 'See your networks, members, seats and revenue.'],
                        ] as const
                    ).map(([as, Icon, title, body]) => (
                        <Card key={as} className="p-6">
                            <Icon className="size-7 text-primary" aria-hidden />
                            <h2 className="mt-3 font-display text-xl font-bold">{title}</h2>
                            <p className="mt-2 text-sm text-muted">{body}</p>
                            <button
                                type="button"
                                onClick={() => router.post('/demo/enter', { as })}
                                className="mt-4 inline-flex h-11 items-center rounded-md bg-primary px-4 font-medium text-on-primary hover:bg-primary-hover"
                            >
                                Enter as {title.toLowerCase()}
                            </button>
                        </Card>
                    ))}
                </div>
            </div>
        </PublicLayout>
    );
}
