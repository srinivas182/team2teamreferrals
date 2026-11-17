import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Issue = {
    id: number;
    title: string;
    kind: string;
    path: string;
    severity: 'high' | 'medium' | 'low';
    status: string;
    hits: number;
    testers: number;
    families: string[];
    notes: string;
    lastSeen: string;
};
type Session = {
    id: string;
    name: string;
    email: string;
    family: string;
    status: string;
    tasks: number;
    done: number;
    gaveUp: number;
    problems: number;
    invited: string;
};
type Task = { key: string; title: string; task: string; done: string; start: string };
type Props = {
    coverage: { family: string; name: string; invited: number; finished: number; target: number }[];
    issues: Issue[];
    sessions: Session[];
    families: { key: string; name: string }[];
    industries: { slug: string; name: string }[];
    journeys: { key: string; title: string }[];
    family: string;
    script: { family: string; familyName: string; industry: string | null; tasks: Task[] };
    statuses: Record<string, string>;
    flash: string | null;
    seo: Seo;
};

const severityTone = { high: 'danger', medium: 'warning', low: 'neutral' } as const;

function IssueRow({ issue, statuses }: { issue: Issue; statuses: Record<string, string> }) {
    const [editing, setEditing] = useState(false);
    const f = useForm({ status: issue.status, severity: issue.severity, notes: issue.notes });
    return (
        <li className="border-b border-line py-3 last:border-0">
            <div className="flex flex-wrap items-center gap-2">
                <Badge tone={severityTone[issue.severity]}>{issue.severity}</Badge>
                <span className="flex-1 font-medium">{issue.title}</span>
                <Badge tone={issue.status === 'fixed' ? 'success' : issue.status === 'planned' ? 'info' : 'neutral'}>
                    {statuses[issue.status] ?? issue.status}
                </Badge>
                <Button size="sm" variant="ghost" onClick={() => setEditing(!editing)}>
                    {editing ? 'Close' : 'Update'}
                </Button>
            </div>
            <p className="mt-1 text-xs text-muted">
                {issue.testers} {issue.testers === 1 ? 'tester' : 'testers'} · {issue.hits} {issue.hits === 1 ? 'time' : 'times'} ·{' '}
                {issue.families.join(', ')} · last {issue.lastSeen}
            </p>
            {issue.notes && !editing && <p className="mt-1 text-sm">{issue.notes}</p>}
            {editing && (
                <div className="mt-3 grid gap-3 sm:grid-cols-[160px_160px_1fr_auto] sm:items-end">
                    <Field label="Status">
                        <Select value={f.data.status} onChange={(e) => f.setData('status', e.target.value)}>
                            {Object.entries(statuses).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Severity">
                        <Select value={f.data.severity} onChange={(e) => f.setData('severity', e.target.value as Issue['severity'])}>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </Select>
                    </Field>
                    <Field label="Notes">
                        <Textarea rows={1} value={f.data.notes} onChange={(e) => f.setData('notes', e.target.value)} />
                    </Field>
                    <Button
                        size="sm"
                        disabled={f.processing}
                        onClick={() => f.put(`/admin/usability/issues/${issue.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) })}
                    >
                        Save
                    </Button>
                </div>
            )}
        </li>
    );
}

/** Admin → Usability tests: coverage per family, the fix list, testers and the script each family gets. */
export default function UsabilityAdmin({ coverage, issues, sessions, families, industries, journeys, family, script, statuses, flash, seo }: Props) {
    const invite = useForm({ name: '', email: '', family, industry: '', journeys: journeys.map((j) => j.key) });
    const pickFamily = (f: string) => {
        invite.setData((d) => ({ ...d, family: f, industry: '' }));
        router.get(
            '/admin/usability',
            { family: f },
            { preserveState: true, preserveScroll: true, replace: true, only: ['industries', 'script', 'family'] },
        );
    };
    const pickIndustry = (slug: string) => {
        invite.setData('industry', slug);
        router.get(
            '/admin/usability',
            { family: invite.data.family, industry: slug || undefined },
            { preserveState: true, preserveScroll: true, replace: true, only: ['script'] },
        );
    };
    const open = issues.filter((i) => i.status === 'open' || i.status === 'planned');
    return (
        <AdminLayout title="Usability tests">
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-6">
                {flash && <Alert tone="success">{statusMessage(flash)}</Alert>}
                <p className="text-sm text-muted">
                    Invite at least three teams per industry family. Each gets a short script in their industry’s words; while they work we note where
                    they get stuck, and each problem lands on the fix list below. Run tests on your demo install so testers don’t change real data.
                </p>
                <section aria-labelledby="coverage">
                    <h2 id="coverage" className="mb-2 font-semibold">
                        Testers per family
                    </h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {coverage.map((c) => (
                            <Card key={c.family} className="p-4">
                                <div className="text-xs text-muted">{c.name}</div>
                                <div className="mt-1 font-display text-xl font-bold">
                                    {c.finished} of {c.target}
                                </div>
                                <div className="mt-2 h-1.5 rounded bg-bg" aria-hidden>
                                    <div className="h-1.5 rounded bg-primary" style={{ width: `${Math.min(100, (c.finished / c.target) * 100)}%` }} />
                                </div>
                                <div className="mt-1 text-xs text-muted">{c.invited} invited</div>
                            </Card>
                        ))}
                    </div>
                </section>
                <Card className="p-5">
                    <h2 className="font-semibold">Fix list</h2>
                    <p className="text-sm text-muted">
                        {open.length} open. Ordered by status, then severity, then how often it happened. A fixed problem that happens again opens
                        again.
                    </p>
                    {issues.length === 0 ? (
                        <p className="mt-3 text-sm text-muted">Nothing yet. Problems appear here as testers run into them.</p>
                    ) : (
                        <ul className="mt-2">
                            {issues.map((i) => (
                                <IssueRow key={i.id} issue={i} statuses={statuses} />
                            ))}
                        </ul>
                    )}
                </Card>
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="space-y-3 p-5">
                        <h2 className="font-semibold">Invite a tester</h2>
                        <Field label="Name" error={invite.errors.name}>
                            <Input value={invite.data.name} onChange={(e) => invite.setData('name', e.target.value)} />
                        </Field>
                        <Field label="Email" error={invite.errors.email}>
                            <Input type="email" value={invite.data.email} onChange={(e) => invite.setData('email', e.target.value)} />
                        </Field>
                        <Field label="Industry family" error={invite.errors.family}>
                            <Select value={invite.data.family} onChange={(e) => pickFamily(e.target.value)}>
                                {families.map((f) => (
                                    <option key={f.key} value={f.key}>
                                        {f.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Industry (the words the script uses)" hint="Leave as suggested to use the most typical one.">
                            <Select value={invite.data.industry} onChange={(e) => pickIndustry(e.target.value)}>
                                <option value="">Suggested{script.industry ? `: ${script.industry}` : ''}</option>
                                {industries.map((i) => (
                                    <option key={i.slug} value={i.slug}>
                                        {i.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <fieldset>
                            <legend className="text-sm font-medium">Tasks</legend>
                            <div className="mt-1 grid gap-1 text-sm sm:grid-cols-2">
                                {journeys.map((j) => (
                                    <label key={j.key} className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={invite.data.journeys.includes(j.key)}
                                            onChange={(e) =>
                                                invite.setData(
                                                    'journeys',
                                                    e.target.checked
                                                        ? [...invite.data.journeys, j.key]
                                                        : invite.data.journeys.filter((k) => k !== j.key),
                                                )
                                            }
                                        />
                                        {j.title}
                                    </label>
                                ))}
                            </div>
                            {invite.errors.journeys && <p className="mt-1 text-sm text-danger">{invite.errors.journeys}</p>}
                        </fieldset>
                        <Button
                            disabled={invite.processing}
                            onClick={() =>
                                invite.post('/admin/usability/invite', { preserveScroll: true, onSuccess: () => invite.reset('name', 'email') })
                            }
                        >
                            Send invitation
                        </Button>
                    </Card>
                    <Card className="p-5">
                        <h2 className="font-semibold">
                            Script: {script.familyName}
                            {script.industry ? ` (${script.industry})` : ''}
                        </h2>
                        <p className="text-sm text-muted">
                            What a tester in this family is asked to do. Tasks the industry’s rules don’t allow are left out.
                        </p>
                        <ol className="mt-3 list-decimal space-y-2 pl-5 text-sm">
                            {script.tasks.map((t) => (
                                <li key={t.key}>
                                    <span className="font-semibold">{t.title}.</span> {t.task} <span className="text-muted">{t.done}</span>
                                </li>
                            ))}
                        </ol>
                    </Card>
                </div>
                <Card className="p-5">
                    <h2 className="font-semibold">Testers</h2>
                    {sessions.length === 0 ? (
                        <p className="mt-2 text-sm text-muted">No one invited yet.</p>
                    ) : (
                        <div className="mt-2 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-muted">
                                    <tr>
                                        <th className="py-2">Tester</th>
                                        <th>Family</th>
                                        <th>Status</th>
                                        <th>Tasks</th>
                                        <th>Problems</th>
                                        <th>Invited</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {sessions.map((s) => (
                                        <tr key={s.id} className="border-t border-line">
                                            <td className="py-2">
                                                <Link href={`/admin/usability/${s.id}`} className="font-medium text-primary">
                                                    {s.name}
                                                </Link>
                                                <div className="text-xs text-muted">{s.email}</div>
                                            </td>
                                            <td>{s.family}</td>
                                            <td>
                                                <Badge tone={s.status === 'finished' ? 'success' : s.status === 'started' ? 'info' : 'neutral'}>
                                                    {s.status}
                                                </Badge>
                                            </td>
                                            <td>
                                                {s.done} done{s.gaveUp > 0 ? `, ${s.gaveUp} given up` : ''} of {s.tasks}
                                            </td>
                                            <td>{s.problems}</td>
                                            <td>{s.invited}</td>
                                            <td>
                                                {s.status !== 'finished' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => router.post(`/admin/usability/${s.id}/resend`, {}, { preserveScroll: true })}
                                                    >
                                                        Resend
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </AdminLayout>
    );
}
