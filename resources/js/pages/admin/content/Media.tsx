import { useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { ContentTabs } from '@/pages/admin/content/Tabs';
import type { Seo } from '@/types/seo';

type M = { id: number; url: string; thumb: string; alt: string; width: number; height: number };

/** Admin → Website → Media: images, converted to WebP and virus-scanned, each with a description. */
export default function Media({ media, status, seo }: { media: M[]; status?: string; seo: Seo }) {
    const f = useForm<{ file: File | null; alt: string }>({ file: null, alt: '' });
    return (
        <AdminLayout title="Website">
            <SeoHead seo={seo} />
            <ContentTabs current="/admin/content/media" />
            <div className="max-w-5xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <Card className="grid gap-3 p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <Field label="Image (JPEG, PNG or WebP, up to 10 MB)" error={f.errors.file}>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={(e) => f.setData('file', e.target.files?.[0] ?? null)}
                        />
                    </Field>
                    <Field label="Description (alt text, required)" error={f.errors.alt}>
                        <Input
                            value={f.data.alt}
                            onChange={(e) => f.setData('alt', e.target.value)}
                            placeholder="e.g. Two agents shaking hands outside a house"
                        />
                    </Field>
                    <Button
                        disabled={!f.data.file || f.data.alt.trim().length < 5}
                        loading={f.processing}
                        onClick={() => f.post('/admin/content/media', { forceFormData: true, preserveScroll: true, onSuccess: () => f.reset() })}
                    >
                        Upload
                    </Button>
                </Card>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {media.map((m) => (
                        <figure key={m.id} className="space-y-1 text-xs">
                            <img src={m.thumb} alt={m.alt} className="aspect-video w-full rounded-md object-cover" loading="lazy" />
                            <figcaption className="text-muted">
                                {m.alt} · {m.width}×{m.height}
                            </figcaption>
                            <button type="button" className="text-primary" onClick={() => void navigator.clipboard?.writeText(m.url)}>
                                Copy address
                            </button>
                        </figure>
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}
