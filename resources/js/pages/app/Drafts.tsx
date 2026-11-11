import { router, usePage } from '@inertiajs/react';
import { Camera, Inbox, Mail, Mic } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Draft = { id: string; client: string | null; channel: string; subject: string | null; at: string; expires: string; toCheck: number };

/** Portal → Drafts: forward a lead to your private address and it becomes a draft to check and send. */
export default function Drafts({
    address,
    drafts,
    transcript = null,
    status,
    seo,
}: {
    address: string | null;
    drafts: Draft[];
    transcript?: { id: number; text: string } | null;
    status?: string;
    seo: Seo;
}) {
    const { auth, errors } = usePage<SharedProps & { errors: Record<string, string> }>().props;
    const [copied, setCopied] = useState(false);
    return (
        <PortalLayout title="Drafts" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-3xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <Card className="space-y-2 p-5">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Mail className="size-5 text-primary" aria-hidden /> Forward a lead, send a referral
                    </h2>
                    {address ? (
                        <>
                            <p className="text-sm text-muted">
                                Forward any lead email to your private address from {auth.user?.email}. It becomes a draft here in seconds; nothing is
                                sent until you check it and confirm your client’s consent.
                            </p>
                            <div className="flex flex-wrap items-center gap-2">
                                <code className="rounded bg-bg px-2 py-1 text-sm">{address}</code>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => {
                                        void navigator.clipboard?.writeText(address);
                                        setCopied(true);
                                    }}
                                >
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                            <p className="text-xs text-muted">Keep it private: it only accepts email from your own address. 1 AI credit per email.</p>
                        </>
                    ) : (
                        <p className="text-sm text-muted">Email forwarding isn’t switched on yet.</p>
                    )}
                </Card>
                <div className="grid gap-4 sm:grid-cols-2">
                    <VoiceNote />
                    <Card className="space-y-2 p-5">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <Camera className="size-5 text-primary" aria-hidden /> Scan a card or note
                        </h2>
                        <p className="text-sm text-muted">
                            Photograph a business card or a handwritten note. Location data is removed and the photo is deleted once it’s read. 2 AI
                            credits.
                        </p>
                        <label className="inline-flex h-9 cursor-pointer items-center rounded-md bg-primary px-3 text-sm font-semibold text-white">
                            Take or choose a photo
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                capture="environment"
                                className="sr-only"
                                onChange={(e) => {
                                    const f = e.target.files?.[0];
                                    if (f) router.post('/app/intake/photo', { photo: f }, { forceFormData: true });
                                }}
                            />
                        </label>
                    </Card>
                </div>
                {(errors.audio || errors.photo || errors.credits) && <Alert tone="danger">{errors.audio ?? errors.photo ?? errors.credits}</Alert>}
                {transcript && <Transcript t={transcript} />}
                {drafts.length === 0 && (
                    <Card className="flex items-center gap-3 p-6 text-muted">
                        <Inbox className="size-5" aria-hidden /> No drafts waiting.
                    </Card>
                )}
                {drafts.map((d) => (
                    <Card key={d.id} className="flex flex-wrap items-center gap-3 p-4">
                        <div className="min-w-0 flex-1">
                            <p className="font-medium">{d.client ?? 'Client name to check'}</p>
                            <p className="text-sm text-muted">
                                {d.channel} · {d.subject ?? 'no subject'} · {d.at} · expires {d.expires}
                            </p>
                        </div>
                        {d.toCheck > 0 && <Badge tone="warning">{d.toCheck} to check</Badge>}
                        <Button size="sm" onClick={() => router.visit(`/app/send?draft=${d.id}`)}>
                            Check and send
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                window.confirm('Discard this draft? The original is deleted.') &&
                                router.post(`/app/drafts/${d.id}/discard`, {}, { preserveScroll: true })
                            }
                        >
                            Discard
                        </Button>
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}

/** Record up to two minutes in the browser; the recording is transcribed, then deleted. */
function VoiceNote() {
    const [rec, setRec] = useState<MediaRecorder | null>(null);
    const [secs, setSecs] = useState(0);
    const start = async () => {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const r = new MediaRecorder(stream);
        const parts: Blob[] = [];
        r.ondataavailable = (e) => parts.push(e.data);
        r.onstop = () => {
            stream.getTracks().forEach((t) => t.stop());
            const blob = new Blob(parts, { type: r.mimeType || 'audio/webm' });
            router.post('/app/intake/voice', { audio: new File([blob], 'voice-note.webm', { type: blob.type }) }, { forceFormData: true });
        };
        r.start();
        setRec(r);
        setSecs(0);
        const started = Date.now();
        const t = window.setInterval(() => {
            const s = Math.floor((Date.now() - started) / 1000);
            setSecs(s);
            if (s >= 120 || r.state !== 'recording') {
                window.clearInterval(t);
                if (r.state === 'recording') r.stop();
                setRec(null);
            }
        }, 500);
    };
    return (
        <Card className="space-y-2 p-5">
            <h2 className="flex items-center gap-2 font-semibold">
                <Mic className="size-5 text-primary" aria-hidden /> Record a voice note
            </h2>
            <p className="text-sm text-muted">
                Say who the client is, what they need, where and when. Up to 2 minutes; you’ll check the text before the draft is made. 2 AI credits.
            </p>
            {rec ? (
                <Button size="sm" onClick={() => rec.stop()}>
                    Stop ({secs}s)
                </Button>
            ) : (
                <Button size="sm" onClick={() => void start()}>
                    Start recording
                </Button>
            )}
        </Card>
    );
}

/** The transcript, editable before the draft is made. */
function Transcript({ t }: { t: { id: number; text: string } }) {
    const [text, setText] = useState(t.text);
    return (
        <Card className="space-y-2 p-5">
            <h2 className="font-semibold">Check your voice note</h2>
            <p className="text-sm text-muted">Fix anything misheard, especially names and numbers, then make the draft.</p>
            <textarea
                aria-label="Transcript"
                rows={5}
                className="w-full rounded-md border border-line bg-surface p-3"
                value={text}
                onChange={(e) => setText(e.target.value)}
            />
            <Button size="sm" disabled={text.trim().length < 5} onClick={() => router.post(`/app/intake/${t.id}/draft`, { text })}>
                Make a draft
            </Button>
        </Card>
    );
}
