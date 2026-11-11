import { usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Row = {
    id: string;
    client: string;
    territory: string;
    temperature: string | null;
    nextAt: string | null;
    protectedUntil: string | null;
    other: string;
    last: string | null;
};

const lastWords: Record<string, string> = {
    still: 'Still nurturing',
    ready: 'Ready now',
    client_ready: 'Client says ready',
    client_later: 'Client still planning',
    client_not_needed: 'Client no longer needs help',
    lost: 'Lost',
};

function Table({ rows, received }: { rows: Row[]; received: boolean }) {
    return (
        <Card className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
                <thead className="text-left text-muted">
                    <tr>
                        {['Lead', received ? 'From' : 'With', 'Last update', 'Next check-in', 'Protected until'].map((h) => (
                            <th key={h} scope="col" className="px-4 py-3 font-medium">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={5} className="px-4 py-6 text-center text-muted">
                                No long-term leads right now.
                            </td>
                        </tr>
                    )}
                    {rows.map((r) => (
                        <tr key={r.id} className="border-t border-line">
                            <td className="px-4 py-3">
                                <a href={`/app/referrals/${r.id}`} className="font-medium hover:underline">
                                    {r.client}
                                </a>{' '}
                                <Badge tone={r.temperature === 'hot' ? 'danger' : 'info'}>{r.temperature ?? '—'}</Badge>
                                <div className="text-xs text-muted">{r.territory}</div>
                            </td>
                            <td className="px-4 py-3">{r.other}</td>
                            <td className="px-4 py-3 text-muted">{r.last ? (lastWords[r.last] ?? r.last) : '—'}</td>
                            <td className="px-4 py-3 tabular">{r.nextAt ?? '—'}</td>
                            <td className="px-4 py-3 tabular">{r.protectedUntil ?? '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Card>
    );
}

/** Portal → Nurture: long-term (Future) leads, so none are forgotten. */
export default function Nurture({ received, sent, seo }: { received: Row[]; sent: Row[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Nurture" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-6">
                <section>
                    <h2 className="mb-2 font-semibold">Leads you’re nurturing</h2>
                    <Table rows={received} received />
                </section>
                <section>
                    <h2 className="mb-2 font-semibold">Leads you referred</h2>
                    <p className="mb-2 text-sm text-muted">Progress only: the receiving team handles the client.</p>
                    <Table rows={sent} received={false} />
                </section>
            </div>
        </PortalLayout>
    );
}
