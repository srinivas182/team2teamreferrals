import { Link, router, useForm } from '@inertiajs/react';
import { History, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Version = { id: number; version: number; subject: string | null; body: string; active: boolean; when: string };
type Channel = { channel: string; label: string; current: { subject: string; body: string }; history: Version[] };
type Props = {
    type: { key: string; label: string; description: string; urgent: boolean; locked: boolean };
    channels: Channel[];
    mergeFields: string[];
    sample: Record<string, string | number>;
    status?: string;
    seo: Seo;
};

const render = (t: string, data: Record<string, string | number>) => t.replace(/\{([a-z_]+)\}/g, (m, k: string) => (k in data ? String(data[k]) : m));

function ChannelEditor({ type, c, mergeFields, sample }: { type: string; c: Channel; mergeFields: string[]; sample: Props['sample'] }) {
    const form = useForm({ channel: c.channel, subject: c.current.subject, body: c.current.body });
    const isSms = c.channel === 'sms';
    return (
        <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
            <Card className="p-6">
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/admin/templates/${type}`, { preserveScroll: true });
                    }}
                >
                    {!isSms && (
                        <Field label={c.channel === 'mail' ? 'Subject' : 'Title'} error={form.errors.subject}>
                            <Input value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                        </Field>
                    )}
                    <Field
                        label="Message"
                        hint={isSms ? `${form.data.body.length}/320 characters. STOP wording is added by the carrier.` : undefined}
                        error={form.errors.body}
                    >
                        <Textarea rows={isSms ? 3 : 6} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                    </Field>
                    <div className="flex flex-wrap gap-1.5" aria-label="Merge fields">
                        {mergeFields.map((m) => (
                            <button
                                key={m}
                                type="button"
                                className="rounded border border-line px-2 py-0.5 font-mono text-xs hover:border-primary"
                                onClick={() => form.setData('body', `${form.data.body}{${m}}`)}
                            >
                                {`{${m}}`}
                            </button>
                        ))}
                    </div>
                    <div className="flex gap-2">
                        <Button type="submit" loading={form.processing}>
                            Save as new version
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => router.post(`/admin/templates/${type}/${c.channel}/reset`, {}, { preserveScroll: true })}
                        >
                            <RotateCcw /> Use default
                        </Button>
                    </div>
                </form>
            </Card>
            <div className="space-y-6">
                <Card className="p-5">
                    <h3 className="text-sm font-semibold text-muted">Preview with sample data</h3>
                    {!isSms && <div className="mt-3 font-semibold">{render(form.data.subject, sample)}</div>}
                    <p className={cn('mt-2 text-sm', isSms && 'rounded-2xl rounded-bl-sm bg-bg p-3')}>{render(form.data.body, sample)}</p>
                </Card>
                <Card className="p-5">
                    <h3 className="flex items-center gap-2 text-sm font-semibold">
                        <History className="size-4" aria-hidden /> Versions
                    </h3>
                    {c.history.length === 0 ? (
                        <p className="mt-2 text-sm text-muted">Using the default wording. Saving creates version 1.</p>
                    ) : (
                        <ul className="mt-3 space-y-2 text-sm">
                            {c.history.map((v) => (
                                <li key={v.id} className="flex items-center gap-2">
                                    <span className="font-medium">v{v.version}</span>
                                    <span className="text-muted">{v.when}</span>
                                    {v.active ? (
                                        <Badge tone="success" className="ml-auto">
                                            Active
                                        </Badge>
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="ml-auto"
                                            onClick={() => router.post(`/admin/templates/versions/${v.id}/activate`, {}, { preserveScroll: true })}
                                        >
                                            Restore
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </div>
    );
}

export default function TemplateEdit({ type, channels, mergeFields, sample, status, seo }: Props) {
    const [tab, setTab] = useState(channels[0]?.channel ?? 'mail');
    const current = channels.find((c) => c.channel === tab);
    return (
        <AdminLayout title={type.label}>
            <SeoHead seo={seo} />
            <Link href="/admin/templates" className="text-sm text-muted hover:text-ink">
                All templates
            </Link>
            <p className="mt-2 mb-4 max-w-3xl text-muted">
                {type.description} {type.urgent && <Badge tone="warning">Urgent</Badge>} {type.locked && <Badge>Members can’t turn off</Badge>}
            </p>
            {status && (
                <Alert tone="success" className="mb-4 max-w-3xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="mb-6 flex gap-1 border-b border-line" role="tablist">
                {channels.map((c) => (
                    <button
                        key={c.channel}
                        role="tab"
                        aria-selected={tab === c.channel}
                        onClick={() => setTab(c.channel)}
                        className={cn(
                            '-mb-px border-b-2 px-4 py-2.5 text-sm font-medium',
                            tab === c.channel ? 'border-primary' : 'border-transparent text-muted hover:text-ink',
                        )}
                    >
                        {c.label}
                    </button>
                ))}
            </div>
            {current && (
                <ChannelEditor key={current.channel + current.history.length} type={type.key} c={current} mergeFields={mergeFields} sample={sample} />
            )}
        </AdminLayout>
    );
}
