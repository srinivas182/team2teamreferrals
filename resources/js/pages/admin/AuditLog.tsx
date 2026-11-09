import { router } from '@inertiajs/react';
import { Download, Search } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Entry = {
    id: number;
    when: string | null;
    ago: string | null;
    log: string | null;
    event: string | null;
    description: string;
    causer: string | null;
    subject: string | null;
    ip: string | null;
    device: string | null;
    changes: { old?: Record<string, unknown> | string[]; new?: Record<string, unknown> | string[] };
};
type Filters = { q?: string; log?: string; from?: string; to?: string };

const logTone: Record<string, BadgeTone> = { auth: 'info', security: 'warning', accounts: 'neutral', admin: 'sender' };

export default function AuditLog({
    entries,
    pagination,
    filters,
    logs,
    seo,
}: {
    entries: Entry[];
    pagination: { current: number; last: number; total: number };
    filters: Filters;
    logs: Record<string, string>;
    seo: Seo;
}) {
    const [f, setF] = useState<Filters>(filters);
    const apply = (e?: FormEvent, page = 1) => {
        e?.preventDefault();
        router.get('/admin/audit', { ...f, page }, { preserveState: true, preserveScroll: true });
    };
    const exportUrl = '/admin/audit/export?' + new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString();

    return (
        <AdminLayout
            title="Audit log"
            actions={
                <Button variant="secondary" asChild>
                    <a href={exportUrl}>
                        <Download /> Export CSV
                    </a>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            <Card className="mb-4 p-4">
                <form onSubmit={apply} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto] lg:items-end">
                    <Field label="Person or description">
                        <Input placeholder="Name, email or text" value={f.q ?? ''} onChange={(e) => setF({ ...f, q: e.target.value })} />
                    </Field>
                    <Field label="Type">
                        <Select value={f.log ?? ''} onChange={(e) => setF({ ...f, log: e.target.value })}>
                            <option value="">All</option>
                            {Object.entries(logs).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="From">
                        <Input type="date" value={f.from ?? ''} onChange={(e) => setF({ ...f, from: e.target.value })} />
                    </Field>
                    <Field label="To">
                        <Input type="date" value={f.to ?? ''} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    </Field>
                    <Button type="submit">
                        <Search /> Filter
                    </Button>
                </form>
            </Card>
            <p className="mb-2 text-sm text-muted">{pagination.total.toLocaleString()} entries</p>
            <Card className="overflow-x-auto">
                <table className="w-full min-w-[900px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['When', 'Type', 'What happened', 'By', 'From'].map((h) => (
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
                                    <time dateTime={e.when ?? undefined} title={e.when ?? undefined}>
                                        {e.ago}
                                    </time>
                                </td>
                                <td className="px-4 py-3">{e.log && <Badge tone={logTone[e.log] ?? 'neutral'}>{logs[e.log] ?? e.log}</Badge>}</td>
                                <td className="px-4 py-3">
                                    <div className="font-medium">{e.description}</div>
                                    {e.subject && <div className="text-muted">Account: {e.subject}</div>}
                                    {(e.changes.old || e.changes.new) && (
                                        <details className="mt-1">
                                            <summary className="cursor-pointer text-primary">Changes</summary>
                                            <pre className="mt-1 overflow-x-auto rounded bg-bg p-2 text-xs">{JSON.stringify(e.changes, null, 2)}</pre>
                                        </details>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-muted">{e.causer}</td>
                                <td className="px-4 py-3 text-muted">
                                    {e.ip}
                                    {e.device && <div>{e.device}</div>}
                                </td>
                            </tr>
                        ))}
                        {entries.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-10 text-center text-muted">
                                    Nothing matches these filters.
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
