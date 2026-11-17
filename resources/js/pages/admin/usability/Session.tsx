import { Link } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Props = {
    session: { id: string; name: string; email: string; family: string; status: string; started: string | null; finished: string | null };
    tasks: { key: string; title: string; outcome: string | null; seconds: number | null; note: string | null }[];
    events: { id: number; task: string | null; kind: string; path: string; detail: string | null; seconds: number | null; at: string }[];
    seo: Seo;
};

const labels: Record<string, string> = {
    page: 'Page',
    stuck: 'Stuck',
    rage: 'Clicks did nothing',
    error: 'Form error',
    help: 'Pressed “I’m stuck”',
    done: 'Done',
    gave_up: 'Gave up',
};
const tones: Record<string, 'neutral' | 'danger' | 'warning' | 'success' | 'info'> = {
    page: 'neutral',
    stuck: 'warning',
    rage: 'warning',
    error: 'danger',
    help: 'info',
    done: 'success',
    gave_up: 'danger',
};

const duration = (s: number | null) => (s === null ? '' : s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`);

/** One tester's test: how each task went, and everything that happened, in order. */
export default function UsabilitySession({ session, tasks, events, seo }: Props) {
    return (
        <AdminLayout title={`Usability test: ${session.name}`}>
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-6">
                <Link href="/admin/usability" className="text-sm text-primary">
                    ← All usability tests
                </Link>
                <p className="text-sm text-muted">
                    {session.email} · {session.family} · {session.status}
                    {session.started ? ` · started ${session.started}` : ''}
                    {session.finished ? ` · finished ${session.finished}` : ''}
                </p>
                <Card className="p-5">
                    <h2 className="font-semibold">Tasks</h2>
                    <ol className="mt-2 space-y-2 text-sm">
                        {tasks.map((t, i) => (
                            <li key={t.key} className="flex flex-wrap items-center gap-2">
                                <span className="w-5 text-muted">{i + 1}.</span>
                                <span className="flex-1">{t.title}</span>
                                {t.outcome ? (
                                    <Badge tone={tones[t.outcome] ?? 'neutral'}>{labels[t.outcome] ?? t.outcome}</Badge>
                                ) : (
                                    <Badge>Not reached</Badge>
                                )}
                                <span className="w-16 text-right text-muted">{duration(t.seconds)}</span>
                                {t.note && <p className="w-full pl-7 text-muted">“{t.note}”</p>}
                            </li>
                        ))}
                    </ol>
                </Card>
                <Card className="p-5">
                    <h2 className="font-semibold">What happened</h2>
                    {events.length === 0 ? (
                        <p className="mt-2 text-sm text-muted">Nothing recorded yet.</p>
                    ) : (
                        <ul className="mt-2 space-y-1 text-sm">
                            {events.map((e) => (
                                <li key={e.id} className="flex flex-wrap items-center gap-2">
                                    <span className="w-16 text-xs text-muted tabular-nums">{e.at}</span>
                                    <Badge tone={tones[e.kind] ?? 'neutral'}>{labels[e.kind] ?? e.kind}</Badge>
                                    <code className="text-xs">{e.path}</code>
                                    {e.seconds !== null && <span className="text-xs text-muted">{duration(e.seconds)}</span>}
                                    {e.detail && <span className="text-muted">{e.detail}</span>}
                                    {e.task && <span className="ml-auto text-xs text-subtle">{e.task}</span>}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AdminLayout>
    );
}
