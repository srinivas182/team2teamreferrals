import { Link, router, useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { ContentTabs } from '@/pages/admin/content/Tabs';
import type { Seo } from '@/types/seo';

type Page = { id: string; title: string; slug: string; status: string; template: string; publishAt: string | null; publishedAt: string | null };
type Redirect = { id: number; from_path: string; to_path: string; code: number; hits: number; automatic: boolean };

/** Admin → Website: the pages, and redirects. */
export default function ContentIndex({ pages, redirects, status, seo }: { pages: Page[]; redirects: Redirect[]; status?: string; seo: Seo }) {
    const f = useForm({ from: '', to: '', code: '301' });
    return (
        <AdminLayout title="Website">
            <SeoHead seo={seo} />
            <ContentTabs current="/admin/content" />
            <div className="max-w-5xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={() => router.post('/admin/content/core', {}, { preserveScroll: true })}>
                        Create core pages
                    </Button>
                    <Link href="/admin/content/new" className="inline-flex h-10 items-center rounded-md bg-primary px-4 font-semibold text-white">
                        New page
                    </Link>
                </div>
                <Card className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <tbody>
                            {pages.length === 0 && (
                                <tr>
                                    <td className="p-4 text-muted">No pages yet.</td>
                                </tr>
                            )}
                            {pages.map((p) => (
                                <tr key={p.id} className="border-t border-line first:border-0">
                                    <td className="px-4 py-2">
                                        <Link href={`/admin/content/${p.id}`} className="font-medium text-primary">
                                            {p.title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2 text-muted">/{p.slug}</td>
                                    <td className="px-4 py-2">
                                        <Badge tone={p.status === 'published' ? 'success' : p.status === 'scheduled' ? 'info' : 'neutral'}>
                                            {p.status}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-2 text-muted">
                                        {p.status === 'scheduled'
                                            ? `goes live ${p.publishAt}`
                                            : p.publishedAt
                                              ? `live since ${p.publishedAt.slice(0, 10)}`
                                              : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
                <Card className="space-y-3 p-4">
                    <h2 className="font-semibold">Redirects</h2>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            aria-label="From"
                            placeholder="/old-address"
                            className="h-9 w-56"
                            value={f.data.from}
                            onChange={(e) => f.setData('from', e.target.value)}
                        />
                        <Input
                            aria-label="To"
                            placeholder="/new-address or https://…"
                            className="h-9 w-64"
                            value={f.data.to}
                            onChange={(e) => f.setData('to', e.target.value)}
                        />
                        <select
                            aria-label="Type"
                            className="h-9 rounded-md border border-line bg-surface px-2 text-sm"
                            value={f.data.code}
                            onChange={(e) => f.setData('code', e.target.value)}
                        >
                            <option value="301">Permanent (301)</option>
                            <option value="302">Temporary (302)</option>
                        </select>
                        <Button size="sm" onClick={() => f.post('/admin/content/redirects', { preserveScroll: true, onSuccess: () => f.reset() })}>
                            Add redirect
                        </Button>
                        {(f.errors.from || f.errors.to) && <span className="text-sm text-danger">{f.errors.from ?? f.errors.to}</span>}
                    </div>
                    <table className="w-full text-sm">
                        <tbody>
                            {redirects.map((r) => (
                                <tr key={r.id} className="border-t border-line">
                                    <td className="py-1.5">{r.from_path}</td>
                                    <td className="py-1.5">→ {r.to_path}</td>
                                    <td className="py-1.5 text-muted">
                                        {r.code}
                                        {r.automatic ? ' · automatic' : ''} · {r.hits} visits
                                    </td>
                                    <td className="py-1.5 text-right">
                                        <button
                                            className="text-xs text-muted underline"
                                            onClick={() => router.post(`/admin/content/redirects/${r.id}/delete`, {}, { preserveScroll: true })}
                                        >
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            </div>
        </AdminLayout>
    );
}
