import { Link, router, usePage, Deferred } from '@inertiajs/react';
import { BellOff, Paperclip, Search } from 'lucide-react';
import { useReferralLive } from '@/lib/realtime';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';
import { ClosingPanel, type ClosingInfo } from '@/components/referrals/ClosingPanel';
import { Progress, type PipelineInfo } from '@/components/referrals/Progress';
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
    pipeline: PipelineInfo | null;
    closing: ClosingInfo | null;
    aiSummary?: { summary: string; usageId: number | null } | null;
    assist?: Assist;
    matches?: { team: string; teamSlug: string; cityId: number; city: string; miles: number; score: number | null }[];
    introduction?: { from: string; explanation: string } | null;
    nurture: {
        temperature: string | null;
        nextAt: string | null;
        protectedUntil: string | null;
        canCheckIn: boolean;
        touchpoints: { kind: string; source: string; note: string | null; at: string }[];
    };
    fee: { terms: string; custom: boolean; proposal: { text: string; mine: boolean } | null; canChange: boolean } | null;
    muted: boolean;
    quickReplies: string[];
    messages: {
        id: number;
        seen: boolean;
        mine: boolean;
        who: string;
        when: string;
        body: string;
        files: { id: number; name: string; size: number; status: string; url: string | null }[];
    }[];
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
    close_confirmed: 'Close confirmed',
    close_auto_confirmed: 'Close confirmed automatically',
    close_disputed: 'Close disputed',
    consent_requested: 'Client asked to confirm by text',
    client_confirmed: 'Client confirmed',
    signed: 'Signed the agreement',
    call_logged: 'Logged a call',
    update: 'Progress note',
    nudged: 'Asked for an update',
    missed_first_contact: 'First contact deadline missed',
    missed_update: 'Update deadline missed',
    sent: 'Sent',
    accepted: 'Accepted',
    declined: 'Declined',
    expired: 'Deadline missed',
    passed_to_backup: 'Passed to the backup',
};
const show = (v: unknown) => (Array.isArray(v) ? v.join(', ') : typeof v === 'boolean' ? (v ? 'Yes' : 'No') : String(v));

export default function ReferralShow({
    referral: r,
    isReceiver,
    agreement: ag,
    closing,
    matches = [],
    assist,
    aiSummary,
    introduction = null,
    nurture,
    fee,
    muted,
    quickReplies,
    pipeline,
    messages,
    clientLink,
    terms,
    timeline,
    status,
    seo,
}: Props) {
    const { modules } = usePage<{ modules?: Record<string, boolean> }>().props;
    // Live: anything that changes on this referral reloads here instantly (10-second refresh if real-time is off).
    const [typing, setTyping] = useState<string | null>(null);
    const [filter, setFilter] = useState('');
    const sendTyping = useReferralLive(r.id, ['messages', 'timeline', 'referral', 'pipeline', 'agreement'], (who) => {
        setTyping(who);
        window.setTimeout(() => setTyping(null), 4000);
    });
    const [lastTyped, setLastTyped] = useState(0);
    const [files, setFiles] = useState<File[]>([]);
    const fileError = (usePage().props.errors as Record<string, string> | undefined)?.['files.0'];
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
                    {pipeline && <Progress id={r.id} p={pipeline} clientTerm={terms.client ?? 'client'} />}
                    {nurture.temperature === 'future' || nurture.touchpoints.length > 0 ? <NurturePanel id={r.id} n={nurture} /> : null}
                    {matches.length > 0 && <MatchesCard id={r.id} client={r.client} matches={matches} />}
                    {assist && <AssistCard id={r.id} a={assist} onUse={(t) => setMsg(t)} />}
                    <Deferred data="aiSummary" fallback={<Card className="p-4 text-sm text-muted">Preparing an AI summary…</Card>}>
                        <AiSummaryCard s={aiSummary ?? null} />
                    </Deferred>
                    {introduction && (
                        <Card className="p-4 text-sm">
                            <strong>Cross-industry introduction</strong> from the {introduction.from}. {introduction.explanation}
                        </Card>
                    )}
                    {modules?.cross_industry !== false && (
                        <a href={`/app/introduce?referral=${r.id}`} className="inline-flex items-center gap-1 text-sm font-medium text-primary">
                            Introduce this client to other pros they need →
                        </a>
                    )}
                    {closing && <ClosingPanel id={r.id} c={closing} />}
                    {fee && <FeePanel id={r.id} fee={fee} />}
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
                                    {ag.status === 'signed' && (
                                        <a
                                            href={`/app/referrals/${r.id}/agreement.pdf`}
                                            className="inline-flex items-center gap-1 text-sm font-medium text-primary"
                                        >
                                            Download the signed agreement (PDF)
                                        </a>
                                    )}
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
                        <div className="flex items-center gap-2">
                            <h2 className="font-semibold">Messages</h2>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="ml-auto"
                                onClick={() => router.post(`/app/referrals/${r.id}/mute`, {}, { preserveScroll: true })}
                            >
                                <BellOff /> {muted ? 'Unmute' : 'Mute'}
                            </Button>
                            <Link href="/app/messages/search" aria-label="Search all messages" className="text-muted hover:text-ink">
                                <Search className="size-4" />
                            </Link>
                        </div>
                        {muted && (
                            <p className="mt-1 text-xs text-muted">
                                Muted: you won’t be notified of messages here. Deadline reminders still come through.
                            </p>
                        )}
                        {messages.length > 5 && (
                            <Input
                                aria-label="Search this conversation"
                                placeholder="Search this conversation"
                                className="mt-2 h-8"
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                            />
                        )}
                        <ol className="mt-3 max-h-80 space-y-2 overflow-y-auto text-sm" aria-live="polite">
                            {messages.length === 0 && (
                                <li className="text-muted">Questions about this {terms.client}? Ask here; the other team is notified.</li>
                            )}
                            {messages
                                .filter((m) => !filter || m.body.toLowerCase().includes(filter.toLowerCase()))
                                .map((m) => (
                                    <li key={m.id} className={m.mine ? 'ml-6 rounded-md bg-primary/10 p-2' : 'mr-6 rounded-md bg-bg p-2'}>
                                        <div className="text-xs text-muted">{m.who}</div>
                                        {m.body && <p className="whitespace-pre-wrap">{m.body}</p>}
                                        {m.files.map((f) => (
                                            <div key={f.id} className="mt-1 flex items-center gap-1.5 text-xs">
                                                <Paperclip className="size-3" aria-hidden />
                                                {f.url ? (
                                                    <a href={f.url} className="font-medium text-primary hover:underline">
                                                        {f.name}
                                                    </a>
                                                ) : (
                                                    <span className="font-medium">{f.name}</span>
                                                )}
                                                <span className="text-muted">
                                                    {Math.max(1, Math.round(f.size / 1024))} KB
                                                    {f.status === 'pending'
                                                        ? ' · checking for viruses…'
                                                        : f.status === 'infected'
                                                          ? ' · blocked: failed the virus check'
                                                          : !f.url
                                                            ? ' · opens after you accept'
                                                            : ''}
                                                </span>
                                            </div>
                                        ))}
                                        {m.mine && m.seen && <div className="text-right text-[11px] text-muted">Seen</div>}
                                    </li>
                                ))}
                        </ol>
                        {typing && <p className="mt-1 text-xs text-muted">{typing} is typing…</p>}
                        {fileError && <p className="mt-1 text-xs text-danger">{fileError}</p>}
                        {files.length > 0 && <p className="mt-1 text-xs text-muted">Attaching: {files.map((f) => f.name).join(', ')}</p>}
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {quickReplies.map((q) => (
                                <button key={q} className="rounded-full border border-line px-2.5 py-1 text-xs hover:bg-bg" onClick={() => setMsg(q)}>
                                    {q}
                                </button>
                            ))}
                        </div>
                        <div className="mt-2 flex gap-2">
                            <Input
                                aria-label="Message"
                                placeholder="Write a message"
                                value={msg}
                                onChange={(e) => {
                                    setMsg(e.target.value);
                                    if (Date.now() - lastTyped > 3000) {
                                        setLastTyped(Date.now());
                                        sendTyping(auth.user?.firstName ?? 'Someone');
                                    }
                                }}
                            />
                            <label
                                className="flex cursor-pointer items-center rounded-md border border-line px-2.5 hover:bg-bg"
                                aria-label="Attach files"
                            >
                                <Paperclip className="size-4" aria-hidden />
                                <input
                                    type="file"
                                    multiple
                                    className="sr-only"
                                    accept=".jpg,.jpeg,.png,.webp,.heic,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
                                    onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 3))}
                                />
                            </label>
                            <Button
                                disabled={!msg.trim() && files.length === 0}
                                onClick={() =>
                                    router.post(
                                        `/app/referrals/${r.id}/messages`,
                                        { body: msg, files },
                                        { forceFormData: true, preserveScroll: true, onSuccess: () => (setMsg(''), setFiles([])) },
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
                                        {t.event === 'stage' && typeof t.meta?.to === 'string'
                                            ? `Moved to ${pipeline?.stages.find((x) => x.key === t.meta?.to)?.label ?? t.meta.to}`
                                            : (words[t.event] ?? t.event.replace(/_/g, ' '))}
                                        {t.team ? ` · ${t.team}` : ''}
                                    </div>
                                    <div className="text-xs text-muted">{new Date(t.when.replace(' ', 'T') + 'Z').toLocaleString()}</div>
                                    {t.meta && typeof t.meta.reason === 'string' && <div className="text-xs text-muted">“{t.meta.reason}”</div>}
                                    {t.meta && typeof t.meta.note === 'string' && <div className="text-xs text-muted">“{t.meta.note}”</div>}
                                </li>
                            ))}
                        </ol>
                    </Card>
                </div>
            </div>
        </PortalLayout>
    );
}

function FeePanel({
    id,
    fee,
}: {
    id: string;
    fee: { terms: string; custom: boolean; proposal: { text: string; mine: boolean } | null; canChange: boolean };
}) {
    const [type, setType] = useState('percent');
    const [value, setValue] = useState('');
    return (
        <Card className="space-y-3 p-6">
            <h2 className="font-semibold">Referral fee</h2>
            <p className="text-sm">
                {fee.terms}
                {fee.custom ? ' (agreed for this referral)' : ' (the network’s default)'}. Paid directly between the teams after the close is
                confirmed.
            </p>
            {fee.proposal &&
                (fee.proposal.mine ? (
                    <p className="text-sm text-muted">You proposed {fee.proposal.text}; waiting for the other team.</p>
                ) : (
                    <div className="flex items-center gap-2 text-sm">
                        The other team proposed {fee.proposal.text}.
                        {fee.canChange && (
                            <Button size="sm" onClick={() => router.post(`/app/referrals/${id}/fee/accept`, {}, { preserveScroll: true })}>
                                Accept
                            </Button>
                        )}
                    </div>
                ))}
            {fee.canChange && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    Propose a different fee:
                    <Select aria-label="Fee type" className="h-9 w-40" value={type} onChange={(e) => setType(e.target.value)}>
                        <option value="percent">% of the receiving team’s earnings</option>
                        <option value="flat">Flat amount ($)</option>
                    </Select>
                    <Input
                        aria-label="Fee value"
                        type="number"
                        min="0"
                        className="h-9 w-28"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                    />
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={!value}
                        onClick={() => router.post(`/app/referrals/${id}/fee`, { type, value }, { preserveScroll: true })}
                    >
                        Propose
                    </Button>
                </div>
            )}
            {!fee.canChange && <p className="text-xs text-muted">The agreement has been signed, so the fee is fixed.</p>}
        </Card>
    );
}

function NurturePanel({
    id,
    n,
}: {
    id: string;
    n: {
        temperature: string | null;
        nextAt: string | null;
        protectedUntil: string | null;
        canCheckIn: boolean;
        touchpoints: { kind: string; source: string; note: string | null; at: string }[];
    };
}) {
    const [note, setNote] = useState('');
    const [next, setNext] = useState('');
    const go = (outcome: string) =>
        router.post(
            `/app/referrals/${id}/nurture`,
            { outcome, note: note || null, next_on: next || null },
            { preserveScroll: true, onSuccess: () => setNote('') },
        );
    const words: Record<string, string> = {
        still: 'Still nurturing',
        ready: 'Marked ready',
        lost: 'Marked lost',
        client_ready: 'Client said they’re ready',
        client_later: 'Client still planning',
        client_not_needed: 'Client no longer needs help',
    };
    return (
        <Card className="space-y-3 p-6">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">Nurture</h2>
                <Badge tone={n.temperature === 'future' ? 'info' : 'danger'}>{n.temperature}</Badge>
                {n.nextAt && <span className="text-sm text-muted">Next check-in {n.nextAt}</span>}
                {n.protectedUntil && <span className="text-sm text-muted">· protected until {n.protectedUntil}</span>}
            </div>
            {n.canCheckIn && n.temperature === 'future' && (
                <div className="space-y-2">
                    <Input
                        aria-label="Check-in note"
                        placeholder="A quick note for the referring team (optional)"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button size="sm" variant="secondary" onClick={() => go('still')}>
                            Still nurturing
                        </Button>
                        <Input
                            aria-label="Next check-in date"
                            type="date"
                            className="h-9 w-44"
                            value={next}
                            onChange={(e) => setNext(e.target.value)}
                        />
                        <Button size="sm" onClick={() => go('ready')}>
                            Ready now
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => go('lost')}>
                            Lost
                        </Button>
                    </div>
                    <p className="text-xs text-muted">Each check-in keeps the referring team informed and extends your protection period.</p>
                </div>
            )}
            {n.touchpoints.length > 0 && (
                <ul className="space-y-1 text-sm">
                    {n.touchpoints.map((t, i) => (
                        <li key={i}>
                            <span className="text-muted tabular">{t.at}</span> · {words[t.kind] ?? t.kind}
                            {t.note ? `: ${t.note}` : ''}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/** The receiver's AI summary, clearly labelled, with helpful / not helpful feedback. Hidden when AI is unavailable. */
function AiSummaryCard({ s }: { s: { summary: string; usageId: number | null } | null }) {
    if (!s) return null;
    const rate = (rating: number) => s.usageId && router.post('/app/ai/feedback', { usage_id: s.usageId, rating }, { preserveScroll: true });
    return (
        <Card className="space-y-2 border-primary/30 p-4 text-sm">
            <p className="text-xs font-semibold tracking-wide text-primary uppercase">AI summary · check the details below</p>
            <p>{s.summary}</p>
            {s.usageId && (
                <div className="flex items-center gap-2 text-xs text-muted">
                    Helpful?
                    <button className="rounded px-2 py-1 hover:bg-bg" onClick={() => rate(1)} aria-label="Helpful">
                        👍
                    </button>
                    <button className="rounded px-2 py-1 hover:bg-bg" onClick={() => rate(-1)} aria-label="Not helpful">
                        👎
                    </button>
                </div>
            )}
        </Card>
    );
}

type Assist = {
    actions: { key: string; title: string; body: string; action: string; label: string }[];
    score: { score: number; reasons: { text: string; effect: number }[] } | null;
    canDraftClient: boolean;
    tones: string[];
    reasons: string[];
    draft: { text: string; usageId: number; to: string } | null;
    drafts?: boolean;
};

const REASON_LABELS: Record<string, string> = {
    not_relevant: 'Not relevant',
    already_done: 'Already done',
    wrong_timing: 'Wrong timing',
    other: 'Other',
};

/** Next steps: the explainable score, suggested actions (open, dismiss, snooze) and AI drafts the member edits and sends. */
function AssistCard({ id, a, onUse }: { id: string; a: Assist; onUse: (text: string) => void }) {
    const [tone, setTone] = useState(a.tones[0] ?? 'friendly');
    const [dismissing, setDismissing] = useState<string | null>(null);
    const act = (key: string, action: string, extra: Record<string, string> = {}) =>
        router.post(`/app/referrals/${id}/assist/${key}/${action}`, extra, { preserveScroll: true });
    if (!a.score && a.actions.length === 0 && !a.draft && !a.canDraftClient && a.tones.length === 0) return null;
    return (
        <Card className="space-y-3 p-4 text-sm">
            <p className="text-xs font-semibold tracking-wide text-primary uppercase">Next steps</p>
            {a.score && (
                <div>
                    <p className="font-semibold">Close likelihood: {a.score.score}%</p>
                    <ul className="mt-1 space-y-0.5 text-xs text-muted">
                        {a.score.reasons.map((r) => (
                            <li key={r.text}>
                                {r.effect > 0 ? '▲ ' : r.effect < 0 ? '▼ ' : '• '}
                                {r.text}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-1 text-xs text-subtle">Only your team sees this. It’s a guide from platform history, not a judgement.</p>
                </div>
            )}
            {a.actions.map((x) => (
                <div key={x.key} className="rounded-md border border-line p-3">
                    <p className="font-medium">{x.title}</p>
                    <p className="text-muted">{x.body}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            onClick={() => {
                                act(x.key, 'use');
                                window.location.hash = x.action.split('#')[1] ?? '';
                            }}
                        >
                            {x.label}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => act(x.key, 'snooze')}>
                            Remind me tomorrow
                        </Button>
                        {dismissing === x.key ? (
                            <select
                                aria-label="Why dismiss?"
                                className="h-8 rounded-md border border-line bg-surface px-2 text-xs"
                                defaultValue=""
                                onChange={(e) => act(x.key, 'dismiss', { reason: e.target.value })}
                            >
                                <option value="" disabled>
                                    Why?
                                </option>
                                {a.reasons.map((r) => (
                                    <option key={r} value={r}>
                                        {REASON_LABELS[r] ?? r}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <Button size="sm" variant="ghost" onClick={() => setDismissing(x.key)}>
                                Dismiss
                            </Button>
                        )}
                    </div>
                </div>
            ))}
            {a.drafts !== false && (
                <div className="flex flex-wrap items-center gap-2 border-t border-line pt-3">
                    <span className="text-xs text-muted">AI draft (1 credit):</span>
                    <select
                        aria-label="Tone"
                        className="h-8 rounded-md border border-line bg-surface px-2 text-xs"
                        value={tone}
                        onChange={(e) => setTone(e.target.value)}
                    >
                        {a.tones.map((t) => (
                            <option key={t} value={t}>
                                {t}
                            </option>
                        ))}
                    </select>
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => router.post(`/app/referrals/${id}/draft`, { to: 'partner', tone }, { preserveScroll: true })}
                    >
                        Draft an update to the other team
                    </Button>
                    {a.canDraftClient && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => router.post(`/app/referrals/${id}/draft`, { to: 'client', tone }, { preserveScroll: true })}
                        >
                            Draft a message to the client
                        </Button>
                    )}
                </div>
            )}
            {a.draft && (
                <div className="space-y-2 rounded-md bg-bg p-3">
                    <p className="text-xs text-muted">Draft · check it and edit before sending. Nothing is sent automatically.</p>
                    <p className="whitespace-pre-wrap">{a.draft.text}</p>
                    {a.draft.to === 'partner' ? (
                        <Button
                            size="sm"
                            onClick={() => {
                                onUse(a.draft?.text ?? '');
                                act('draft', 'use', { kind: 'draft' });
                                window.location.hash = 'messages';
                            }}
                        >
                            Put in the message box
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            onClick={() => {
                                void navigator.clipboard?.writeText(a.draft?.text ?? '');
                                act('draft_client', 'use', { kind: 'draft' });
                            }}
                        >
                            Copy for the client
                        </Button>
                    )}
                </div>
            )}
        </Card>
    );
}

/** An unplaced referral: send it on to a nearby pro, with the client's agreement to that business. */
function MatchesCard({ id, client, matches }: { id: string; client: string; matches: NonNullable<Props['matches']> }) {
    const [agreed, setAgreed] = useState<number | null>(null);
    return (
        <Card className="space-y-2 p-4 text-sm">
            <p className="font-semibold">Send it to a nearby pro</p>
            <p className="text-muted">No one in this city could take it. These pros in the same network are close by.</p>
            {matches.map((m) => (
                <div key={m.cityId} className="space-y-2 rounded-md border border-line p-3">
                    <p>
                        <strong>{m.team}</strong> · {m.city} · {m.miles} miles{m.score !== null ? ` · service ${m.score}` : ''}
                    </p>
                    <label className="flex items-start gap-2 text-xs">
                        <input type="checkbox" checked={agreed === m.cityId} onChange={(e) => setAgreed(e.target.checked ? m.cityId : null)} />
                        {client} agreed to be referred to {m.team}.
                    </label>
                    <Button
                        size="sm"
                        disabled={agreed !== m.cityId}
                        onClick={() => router.post(`/app/referrals/${id}/resend`, { city_id: m.cityId, consent: true })}
                    >
                        Send to {m.team}
                    </Button>
                </div>
            ))}
        </Card>
    );
}
