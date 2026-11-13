import { router } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { ContentTabs } from '@/pages/admin/content/Tabs';
import type { Seo } from '@/types/seo';

type T = { id: number; quote: string; author_name: string; business: string | null; status: string; consented_at: string };

/** Admin → Website → Testimonials: members’ quotes with recorded consent. Approved ones can be added to pages. */
export default function Testimonials({ testimonials, status, seo }: { testimonials: T[]; status?: string; seo: Seo }) {
    const decide = (id: number, d: string) => router.post(`/admin/content/testimonials/${id}/${d}`, {}, { preserveScroll: true });
    return (
        <AdminLayout title="Website">
            <SeoHead seo={seo} />
            <ContentTabs current="/admin/content/testimonials" />
            <div className="max-w-4xl space-y-3">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                {testimonials.length === 0 && (
                    <p className="text-muted">No testimonials yet. Members can share one from the portal (Share your experience).</p>
                )}
                {testimonials.map((t) => (
                    <Card key={t.id} className="space-y-2 p-4 text-sm">
                        <p>“{t.quote}”</p>
                        <p className="text-muted">
                            {t.author_name}
                            {t.business ? `, ${t.business}` : ''} · consent given {t.consented_at.slice(0, 10)}
                        </p>
                        <div className="flex items-center gap-3">
                            <Badge tone={t.status === 'approved' ? 'success' : t.status === 'pending' ? 'warning' : 'neutral'}>{t.status}</Badge>
                            {t.status === 'pending' && (
                                <>
                                    <button className="text-primary" onClick={() => decide(t.id, 'approve')}>
                                        Approve
                                    </button>
                                    <button className="text-muted underline" onClick={() => decide(t.id, 'decline')}>
                                        Decline
                                    </button>
                                </>
                            )}
                            {t.status === 'approved' && (
                                <button className="text-muted underline" onClick={() => decide(t.id, 'decline')}>
                                    Remove from use
                                </button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
        </AdminLayout>
    );
}
