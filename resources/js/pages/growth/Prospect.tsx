import { Link, router, useForm } from '@inertiajs/react';
import { GrowthShell, TEMP_TONE } from '@/components/growth/GrowthShell';
import type { GrowthShared } from '@/components/growth/GrowthShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/field';

type P = {
    id: string;
    name: string;
    email: string;
    first_name: string | null;
    last_name: string | null;
    business: string | null;
    phone: string | null;
    stage: string;
    lostReason: string | null;
    score: number;
    temp: 'hot' | 'warm' | 'cold';
    source: string;
    sourceDetail: string | null;
    consentBasis: string;
    city: string | null;
    state: string | null;
    industry: string | null;
    group: string | null;
    ownerId: number | null;
    tags: string[];
    createdAt: string;
    joinedAt: string | null;
    unsubscribed: boolean;
    member: boolean;
};
type Props = GrowthShared & {
    prospect: P;
    activities: { id: number; type: string; body: string | null; at: string; by: string | null }[];
    tasks: { id: number; title: string; due: string; done: boolean; assignee: string }[];
};

const BASIS: Record<string, string> = {
    signed_up: 'Signed up themselves',
    reserved: 'Reserved a seat themselves',
    wrote_to_us: 'Wrote to us',
    imported_not_purchased: 'Imported (confirmed not a bought list)',
    added_by_staff: 'Added by hand',
};

/** One prospect: details, stage, owner, timeline, notes and tasks; and "forget me". */
export default function Prospect(props: Props) {
    const { prospect: p, activities, tasks, base, stages, sources, people, me } = props;
    const note = useForm({ note: '' });
    const task = useForm({ title: '', due_at: '', assignee_id: String(me) });
    const details = useForm({ first_name: p.first_name ?? '', last_name: p.last_name ?? '', business: p.business ?? '', phone: p.phone ?? '' });
    return (
        <GrowthShell shared={props} title="Prospect">
            <Link href={base} className="text-sm text-primary">
                ← All prospects
            </Link>
            <div className="grid gap-5 lg:grid-cols-[2fr_1fr]">
                <div className="space-y-5">
                    <Card className="space-y-3 p-5">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h1 className="text-xl font-semibold">{p.name}</h1>
                                <p className="text-sm text-muted">
                                    {p.email}
                                    {p.phone ? ` · ${p.phone}` : ''}
                                    {p.unsubscribed ? ' · unsubscribed from email' : ''}
                                    {p.member ? ' · has an account' : ''}
                                </p>
                            </div>
                            <Badge tone={TEMP_TONE[p.temp]}>
                                {p.temp} · score {p.score}
                            </Badge>
                        </div>
                        <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                            <dt className="text-muted">Where</dt>
                            <dd>{[p.city, p.state].filter(Boolean).join(', ') || '—'}</dd>
                            <dt className="text-muted">Network</dt>
                            <dd>{p.group ?? p.industry ?? '—'}</dd>
                            <dt className="text-muted">Came from</dt>
                            <dd>
                                {sources[p.source] ?? p.source}
                                {p.sourceDetail ? ` (${p.sourceDetail})` : ''}
                            </dd>
                            <dt className="text-muted">How we can contact them</dt>
                            <dd>{BASIS[p.consentBasis] ?? p.consentBasis}</dd>
                            <dt className="text-muted">Added</dt>
                            <dd>{new Date(p.createdAt).toLocaleDateString()}</dd>
                        </dl>
                        <details className="text-sm">
                            <summary className="cursor-pointer text-primary">Edit details</summary>
                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                {(['first_name', 'last_name', 'business', 'phone'] as const).map((k) => (
                                    <Field
                                        key={k}
                                        label={{ first_name: 'First name', last_name: 'Last name', business: 'Business', phone: 'Phone' }[k]}
                                    >
                                        <Input value={details.data[k]} onChange={(e) => details.setData(k, e.target.value)} />
                                    </Field>
                                ))}
                                <Button
                                    size="sm"
                                    loading={details.processing}
                                    onClick={() => details.patch(`${base}/${p.id}`, { preserveScroll: true })}
                                >
                                    Save
                                </Button>
                            </div>
                        </details>
                    </Card>
                    <Card className="space-y-3 p-5">
                        <h2 className="font-semibold">Timeline</h2>
                        <div className="space-y-2">
                            <Textarea
                                rows={2}
                                placeholder="Add a note (a call, a meeting, what they said)"
                                value={note.data.note}
                                onChange={(e) => note.setData('note', e.target.value)}
                            />
                            <Button
                                size="sm"
                                disabled={note.data.note.trim() === ''}
                                loading={note.processing}
                                onClick={() => note.post(`${base}/${p.id}/notes`, { preserveScroll: true, onSuccess: () => note.reset() })}
                            >
                                Add note
                            </Button>
                        </div>
                        <ol className="space-y-3 border-l border-line pl-4 text-sm">
                            {activities.map((a) => (
                                <li key={a.id}>
                                    <p className="text-xs text-muted">
                                        {new Date(a.at).toLocaleString()} · {a.by ?? 'Automatic'} · {a.type}
                                    </p>
                                    {a.body && <p className="whitespace-pre-line">{a.body}</p>}
                                </li>
                            ))}
                        </ol>
                    </Card>
                </div>
                <div className="space-y-5">
                    <Card className="space-y-3 p-5">
                        <Field label="Stage">
                            <Select
                                value={p.stage}
                                onChange={(e) => {
                                    const stage = e.target.value;
                                    const reason = stage === 'lost' ? window.prompt('Why were they lost?') : null;
                                    if (stage !== 'lost' || reason)
                                        router.post(`${base}/${p.id}/stage`, { stage, lost_reason: reason }, { preserveScroll: true });
                                }}
                            >
                                {Object.entries(stages).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        {p.lostReason && <p className="text-sm text-muted">Lost: {p.lostReason}</p>}
                        <Field label="Owner">
                            <Select
                                value={p.ownerId === null ? '' : String(p.ownerId)}
                                onChange={(e) =>
                                    router.post(
                                        `${base}/${p.id}/owner`,
                                        { owner_id: e.target.value === '' ? null : Number(e.target.value) },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <option value="">Nobody yet</option>
                                {people.map((u) => (
                                    <option key={u.id} value={String(u.id)}>
                                        {u.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </Card>
                    <Card className="space-y-3 p-5">
                        <h2 className="font-semibold">Tasks</h2>
                        <ul className="space-y-1 text-sm">
                            {tasks.map((t) => (
                                <li key={t.id} className="flex items-start gap-2">
                                    <input
                                        type="checkbox"
                                        className="mt-1 size-4"
                                        aria-label={`Done: ${t.title}`}
                                        checked={t.done}
                                        onChange={() => router.post(`${base}/tasks/${t.id}`, {}, { preserveScroll: true })}
                                    />
                                    <span className={t.done ? 'text-muted line-through' : ''}>
                                        {t.title}
                                        <span className="block text-xs text-muted">
                                            {t.assignee} · due {new Date(t.due).toLocaleDateString()}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <Field label="New task" error={task.errors.title}>
                            <Input
                                value={task.data.title}
                                placeholder="e.g. Call about the Austin seat"
                                onChange={(e) => task.setData('title', e.target.value)}
                            />
                        </Field>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Due" error={task.errors.due_at}>
                                <Input type="date" value={task.data.due_at} onChange={(e) => task.setData('due_at', e.target.value)} />
                            </Field>
                            <Field label="For">
                                <Select value={task.data.assignee_id} onChange={(e) => task.setData('assignee_id', e.target.value)}>
                                    {people.map((u) => (
                                        <option key={u.id} value={String(u.id)}>
                                            {u.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                        <Button
                            size="sm"
                            loading={task.processing}
                            onClick={() =>
                                task.post(`${base}/${p.id}/tasks`, { preserveScroll: true, onSuccess: () => task.reset('title', 'due_at') })
                            }
                        >
                            Add task
                        </Button>
                    </Card>
                    <Card className="space-y-2 p-5 text-sm">
                        <h2 className="font-semibold">Forget this person</h2>
                        <p className="text-muted">
                            For a “delete my data” request: everything about them is deleted, and they’re never added or emailed again.
                        </p>
                        <Button
                            size="sm"
                            variant="danger"
                            onClick={() => window.confirm(`Forget ${p.name}? This can’t be undone.`) && router.delete(`${base}/${p.id}`)}
                        >
                            Forget
                        </Button>
                    </Card>
                </div>
            </div>
        </GrowthShell>
    );
}
