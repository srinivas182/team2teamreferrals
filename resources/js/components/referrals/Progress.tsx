import { router, usePage } from '@inertiajs/react';
import { Bell, Check, Phone } from 'lucide-react';
import { useState } from 'react';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { cn } from '@/lib/utils';

export type Stage = { key: string; label: string; kind: 'open' | 'won' | 'lost'; client_label?: string | null; limit_days?: number | null };
export type PipelineInfo = {
    stages: Stage[];
    current: string | null;
    canWork: boolean;
    firstContact: string | null;
    firstContactDue: string | null;
    closingDate: string | null;
    closingValue: number | null;
    lostReason: string | null;
    canNudge: boolean;
    nextNudge: string | null;
};

/** Where the referral stands, and (for the receiving team) the one-tap actions to move it on. */
export function Progress({ id, p, clientTerm }: { id: string; p: PipelineInfo; clientTerm: string }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [target, setTarget] = useState<Stage | null>(null);
    const [note, setNote] = useState('');
    const [closing, setClosing] = useState({ date: '', value: '', reason: '' });
    const idx = p.stages.findIndex((s) => s.key === p.current);
    const current = p.stages[idx];
    const closed = current?.kind !== 'open';
    const post = (url: string, data: Record<string, string | number | null>) =>
        router.post(url, data, { preserveScroll: true, onSuccess: () => (setTarget(null), setNote('')) });
    return (
        <Card className="space-y-4 p-6">
            <div className="flex items-center gap-2">
                <h2 className="font-semibold">Progress</h2>
                {current && <Badge tone={current.kind === 'won' ? 'success' : current.kind === 'lost' ? 'neutral' : 'info'}>{current.label}</Badge>}
            </div>
            <ol className="flex flex-wrap gap-1.5" aria-label="Stages">
                {p.stages.map((s, i) => (
                    <li
                        key={s.key}
                        className={cn(
                            'rounded-full px-2.5 py-1 text-xs',
                            i < idx && s.kind === 'open' ? 'bg-primary/15 text-primary' : i === idx ? 'bg-primary text-white' : 'bg-bg text-muted',
                        )}
                    >
                        {i < idx && s.kind === 'open' && <Check className="mr-0.5 inline size-3" aria-hidden />}
                        {s.label}
                    </li>
                ))}
            </ol>
            {!p.firstContact && p.firstContactDue && !closed && (
                <p className="text-sm text-warning">First contact due by {new Date(p.firstContactDue).toLocaleString()}.</p>
            )}
            {p.closingDate && (
                <p className="text-sm">
                    Closed {p.closingDate}
                    {p.closingValue !== null ? ` · value ${p.closingValue.toLocaleString(undefined, { style: 'currency', currency: 'USD' })}` : ''}
                </p>
            )}
            {p.lostReason && <p className="text-sm text-muted">Didn’t go ahead: {p.lostReason}</p>}

            {p.canWork && !closed && (
                <div className="space-y-3 border-t border-line pt-4">
                    <div className="flex flex-wrap gap-2">
                        {!p.firstContact && (
                            <Button onClick={() => post(`/app/referrals/${id}/call`, {})}>
                                <Phone /> Log a call
                            </Button>
                        )}
                        {p.stages
                            .filter((s, i) => i > idx || s.kind !== 'open')
                            .filter((s) => s.key !== p.current)
                            .map((s) => (
                                <Button
                                    key={s.key}
                                    variant={s.kind === 'open' ? 'secondary' : s.kind === 'won' ? 'primary' : 'ghost'}
                                    size="sm"
                                    onClick={() => setTarget(s)}
                                >
                                    {s.kind === 'open' ? `Move to ${s.label}` : s.label}
                                </Button>
                            ))}
                    </div>
                    {target && (
                        <div className="space-y-3 rounded-md border border-line p-4">
                            <p className="font-medium">
                                {target.kind === 'won'
                                    ? `Great news! Close this ${clientTerm} as “${target.label}”.`
                                    : target.kind === 'lost'
                                      ? `Close as “${target.label}”`
                                      : `Move to “${target.label}”`}
                            </p>
                            {target.kind === 'won' && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Closing date" error={errors.closing_date}>
                                        <Input type="date" value={closing.date} onChange={(e) => setClosing({ ...closing, date: e.target.value })} />
                                    </Field>
                                    <Field label="Value (USD)" hint="The sale, contract or job value." error={errors.closing_value}>
                                        <Input
                                            type="number"
                                            min="0"
                                            value={closing.value}
                                            onChange={(e) => setClosing({ ...closing, value: e.target.value })}
                                        />
                                    </Field>
                                </div>
                            )}
                            {target.kind === 'lost' && (
                                <Field label="Why didn’t it go ahead?" hint="The sender sees this." error={errors.lost_reason}>
                                    <Input value={closing.reason} onChange={(e) => setClosing({ ...closing, reason: e.target.value })} />
                                </Field>
                            )}
                            <Field label="Note for the sender (optional)">
                                <Input value={note} onChange={(e) => setNote(e.target.value)} />
                            </Field>
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    disabled={
                                        (target.kind === 'won' && (!closing.date || closing.value === '')) ||
                                        (target.kind === 'lost' && !closing.reason.trim())
                                    }
                                    onClick={() =>
                                        post(`/app/referrals/${id}/stage`, {
                                            stage: target.key,
                                            note,
                                            closing_date: closing.date || null,
                                            closing_value: closing.value || null,
                                            lost_reason: closing.reason || null,
                                        })
                                    }
                                >
                                    Confirm
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setTarget(null)}>
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    )}
                    {!target && (
                        <div className="flex gap-2">
                            <Input
                                aria-label="Progress note"
                                placeholder="Post a progress note for the sender"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                            />
                            <Button variant="secondary" disabled={!note.trim()} onClick={() => post(`/app/referrals/${id}/note`, { note })}>
                                Post
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {!p.canWork && !closed && (
                <div className="border-t border-line pt-4">
                    {errors.nudge && <Alert tone="info">{errors.nudge}</Alert>}
                    <Button variant="secondary" size="sm" disabled={!p.canNudge} onClick={() => post(`/app/referrals/${id}/nudge`, {})}>
                        <Bell />{' '}
                        {p.canNudge
                            ? 'Nudge for an update'
                            : `You can nudge again on ${p.nextNudge ? new Date(p.nextNudge).toLocaleDateString() : ''}`}
                    </Button>
                </div>
            )}
        </Card>
    );
}
