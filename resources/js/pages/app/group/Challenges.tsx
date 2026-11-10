import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type C = {
    id: number;
    status: string;
    territory: string;
    holder: string;
    challenger: string;
    scoreAtOpen: number;
    scoreAtEnd: number | null;
    windowEnds: string;
    appeal: string | null;
    note: string | null;
};
type Props = {
    group: { slug: string; name: string; threshold: number; days: number };
    atRisk: { territory: string; holder: string; score: number | null; daysBelow: number }[];
    challenges: C[];
    isAdmin: boolean;
    status?: string;
    seo: Seo;
};

const tone: Record<string, 'warning' | 'success' | 'neutral' | 'danger' | 'info'> = {
    open: 'warning',
    pending: 'warning',
    appealed: 'info',
    recovered: 'success',
    overturned: 'success',
    transferred: 'neutral',
    cancelled: 'neutral',
};

function Decide({ c, isAdmin }: { c: C; isAdmin: boolean }) {
    const [note, setNote] = useState('');
    const post = (url: string, data: Record<string, string | boolean>) => router.post(url, data, { preserveScroll: true });
    return (
        <div className="mt-3 flex flex-wrap gap-2">
            <Input
                aria-label="Decision note"
                placeholder="Your reasons (both teams see them)"
                value={note}
                onChange={(e) => setNote(e.target.value)}
                className="min-w-72 flex-1"
            />
            {(c.status === 'appealed' || isAdmin) && (
                <>
                    <Button size="sm" disabled={!note.trim()} onClick={() => post(`/app/challenges/${c.id}/decide`, { uphold: false, note })}>
                        Keep with holder
                    </Button>
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={!note.trim()}
                        onClick={() => post(`/app/challenges/${c.id}/decide`, { uphold: true, note })}
                    >
                        Move to challenger
                    </Button>
                </>
            )}
            {isAdmin && (
                <Button size="sm" variant="ghost" disabled={!note.trim()} onClick={() => post(`/app/challenges/${c.id}/cancel`, { note })}>
                    Cancel challenge
                </Button>
            )}
        </div>
    );
}

/** Group owners and admins: seats at risk, open challenges, appeals to decide, and outcomes. */
export default function GroupChallenges({ group, atRisk, challenges, isAdmin, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const active = challenges.filter((c) => ['open', 'pending', 'appealed'].includes(c.status));
    return (
        <PortalLayout title={`${group.name}: seat challenges`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <p className="mb-4 max-w-4xl text-sm text-muted">
                A holder can be challenged after {group.days} days below a service score of {group.threshold} (first 90 days exempt, at least 5
                referrals, time off paused). They get 30 days to recover, and 7 days to appeal to you.
            </p>
            <Card className="mb-6 max-w-4xl p-6">
                <h2 className="font-semibold">Seats at risk</h2>
                {atRisk.length === 0 && <p className="mt-2 text-sm text-muted">No seats are below the threshold.</p>}
                <ul className="mt-2 divide-y divide-line text-sm">
                    {atRisk.map((s) => (
                        <li key={s.territory} className="flex justify-between py-2">
                            <span>
                                {s.territory} · {s.holder}
                            </span>
                            <span className="text-muted tabular">
                                score {s.score ?? '—'} · {s.daysBelow} days below
                            </span>
                        </li>
                    ))}
                </ul>
            </Card>
            <div className="max-w-4xl space-y-3">
                {challenges.map((c) => (
                    <Card key={c.id} className="p-5">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-semibold">{c.territory}</span>
                            <Badge tone={tone[c.status] ?? 'neutral'}>{c.status}</Badge>
                            <span className="text-sm text-muted">
                                {c.holder} (holder) vs {c.challenger} · score {c.scoreAtOpen}
                                {c.scoreAtEnd !== null ? ` → ${c.scoreAtEnd}` : ''}
                            </span>
                        </div>
                        {c.appeal && <p className="mt-2 text-sm">Appeal: “{c.appeal}”</p>}
                        {c.note && <p className="mt-1 text-sm text-muted">Decision: {c.note}</p>}
                        {active.includes(c) && <Decide c={c} isAdmin={isAdmin} />}
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
