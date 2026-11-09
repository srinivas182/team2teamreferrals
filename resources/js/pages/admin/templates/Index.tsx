import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type T = { key: string; group: string; label: string; channels: string[]; edited: Record<string, number> };

export default function TemplatesIndex({ types, seo }: { types: T[]; seo: Seo }) {
    const groups = Array.from(new Set(types.map((t) => t.group)));
    return (
        <AdminLayout title="Message templates">
            <SeoHead seo={seo} />
            <p className="mb-6 max-w-3xl text-muted">
                The wording of every email, text and in-app notification. Edits are saved as new versions, so any earlier wording can be restored.
            </p>
            <div className="max-w-4xl space-y-6">
                {groups.map((g) => (
                    <Card key={g}>
                        <h2 className="border-b border-line px-5 py-3 text-sm font-semibold">{g}</h2>
                        <ul className="divide-y divide-line">
                            {types
                                .filter((t) => t.group === g)
                                .map((t) => (
                                    <li key={t.key}>
                                        <Link href={`/admin/templates/${t.key}`} className="flex items-center gap-3 px-5 py-3 hover:bg-bg">
                                            <span className="font-medium">{t.label}</span>
                                            <span className="ml-auto flex gap-1">
                                                {Object.keys(t.edited).length > 0 ? <Badge tone="info">Edited</Badge> : <Badge>Default</Badge>}
                                            </span>
                                            <ChevronRight className="size-4 text-subtle" aria-hidden />
                                        </Link>
                                    </li>
                                ))}
                        </ul>
                    </Card>
                ))}
            </div>
        </AdminLayout>
    );
}
