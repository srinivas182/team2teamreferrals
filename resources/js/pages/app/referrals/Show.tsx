import { router, usePage, usePoll } from '@inertiajs/react';
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
        clientConfirmed: string | null;
        consentRequested: boolean;
        canAskClient: boolean;
        answers: { label: string; value: unknown }[];
    };
    isReceiver: boolean;
    messages: { id: number; mine: boolean; who: string; when: string; body: string }[];
    clientLink: string | null;
    agreement: {
        status: string;
        version: number | null;
        summary: string[];
        sections: { title: string; text: string }[];
        sender: { name: string | null; at: string | null };
        receiver: { name: string | null; at: string | null };
        canSign: boolean;
        signerName: string;
        pdf: string | null;
    } | null;
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

export default function ReferralShow({ referral: r, isReceiver, agreement: ag, messages, clientLink, terms, timeline, status, seo }: Props) {
    // New messages appear without reloading while the referral is open.
    usePoll(10000, { only: ['messages', 'timeline', 'referral'] });
    const [msg, setMsg] = useState('');
    const [copied, setCopied] = useState(false);
    const [signName, setSignName] = useState('');
    const [signConsent, setSignConsent] = useState(false);
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
                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            {r.clientConfirmed ? (
                                <Badge tone="success">Client confirmed</Badge>
                            ) : r.consentRequested ? (
                                <Badge tone="warning">Waiting for the client to confirm</Badge>
                            ) : null}
                            {r.canAskClient && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => router.post(`/app/referrals/${r.id}/ask-client`, {}, { preserveScroll: true })}
                                >
                                    Ask client to confirm by text
                                </Button>
                            )}
                        </div>
                        <p className="mt-2 text-xs text-muted">
                            Consent recorded {new Date(r.consentAt).toLocaleString()}: “{r.consentText}”
                        </p>
                    </Card>
                    {ag && (
                        <Card className="space-y-3 p-6">
                            <div className="flex items-center gap-2">
                                <h2 className="font-semibold">Referral agreement</h2>
                                <Badge tone={ag.status === 'signed' ? 'success' : 'warning'}>
                                    {ag.status === 'signed'
                                        ? 'Signed by both'
                                        : ag.status === 'pending'
                                          ? 'Waiting for signatures'
                                          : 'Waiting for approval'}
                                </Badge>
                            </div>
                            {ag.status === 'awaiting_approval' ? (
                                <p className="text-sm text-muted">
                                    The agreement for this network is being approved by counsel. You’ll both be asked to sign as soon as it’s ready;
                                    the referral can go ahead meanwhile.
                                </p>
                            ) : (
                                <>
                                    <ul className="list-disc space-y-1 pl-5 text-sm">
                                        {ag.summary.map((x) => (
                                            <li key={x}>{x}</li>
                                        ))}
                                    </ul>
                                    <details className="text-sm">
                                        <summary className="cursor-pointer font-medium text-primary">
                                            Read the full agreement (version {ag.version})
                                        </summary>
                                        <div className="mt-3 max-h-80 space-y-3 overflow-y-auto rounded-md bg-bg p-4">
                                            {ag.sections.map((x) => (
                                                <div key={x.title}>
                                                    <h3 className="font-semibold">{x.title}</h3>
                                                    <p>{x.text}</p>
                                                </div>
                                            ))}
                                        </div>
                                    </details>
                                    <ul className="text-sm">
                                        <li>Referring team: {ag.sender.at ? `signed by ${ag.sender.name}` : 'not signed yet'}</li>
                                        <li>Receiving team: {ag.receiver.at ? `signed by ${ag.receiver.name}` : 'not signed yet'}</li>
                                    </ul>
                                    {ag.canSign && (
                                        <div className="space-y-2 rounded-md border border-line p-4">
                                            <Input
                                                aria-label="Type your full name"
                                                placeholder={`Type your full name: ${ag.signerName}`}
                                                value={signName}
                                                onChange={(e) => setSignName(e.target.value)}
                                            />
                                            <label className="flex items-start gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    className="mt-1"
                                                    checked={signConsent}
                                                    onChange={(e) => setSignConsent(e.target.checked)}
                                                />{' '}
                                                I agree to sign this agreement electronically, and my typed name is my signature.
                                            </label>
                                            <Button
                                                disabled={!signName.trim() || !signConsent}
                                                onClick={() =>
                                                    router.post(
                                                        `/app/referrals/${r.id}/sign`,
                                                        { name: signName, consent: signConsent },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Sign as {ag.signerName}
                                            </Button>
                                        </div>
                                    )}
                                    {ag.pdf && (
                                        <a href={ag.pdf} className="text-sm font-medium text-primary">
                                            Download the signed agreement (PDF)
                                        </a>
                                    )}
                                </>
                            )}
                        </Card>
                    )}
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
                <div className="space-y-6">
                    <Card className="p-6">
                        <h2 className="font-semibold">Messages</h2>
                        <ol className="mt-3 max-h-80 space-y-2 overflow-y-auto text-sm" aria-live="polite">
                            {messages.length === 0 && (
                                <li className="text-muted">Questions about this {terms.client}? Ask here; the other team is notified.</li>
                            )}
                            {messages.map((m) => (
                                <li key={m.id} className={m.mine ? 'ml-6 rounded-md bg-primary/10 p-2' : 'mr-6 rounded-md bg-bg p-2'}>
                                    <div className="text-xs text-muted">{m.who}</div>
                                    <p className="whitespace-pre-wrap">{m.body}</p>
                                </li>
                            ))}
                        </ol>
                        <div className="mt-3 flex gap-2">
                            <Input aria-label="Message" placeholder="Write a message" value={msg} onChange={(e) => setMsg(e.target.value)} />
                            <Button
                                disabled={!msg.trim()}
                                onClick={() =>
                                    router.post(
                                        `/app/referrals/${r.id}/messages`,
                                        { body: msg },
                                        { preserveScroll: true, onSuccess: () => setMsg('') },
                                    )
                                }
                            >
                                Send
                            </Button>
                        </div>
                    </Card>
                    {clientLink && (
                        <Card className="space-y-2 p-6">
                            <h2 className="font-semibold">Your {terms.client}’s status page</h2>
                            <p className="text-sm text-muted">
                                A private link showing who will contact them, their verified badges and where things stand.
                            </p>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => {
                                    void navigator.clipboard.writeText(clientLink);
                                    setCopied(true);
                                }}
                            >
                                {copied ? 'Link copied' : 'Copy link to send'}
                            </Button>
                        </Card>
                    )}
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
            </div>
        </PortalLayout>
    );
}
