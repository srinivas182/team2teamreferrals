import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

export type Item = {
    id: string;
    status: string;
    client: string;
    territory: string;
    group: string;
    sender: string;
    receiver: string | null;
    respondBy: string;
    when: string;
};

export const tone = (s: string) => (s === 'accepted' ? 'success' : s === 'sent' ? 'warning' : 'neutral') as 'success' | 'warning' | 'neutral';

export function useNow() {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const t = window.setInterval(() => setNow(Date.now()), 30000);
        return () => window.clearInterval(t);
    }, []);
    return now;
}

export function left(iso: string, now: number) {
    const ms = new Date(iso).getTime() - now;
    if (ms <= 0) return 'due now';
    const h = Math.floor(ms / 3.6e6);
    return h >= 1 ? `${h}h ${Math.floor((ms % 3.6e6) / 6e4)}m left` : `${Math.floor(ms / 6e4)}m left`;
}

export function ReferralList({
    title,
    items,
    mode,
    status,
    seo,
    empty,
}: {
    title: string;
    items: Item[];
    mode: 'in' | 'out';
    status?: string;
    seo: Seo;
    empty: string;
}) {
    const { auth } = usePage<SharedProps>().props;
    const now = useNow();
    return (
        <PortalLayout title={title} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <Card className="max-w-4xl">
                {items.length === 0 && <p className="p-8 text-center text-muted">{empty}</p>}
                <ul className="divide-y divide-line">
                    {items.map((r) => (
                        <li key={r.id}>
                            <Link href={`/app/referrals/${r.id}`} className="flex flex-wrap items-center gap-3 p-4 hover:bg-bg">
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">
                                        {r.client} · {r.territory}
                                    </div>
                                    <div className="text-sm text-muted">
                                        {mode === 'in' ? `From ${r.sender}` : `To ${r.receiver ?? '—'}`} · {r.group}
                                    </div>
                                </div>
                                {r.status === 'sent' && <span className="text-sm text-warning tabular">{left(r.respondBy, now)}</span>}
                                <Badge tone={tone(r.status)}>
                                    {r.status === 'sent' ? (mode === 'in' ? 'Waiting for you' : 'Waiting') : r.status}
                                </Badge>
                            </Link>
                        </li>
                    ))}
                </ul>
            </Card>
        </PortalLayout>
    );
}
