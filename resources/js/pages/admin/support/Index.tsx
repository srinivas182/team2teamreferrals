import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Row = {
    id: string;
    number: string;
    subject: string;
    name: string;
    email: string;
    status: string;
    priority: string;
    category: string;
    assignee: string | null;
    updated: string;
    due: { label: string; overdue: boolean } | null;
    source: string;
};
type Props = {
    view: string;
    q: string;
    priority: string;
    views: { key: string; label: string; count: number | null }[];
    tickets: Row[];
    stats: { open: number; overdue: number; firstResponseHours: number | null; satisfaction: number | null };
    seo: Seo;
};
const prio: Record<string, 'danger' | 'warning' | 'info' | 'neutral'> = { urgent: 'danger', high: 'warning', normal: 'info', low: 'neutral' };

/** Admin → Support: the ticket inbox, most urgent and soonest due first. */
export default function SupportInbox({ view, q, priority, views, tickets, stats, seo }: Props) {
    const [search, setSearch] = useState(q);
    const go = (o: Record<string, string>) => router.get('/admin/support', { view, q: search, priority, ...o }, { preserveState: true });
    return (
        <AdminLayout
            title="Support"
            actions={
                <Button variant="secondary" size="sm" asChild>
                    <Link href="/admin/support/settings">Saved replies and targets</Link>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            <div className="mb-4 grid max-w-5xl gap-3 sm:grid-cols-4">
                {(
                    [
                        ['Open', stats.open],
                        ['Past response time', stats.overdue],
                        ['First reply (30 days)', stats.firstResponseHours !== null ? `${stats.firstResponseHours} h` : '–'],
                        ['Satisfaction (30 days)', stats.satisfaction !== null ? `${stats.satisfaction}%` : '–'],
                    ] as [string, string | number][]
                ).map(([l, v]) => (
                    <Card key={l} className="p-4">
                        <div className="text-sm text-muted">{l}</div>
                        <div className="mt-1 font-display text-2xl font-bold">{v}</div>
                    </Card>
                ))}
            </div>
            <nav aria-label="Ticket views" className="mb-3 flex flex-wrap gap-2">
                {views.map((v) => (
                    <Button key={v.key} size="sm" variant={v.key === view ? 'primary' : 'secondary'} onClick={() => go({ view: v.key })}>
                        {v.label}
                        {v.count !== null ? ` (${v.count})` : ''}
                    </Button>
                ))}
            </nav>
            <form
                className="mb-4 flex max-w-5xl flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    go({});
                }}
            >
                <Input
                    aria-label="Search tickets"
                    placeholder="Number, subject, name or email"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="h-9 min-w-64 flex-1"
                />
                <Select aria-label="Priority" value={priority} onChange={(e) => go({ priority: e.target.value })} className="h-9 w-40">
                    <option value="">Any priority</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="normal">Normal</option>
                    <option value="low">Low</option>
                </Select>
                <Button type="submit" size="sm">
                    Search
                </Button>
            </form>
            <div className="max-w-5xl overflow-x-auto rounded-lg border border-line">
                {tickets.length === 0 ? (
                    <p className="p-10 text-center text-muted">Nothing here.</p>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-surface text-left text-muted">
                            <tr>
                                <th className="p-2">Ticket</th>
                                <th className="p-2">From</th>
                                <th className="p-2">Priority</th>
                                <th className="p-2">Assigned</th>
                                <th className="p-2">Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tickets.map((t) => (
                                <tr key={t.id} className="border-t border-line">
                                    <td className="p-2">
                                        <Link href={`/admin/support/${t.id}`} className="font-medium text-primary">
                                            {t.subject}
                                        </Link>
                                        <div className="text-xs text-muted">
                                            {t.number} · {t.category} · {t.status}
                                            {t.source !== 'web' ? ` · by ${t.source}` : ''}
                                        </div>
                                    </td>
                                    <td className="p-2">
                                        {t.name}
                                        <div className="text-xs text-muted">{t.email}</div>
                                    </td>
                                    <td className="p-2">
                                        <Badge tone={prio[t.priority] ?? 'neutral'}>{t.priority}</Badge>
                                    </td>
                                    <td className="p-2">{t.assignee ?? <span className="text-muted">Nobody</span>}</td>
                                    <td className={`p-2 ${t.due?.overdue ? 'font-medium text-danger' : ''}`}>{t.due?.label ?? '–'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AdminLayout>
    );
}
