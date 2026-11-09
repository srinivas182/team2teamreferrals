import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { HealthBadge, type Health } from '@/components/admin/HealthBadge';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Entry = {
    id: number;
    channel: string;
    provider: string;
    recipient: string;
    subject: string | null;
    status: string;
    error: string | null;
    when: string;
    ago: string;
};
type Filters = { channel?: string; status?: string; q?: string };
const tone: Record<string, BadgeTone> = { sent: 'info', delivered: 'success', failed: 'danger', bounced: 'danger' };

export default function Deliveries({
    entries,
    pagination,
    filters,
    health,
    seo,
}: {
    entries: Entry[];
    pagination: { current: number; last: number; total: number };
    filters: Filters;
    health: { mail: Health; sms: Health };
    seo: Seo;
}) {
    const [f, setF] = useState<Filters>(filters);
    const apply = (e?: FormEvent, page = 1) => {
        e?.preventDefault();
        router.get('/admin/deliveries', { ...f, page }, { preserveState: true, preserveScroll: true });
    };
    return (
        <AdminLayout title="Delivery log">
            <SeoHead seo={seo} />
            <div className="mb-4 flex flex-wrap gap-4 text-sm">
                <span className="flex items-center gap-2">
                    Email <HealthBadge health={health.mail} />
                </span>
                <span className="flex items-center gap-2">
                    Text <HealthBadge health={health.sms} />
                </span>
            </div>
            <Card className="mb-4 p-4">
                <form onSubmit={apply} className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
                    <Field label="Recipient">
                        <Input placeholder="Email or phone" value={f.q ?? ''} onChange={(e) => setF({ ...f, q: e.target.value })} />
                    </Field>
                    <Field label="Channel">
                        <Select value={f.channel ?? ''} onChange={(e) => setF({ ...f, channel: e.target.value })}>
                            <option value="">All</option>
                            <option value="mail">Email</option>
                            <option value="sms">Text</option>
                        </Select>
                    </Field>
                    <Field label="Status">
                        <Select value={f.status ?? ''} onChange={(e) => setF({ ...f, status: e.target.value })}>
                            <option value="">All</option>
                            {['sent', 'delivered', 'failed', 'bounced'].map((s) => (
                                <option key={s} value={s}>
                                    {s[0]!.toUpperCase() + s.slice(1)}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Button type="submit">
                        <Search /> Filter
                    </Button>
                </form>
            </Card>
            <p className="mb-2 text-sm text-muted">{pagination.total.toLocaleString()} messages</p>
            <Card className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['When', 'Channel', 'To', 'Message', 'Status'].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {entries.map((e) => (
                            <tr key={e.id} className="border-t border-line align-top">
                                <td className="px-4 py-3 whitespace-nowrap">
                                    <time dateTime={e.when} title={e.when}>
                                        {e.ago}
                                    </time>
                                </td>
                                <td className="px-4 py-3">
                                    {e.channel === 'mail' ? 'Email' : 'Text'} <span className="text-muted">· {e.provider}</span>
                                </td>
                                <td className="px-4 py-3 break-all">{e.recipient}</td>
                                <td className="px-4 py-3">{e.subject}</td>
                                <td className="px-4 py-3">
                                    <Badge tone={tone[e.status] ?? 'neutral'}>{e.status[0]!.toUpperCase() + e.status.slice(1)}</Badge>
                                    {e.error && <div className="mt-1 max-w-xs text-xs text-danger dark:text-[#FDA4AF]">{e.error}</div>}
                                </td>
                            </tr>
                        ))}
                        {entries.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-10 text-center text-muted">
                                    No messages match these filters.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </Card>
            {pagination.last > 1 && (
                <nav className="mt-4 flex items-center justify-between" aria-label="Pagination">
                    <Button variant="secondary" size="sm" disabled={pagination.current <= 1} onClick={() => apply(undefined, pagination.current - 1)}>
                        Newer
                    </Button>
                    <span className="text-sm text-muted">
                        Page {pagination.current} of {pagination.last}
                    </span>
                    <Button
                        variant="secondary"
                        size="sm"
                        disabled={pagination.current >= pagination.last}
                        onClick={() => apply(undefined, pagination.current + 1)}
                    >
                        Older
                    </Button>
                </nav>
            )}
        </AdminLayout>
    );
}
