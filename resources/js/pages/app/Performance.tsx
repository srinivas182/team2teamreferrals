import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarOff, Lightbulb, Trash2 } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Part = { label: string; tip: string; points: number | null; done: number; of: number };
type Score = { score: number | null; referrals: number; parts: Record<string, Part>; from: string; to: string };
type CardT = {
    period: string;
    score: number | null;
    data: { received: number; accepted: number; won: number; sent: number; avg_accept_minutes: number | null; tips: string[] };
};
type Ch = {
    id: number;
    status: string;
    territory: string;
    holder: string;
    challenger: string;
    isHolder: boolean;
    threshold: number;
    scoreAtOpen: number;
    windowEnds: string;
    appealUntil: string | null;
    choice: string;
};
type Props = {
    trust?: {
        score: number | null;
        status: string;
        parts: Record<string, { label: string; tip: string; points: number | null; items: number }>;
    } | null;
    challenges: Ch[];
    isLeader: boolean;
    score: Score;
    tips: string[];
    minReferrals: number;
    windowDays: number;
    cards: CardT[];
    timeOff: { id: number; from: string; to: string; reason: string | null }[];
    canEdit: boolean;
    status?: string;
    seo: Seo;
};

/** The service score, fully explained: every part, the numbers behind it, and what would help most. */
export default function Performance({
    trust = null,
    challenges = [],
    isLeader = false,
    score,
    tips,
    minReferrals,
    windowDays,
    cards,
    timeOff,
    canEdit,
    status,
    seo,
}: Props) {
    const { auth } = usePage<SharedProps>().props;
    const off = useForm({ from: '', to: '', reason: '' });
    const s = score.score;
    return (
        <PortalLayout title="Performance" user={{ name: auth.user?.name ?? '', team: '' }}>
            {trust && (
                <div className="mb-6 max-w-5xl rounded-lg border border-line bg-surface p-5">
                    <h2 className="font-semibold">Trust score: {trust.status === 'new' ? 'New' : trust.score}</h2>
                    <p className="text-sm text-muted">
                        What other members see on your profile. Last 12 months, recent activity counting more; updated nightly.
                        {trust.status === 'new' ? ' You’ll get a score after 3 accepted referrals.' : ''}
                    </p>
                    <ul className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                        {Object.entries(trust.parts).map(([k, p]) => (
                            <li key={k}>
                                <span className="font-medium">{p.label}: </span>
                                {p.points === null ? <span className="text-muted">not enough yet ({p.items} of 3)</span> : `${p.points}`}
                                {p.points !== null && p.points < 80 && <span className="block text-xs text-muted">{p.tip}</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-5xl">
                    {statusMessage(status)}
                </Alert>
            )}
            {challenges.map((c) => (
                <ChallengeCard key={c.id} c={c} current={s} canAct={isLeader} />
            ))}
            <div className="grid max-w-5xl gap-6 lg:grid-cols-[1.3fr_1fr]">
                <Card className="p-6">
                    <div className="flex items-end gap-3">
                        <div>
                            <h2 className="font-semibold">Service score</h2>
                            <p className="text-xs text-muted">
                                Last {windowDays} days · {score.referrals} referral{score.referrals === 1 ? '' : 's'}
                            </p>
                        </div>
                        <div className="ml-auto font-display text-5xl font-extrabold tabular">{s ?? '—'}</div>
                        <div className="pb-1.5 text-sm text-muted">/ 100</div>
                    </div>
                    {s === null && (
                        <p className="mt-3 text-sm text-muted">
                            Your score appears after {minReferrals} referrals. A quiet period never counts against you.
                        </p>
                    )}
                    <ul className="mt-5 space-y-4">
                        {Object.entries(score.parts).map(([k, p]) => (
                            <li key={k}>
                                <div className="flex justify-between text-sm">
                                    <span className="font-medium">{p.label}</span>
                                    <span className="text-muted tabular">
                                        {p.points === null ? 'nothing to count yet' : `${p.points} / 25 · ${p.done} of ${p.of}`}
                                    </span>
                                </div>
                                <div className="mt-1 h-2 rounded-full bg-bg">
                                    <div className="h-2 rounded-full bg-primary" style={{ width: `${((p.points ?? 0) / 25) * 100}%` }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                    {tips.length > 0 && (
                        <div className="mt-5 rounded-md bg-bg p-4 text-sm">
                            <div className="mb-1 flex items-center gap-2 font-semibold">
                                <Lightbulb className="size-4 text-orange-500" aria-hidden /> What would help most
                            </div>
                            <ul className="list-disc space-y-1 pl-5">
                                {tips.map((t) => (
                                    <li key={t}>{t}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <p className="mt-4 text-xs text-muted">
                        Four equal parts over the last {windowDays} days; a part with nothing to count yet is left out. Referrals during approved time
                        off aren’t counted.
                    </p>
                </Card>
                <div className="space-y-6">
                    <Card className="space-y-3 p-6">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <CalendarOff className="size-5" aria-hidden /> Time off
                        </h2>
                        <p className="text-sm text-muted">While you’re away, new referrals go to your backup and your score is paused.</p>
                        <ul className="space-y-2 text-sm">
                            {timeOff.map((t) => (
                                <li key={t.id} className="flex items-center gap-2">
                                    <span className="flex-1">
                                        {t.from} → {t.to}
                                        {t.reason ? ` · ${t.reason}` : ''}
                                    </span>
                                    {canEdit && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Remove time off"
                                            onClick={() => router.delete(`/app/performance/time-off/${t.id}`, { preserveScroll: true })}
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {canEdit && (
                            <>
                                <div className="grid grid-cols-2 gap-2">
                                    <Field label="From" error={off.errors.from}>
                                        <Input type="date" value={off.data.from} onChange={(e) => off.setData('from', e.target.value)} />
                                    </Field>
                                    <Field label="To" error={off.errors.to}>
                                        <Input type="date" value={off.data.to} onChange={(e) => off.setData('to', e.target.value)} />
                                    </Field>
                                </div>
                                <Input
                                    aria-label="Reason (optional)"
                                    placeholder="Reason (optional), e.g. family holiday"
                                    value={off.data.reason}
                                    onChange={(e) => off.setData('reason', e.target.value)}
                                />
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    disabled={!off.data.from || !off.data.to}
                                    loading={off.processing}
                                    onClick={() => off.post('/app/performance/time-off', { preserveScroll: true, onSuccess: () => off.reset() })}
                                >
                                    Add time off
                                </Button>
                            </>
                        )}
                    </Card>
                    <Card className="p-6">
                        <h2 className="font-semibold">Report cards</h2>
                        {cards.length === 0 && <p className="mt-2 text-sm text-muted">Your first report card arrives on the 1st of next month.</p>}
                        <ul className="mt-3 divide-y divide-line text-sm">
                            {cards.map((c) => (
                                <li key={c.period} className="py-2.5">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {new Date(c.period + '-01T00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}
                                        </span>
                                        {c.score !== null && <Badge tone={c.score >= 75 ? 'success' : 'warning'}>{c.score}</Badge>}
                                    </div>
                                    <div className="text-muted">
                                        {c.data.received} received · {c.data.accepted} accepted · {c.data.won} won · {c.data.sent} sent
                                        {c.data.avg_accept_minutes !== null ? ` · accepts in ~${c.data.avg_accept_minutes} min` : ''}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>
        </PortalLayout>
    );
}

const CHOICES: Record<string, string> = {
    backup: 'Become the backup (if the slot is free)',
    waitlist: 'Join the waitlist',
    leave: 'Leave this seat',
};

function ChallengeCard({ c, current, canAct }: { c: Ch; current: number | null; canAct: boolean }) {
    const [reason, setReason] = useState('');
    const until = new Date(c.windowEnds.replace(' ', 'T') + 'Z').toLocaleDateString();
    return (
        <Card className="mb-6 max-w-5xl space-y-3 border-warning p-6">
            <h2 className="font-semibold">{c.isHolder ? `Your ${c.territory} seat is being challenged` : `You’re challenging for ${c.territory}`}</h2>
            {c.status === 'open' && (
                <p className="text-sm">
                    {c.isHolder
                        ? `Reach a service score of ${c.threshold} by ${until} to keep the seat. You’re at ${current ?? '—'} now.`
                        : `${c.holder} has until ${until} to reach ${c.threshold}. If they don’t, the seat is offered to you.`}
                </p>
            )}
            {c.status === 'pending' && (
                <p className="text-sm">
                    The score stayed below {c.threshold}. The seat passes to {c.isHolder ? c.challenger : 'you'} on{' '}
                    {c.appealUntil ? new Date(c.appealUntil.replace(' ', 'T') + 'Z').toLocaleDateString() : ''}
                    {c.isHolder ? ' unless you appeal to the group owner before then.' : ', unless the holder appeals.'}
                </p>
            )}
            {c.status === 'appealed' && <p className="text-sm">An appeal is with the group owner. The seat stays where it is until they decide.</p>}
            {c.isHolder && canAct && (
                <div className="space-y-2">
                    <label className="flex flex-wrap items-center gap-2 text-sm">
                        If the seat passes on:
                        <Select
                            aria-label="If the seat passes on"
                            className="h-9 w-72"
                            value={c.choice}
                            onChange={(e) => router.post(`/app/challenges/${c.id}/choice`, { choice: e.target.value }, { preserveScroll: true })}
                        >
                            {Object.entries(CHOICES).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </Select>
                    </label>
                    {c.status === 'pending' && (
                        <div className="flex flex-wrap gap-2">
                            <Input
                                aria-label="Why should the seat stay with you?"
                                placeholder="Why should the seat stay with you? (the group owner decides)"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                className="min-w-72 flex-1"
                            />
                            <Button
                                variant="secondary"
                                disabled={reason.trim().length < 10}
                                onClick={() => router.post(`/app/challenges/${c.id}/appeal`, { reason, choice: c.choice }, { preserveScroll: true })}
                            >
                                Appeal
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
}
