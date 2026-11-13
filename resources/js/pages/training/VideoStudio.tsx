import { router, useForm, usePage } from '@inertiajs/react';
import { Clapperboard, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Script = {
    id: string;
    title: string;
    script: string;
    status: string;
    lesson: number | null;
    estimate: { minutes: number; credits: number; usd: number };
    video: string | null;
    captions: string | null;
    retake: boolean;
    publishedAt: string | null;
};
type Props = {
    scope: { industry: string; network: string | null; restricted: boolean; isStaff: boolean };
    base: string;
    scripts: Script[];
    configured: boolean;
    lessons: string[];
    avatars: { id: string; name: string }[];
    voices: { id: string; name: string }[];
    draft?: string | null;
    defaultScript: string;
    status?: string;
    seo: Seo;
};

const STATUS: Record<string, [string, 'neutral' | 'info' | 'success' | 'warning' | 'danger']> = {
    draft: ['Draft', 'neutral'],
    submitted: ['Waiting for approval', 'warning'],
    approved: ['Approved: ready to render', 'info'],
    rendering: ['Rendering…', 'info'],
    ready: ['Ready to preview', 'success'],
    failed: ['Render failed: credits refunded', 'danger'],
    published: ['Published', 'success'],
    retired: ['Replaced', 'neutral'],
};
const area = 'w-full rounded-md border border-line bg-surface p-3 text-sm';
const opts = { preserveScroll: true } as const;

function ScriptCard({ s, p }: { s: Script; p: Props }) {
    const [title, setTitle] = useState(s.title);
    const [text, setText] = useState(s.script);
    const [avatar, setAvatar] = useState(p.avatars[0]?.id ?? '');
    const [voice, setVoice] = useState(p.voices[0]?.id ?? '');
    const [retake, setRetake] = useState(false);
    const act = (action: string, data: Record<string, string | boolean> = {}) => router.post(`${p.base}/scripts/${s.id}/${action}`, data, opts);
    const [label, tone] = STATUS[s.status] ?? [s.status, 'neutral'];
    const needsAdmin = p.scope.restricted && !p.scope.isStaff;
    return (
        <Card className="space-y-3 p-5">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="font-semibold">{s.title}</h3>
                {s.lesson !== null && <span className="text-sm text-muted">· lesson: {p.lessons[s.lesson]}</span>}
                <Badge tone={tone} className="ml-auto">
                    {label}
                </Badge>
            </div>
            {s.status === 'draft' ? (
                <>
                    <Input aria-label="Title" value={title} onChange={(e) => setTitle(e.target.value)} />
                    <textarea aria-label="Script" rows={8} className={area} value={text} onChange={(e) => setText(e.target.value)} />
                    <div className="flex gap-2">
                        <Button size="sm" variant="secondary" onClick={() => router.post(`${p.base}/scripts/${s.id}`, { title, script: text }, opts)}>
                            Save
                        </Button>
                        <Button size="sm" onClick={() => act('submit')}>
                            Submit for approval
                        </Button>
                    </div>
                </>
            ) : (
                <details className="text-sm">
                    <summary className="cursor-pointer text-muted">Script ({s.estimate.minutes} min)</summary>
                    <p className="mt-2 whitespace-pre-wrap">{s.script}</p>
                </details>
            )}
            {s.status === 'submitted' &&
                (needsAdmin ? (
                    <p className="text-sm text-muted">In this industry, a platform admin approves training scripts.</p>
                ) : (
                    <Button size="sm" onClick={() => act('approve')}>
                        I’ve read it: approve
                    </Button>
                ))}
            {(s.status === 'approved' || s.status === 'failed') &&
                (p.configured ? (
                    <div className="grid gap-2 sm:grid-cols-3">
                        <Field label="Presenter (stock)">
                            <select
                                className="h-10 w-full rounded-md border border-line bg-surface px-2"
                                value={avatar}
                                onChange={(e) => setAvatar(e.target.value)}
                            >
                                {p.avatars.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Voice">
                            <select
                                className="h-10 w-full rounded-md border border-line bg-surface px-2"
                                value={voice}
                                onChange={(e) => setVoice(e.target.value)}
                            >
                                {p.voices.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <div className="flex flex-col justify-end gap-1">
                            <p className="text-xs text-muted">
                                About {s.estimate.minutes} min ·{' '}
                                {s.estimate.credits > 0 ? `${s.estimate.credits} AI credits` : 'free (paid by the platform)'}
                            </p>
                            <Button
                                size="sm"
                                disabled={!avatar || !voice}
                                onClick={() =>
                                    window.confirm(`Render this video${s.estimate.credits > 0 ? ` for ${s.estimate.credits} credits` : ''}?`) &&
                                    act('render', { avatar, voice })
                                }
                            >
                                Render the video
                            </Button>
                        </div>
                    </div>
                ) : (
                    <p className="text-sm text-muted">The video provider isn’t connected yet (Admin → Settings → Video).</p>
                ))}
            {s.status === 'rendering' && (
                <p className="text-sm text-muted">Usually a few minutes. This page updates when it’s ready; you can leave it.</p>
            )}
            {s.video && (s.status === 'ready' || s.status === 'published') && (
                <div className="space-y-2">
                    <div className="relative">
                        <video src={s.video} controls className="w-full rounded-md" crossOrigin="anonymous">
                            {s.captions && <track kind="captions" src={s.captions} srcLang="en" label="English" default />}
                        </video>
                        <span className="absolute top-2 left-2 rounded bg-black/70 px-2 py-0.5 text-xs text-white">AI presenter</span>
                    </div>
                    {s.status === 'ready' && (
                        <div className="flex flex-wrap items-center gap-3">
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={retake} onChange={(e) => setRetake(e.target.checked)} /> Members must take the
                                training again
                            </label>
                            <Button
                                size="sm"
                                onClick={() => window.confirm('Publish this video as the training version?') && act('publish', { retake })}
                            >
                                Publish
                            </Button>
                        </div>
                    )}
                    {s.status === 'published' && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => window.confirm('Roll back to the previous training video?') && act('rollback')}
                        >
                            Roll back
                        </Button>
                    )}
                </div>
            )}
        </Card>
    );
}

/** Training video studio: write or AI-draft a script, approve it, render it with a stock presenter, preview, publish. */
export default function VideoStudio(p: Props) {
    const { auth } = usePage<SharedProps>().props;
    const f = useForm({ title: '', script: p.draft ?? '', lesson: p.lessons.length ? '0' : '' });
    const [prevDraft, setPrevDraft] = useState(p.draft);
    if (p.draft !== prevDraft) {
        setPrevDraft(p.draft);
        if (p.draft) f.setData('script', p.draft);
    }
    const body = (
        <div className="max-w-4xl space-y-4">
            <SeoHead seo={p.seo} />
            <p className="text-sm text-muted">
                {p.scope.network
                    ? `Your network’s training video, shown to ${p.scope.network} members on their training page.`
                    : `The default training video for ${p.scope.industry}, by lesson. Free: paid by the platform.`}{' '}
                Presenters are stock AI presenters, labelled as such.
            </p>
            {p.status && <Alert tone="success">{statusMessage(p.status)}</Alert>}
            {p.scope.restricted && (
                <Alert tone="info">
                    This industry doesn’t allow referral fees: scripts can’t mention them, and a platform admin approves each script.
                </Alert>
            )}
            <Card className="space-y-3 p-5">
                <h2 className="flex items-center gap-2 font-semibold">
                    <Clapperboard className="size-5 text-primary" aria-hidden /> New video
                </h2>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Title" error={f.errors.title}>
                        <Input value={f.data.title} onChange={(e) => f.setData('title', e.target.value)} />
                    </Field>
                    {p.lessons.length > 0 && (
                        <Field label="Lesson" error={(f.errors as Record<string, string>).lesson}>
                            <select
                                className="h-10 w-full rounded-md border border-line bg-surface px-2"
                                value={f.data.lesson}
                                onChange={(e) => f.setData('lesson', e.target.value)}
                            >
                                {p.lessons.map((l, i) => (
                                    <option key={i} value={i}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                </div>
                <Field label="Script (what the presenter says)" error={f.errors.script}>
                    <textarea rows={8} className={area} value={f.data.script} onChange={(e) => f.setData('script', e.target.value)} />
                </Field>
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => f.setData({ ...f.data, title: f.data.title || 'How referrals work here', script: p.defaultScript })}
                    >
                        Start from the default script
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => router.post(`${p.base}/draft`, {}, opts)}>
                        <Sparkles className="size-4" aria-hidden /> Draft with AI from the rules
                    </Button>
                    <Button
                        size="sm"
                        disabled={!f.data.title || f.data.script.length < 20}
                        loading={f.processing}
                        onClick={() => f.post(`${p.base}/scripts`, { ...opts, onSuccess: () => f.reset() })}
                    >
                        Save draft
                    </Button>
                </div>
            </Card>
            {p.scripts.map((s) => (
                <ScriptCard key={s.id} s={s} p={p} />
            ))}
        </div>
    );
    return p.scope.network ? (
        <PortalLayout title={`${p.scope.network}: training video`} user={{ name: auth.user?.name ?? '', team: '' }}>
            {body}
        </PortalLayout>
    ) : (
        <AdminLayout title={`${p.scope.industry}: training videos`}>{body}</AdminLayout>
    );
}
