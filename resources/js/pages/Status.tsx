import { CheckCircle2, AlertTriangle } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Card } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Component = { key: string; label: string; ok: boolean };

/** Public status page, refreshed every 30 seconds from the health checks. */
export default function Status({
    components,
    incident,
    checkedAt,
    seo,
}: {
    components: Component[];
    incident: string | null;
    checkedAt: string;
    seo: Seo;
}) {
    const allOk = components.every((c) => c.ok);
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-2xl px-5 py-16">
                <h1 className="font-display text-3xl font-extrabold display">System status</h1>
                <div
                    className={
                        allOk
                            ? 'mt-6 flex items-center gap-3 rounded-lg bg-[#ECFDF3] p-5 text-success dark:bg-success/15'
                            : 'mt-6 flex items-center gap-3 rounded-lg bg-[#FEF9E7] p-5 text-warning dark:bg-warning/15'
                    }
                    role="status"
                >
                    {allOk ? <CheckCircle2 aria-hidden /> : <AlertTriangle aria-hidden />}
                    <span className="text-lg font-semibold">{allOk ? 'All systems operational' : 'Some systems are having problems'}</span>
                </div>
                {incident && (
                    <Alert tone="info" className="mt-4">
                        {incident}
                    </Alert>
                )}
                <Card className="mt-6">
                    <ul className="divide-y divide-line">
                        {components.map((c) => (
                            <li key={c.key} className="flex items-center justify-between px-5 py-4">
                                <span className="font-medium">{c.label}</span>
                                <span className={c.ok ? 'text-sm font-medium text-success' : 'text-sm font-medium text-warning'}>
                                    {c.ok ? 'Operational' : 'Degraded'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
                <p className="mt-4 text-sm text-muted">
                    Last checked <time dateTime={checkedAt}>{new Date(checkedAt).toLocaleTimeString()}</time>. Refreshes every 30 seconds.
                </p>
            </section>
        </PublicLayout>
    );
}
