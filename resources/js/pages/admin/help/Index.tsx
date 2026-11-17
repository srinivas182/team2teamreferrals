import { Link, useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Article = {
    id: string;
    title: string;
    slug: string;
    status: string;
    audience: string;
    category: string;
    views: number;
    helpful: number | null;
    votes: number;
    reviewDue: boolean;
};
type Category = { id: number; slug: string; name: string; description: string | null; sort: number };
type Props = {
    articles: Article[];
    categories: Category[];
    gaps: { query: string; count: number }[];
    comments: { article: string; id: string; comment: string; at: string }[];
    flash?: string;
    seo: Seo;
};

function CategoryForm({ c }: { c: Category | null }) {
    const f = useForm({ name: c?.name ?? '', slug: c?.slug ?? '', description: c?.description ?? '', sort: c?.sort ?? 0 });
    const e = f.errors as Record<string, string | undefined>;
    return (
        <div className="grid items-end gap-2 sm:grid-cols-[1fr_1fr_2fr_5rem_auto]">
            <Field label="Name" error={e.name}>
                <Input value={f.data.name} onChange={(ev) => f.setData('name', ev.target.value)} />
            </Field>
            <Field label="Address" error={e.slug}>
                <Input value={f.data.slug} onChange={(ev) => f.setData('slug', ev.target.value)} />
            </Field>
            <Field label="Description" error={e.description}>
                <Input value={f.data.description} onChange={(ev) => f.setData('description', ev.target.value)} />
            </Field>
            <Field label="Order" error={e.sort}>
                <Input type="number" value={f.data.sort} onChange={(ev) => f.setData('sort', Number(ev.target.value))} />
            </Field>
            <Button
                size="sm"
                onClick={() =>
                    c
                        ? f.put(`/admin/help/categories/${c.id}`, { preserveScroll: true })
                        : f.post('/admin/help/categories', { preserveScroll: true, onSuccess: () => f.reset() })
                }
            >
                {c ? 'Save' : 'Add'}
            </Button>
        </div>
    );
}

/** Admin → Help centre: every article with how it's doing, topics, and what people couldn't find. */
export default function HelpAdmin({ articles, categories, gaps, comments, flash, seo }: Props) {
    return (
        <AdminLayout
            title="Help centre"
            actions={
                <Button size="sm" asChild>
                    <Link href="/admin/help/articles/new">New article</Link>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            {flash && (
                <Alert tone="success" className="mb-4 max-w-5xl">
                    {statusMessage(flash)}
                </Alert>
            )}
            <div className="max-w-5xl space-y-6">
                <div className="overflow-x-auto rounded-lg border border-line">
                    <table className="w-full text-sm">
                        <thead className="bg-surface text-left text-muted">
                            <tr>
                                <th className="p-2">Article</th>
                                <th className="p-2">Topic</th>
                                <th className="p-2">For</th>
                                <th className="p-2">Views</th>
                                <th className="p-2">Helpful</th>
                            </tr>
                        </thead>
                        <tbody>
                            {articles.map((a) => (
                                <tr key={a.id} className="border-t border-line">
                                    <td className="p-2">
                                        <Link href={`/admin/help/articles/${a.id}`} className="text-primary">
                                            {a.title}
                                        </Link>{' '}
                                        {a.status === 'draft' && <Badge>Draft</Badge>} {a.reviewDue && <Badge tone="warning">Review due</Badge>}
                                    </td>
                                    <td className="p-2">{a.category}</td>
                                    <td className="p-2">{a.audience}</td>
                                    <td className="p-2">{a.views}</td>
                                    <td className="p-2">{a.helpful !== null ? `${a.helpful}% of ${a.votes}` : '–'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    <Card className="p-4">
                        <h2 className="font-semibold">Searched for, nothing found (30 days)</h2>
                        {gaps.length === 0 ? (
                            <p className="mt-2 text-sm text-muted">Nothing yet.</p>
                        ) : (
                            <ul className="mt-2 space-y-1 text-sm">
                                {gaps.map((g) => (
                                    <li key={g.query} className="flex items-center gap-2">
                                        <span className="flex-1">
                                            “{g.query}” <span className="text-muted">×{g.count}</span>
                                        </span>
                                        <Link href={`/admin/help/articles/new?title=${encodeURIComponent(g.query)}`} className="text-primary">
                                            Write it
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                    <Card className="p-4">
                        <h2 className="font-semibold">“Not helpful” comments</h2>
                        {comments.length === 0 ? (
                            <p className="mt-2 text-sm text-muted">Nothing yet.</p>
                        ) : (
                            <ul className="mt-2 space-y-2 text-sm">
                                {comments.map((c, i) => (
                                    <li key={i}>
                                        <Link href={`/admin/help/articles/${c.id}`} className="text-primary">
                                            {c.article}
                                        </Link>
                                        : “{c.comment}” <span className="text-muted">{c.at}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
                <Card className="space-y-3 p-4">
                    <h2 className="font-semibold">Topics</h2>
                    {categories.map((c) => (
                        <CategoryForm key={c.id} c={c} />
                    ))}
                    <CategoryForm c={null} />
                </Card>
            </div>
        </AdminLayout>
    );
}
