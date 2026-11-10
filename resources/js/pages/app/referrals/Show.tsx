import { router, usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';
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
import { left, tone, useNow } from './List';

type Props = {
    referral: {
        id: string;
        status: string;
        territory: string;
        group: string;
        sender: string;
        receiver: string | null;
        routedTo: string;
        respondBy: string;
        client: string;
        notes: string | null;
        consentText: string;
        consentAt: string;
        declineReason: string | null;
        answers: { label: string; value: unknown }[];
    };
    isReceiver: boolean;
    terms: Record<string, string>;
    timeline: { event: string; when: string; team: string | null; meta: Record<string, unknown> | null }[];
    status?: string;
    seo: Seo;
};

const words: Record<string, string> = {
    sent: 'Sent',
    accepted: 'Accepted',
    declined: 'Declined',
    expired: 'Deadline missed',
    passed_to_backup: 'Passed to the backup',
};
const show = (v: unknown) => (Array.isArray(v) ? v.join(', ') : typeof v === 'boolean' ? (v ? 'Yes' : 'No') : String(v));

export default function ReferralShow({ referral: r, isReceiver, terms, timeline, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const now = useNow();
    const [declining, setDeclining] = useState(false);
    const [reason, setReason] = useState('');
    const waiting = r.status === 'sent';
    return (
        <PortalLayout title={`${r.client} · ${r.territory}`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="grid max-w-5xl gap-6 lg:grid-cols-[1.4fr_1fr]">
                <div className="space-y-6">
                    <Card className="p-6">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge tone={tone(r.status)}>{r.status}</Badge>
                            {waiting && <span className="text-sm text-warning tabular">{left(r.respondBy, now)}</span>}
                            <span className="ml-auto text-sm text-muted">{r.group}</span>
                        </div>
                        <p className="mt-3 text-sm text-muted">
                            From {r.sender} to {r.receiver ?? '—'}
                            {r.routedTo === 'backup' ? ' (backup)' : ''}
                        </p>
                        <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                            {r.answers.map((a) => (
                                <div key={a.label}>
                                    <dt className="text-xs text-subtle">{a.label}</dt>
                                    <dd className="font-medium">
                                        {a.value === null ? (
                                            <span className="flex items-center gap-1 text-sm text-muted">
                                                <Lock className="size-3" aria-hidden /> Shown after you accept
                                            </span>
                                        ) : (
                                            show(a.value)
                                        )}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {r.notes && <p className="mt-4 rounded-md bg-bg p-3 text-sm">{r.notes}</p>}
                        <p className="mt-4 text-xs text-muted">
                            Consent recorded {new Date(r.consentAt).toLocaleString()}: “{r.consentText}”
                        </p>
                    </Card>
                    {isReceiver && waiting && (
                        <Card className="space-y-3 p-6">
                            <h2 className="font-semibold">Can you help this {terms.client}?</h2>
                            {declining ? (
                                <div className="flex flex-wrap gap-2">
                                    <Input
                                        aria-label="Reason"
                                        placeholder="Why can’t you take it? The sender will see this."
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        className="min-w-64 flex-1"
                                    />
                                    <Button
                                        variant="danger"
                                        disabled={!reason.trim()}
                                        onClick={() => router.post(`/app/referrals/${r.id}/decline`, { reason })}
                                    >
                                        Decline
                                    </Button>
                                </div>
                            ) : (
                                <div className="flex gap-2">
                                    <Button onClick={() => router.post(`/app/referrals/${r.id}/accept`, {}, { preserveScroll: true })}>Accept</Button>
                                    <Button variant="ghost" onClick={() => setDeclining(true)}>
                                        Decline
                                    </Button>
                                </div>
                            )}
                        </Card>
                    )}
                </div>
                <Card className="h-fit p-6">
                    <h2 className="font-semibold">Timeline</h2>
                    <ol className="mt-3 space-y-3 border-l border-line pl-4 text-sm">
                        {timeline.map((t, i) => (
                            <li key={i}>
                                <div className="font-medium">
                                    {words[t.event] ?? t.event}
                                    {t.team ? ` · ${t.team}` : ''}
                                </div>
                                <div className="text-xs text-muted">{new Date(t.when.replace(' ', 'T') + 'Z').toLocaleString()}</div>
                                {t.meta && typeof t.meta.reason === 'string' && <div className="text-xs text-muted">“{t.meta.reason}”</div>}
                            </li>
                        ))}
                    </ol>
                </Card>
            </div>
        </PortalLayout>
    );
}
