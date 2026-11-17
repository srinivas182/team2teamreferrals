import { router, useForm, usePage } from '@inertiajs/react';
import { Paperclip, ThumbsDown, ThumbsUp } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Textarea } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { PublicLayout } from '@/layouts/PublicLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Msg = {
    id: number;
    author: 'customer' | 'staff' | 'system';
    name: string | null;
    body: string;
    at: string;
    files: { name: string; href: string }[];
};
type Props = {
    ticket: {
        id: string;
        number: string;
        subject: string;
        status: string;
        statusLabel: string;
        category: string;
        created: string;
        rating: string | null;
    };
    messages: Msg[];
    base: string;
    status?: string;
    seo: Seo;
};

/** A ticket from the customer's side: the conversation, a reply box, "this is solved" and "how did we do?". */
export default function SupportTicket({ ticket, messages, base, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const f = useForm({ body: '', files: [] as File[] });
    const [comment, setComment] = useState('');
    const solved = ['solved', 'closed'].includes(ticket.status);
    const body = (
        <div className="max-w-3xl space-y-4">
            <div>
                <p className="font-mono text-sm text-muted">{ticket.number}</p>
                <h1 className="font-display text-2xl font-bold">{ticket.subject}</h1>
                <p className="mt-1 text-sm text-muted">
                    {ticket.category} ·{' '}
                    <Badge tone={ticket.status === 'pending' ? 'warning' : solved ? 'success' : 'info'}>{ticket.statusLabel}</Badge>
                </p>
            </div>
            {status && <Alert tone="success">{statusMessage(status)}</Alert>}
            <ol className="space-y-3">
                {messages.map((m) => (
                    <li key={m.id}>
                        <Card className={`p-4 ${m.author === 'staff' ? 'border-primary/40' : ''}`}>
                            <div className="text-sm text-muted">
                                <span className="font-medium text-ink">{m.author === 'customer' ? 'You' : m.name}</span> · {m.at}
                            </div>
                            <p className="mt-2 whitespace-pre-line">{m.body}</p>
                            {m.files.length > 0 && (
                                <ul className="mt-2 flex flex-wrap gap-3 text-sm">
                                    {m.files.map((x) => (
                                        <li key={x.href}>
                                            <a href={x.href} className="flex items-center gap-1 text-primary">
                                                <Paperclip className="size-3.5" aria-hidden /> {x.name}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </li>
                ))}
            </ol>
            <form
                className="space-y-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    f.post(`${base}/reply`, { forceFormData: true, preserveScroll: true, onSuccess: () => f.reset() });
                }}
            >
                <Field label={ticket.status === 'closed' ? 'Write again (this starts a new ticket)' : 'Reply'} error={f.errors.body}>
                    <Textarea rows={4} value={f.data.body} onChange={(e) => f.setData('body', e.target.value)} />
                </Field>
                <Input
                    type="file"
                    multiple
                    aria-label="Attach files"
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    className="py-2"
                    onChange={(e) => f.setData('files', Array.from(e.target.files ?? []).slice(0, 3))}
                />
                <div className="flex flex-wrap gap-2">
                    <Button type="submit" loading={f.processing} disabled={!f.data.body.trim()}>
                        Send reply
                    </Button>
                    {!solved && (
                        <Button type="button" variant="secondary" onClick={() => router.post(`${base}/solve`, {}, { preserveScroll: true })}>
                            This is solved
                        </Button>
                    )}
                </div>
            </form>
            {solved && !ticket.rating && (
                <Card className="space-y-2 p-4">
                    <h2 className="font-semibold">How did we do?</h2>
                    <Input
                        aria-label="Anything to add (optional)"
                        placeholder="Anything to add? (optional)"
                        value={comment}
                        onChange={(e) => setComment(e.target.value)}
                    />
                    <div className="flex gap-2">
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => router.post(`${base}/rate`, { rating: 'good', comment }, { preserveScroll: true })}
                        >
                            <ThumbsUp /> Good
                        </Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => router.post(`${base}/rate`, { rating: 'bad', comment }, { preserveScroll: true })}
                        >
                            <ThumbsDown /> Not good
                        </Button>
                    </div>
                </Card>
            )}
        </div>
    );
    return base.startsWith('/app/') ? (
        <PortalLayout title="Help and support" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {body}
        </PortalLayout>
    ) : (
        <Public seo={seo}>{body}</Public>
    );
}

function Public({ seo, children }: { seo: Seo; children: ReactNode }) {
    return (
        <PublicLayout current="/help">
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-3xl px-4 py-10">{children}</div>
        </PublicLayout>
    );
}
