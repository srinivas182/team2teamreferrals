import { router, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Item = { target: 'post' | 'comment'; id: number; body: string; reports: number; hidden: boolean; reasons: string[] };

/** Owners: reported posts and comments (hidden automatically at three reports), to restore or remove. */
export default function Moderation({ group, items, seo }: { group: { slug: string; name: string }; items: Item[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    const decide = (i: Item, restore: boolean) => router.post(`/app/feed/moderate/${i.target}/${i.id}`, { restore }, { preserveScroll: true });
    return (
        <PortalLayout title={`${group.name}: moderation`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-3xl space-y-3">
                {items.length === 0 && <Card className="p-8 text-center text-muted">Nothing to review.</Card>}
                {items.map((i) => (
                    <Card key={`${i.target}-${i.id}`} className="space-y-2 p-5">
                        <div className="flex items-center gap-2 text-sm">
                            <Badge>{i.target}</Badge>
                            <Badge tone={i.hidden ? 'danger' : 'warning'}>
                                {i.reports} report{i.reports === 1 ? '' : 's'}
                                {i.hidden ? ' · hidden' : ''}
                            </Badge>
                        </div>
                        <p className="whitespace-pre-wrap">{i.body}</p>
                        {i.reasons.length > 0 && <p className="text-sm text-muted">Reasons: {i.reasons.join('; ')}</p>}
                        <div className="flex gap-2">
                            <Button size="sm" variant="secondary" onClick={() => decide(i, true)}>
                                Restore
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => window.confirm('Remove this for everyone?') && decide(i, false)}>
                                Remove
                            </Button>
                        </div>
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
