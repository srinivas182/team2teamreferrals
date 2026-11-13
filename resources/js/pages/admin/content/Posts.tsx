import { Link, useForm } from '@inertiajs/react';
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

type Post = {
    ulid: string;
    title: string;
    slug: string;
    status: string;
    publish_at: string | null;
    published_at: string | null;
    category: string | null;
    reviewPending: boolean;
};

/** Admin → Website → Blog: posts and categories. */
export default function Posts({
    posts,
    categories,
    status,
    seo,
}: {
    posts: Post[];
    categories: { id: number; slug: string; name: string }[];
    status?: string;
    seo: Seo;
}) {
    const c = useForm({ name: '', description: '' });
    return (
        <AdminLayout title="Website">
            <SeoHead seo={seo} />
            <ContentTabs current="/admin/content/posts" />
            <div className="max-w-5xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <div className="flex justify-end">
                    <Link
                        href="/admin/content/posts/new"
                        className="inline-flex h-10 items-center rounded-md bg-primary px-4 font-semibold text-white"
                    >
                        New post
                    </Link>
                </div>
                <Card className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <tbody>
                            {posts.length === 0 && (
                                <tr>
                                    <td className="p-4 text-muted">No posts yet.</td>
                                </tr>
                            )}
                            {posts.map((p) => (
                                <tr key={p.ulid} className="border-t border-line first:border-0">
                                    <td className="px-4 py-2">
                                        <Link href={`/admin/content/posts/${p.ulid}`} className="font-medium text-primary">
                                            {p.title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2 text-muted">{p.category ?? ''}</td>
                                    <td className="px-4 py-2">
                                        <Badge tone={p.status === 'published' ? 'success' : p.status === 'scheduled' ? 'info' : 'neutral'}>
                                            {p.status}
                                        </Badge>
                                        {p.reviewPending && (
                                            <Badge tone="warning" className="ml-1">
                                                Review pending
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-muted">
                                        {p.status === 'scheduled' ? `goes live ${p.publish_at}` : (p.published_at ?? '').slice(0, 10)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
                <Card className="space-y-2 p-4">
                    <h2 className="font-semibold">Categories</h2>
                    <p className="text-sm text-muted">{categories.map((x) => x.name).join(', ') || 'None yet.'}</p>
                    <div className="flex flex-wrap gap-2">
                        <Input
                            aria-label="Category name"
                            placeholder="Name"
                            className="h-9 w-48"
                            value={c.data.name}
                            onChange={(e) => c.setData('name', e.target.value)}
                        />
                        <Input
                            aria-label="Description"
                            placeholder="Short description (optional)"
                            className="h-9 w-72"
                            value={c.data.description}
                            onChange={(e) => c.setData('description', e.target.value)}
                        />
                        <Button
                            size="sm"
                            variant="secondary"
                            disabled={!c.data.name}
                            onClick={() => c.post('/admin/content/categories', { preserveScroll: true, onSuccess: () => c.reset() })}
                        >
                            Add category
                        </Button>
                    </div>
                </Card>
            </div>
        </AdminLayout>
    );
}
