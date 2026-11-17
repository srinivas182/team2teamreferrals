import { Link } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Props = {
    conversation: { id: string; audience: string; page: string | null; started: string; ticket: { ulid: string; number: string } | null };
    messages: {
        role: string;
        body: string;
        mode: string | null;
        answered: boolean | null;
        helpful: boolean | null;
        sources: { title: string; href: string }[];
        at: string;
    }[];
    seo: Seo;
};

/** One Ally conversation, as saved (personal details removed). */
export default function AllyConversation({ conversation, messages, seo }: Props) {
    return (
        <AdminLayout
            title="Ally conversation"
            actions={
                <Button variant="secondary" size="sm" asChild>
                    <Link href="/admin/ally">Back to Ally</Link>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            <div className="max-w-3xl space-y-3">
                <p className="text-sm text-muted">
                    {conversation.audience} · started {conversation.started}
                    {conversation.page ? ` on ${conversation.page}` : ''}
                    {conversation.ticket && (
                        <>
                            {' · '}
                            <Link href={`/admin/support/${conversation.ticket.ulid}`} className="text-primary">
                                {conversation.ticket.number}
                            </Link>
                        </>
                    )}
                </p>
                {messages.map((m, i) => (
                    <Card key={i} className={`p-4 ${m.role === 'user' ? '' : 'bg-surface'}`}>
                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
                            <span className="font-medium text-ink">{m.role === 'user' ? 'Person' : 'Ally'}</span> {m.at}
                            {m.mode && <Badge>{m.mode}</Badge>}
                            {m.answered === false && <Badge tone="warning">Couldn’t answer</Badge>}
                            {m.helpful === true && <Badge tone="success">Helpful</Badge>}
                            {m.helpful === false && <Badge tone="danger">Not helpful</Badge>}
                        </div>
                        <p className="mt-2 text-sm whitespace-pre-line">{m.body}</p>
                        {m.sources.length > 0 && <p className="mt-1 text-xs text-muted">Sources: {m.sources.map((s) => s.title).join('; ')}</p>}
                    </Card>
                ))}
            </div>
        </AdminLayout>
    );
}
