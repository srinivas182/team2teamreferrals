import { Link, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Row = { id: string; number: string; subject: string; status: string; statusLabel: string; updated: string };

/** Portal → Help and support: your tickets, the help centre and a way to ask something new. */
export default function MyTickets({ tickets, seo }: { tickets: Row[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Help and support" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-3xl space-y-4">
                <div className="flex flex-wrap gap-2">
                    <Button asChild>
                        <a href="/support/new">Contact support</a>
                    </Button>
                    <Button variant="secondary" asChild>
                        <a href="/help">Browse the help centre</a>
                    </Button>
                </div>
                {tickets.length === 0 ? (
                    <Card className="p-8 text-center text-muted">You haven’t contacted support yet.</Card>
                ) : (
                    <ul className="divide-y divide-line rounded-lg border border-line">
                        {tickets.map((t) => (
                            <li key={t.id} className="flex flex-wrap items-center gap-3 p-3">
                                <span className="font-mono text-sm text-muted">{t.number}</span>
                                <Link href={`/app/support/${t.id}`} className="flex-1 text-primary">
                                    {t.subject}
                                </Link>
                                <Badge tone={t.status === 'pending' ? 'warning' : t.status === 'open' ? 'info' : 'success'}>{t.statusLabel}</Badge>
                                <span className="text-sm text-muted">{t.updated}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PortalLayout>
    );
}
