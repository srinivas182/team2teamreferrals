import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Textarea } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type M = { type: string; label: string; subject: string; body: string; sms: string; custom: boolean; placeholders: string[] };

function Editor({ m, slug }: { m: M; slug: string }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [d, setD] = useState({ subject: m.subject, body: m.body, sms: m.sms });
    return (
        <Card className="space-y-3 p-5">
            <div className="flex items-center gap-2">
                <h2 className="font-semibold">{m.label}</h2>
                {m.custom && <Badge tone="info">Your wording</Badge>}
            </div>
            <Field label="Subject" error={errors.subject}>
                <Input value={d.subject} onChange={(e) => setD({ ...d, subject: e.target.value })} />
            </Field>
            <Field label="Message" error={errors.body}>
                <Textarea rows={2} value={d.body} onChange={(e) => setD({ ...d, body: e.target.value })} />
            </Field>
            <Field label="Text message" hint="Keep it short; we add your network’s name and opt-out wording." error={errors.sms}>
                <Input value={d.sms} onChange={(e) => setD({ ...d, sms: e.target.value })} />
            </Field>
            <p className="text-xs text-muted">You can use: {m.placeholders.map((p) => `{${p}}`).join(' ')}</p>
            <div className="flex gap-2">
                <Button size="sm" onClick={() => router.post(`/app/group/${slug}/messages`, { type: m.type, ...d }, { preserveScroll: true })}>
                    Save
                </Button>
                {m.custom && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => router.delete(`/app/group/${slug}/messages/${m.type}`, { preserveScroll: true })}
                    >
                        Use the industry’s wording
                    </Button>
                )}
            </div>
        </Card>
    );
}

export default function GroupMessages({
    group,
    messages,
    status,
    seo,
}: {
    group: { slug: string; name: string };
    messages: M[];
    status?: string;
    seo: Seo;
}) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title={`${group.name}: message wording`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-3xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="max-w-3xl space-y-4">
                {messages.map((m) => (
                    <Editor key={m.type} m={m} slug={group.slug} />
                ))}
            </div>
        </PortalLayout>
    );
}
