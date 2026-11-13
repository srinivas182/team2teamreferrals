import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { ContentTabs } from '@/pages/admin/content/Tabs';
import type { Seo } from '@/types/seo';

type Post = {
    id: string;
    title: string;
    slug: string;
    answer_summary: string | null;
    body: string;
    category_id: number | null;
    author_id: number | null;
    reviewer: string | null;
    cover_media_id: number | null;
    status: string;
    publishAt: string | null;
    seo: Record<string, string | boolean>;
};
type Props = {
    post: Post | null;
    categories: { id: number; name: string }[];
    authors: { id: number; name: string }[];
    media: { id: number; thumb: string; alt: string }[];
    status?: string;
    seo: Seo;
};

/** Admin → Website → Blog → a post. */
export default function PostEdit({ post, categories, authors, media, status, seo }: Props) {
    const f = useForm({
        title: post?.title ?? '',
        slug: post?.slug ?? '',
        answer_summary: post?.answer_summary ?? '',
        body: post?.body ?? '',
        category_id: post?.category_id ? String(post.category_id) : '',
        author_id: post?.author_id ? String(post.author_id) : '',
        reviewer: post?.reviewer ?? '',
        cover_media_id: post?.cover_media_id ? String(post.cover_media_id) : '',
        seo: { title: String(post?.seo.title ?? ''), description: String(post?.seo.description ?? ''), noindex: Boolean(post?.seo.noindex) },
    });
    const [at, setAt] = useState('');
    const errors = f.errors as Record<string, string>;
    const sel = 'h-10 w-full rounded-md border border-line bg-surface px-2';
    return (
        <AdminLayout title={post?.title || 'New post'}>
            <SeoHead seo={seo} />
            <ContentTabs current="/admin/content/posts" />
            <div className="grid max-w-6xl gap-4 lg:grid-cols-[1fr_18rem]">
                <div className="space-y-4">
                    {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                    {(errors.answer_summary || errors.body) && <Alert tone="danger">{errors.answer_summary ?? errors.body}</Alert>}
                    <Card className="grid gap-3 p-4 sm:grid-cols-2">
                        <Field label="Title" error={errors.title}>
                            <Input value={f.data.title} onChange={(e) => f.setData('title', e.target.value)} />
                        </Field>
                        <Field label="Address (after /blog/)" error={errors.slug}>
                            <Input value={f.data.slug} onChange={(e) => f.setData('slug', e.target.value)} />
                        </Field>
                        <div className="sm:col-span-2">
                            <Field label={`Answer summary (${f.data.answer_summary.trim().length} of 40–320 characters, required to publish)`}>
                                <textarea
                                    rows={2}
                                    className="w-full rounded-md border border-line bg-surface p-2 text-sm"
                                    value={f.data.answer_summary}
                                    onChange={(e) => f.setData('answer_summary', e.target.value)}
                                />
                            </Field>
                        </div>
                        <div className="sm:col-span-2">
                            <Field label="Post (headings, lists, links and emphasis are kept; anything else is removed)">
                                <textarea
                                    rows={16}
                                    className="w-full rounded-md border border-line bg-surface p-2 font-mono text-sm"
                                    value={f.data.body}
                                    onChange={(e) => f.setData('body', e.target.value)}
                                />
                            </Field>
                        </div>
                        <Field label="Category">
                            <select className={sel} value={f.data.category_id} onChange={(e) => f.setData('category_id', e.target.value)}>
                                <option value="">None</option>
                                {categories.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Author">
                            <select className={sel} value={f.data.author_id} onChange={(e) => f.setData('author_id', e.target.value)}>
                                <option value="">Me</option>
                                {authors.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Reviewed by (the expert who checked the facts)" error={f.errors.reviewer}>
                            <Input placeholder="Review pending" value={f.data.reviewer} onChange={(e) => f.setData('reviewer', e.target.value)} />
                        </Field>
                        <Field label="Cover image (from the media library)">
                            <select className={sel} value={f.data.cover_media_id} onChange={(e) => f.setData('cover_media_id', e.target.value)}>
                                <option value="">None</option>
                                {media.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.alt}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Search title (optional)">
                            <Input value={f.data.seo.title} onChange={(e) => f.setData('seo', { ...f.data.seo, title: e.target.value })} />
                        </Field>
                        <Field label="Search description (optional)">
                            <Input
                                value={f.data.seo.description}
                                onChange={(e) => f.setData('seo', { ...f.data.seo, description: e.target.value })}
                            />
                        </Field>
                    </Card>
                </div>
                <aside>
                    <Card className="space-y-2 p-4">
                        <Button
                            className="w-full"
                            loading={f.processing}
                            onClick={() => f.post(post ? `/admin/content/posts/${post.id}` : '/admin/content/posts', { preserveScroll: true })}
                        >
                            Save draft
                        </Button>
                        {post && (
                            <>
                                <p className="text-xs text-muted">
                                    Status: {post.status}
                                    {post.publishAt ? ` (goes live ${post.publishAt})` : ''}
                                </p>
                                <Button
                                    className="w-full"
                                    variant="secondary"
                                    onClick={() =>
                                        window.confirm('Publish the saved post now?') &&
                                        router.post(`/admin/content/posts/${post.id}/publish`, {}, { preserveScroll: true })
                                    }
                                >
                                    Publish now
                                </Button>
                                <div className="flex gap-1">
                                    <Input
                                        type="datetime-local"
                                        aria-label="Publish at"
                                        className="h-9"
                                        value={at}
                                        onChange={(e) => setAt(e.target.value)}
                                    />
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        disabled={!at}
                                        onClick={() => router.post(`/admin/content/posts/${post.id}/publish`, { at }, { preserveScroll: true })}
                                    >
                                        Schedule
                                    </Button>
                                </div>
                                {post.status === 'published' && (
                                    <button
                                        className="w-full text-sm text-muted underline"
                                        onClick={() =>
                                            window.confirm('Take this post offline?') &&
                                            router.post(`/admin/content/posts/${post.id}/unpublish`, {}, { preserveScroll: true })
                                        }
                                    >
                                        Unpublish
                                    </button>
                                )}
                            </>
                        )}
                    </Card>
                </aside>
            </div>
        </AdminLayout>
    );
}
