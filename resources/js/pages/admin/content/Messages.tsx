import { router } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { ContentTabs } from '@/pages/admin/content/Tabs';
import type { Seo } from '@/types/seo';

type M = { id: number; name: string; email: string; topic: string; message: string; status: string; created_at: string };

/** Admin → Website → Messages from the Contact page. */
export default function Messages({ messages, status, seo }: { messages: M[]; status?: string; seo: Seo }) {
    return (
        <AdminLayout title="Website">
            <SeoHead seo={seo} />
            <ContentTabs current="/admin/content/messages" />
            <div className="max-w-4xl space-y-3">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                {messages.length === 0 && <p className="text-muted">No messages yet.</p>}
                {messages.map((m) => (
                    <Card key={m.id} className="space-y-2 p-4 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-semibold">{m.name}</span>
                            <a href={`mailto:${m.email}`} className="text-primary">
                                {m.email}
                            </a>
                            <Badge tone="info">{m.topic}</Badge>
                            <span className="ml-auto text-xs text-muted">{m.created_at.slice(0, 16)}</span>
                        </div>
                        <p className="whitespace-pre-wrap">{m.message}</p>
                        {m.status === 'new' ? (
                            <button
                                className="text-xs text-primary"
                                onClick={() => router.post(`/admin/content/messages/${m.id}/handled`, {}, { preserveScroll: true })}
                            >
                                Mark as handled
                            </button>
                        ) : (
                            <span className="text-xs text-muted">Handled</span>
                        )}
                    </Card>
                ))}
            </div>
        </AdminLayout>
    );
}
