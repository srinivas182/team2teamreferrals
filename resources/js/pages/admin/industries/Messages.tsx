import { Link, router, useForm } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
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

type Wording = { subject: string; body: string; source: 'industry' | 'platform' | 'default' };
type Channel = {
    channel: string;
    label: string;
    current: Wording;
    inherited: Wording;
    history: { id: number; version: number; active: boolean; when: string }[];
};
type Props = {
    industry: { slug: string; name: string; tier: string; terms: Record<string, string> };
    types: { key: string; group: string; label: string; overridden: boolean }[];
    type: { key: string; label: string; description: string };
    channels: Channel[];
    sample: Record<string, string>;
    mergeFields: string[];
    status?: string;
    seo: Seo;
};

const render = (t: string, d: Record<string, string>) => t.replace(/\{([a-z_]+)\}/g, (m, k: string) => d[k] ?? m);

function Editor({
    slug,
    type,
    c,
    sample,
    mergeFields,
}: {
    slug: string;
    type: string;
    c: Channel;
    sample: Record<string, string>;
    mergeFields: string[];
}) {
    const form = useForm({ type, channel: c.channel, subject: c.current.subject, body: c.current.body });
    const sms = c.channel === 'sms';
    const own = c.current.source === 'industry';
    return (
        <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
            <Card className="p-6">
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    {own ? <Badge tone="info">This industry’s wording</Badge> : <Badge>Inherited from the platform</Badge>}
                    {own && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.post(`/admin/industries/${slug}/messages/reset`, { type, channel: c.channel }, { preserveScroll: true })
                            }
                        >
                            <RotateCcw /> Use platform wording
                        </Button>
                    )}
                </div>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/admin/industries/${slug}/messages`, { preserveScroll: true });
                    }}
                >
                    {!sms && (
                        <Field label={c.channel === 'mail' ? 'Subject' : 'Title'} error={form.errors.subject}>
                            <Input value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                        </Field>
                    )}
                    <Field label="Message" hint={sms ? `${form.data.body.length}/320 characters` : undefined} error={form.errors.body}>
                        <Textarea rows={sms ? 3 : 5} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                    </Field>
                    <div className="flex flex-wrap gap-1.5">
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
                    <Button type="submit" loading={form.processing}>
                        Save as this industry’s wording
                    </Button>
                </form>
            </Card>
            <div className="space-y-6">
                <Card className="p-5">
                    <h3 className="text-sm font-semibold text-muted">How it reads</h3>
                    {!sms && <div className="mt-3 font-semibold">{render(form.data.subject, sample)}</div>}
                    <p className={cn('mt-2 text-sm', sms && 'rounded-2xl rounded-bl-sm bg-bg p-3')}>{render(form.data.body, sample)}</p>
                </Card>
                {own && (
                    <Card className="p-5">
                        <h3 className="text-sm font-semibold text-muted">Platform wording (used if you reset)</h3>
                        <p className="mt-2 text-sm">{render(c.inherited.body, sample)}</p>
                    </Card>
                )}
                {c.history.length > 0 && (
                    <Card className="p-5">
                        <h3 className="text-sm font-semibold">Versions</h3>
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
                                            onClick={() =>
                                                router.post(`/admin/industries/${slug}/messages/${v.id}/activate`, {}, { preserveScroll: true })
                                            }
                                        >
                                            Restore
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>
        </div>
    );
}

export default function Messages({ industry, types, type, channels, sample, mergeFields, status, seo }: Props) {
    const [tab, setTab] = useState(channels[0]?.channel ?? 'mail');
    const c = channels.find((x) => x.channel === tab);
    const groups = Array.from(new Set(types.map((t) => t.group)));
    const t = industry.terms;
    return (
        <AdminLayout title={`${industry.name}: messages`}>
            <SeoHead seo={seo} />
            <Link href={`/admin/industries/${industry.slug}`} className="text-sm text-muted hover:text-ink">
                {industry.name}
            </Link>
            <p className="mt-2 mb-4 max-w-3xl text-sm text-muted">
                In this industry a <strong className="text-ink">{t.pro}</strong> serves a <strong className="text-ink">{t.client}</strong>, and a
                referral is called{' '}
                {t.referral === 'referral' ? (
                    'a referral'
                ) : (
                    <>
                        an <strong className="text-ink">{t.referral}</strong>
                    </>
                )}
                . Messages inherit the platform wording until you give them this industry’s own.
            </p>
            {status && (
                <Alert tone="success" className="mb-4 max-w-3xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="grid gap-6 xl:grid-cols-[260px_1fr]">
                <Card className="h-fit p-2">
                    {groups.map((g) => (
                        <div key={g} className="mb-2">
                            <div className="px-3 pt-2 pb-1 text-xs font-semibold text-subtle uppercase">{g}</div>
                            {types
                                .filter((x) => x.group === g)
                                .map((x) => (
                                    <Link
                                        key={x.key}
                                        href={`/admin/industries/${industry.slug}/messages?type=${x.key}`}
                                        preserveScroll
                                        aria-current={x.key === type.key ? 'page' : undefined}
                                        className={cn(
                                            'flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-bg',
                                            x.key === type.key && 'bg-cobalt-50 font-medium dark:bg-cobalt-500/15',
                                        )}
                                    >
                                        <span className="flex-1">{x.label}</span>
                                        {x.overridden && <span className="size-2 rounded-full bg-primary" aria-label="Has industry wording" />}
                                    </Link>
                                ))}
                        </div>
                    ))}
                </Card>
                <div>
                    <h2 className="font-semibold">{type.label}</h2>
                    <p className="mb-4 text-sm text-muted">{type.description}</p>
                    <div className="mb-5 flex gap-1 border-b border-line" role="tablist">
                        {channels.map((x) => (
                            <button
                                key={x.channel}
                                role="tab"
                                aria-selected={tab === x.channel}
                                onClick={() => setTab(x.channel)}
                                className={cn(
                                    '-mb-px border-b-2 px-4 py-2.5 text-sm font-medium',
                                    tab === x.channel ? 'border-primary' : 'border-transparent text-muted hover:text-ink',
                                )}
                            >
                                {x.label}
                            </button>
                        ))}
                    </div>
                    {c && (
                        <Editor
                            key={type.key + c.channel + c.history.length + c.current.source}
                            slug={industry.slug}
                            type={type.key}
                            c={c}
                            sample={sample}
                            mergeFields={mergeFields}
                        />
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
