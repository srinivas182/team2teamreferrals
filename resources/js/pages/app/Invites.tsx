import { usePage } from '@inertiajs/react';
import { Copy, Trophy } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    link: string;
    signups: { name: string; status: string; reward: number; joined: string }[];
    leaderboard: { team: string; invites: number; me: boolean }[];
    rules: { months: number; cap: number };
    seo: Seo;
};

const statusWords: Record<string, [string, 'neutral' | 'success' | 'info']> = {
    joined: ['Joined: waiting for licence check and a first referral', 'neutral'],
    rewarded: ['Up and running: free month added', 'success'],
    badge: ['Up and running: counts towards your badge', 'info'],
    capped: ['Up and running: yearly reward limit reached', 'info'],
};

/** Portal → Invites: your invite link, who joined through it, and the network leaderboard. */
export default function Invites({ link, signups, leaderboard, rules, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [copied, setCopied] = useState(false);
    return (
        <PortalLayout title="Invites" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="grid max-w-5xl gap-6 lg:grid-cols-[1.4fr_1fr]">
                <div className="space-y-6">
                    <Card className="space-y-3 p-6">
                        <h2 className="font-semibold">Your invite link</h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <code className="rounded bg-bg px-2 py-1 text-sm">{link}</code>
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => void navigator.clipboard.writeText(link).then(() => setCopied(true))}
                            >
                                <Copy /> {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                        <p className="text-sm text-muted">
                            Earn {rules.months} free month{rules.months === 1 ? '' : 's'} for each pro who joins through your link, once their licence
                            is verified and they accept their first referral (up to {rules.cap} a year). In restricted industries, invites count
                            towards your Network builder badge instead.
                        </p>
                    </Card>
                    <Card className="p-6">
                        <h2 className="mb-2 font-semibold">People you invited</h2>
                        {signups.length === 0 && (
                            <p className="text-sm text-muted">Nobody yet. Share your link with pros you trust in other cities.</p>
                        )}
                        <ul className="divide-y divide-line text-sm">
                            {signups.map((s, i) => (
                                <li key={i} className="flex flex-wrap items-center gap-2 py-2.5">
                                    <span className="font-medium">{s.name}</span>
                                    <span className="text-muted">joined {s.joined}</span>
                                    <Badge tone={statusWords[s.status]?.[1] ?? 'neutral'} className="ml-auto">
                                        {statusWords[s.status]?.[0] ?? s.status}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
                <Card className="p-6">
                    <h2 className="mb-2 flex items-center gap-2 font-semibold">
                        <Trophy className="size-5 text-orange-500" aria-hidden /> Network builders this year
                    </h2>
                    {leaderboard.length === 0 && <p className="text-sm text-muted">Be the first on the board.</p>}
                    <ol className="space-y-1.5 text-sm">
                        {leaderboard.map((l, i) => (
                            <li key={l.team} className={`flex justify-between ${l.me ? 'font-semibold text-primary' : ''}`}>
                                <span>
                                    {i + 1}. {l.team}
                                </span>
                                <span className="tabular">{l.invites}</span>
                            </li>
                        ))}
                    </ol>
                </Card>
            </div>
        </PortalLayout>
    );
}
