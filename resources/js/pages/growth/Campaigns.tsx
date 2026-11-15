import { Link, useForm } from '@inertiajs/react';
import { GrowthTabs } from '@/components/growth/GrowthTabs';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

export type Stats = {
    enrolled: number;
    active: number;
    converted: number;
    completed: number;
    exited: number;
    sent: number;
    opened: number;
    clicked: number;
    replied: number;
    unsubscribed: number;
    bounced: number;
    revenue: number;
};
type Row = { id: string; name: string; kind: 'drip' | 'abandoned'; status: 'draft' | 'active' | 'paused'; steps: number; stats: Stats };

export const pct = (n: number, of: number) => (of > 0 ? `${Math.round((100 * n) / of)}%` : '—');
export const STATUS_TONE = { draft: 'neutral', active: 'success', paused: 'warning' } as const;

/** Admin → Growth → Campaigns. */
export default function Campaigns({ campaigns, sending, status, seo }: { campaigns: Row[]; sending: boolean; status?: string; seo: Seo }) {
    const f = useForm({ name: '' });
    return (
        <AdminLayout title="Growth">
            <SeoHead seo={seo} />
            <div className="max-w-6xl space-y-5">
                <GrowthTabs current="/admin/growth/campaigns" />
                {statusMessage(status) && <Alert tone="success">{statusMessage(status)}</Alert>}
                {!sending && (
                    <Alert tone="info">
                        Prospect email is switched off, so active campaigns wait and nothing is sent. Switch it on in Email settings once the sending
                        subdomain is set up.
                    </Alert>
                )}
                <Card className="flex flex-wrap items-end gap-2 p-4">
                    <Field label="New campaign" error={f.errors.name} className="min-w-64 flex-1">
                        <Input value={f.data.name} placeholder="e.g. Austin solar installers" onChange={(e) => f.setData('name', e.target.value)} />
                    </Field>
                    <Button loading={f.processing} disabled={f.data.name.trim() === ''} onClick={() => f.post('/admin/growth/campaigns')}>
                        Create
                    </Button>
                </Card>
                <Card className="overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs text-muted">
                            <tr>
                                <th className="p-3 font-medium">Campaign</th>
                                <th className="p-3 font-medium">People</th>
                                <th className="p-3 font-medium">Sent</th>
                                <th className="p-3 font-medium">Clicked</th>
                                <th className="p-3 font-medium">Replied</th>
                                <th className="p-3 font-medium">Unsubscribed</th>
                                <th className="p-3 font-medium">Reached goal</th>
                                <th className="p-3 font-medium">Revenue</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {campaigns.map((c) => (
                                <tr key={c.id}>
                                    <td className="p-3">
                                        <Link href={`/admin/growth/campaigns/${c.id}`} className="font-medium text-primary">
                                            {c.name}
                                        </Link>{' '}
                                        <Badge tone={STATUS_TONE[c.status]}>{c.status}</Badge>
                                        <span className="block text-xs text-muted">
                                            {c.kind === 'abandoned' ? 'Ready-made: people who sign up but don’t apply' : 'Drip'} · {c.steps} emails
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {c.stats.enrolled}
                                        <span className="block text-xs text-muted">{c.stats.active} in progress</span>
                                    </td>
                                    <td className="p-3">{c.stats.sent}</td>
                                    <td className="p-3">{pct(c.stats.clicked, c.stats.sent)}</td>
                                    <td className="p-3">{pct(c.stats.replied, c.stats.sent)}</td>
                                    <td className="p-3">{pct(c.stats.unsubscribed, c.stats.sent)}</td>
                                    <td className="p-3">
                                        {c.stats.converted} ({pct(c.stats.converted, c.stats.enrolled)})
                                    </td>
                                    <td className="p-3">${c.stats.revenue.toLocaleString()}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
                <p className="text-xs text-muted">
                    Opens are counted but not shown as a measure: Apple Mail opens every email by itself, so clicks, replies and joining are what
                    count.
                </p>
            </div>
        </AdminLayout>
    );
}
