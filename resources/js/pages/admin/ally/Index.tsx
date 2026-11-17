import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AllyAvatar } from '@/components/ally/AllyAvatar';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Eval = {
    id: number;
    question: string;
    mustInclude: string;
    mustNotInclude: string;
    expectAnswer: boolean;
    passed: boolean | null;
    answer: string | null;
    ran: string | null;
};
type Props = {
    metrics: {
        conversations: number;
        questions: number;
        answered: number | null;
        helpful: number | null;
        handedOff: number | null;
        cost: number;
        aiOn: boolean;
    };
    topSources: { title: string; count: number }[];
    gaps: { question: string; why: string; at: string; conversation: string }[];
    conversations: { id: string; audience: string; page: string | null; at: string; ticket: string | null; first: string }[];
    settings: {
        audiences: { key: string; label: string; on: boolean }[];
        visitorHourly: number;
        memberDaily: number;
        handoffAfter: number;
        budget: number;
        live: { tone: string; blocked: string };
        draft: { tone: string; blocked: string };
        draftReady: boolean;
        draftRate: number | null;
        passRate: number;
    };
    evals: Eval[];
    flash?: string;
    seo: Seo;
};
const pct = (v: number | null) => (v === null ? '–' : `${v}%`);

function EvalForm({ e }: { e: Eval | null }) {
    const f = useForm({
        question: e?.question ?? '',
        must_include: e?.mustInclude ?? '',
        must_not_include: e?.mustNotInclude ?? '',
        expect_answer: e?.expectAnswer ?? true,
    });
    return (
        <div className="grid items-end gap-2 md:grid-cols-[2fr_1fr_1fr_auto_auto]">
            <Field label="Question" error={f.errors.question}>
                <Input value={f.data.question} onChange={(ev) => f.setData('question', ev.target.value)} />
            </Field>
            <Field label="Must mention (comma-separated)">
                <Input value={f.data.must_include} onChange={(ev) => f.setData('must_include', ev.target.value)} />
            </Field>
            <Field label="Must never say">
                <Input value={f.data.must_not_include} onChange={(ev) => f.setData('must_not_include', ev.target.value)} />
            </Field>
            <label className="flex h-11 items-center gap-2 text-sm">
                <input type="checkbox" checked={f.data.expect_answer} onChange={(ev) => f.setData('expect_answer', ev.target.checked)} /> Should
                answer
            </label>
            <div className="flex gap-1">
                <Button
                    size="sm"
                    onClick={() =>
                        e
                            ? f.put(`/admin/ally/evals/${e.id}`, { preserveScroll: true })
                            : f.post('/admin/ally/evals', { preserveScroll: true, onSuccess: () => f.reset() })
                    }
                >
                    {e ? 'Save' : 'Add'}
                </Button>
                {e && (
                    <Button size="sm" variant="ghost" onClick={() => router.delete(`/admin/ally/evals/${e.id}`, { preserveScroll: true })}>
                        Delete
                    </Button>
                )}
            </div>
        </div>
    );
}

/** Admin → Ally: results, what it couldn't answer, conversations, settings (draft, test, publish) and test questions. */
export default function AllyAdmin({ metrics, topSources, gaps, conversations, settings, evals, flash, seo }: Props) {
    const { errors, result } = usePage<{ errors: Record<string, string>; result?: { passed: number; total: number; rate: number } }>().props;
    const limits = useForm({
        audiences: Object.fromEntries(settings.audiences.map((a) => [a.key, a.on])) as Record<string, boolean>,
        visitor_hourly: settings.visitorHourly,
        member_daily: settings.memberDaily,
        handoff_after: settings.handoffAfter,
        budget: settings.budget,
    });
    const draft = useForm({ tone: settings.draft.tone, blocked: settings.draft.blocked });
    const changed = draft.data.tone !== settings.live.tone || draft.data.blocked !== settings.live.blocked;
    return (
        <AdminLayout title="Ally">
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-6">
                <div className="flex items-center gap-3">
                    <AllyAvatar size={48} />
                    <div>
                        <div className="font-semibold">Ally</div>
                        <div className="text-sm text-muted">Your AI Assistant</div>
                    </div>
                </div>
                {flash && <Alert tone="success">{statusMessage(flash)}</Alert>}
                {result && (
                    <Alert tone={result.rate >= settings.passRate ? 'success' : 'danger'}>
                        Test questions: {result.passed} of {result.total} passed ({result.rate}%).
                    </Alert>
                )}
                {errors.draft && <Alert tone="danger">{errors.draft}</Alert>}
                {!metrics.aiOn && (
                    <Alert tone="info">
                        No AI provider is connected (Admin → AI), so Ally suggests help articles instead of writing answers. Everything else works.
                    </Alert>
                )}
                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {(
                        [
                            ['Conversations', metrics.conversations],
                            ['Questions', metrics.questions],
                            ['Answered', pct(metrics.answered)],
                            ['Helpful (of rated)', pct(metrics.helpful)],
                            ['Passed to a person', pct(metrics.handedOff)],
                            ['AI cost', `$${metrics.cost.toFixed(2)}`],
                        ] as [string, string | number][]
                    ).map(([l, v]) => (
                        <Card key={l} className="p-4">
                            <div className="text-xs text-muted">{l}</div>
                            <div className="mt-1 font-display text-xl font-bold">{v}</div>
                        </Card>
                    ))}
                </div>
                <p className="-mt-3 text-xs text-muted">Last 30 days. Support questions use no AI credits.</p>
                <div className="grid gap-4 md:grid-cols-2">
                    <Card className="p-4">
                        <h2 className="font-semibold">Couldn’t answer or unhelpful</h2>
                        {gaps.length === 0 ? (
                            <p className="mt-2 text-sm text-muted">Nothing yet.</p>
                        ) : (
                            <ul className="mt-2 space-y-2 text-sm">
                                {gaps.map((g, i) => (
                                    <li key={i} className="flex gap-2">
                                        <span className="flex-1">
                                            “{g.question}” <span className="text-muted">· {g.why}</span>
                                        </span>
                                        <Link href={`/admin/ally/conversations/${g.conversation}`} className="text-primary">
                                            View
                                        </Link>
                                        <Link href={`/admin/help/articles/new?title=${encodeURIComponent(g.question)}`} className="text-primary">
                                            Write it
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                    <Card className="p-4">
                        <h2 className="font-semibold">Articles Ally uses most</h2>
                        {topSources.length === 0 ? (
                            <p className="mt-2 text-sm text-muted">Nothing yet.</p>
                        ) : (
                            <ul className="mt-2 space-y-1 text-sm">
                                {topSources.map((s) => (
                                    <li key={s.title}>
                                        {s.title} <span className="text-muted">×{s.count}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
                <Card className="space-y-4 p-5">
                    <h2 className="font-semibold">Who sees Ally and limits</h2>
                    <div className="flex flex-wrap gap-6 text-sm">
                        {settings.audiences.map((a) => (
                            <label key={a.key} className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={limits.data.audiences[a.key] ?? false}
                                    onChange={(e) => limits.setData('audiences', { ...limits.data.audiences, [a.key]: e.target.checked })}
                                />
                                {a.label}
                            </label>
                        ))}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-4">
                        <Field label="Visitor questions an hour">
                            <Input
                                type="number"
                                value={limits.data.visitor_hourly}
                                onChange={(e) => limits.setData('visitor_hourly', Number(e.target.value))}
                            />
                        </Field>
                        <Field label="Member questions a day">
                            <Input
                                type="number"
                                value={limits.data.member_daily}
                                onChange={(e) => limits.setData('member_daily', Number(e.target.value))}
                            />
                        </Field>
                        <Field label="Offer a person after (misses)">
                            <Input
                                type="number"
                                value={limits.data.handoff_after}
                                onChange={(e) => limits.setData('handoff_after', Number(e.target.value))}
                            />
                        </Field>
                        <Field label="AI spend a day ($)" hint="Then Ally suggests articles until tomorrow.">
                            <Input
                                type="number"
                                step="0.5"
                                value={limits.data.budget}
                                onChange={(e) => limits.setData('budget', Number(e.target.value))}
                            />
                        </Field>
                    </div>
                    <Button size="sm" loading={limits.processing} onClick={() => limits.post('/admin/ally/limits', { preserveScroll: true })}>
                        Save
                    </Button>
                </Card>
                <Card className="space-y-3 p-5">
                    <h2 className="font-semibold">Instructions (draft, test, publish)</h2>
                    <p className="text-sm text-muted">
                        Changes here are a draft until they pass the test questions ({settings.passRate}% or more) and you publish them. Ally always
                        answers only from the help centre and the person’s own account.
                    </p>
                    <Field label="Tone">
                        <Textarea rows={2} value={draft.data.tone} onChange={(e) => draft.setData('tone', e.target.value)} />
                    </Field>
                    <Field label="Topics Ally must not discuss (one per line)">
                        <Textarea rows={4} value={draft.data.blocked} onChange={(e) => draft.setData('blocked', e.target.value)} />
                    </Field>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            variant="secondary"
                            loading={draft.processing}
                            onClick={() => draft.post('/admin/ally/draft', { preserveScroll: true })}
                        >
                            Save draft
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => router.post('/admin/ally/test', { which: 'draft' }, { preserveScroll: true })}
                        >
                            Test the draft
                        </Button>
                        <Button
                            size="sm"
                            disabled={!settings.draftReady}
                            onClick={() => router.post('/admin/ally/publish', {}, { preserveScroll: true })}
                        >
                            Publish
                        </Button>
                        {settings.draftRate !== null && <span className="text-sm text-muted">Last draft test: {settings.draftRate}%</span>}
                        {changed && <Badge tone="warning">Unsaved or unpublished changes</Badge>}
                    </div>
                </Card>
                <Card className="space-y-3 p-5">
                    <div className="flex items-center justify-between">
                        <h2 className="font-semibold">Test questions</h2>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => router.post('/admin/ally/test', { which: 'live' }, { preserveScroll: true })}
                        >
                            Run on the live version
                        </Button>
                    </div>
                    {evals.map((e) => (
                        <div key={e.id} className="space-y-1 border-t border-line pt-3">
                            <EvalForm e={e} />
                            {e.ran && (
                                <p className="text-xs">
                                    <Badge tone={e.passed ? 'success' : 'danger'}>{e.passed ? 'Passed' : 'Failed'}</Badge>{' '}
                                    <span className="text-muted">{e.ran}:</span> {e.answer}
                                </p>
                            )}
                        </div>
                    ))}
                    <div className="border-t border-line pt-3">
                        <EvalForm e={null} />
                    </div>
                </Card>
                <Card className="p-4">
                    <h2 className="font-semibold">Recent conversations</h2>
                    <ul className="mt-2 divide-y divide-line text-sm">
                        {conversations.map((c) => (
                            <li key={c.id} className="flex flex-wrap gap-2 py-2">
                                <Link href={`/admin/ally/conversations/${c.id}`} className="flex-1 text-primary">
                                    {c.first || '(no question)'}
                                </Link>
                                <span className="text-muted">
                                    {c.audience} · {c.at}
                                </span>
                                {c.ticket && <Badge>{c.ticket}</Badge>}
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>
        </AdminLayout>
    );
}
