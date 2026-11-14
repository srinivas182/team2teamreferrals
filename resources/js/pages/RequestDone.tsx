import { Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    status: string;
    first: string;
    pro: { name: string; slug: string | null } | null;
    respondBy: string | null;
    portal: string | null;
    seo: Seo;
};

/** The confirmation: who has the request, when they'll answer, and the link to follow it. */
export default function RequestDone({ status, first, pro, portal, seo }: Props) {
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-xl px-4 py-16">
                <CheckCircle2 className="size-10 text-success" aria-hidden />
                {status === 'duplicate' ? (
                    <>
                        <h1 className="mt-3 font-display text-3xl font-bold">We already have your request</h1>
                        <p className="mt-3">
                            You asked this network recently, so we haven’t sent it twice. Check your texts for the link to follow it.
                        </p>
                    </>
                ) : status === 'board' ? (
                    <>
                        <h1 className="mt-3 font-display text-3xl font-bold">Thanks, {first}</h1>
                        <p className="mt-3">We’re finding a verified pro for your area. We’ll text you as soon as one takes your request.</p>
                    </>
                ) : (
                    <>
                        <h1 className="mt-3 font-display text-3xl font-bold">Your request is on its way, {first}</h1>
                        {pro && (
                            <p className="mt-3">
                                It went to{' '}
                                {pro.slug ? (
                                    <Link href={`/t/${pro.slug}`} className="text-primary">
                                        {pro.name}
                                    </Link>
                                ) : (
                                    pro.name
                                )}
                                , the verified pro for your area. They’ll be in touch the way you asked. If they can’t help in time, their backup gets
                                it.
                            </p>
                        )}
                        {portal && (
                            <Button asChild className="mt-6">
                                <a href={portal}>Follow your request</a>
                            </Button>
                        )}
                        <p className="mt-3 text-sm text-muted">We also texted you this link. Reply STOP at any time to stop texts.</p>
                    </>
                )}
            </section>
        </PublicLayout>
    );
}
