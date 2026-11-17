import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { xsrf } from '@/lib/xsrf';
import type { Seo } from '@/types/seo';

type A = {
    id: string;
    title: string;
    slug: string;
    summary: string;
    body: string;
    category_id: number;
    audience: string;
    howto: boolean;
    status: string;
    review_due_on: string | null;
};
type Props = {
    article: A | null;
    prefill: { title: string } | null;
    categories: { id: number; name: string }[];
    audiences: Record<string, string>;
    flash?: string;
    seo: Seo;
};

const slugify = (s: string) =>
    s
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 120);

/** Write or edit a help article in Markdown, with the short answer first and a live preview. */
export default function HelpEdit({ article, prefill, categories, audiences, flash, seo }: Props) {
    const f = useForm({
        title: article?.title ?? prefill?.title ?? '',
        slug: article?.slug ?? slugify(prefill?.title ?? ''),
        summary: article?.summary ?? '',
        body: article?.body ?? '',
        category_id: article?.category_id ?? categories[0]?.id ?? 0,
        audience: article?.audience ?? 'public',
        howto: article?.howto ?? false,
        status: article?.status ?? 'draft',
        review_due_on: article?.review_due_on ?? '',
    });
    const [preview, setPreview] = useState<string | null>(null);
    const e = f.errors as Record<string, string | undefined>;
    const save = () => (article ? f.put(`/admin/help/articles/${article.id}`, { preserveScroll: true }) : f.post('/admin/help/articles'));
    const showPreview = async () => {
        const r = await fetch('/admin/help/preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ body: f.data.body }),
        });
        setPreview(r.ok ? ((await r.json()) as { html: string }).html : '<p>Could not preview.</p>');
    };
    return (
        <AdminLayout
            title={article ? article.title : 'New help article'}
            actions={
                <Button variant="secondary" size="sm" asChild>
                    <Link href="/admin/help">All articles</Link>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            {flash && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(flash)}
                </Alert>
            )}
            <div className="max-w-4xl space-y-4">
                <Field label="Question (title)" hint="Write it the way people ask it: “How do I…?”" error={e.title}>
                    <Input
                        value={f.data.title}
                        onChange={(ev) => {
                            f.setData((d) => ({ ...d, title: ev.target.value, slug: article ? d.slug : slugify(ev.target.value) }));
                        }}
                    />
                </Field>
                <Field label="Address" hint={`/help/${f.data.slug}`} error={e.slug}>
                    <Input value={f.data.slug} onChange={(ev) => f.setData('slug', ev.target.value)} />
                </Field>
                <Field
                    label="Short answer"
                    hint="One to three sentences that answer the question on their own. Shown first, and used by search engines and AI assistants."
                    error={e.summary}
                >
                    <Textarea rows={3} maxLength={400} value={f.data.summary} onChange={(ev) => f.setData('summary', ev.target.value)} />
                </Field>
                <Field
                    label="Full answer (Markdown)"
                    hint="**bold**, lists with 1. or -, links as [text](https://…). HTML is removed."
                    error={e.body}
                >
                    <Textarea rows={14} className="font-mono text-sm" value={f.data.body} onChange={(ev) => f.setData('body', ev.target.value)} />
                </Field>
                <div className="grid gap-4 sm:grid-cols-4">
                    <Field label="Topic" error={e.category_id}>
                        <Select value={f.data.category_id} onChange={(ev) => f.setData('category_id', Number(ev.target.value))}>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Who can read it" error={e.audience}>
                        <Select value={f.data.audience} onChange={(ev) => f.setData('audience', ev.target.value)}>
                            {Object.entries(audiences).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Status" error={e.status}>
                        <Select value={f.data.status} onChange={(ev) => f.setData('status', ev.target.value)}>
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </Select>
                    </Field>
                    <Field label="Review by" error={e.review_due_on}>
                        <Input type="date" value={f.data.review_due_on ?? ''} onChange={(ev) => f.setData('review_due_on', ev.target.value)} />
                    </Field>
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={f.data.howto} onChange={(ev) => f.setData('howto', ev.target.checked)} /> Numbered steps: mark up
                    as a how-to
                </label>
                <div className="flex flex-wrap gap-2">
                    <Button loading={f.processing} onClick={save}>
                        Save
                    </Button>
                    <Button variant="secondary" onClick={() => void showPreview()}>
                        Preview
                    </Button>
                    {article && article.status === 'published' && (
                        <Button variant="secondary" asChild>
                            <a href={`/help/${article.slug}`} target="_blank" rel="noreferrer">
                                View
                            </a>
                        </Button>
                    )}
                    {article && (
                        <Button variant="ghost" onClick={() => router.delete(`/admin/help/articles/${article.id}`)}>
                            Delete
                        </Button>
                    )}
                </div>
                {preview !== null && (
                    <Card className="p-5">
                        <div className="prose max-w-none" dangerouslySetInnerHTML={{ __html: preview }} />
                    </Card>
                )}
            </div>
        </AdminLayout>
    );
}
