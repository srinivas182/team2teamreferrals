import { Link, usePage } from '@inertiajs/react';
import { Send, ShieldCheck } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Item = { label: string; done: boolean };

export default function Home({ checklist, seo }: { checklist: Item[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    const done = checklist.filter((i) => i.done).length;
    const r = 30;
    const c = 2 * Math.PI * r;
    return (
        <PortalLayout title={`Welcome, ${auth.user?.firstName ?? ''}`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
                <div className="space-y-6">
                    <Card className="flex flex-col gap-5 p-6 sm:flex-row sm:items-center">
                        <svg width="76" height="76" className="shrink-0 -rotate-90" aria-hidden>
                            <circle cx="38" cy="38" r={r} fill="none" stroke="var(--t-border)" strokeWidth="7" />
                            <circle
                                cx="38"
                                cy="38"
                                r={r}
                                fill="none"
                                stroke="var(--color-cobalt-500)"
                                strokeWidth="7"
                                strokeLinecap="round"
                                strokeDasharray={`${(c * done) / checklist.length} ${c}`}
                            />
                        </svg>
                        <div>
                            <h2 className="text-lg font-semibold">Let’s get your account ready</h2>
                            <p className="text-muted">
                                {done} of {checklist.length} setup steps done.
                            </p>
                        </div>
                    </Card>
                    <Card>
                        <ul className="divide-y divide-line">
                            {checklist.map((i) => (
                                <li key={i.label} className="flex items-center gap-3 p-4">
                                    <span
                                        className={cn(
                                            'flex size-5 items-center justify-center rounded-full border-2',
                                            i.done ? 'border-success bg-success' : 'border-line',
                                        )}
                                        aria-hidden
                                    />
                                    <span className={cn('font-medium', i.done && 'text-muted line-through')}>{i.label}</span>
                                    <span className="sr-only">{i.done ? '(done)' : '(to do)'}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
                <Card className="h-fit p-6">
                    <ShieldCheck className="text-primary" aria-hidden />
                    <h2 className="mt-3 font-semibold">Protect your account</h2>
                    <p className="mt-1 text-sm text-muted">Add a passkey to sign in with Face ID or your fingerprint, or turn on two-step sign-in.</p>
                    <Button asChild size="sm" className="mt-4">
                        <Link href="/app/settings/security">Security settings</Link>
                    </Button>
                    <div className="mt-6 border-t border-line pt-5">
                        <Send className="text-muted" aria-hidden />
                        <p className="mt-2 text-sm text-muted">Sending referrals unlocks once your license is verified and you join a group.</p>
                    </div>
                </Card>
            </div>
        </PortalLayout>
    );
}
