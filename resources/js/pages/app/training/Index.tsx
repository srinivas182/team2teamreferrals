import { Link, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type T = { industry: string; name: string; lessons: number; minutes: number; done: number; passed: boolean };

export default function TrainingIndex({ trainings, seo }: { trainings: T[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Network training" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {trainings.length === 0 && <Card className="max-w-xl p-6 text-muted">Apply to a group first; its training appears here.</Card>}
            <div className="grid max-w-4xl gap-4 sm:grid-cols-2">
                {trainings.map((t) => (
                    <Link key={t.industry} href={`/app/training/${t.industry}`}>
                        <Card className="p-5 hover:shadow-md">
                            <div className="flex items-center gap-2">
                                <h2 className="font-semibold">{t.name}</h2>
                                {t.passed ? <Badge tone="success">Passed</Badge> : <Badge tone="warning">To do</Badge>}
                            </div>
                            <p className="mt-1 text-sm text-muted">
                                {t.lessons} short lessons · about {t.minutes} minutes · quiz
                            </p>
                            <div className="mt-3 h-2 rounded-full bg-bg">
                                <div
                                    className="h-2 rounded-full bg-primary"
                                    style={{ width: `${t.passed ? 100 : (t.done / Math.max(1, t.lessons)) * 90}%` }}
                                />
                            </div>
                        </Card>
                    </Link>
                ))}
            </div>
        </PortalLayout>
    );
}
