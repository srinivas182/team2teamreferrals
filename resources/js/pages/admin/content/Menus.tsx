import { router } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { ContentTabs } from '@/pages/admin/content/Tabs';
import type { Seo } from '@/types/seo';

type Item = { label: string; url: string };

function MenuEditor({ location, items, limit }: { location: string; items: Item[]; limit: number }) {
    const [list, setList] = useState<Item[]>(items.length ? items : [{ label: '', url: '' }]);
    return (
        <Card className="space-y-2 p-4">
            <h2 className="font-semibold capitalize">{location} menu</h2>
            <p className="text-xs text-muted">Up to {limit} links. Leave empty to use the built-in menu.</p>
            {list.map((it, i) => (
                <div key={i} className="flex gap-2">
                    <Input
                        aria-label="Label"
                        placeholder="Label"
                        className="h-9"
                        value={it.label}
                        onChange={(e) => setList(list.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))}
                    />
                    <Input
                        aria-label="Address"
                        placeholder="/how-it-works"
                        className="h-9"
                        value={it.url}
                        onChange={(e) => setList(list.map((x, j) => (j === i ? { ...x, url: e.target.value } : x)))}
                    />
                    <button type="button" className="text-sm text-muted" onClick={() => setList(list.filter((_, j) => j !== i))}>
                        Remove
                    </button>
                </div>
            ))}
            <div className="flex gap-2">
                {list.length < limit && (
                    <Button size="sm" variant="secondary" onClick={() => setList([...list, { label: '', url: '' }])}>
                        Add link
                    </Button>
                )}
                <Button size="sm" onClick={() => router.post('/admin/content/menus', { location, items: list }, { preserveScroll: true })}>
                    Save {location} menu
                </Button>
            </div>
        </Card>
    );
}

/** Admin → Website → Menus: the public header and footer links. */
export default function Menus({
    menus,
    limits,
    status,
    seo,
}: {
    menus: Record<string, Item[]>;
    limits: Record<string, number>;
    status?: string;
    seo: Seo;
}) {
    return (
        <AdminLayout title="Website">
            <SeoHead seo={seo} />
            <ContentTabs current="/admin/content/menus" />
            <div className="max-w-3xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                {Object.entries(limits).map(([loc, limit]) => (
                    <MenuEditor key={loc} location={loc} items={menus[loc] ?? []} limit={limit} />
                ))}
            </div>
        </AdminLayout>
    );
}
