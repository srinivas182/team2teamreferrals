import { Link, usePage } from '@inertiajs/react';
import { Send, ShieldCheck } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Item = { label: string; done: boolean; href: string | null };
type Strength = { score: number; items: { label: string; points: number; done: boolean }[] };

export default function Home({ checklist, strength, seo }: { checklist: Item[]; strength: Strength; seo: Seo }) {
    const next = checklist.find((i) => !i.done);
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
                            <h2 className="text-lg font-semibold">{next ? 'Let’s get you to your first seat' : 'You’re all set up'}</h2>
                            <p className="text-muted">
                                {done} of {checklist.length} steps done.
                                {next?.href && (
                                    <>
                                        {' '}
                                        Next:{' '}
                                        <Link href={next.href} className="font-medium text-primary">
                                            {next.label}
                                        </Link>
                                    </>
                                )}
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
                                    {i.href && !i.done ? (
                                        <Link href={i.href} className="font-medium text-primary hover:underline">
                                            {i.label}
                                        </Link>
                                    ) : (
                                        <span className={cn('font-medium', i.done && 'text-muted line-through')}>{i.label}</span>
                                    )}
                                    <span className="sr-only">{i.done ? '(done)' : '(to do)'}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card className="p-6">
                        <div className="flex items-center gap-3">
                            <h2 className="font-semibold">Profile strength</h2>
                            <span className="ml-auto font-display text-2xl font-bold tabular">{strength.score}</span>
                            <span className="text-sm text-muted">/ 100</span>
                        </div>
                        <div className="mt-3 h-2 rounded-full bg-bg">
                            <div className="h-2 rounded-full bg-primary" style={{ width: `${strength.score}%` }} />
                        </div>
                        <p className="mt-3 text-sm text-muted">
                            A stronger profile moves you up waitlists and helps other pros trust you with their clients.
                        </p>
                        <ul className="mt-3 space-y-1.5 text-sm">
                            {strength.items
                                .filter((x) => !x.done)
                                .map((x) => (
                                    <li key={x.label} className="flex justify-between gap-3">
                                        <span>{x.label}</span>
                                        <span className="text-muted tabular">+{x.points}</span>
                                    </li>
                                ))}
                        </ul>
                        <div className="mt-4 flex gap-3 text-sm">
                            <Link href="/app/team" className="font-medium text-primary">
                                Edit team profile
                            </Link>
                            <Link href="/app/team/preview" className="font-medium text-primary">
                                Preview public profile
                            </Link>
                        </div>
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
