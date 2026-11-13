import { Link, router } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { ContentTabs } from '@/pages/admin/content/Tabs';
import type { Seo } from '@/types/seo';

type Row = { slug: string; name: string; tier: string; state: string; reviewer: string | null; reviewedOn: string | null; updatedOn: string | null };

const states: Record<string, [string, 'success' | 'warning' | 'info' | 'neutral']> = {
    published: ['Published', 'success'],
    ready: ['Ready to publish', 'info'],
    review_pending: ['Review pending', 'warning'],
    none: ['Not written', 'neutral'],
};

/** Admin → Website → Industries: each industry's hub and where it stands. */
export default function Industries({ industries, status, seo }: { industries: Row[]; status?: string; seo: Seo }) {
    const count = (s: string) => industries.filter((i) => i.state === s).length;
    return (
        <AdminLayout title="Website">
            <SeoHead seo={seo} />
            <ContentTabs current="/admin/content/industries" />
            <div className="max-w-5xl space-y-3">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span>
                        {count('published')} published · {count('ready')} ready · {count('review_pending')} review pending · {count('none')} not
                        written
                    </span>
                    <Button size="sm" variant="secondary" className="ml-auto" onClick={() => router.post('/admin/content/industries/install')}>
                        Add drafted content
                    </Button>
                </div>
                <Card className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted">
                            <tr>
                                <th className="px-4 py-2">Industry</th>
                                <th className="px-4 py-2">Tier</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Reviewed</th>
                            </tr>
                        </thead>
                        <tbody>
                            {industries.map((i) => (
                                <tr key={i.slug} className="border-t border-line">
                                    <td className="px-4 py-2">
                                        <Link href={`/admin/content/industries/${i.slug}`} className="text-primary">
                                            {i.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2">{i.tier}</td>
                                    <td className="px-4 py-2">
                                        <Badge tone={states[i.state]![1]}>{states[i.state]![0]}</Badge>
                                    </td>
                                    <td className="px-4 py-2 text-muted">
                                        {i.reviewer ? `${i.reviewer}${i.reviewedOn ? `, ${i.reviewedOn}` : ''}` : ''}
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
