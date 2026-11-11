import { usePage } from '@inertiajs/react';
import { Award } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    badges: { key: string; name: string; how: string; keep: string }[];
    mine: { key: string; name: string; group: string | null }[];
    windowDays: number;
    seo: Seo;
};

/** Portal → Badges: exactly how each badge is earned and kept. Re-checked on the 1st of each month. */
export default function Badges({ badges, mine, windowDays, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const held = new Set(mine.map((m) => m.key));
    return (
        <PortalLayout title="Badges" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <p className="mb-4 max-w-3xl text-sm text-muted">
                Badges are earned from the last {windowDays} days of activity. New badges appear the day you earn them; on the 1st of each month every
                badge is checked again, so they show what you do now.
            </p>
            <div className="grid max-w-4xl gap-3 sm:grid-cols-2">
                {badges.map((b) => (
                    <Card key={b.key} id={b.key} className="space-y-2 p-5">
                        <div className="flex items-center gap-2">
                            <Award className={`size-5 ${held.has(b.key) ? 'text-orange-500' : 'text-subtle'}`} aria-hidden />
                            <h2 className="font-semibold">{b.name}</h2>
                            {held.has(b.key) && (
                                <Badge tone="success" className="ml-auto">
                                    You have it{mine.find((m) => m.key === b.key)?.group ? ` · ${mine.find((m) => m.key === b.key)?.group}` : ''}
                                </Badge>
                            )}
                        </div>
                        <p className="text-sm">
                            <strong>How to earn it:</strong> {b.how}
                        </p>
                        <p className="text-sm text-muted">
                            <strong>How to keep it:</strong> {b.keep}
                        </p>
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
