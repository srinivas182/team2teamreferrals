import { Link } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Logo } from '@/components/brand/Logo';
import { Button } from '@/components/ui/button';
import type { Seo } from '@/types/seo';

const copy: Record<number, { title: string; body: string; action: [string, string] }> = {
    403: {
        title: 'You don’t have access to this page',
        body: 'If you think you should, ask your group owner or team leader to check your permissions.',
        action: ['Go to your dashboard', '/app'],
    },
    404: {
        title: 'We can’t find that page',
        body: 'The link may be old, or the page may have moved. Try the group directory, or search for a city.',
        action: ['Browse groups', '/groups'],
    },
    410: {
        title: 'This page has been removed',
        body: 'It is no longer on Team to Team Referrals. Find a verified pro in the group directory instead.',
        action: ['Browse groups', '/groups'],
    },
    419: {
        title: 'Your session expired',
        body: 'For your security we sign you out after a period of inactivity. Refresh the page and try again.',
        action: ['Refresh', ''],
    },
    500: {
        title: 'Something went wrong on our side',
        body: 'We’ve been alerted and are looking into it. Your referrals and data are safe.',
        action: ['Back to home', '/'],
    },
    503: {
        title: 'We’ll be right back',
        body: 'Team to Team is being updated. This usually takes a few minutes, and nothing you’ve sent will be lost.',
        action: ['Try again', ''],
    },
};

export default function Error({ status, seo, requestId }: { status: number; seo: Seo; requestId?: string | null }) {
    const c = copy[status] ?? copy[500]!;
    const [label, href] = c.action;
    return (
        <>
            <SeoHead seo={seo} />
            <main className="flex min-h-screen flex-col items-center justify-center px-6 py-16 text-center">
                <Link href="/" aria-label="Team to Team Referrals home">
                    <Logo className="h-9 w-[150px]" />
                </Link>
                <p className="mt-12 font-display text-sm font-semibold text-primary tabular">Error {status}</p>
                <h1 className="mt-3 max-w-xl font-display text-3xl font-extrabold display sm:text-4xl">{c.title}</h1>
                <p className="mt-4 max-w-md text-muted">{c.body}</p>
                <div className="mt-8 flex flex-wrap justify-center gap-3">
                    {href ? (
                        <Button asChild>
                            <Link href={href}>{label}</Link>
                        </Button>
                    ) : (
                        <Button onClick={() => window.location.reload()}>{label}</Button>
                    )}
                    {status !== 503 && (
                        <Button variant="secondary" asChild>
                            <a href="mailto:support@teamtoteamreferrals.com">Contact support</a>
                        </Button>
                    )}
                </div>
                {requestId && (
                    <p className="mt-10 text-xs text-subtle">
                        Reference <span className="font-mono tabular select-all">{requestId}</span>. Quote it if you contact support.
                    </p>
                )}
            </main>
        </>
    );
}
