import { Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Lesson = { title: string; minutes: number; body: string; video_url: string | null; transcript: string | null };
type Q = { q: string; options: string[]; answer: number; why: string };
type Props = {
    industry: { slug: string; name: string; locked: boolean };
    module: { version: number; minVersion: number; passMark: number; lessons: Lesson[]; questions: Q[] };
    completion: { name: string; required: boolean; holders: number; passed: number }[];
    status?: string;
    seo: Seo;
};

export default function TrainingEditor({ industry, module, completion, status, seo }: Props) {
    const form = useForm({ pass_mark: module.passMark, retrain: false, lessons: module.lessons, questions: module.questions });
    const e = form.errors as Record<string, string>;
    const L = form.data.lessons;
    const Qs = form.data.questions;
    const setL = (i: number, p: Partial<Lesson>) =>
        form.setData(
            'lessons',
            L.map((x, k) => (k === i ? { ...x, ...p } : x)),
        );
    const setQ = (i: number, p: Partial<Q>) =>
        form.setData(
            'questions',
            Qs.map((x, k) => (k === i ? { ...x, ...p } : x)),
        );
    return (
        <AdminLayout title={`${industry.name}: training`}>
            <SeoHead seo={seo} />
            <Link href={`/admin/industries/${industry.slug}`} className="text-sm text-muted hover:text-ink">
                {industry.name}
            </Link>
            <div className="mt-2 mb-4 flex flex-wrap gap-2">
                <Badge>Version {module.version}</Badge>
                {industry.locked && <Badge tone="warning">Required by the platform</Badge>}
            </div>
            {status && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <Card className="mb-6 max-w-4xl overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['Group', 'Training', 'Seat holders', 'Passed'].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {completion.map((c) => (
                            <tr key={c.name} className="border-t border-line">
                                <td className="px-4 py-2.5">{c.name}</td>
                                <td className="px-4 py-2.5">{c.required ? <Badge tone="info">Required</Badge> : <Badge>Optional</Badge>}</td>
                                <td className="px-4 py-2.5 tabular">{c.holders}</td>
                                <td className="px-4 py-2.5 tabular">
                                    {c.passed} {c.holders ? `(${Math.round((c.passed / c.holders) * 100)}%)` : ''}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
            <div className="max-w-4xl space-y-4">
                <h2 className="font-semibold">Lessons</h2>
                {L.map((l, i) => (
                    <Card key={i} className="space-y-3 p-5">
                        <div className="flex gap-3">
                            <Field label="Title" className="flex-1" error={e[`lessons.${i}.title`]}>
                                <Input value={l.title} onChange={(ev) => setL(i, { title: ev.target.value })} />
                            </Field>
                            <Field label="Minutes" className="w-24">
                                <Input type="number" value={l.minutes} onChange={(ev) => setL(i, { minutes: +ev.target.value })} />
                            </Field>
                        </div>
                        <Field label="Lesson text" error={e[`lessons.${i}.body`]}>
                            <Textarea rows={3} value={l.body} onChange={(ev) => setL(i, { body: ev.target.value })} />
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Video link (optional)" error={e[`lessons.${i}.video_url`]}>
                                <Input type="url" value={l.video_url ?? ''} onChange={(ev) => setL(i, { video_url: ev.target.value || null })} />
                            </Field>
                            <Field label="Transcript (required with a video)" error={e[`lessons.${i}.transcript`]}>
                                <Textarea rows={2} value={l.transcript ?? ''} onChange={(ev) => setL(i, { transcript: ev.target.value || null })} />
                            </Field>
                        </div>
                        {L.length > 1 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    form.setData(
                                        'lessons',
                                        L.filter((_, k) => k !== i),
                                    )
                                }
                            >
                                <Trash2 /> Remove lesson
                            </Button>
                        )}
                    </Card>
                ))}
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => form.setData('lessons', [...L, { title: '', minutes: 2, body: '', video_url: null, transcript: null }])}
                >
                    <Plus /> Add lesson
                </Button>
                <h2 className="pt-4 font-semibold">Quiz</h2>
                {Qs.map((q, i) => (
                    <Card key={i} className="space-y-3 p-5">
                        <Field label="Question" error={e[`questions.${i}.q`]}>
                            <Input value={q.q} onChange={(ev) => setQ(i, { q: ev.target.value })} />
                        </Field>
                        {q.options.map((o, k) => (
                            <div key={k} className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    aria-label={`Option ${k + 1} is correct`}
                                    checked={q.answer === k}
                                    onChange={() => setQ(i, { answer: k })}
                                />
                                <Input
                                    aria-label={`Option ${k + 1}`}
                                    value={o}
                                    onChange={(ev) => setQ(i, { options: q.options.map((x, j) => (j === k ? ev.target.value : x)) })}
                                />
                            </div>
                        ))}
                        <Button variant="ghost" size="sm" onClick={() => setQ(i, { options: [...q.options, ''] })}>
                            <Plus /> Add option
                        </Button>
                        <Field
                            label="Why the right answer is right"
                            hint="Shown to members who get it wrong."
                            error={e[`questions.${i}.why`] ?? e[`questions.${i}.answer`]}
                        >
                            <Input value={q.why} onChange={(ev) => setQ(i, { why: ev.target.value })} />
                        </Field>
                        {Qs.length > 1 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    form.setData(
                                        'questions',
                                        Qs.filter((_, k) => k !== i),
                                    )
                                }
                            >
                                <Trash2 /> Remove question
                            </Button>
                        )}
                    </Card>
                ))}
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => form.setData('questions', [...Qs, { q: '', options: ['', ''], answer: 0, why: '' }])}
                >
                    <Plus /> Add question
                </Button>
                <Card className="flex flex-wrap items-end gap-4 p-5">
                    <Field label="Pass mark (%)" className="w-32" error={e.pass_mark}>
                        <Input type="number" value={form.data.pass_mark} onChange={(ev) => form.setData('pass_mark', +ev.target.value)} />
                    </Field>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.retrain} onChange={(ev) => form.setData('retrain', ev.target.checked)} /> This is a
                        material change: ask everyone to re-train
                    </label>
                    <Button
                        className="ml-auto"
                        loading={form.processing}
                        onClick={() => form.put(`/admin/industries/${industry.slug}/training`, { preserveScroll: true })}
                    >
                        Save as version {module.version + 1}
                    </Button>
                </Card>
            </div>
        </AdminLayout>
    );
}
