import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Version = { version: number; active: boolean; system: string; template: string; note: string | null; at: string; up: number; down: number };
type Props = {
    month: string;
    totals: { calls: number; cost: number; credits: number; blocked: number; failed: number };
    byFeature: { feature: string; calls: number; cost: number; credits: number }[];
    byModel: { provider: string; model: string; calls: number; tokens: number; cost: number }[];
    byTeam: { team: string; calls: number; credits: number; cost: number }[];
    problems: { status: string; reason: string; count: number }[];
    prompts: { key: string; versions: Version[]; placeholders: string[] }[];
    credits: { per_seat: number; per_seat_premium: number; daily_cap: number };
    testResult?: { version: number; text: string | null; meta: string } | null;
    status?: string;
    seo: Seo;
};

const money = (n: number) => `$${n.toFixed(2)}`;
const area = 'w-full rounded-md border border-line bg-surface p-2 font-mono text-xs';

function Table({ head, rows }: { head: string[]; rows: (string | number)[][] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[420px] text-sm">
                <thead className="text-left text-muted">
                    <tr>
                        {head.map((h) => (
                            <th key={h} scope="col" className="py-2 pr-4 font-medium">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={head.length} className="py-3 text-muted">
                                Nothing this month.
                            </td>
                        </tr>
                    )}
                    {rows.map((r, i) => (
                        <tr key={i} className="border-t border-line">
                            {r.map((c, j) => (
                                <td key={j} className="py-2 pr-4 tabular">
                                    {c}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function PromptEditor({ p }: { p: Props['prompts'][number] }) {
    const live = p.versions.find((v) => v.active) ?? p.versions[0];
    const f = useForm({ system: live?.system ?? '', template: live?.template ?? '', note: '', activate: false });
    const [open, setOpen] = useState(false);
    return (
        <Card className="space-y-3 p-5">
            <h2 className="font-semibold">Prompt: {p.key.replace('_', ' ')}</h2>
            <ul className="divide-y divide-line text-sm">
                {p.versions.map((v) => (
                    <li key={v.version} className="flex flex-wrap items-center gap-2 py-2">
                        <span className="font-medium">Version {v.version}</span>
                        {v.active && <Badge tone="success">Live</Badge>}
                        <span className="text-muted">
                            {v.note} · {v.at} · 👍 {v.up} 👎 {v.down}
                        </span>
                        <span className="ml-auto flex gap-1">
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.post(`/admin/ai/prompts/${p.key}/${v.version}/test`, {}, { preserveScroll: true })}
                            >
                                Test run
                            </Button>
                            {!v.active && (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() =>
                                        window.confirm(`Make version ${v.version} live?`) &&
                                        router.post(`/admin/ai/prompts/${p.key}/${v.version}/activate`, {}, { preserveScroll: true })
                                    }
                                >
                                    Make live
                                </Button>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
            {!open ? (
                <Button size="sm" variant="secondary" onClick={() => setOpen(true)}>
                    Edit as a new version
                </Button>
            ) : (
                <div className="space-y-2">
                    <p className="text-xs text-muted">
                        Keep these placeholders: {p.placeholders.join(', ')}. Saving creates a new version; the live one doesn’t change until you make
                        it live.
                    </p>
                    <Field label="System instructions" error={f.errors.system}>
                        <textarea rows={6} className={area} value={f.data.system} onChange={(e) => f.setData('system', e.target.value)} />
                    </Field>
                    <Field label="Template" error={f.errors.template}>
                        <textarea rows={5} className={area} value={f.data.template} onChange={(e) => f.setData('template', e.target.value)} />
                    </Field>
                    <Field label="What changed" error={f.errors.note}>
                        <Input value={f.data.note} onChange={(e) => f.setData('note', e.target.value)} />
                    </Field>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={f.data.activate} onChange={(e) => f.setData('activate', e.target.checked)} /> Make it live
                        straight away
                    </label>
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            loading={f.processing}
                            onClick={() => f.post(`/admin/ai/prompts/${p.key}`, { preserveScroll: true, onSuccess: () => setOpen(false) })}
                        >
                            Save version
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                            Close
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}

/** Admin → AI: what AI costs and who uses it, the prompts behind it, and the credit defaults. */
export default function Ai({ month, totals, byFeature, byModel, byTeam, problems, prompts, credits, testResult, status, seo }: Props) {
    const c = useForm(credits);
    return (
        <AdminLayout title="AI">
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-6">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                {testResult && (
                    <Alert tone={testResult.text ? 'info' : 'danger'}>
                        <strong>Test run, version {testResult.version}</strong> ({testResult.meta})
                        {testResult.text && <span className="mt-1 block whitespace-pre-wrap">{testResult.text}</span>}
                    </Alert>
                )}
                <div className="flex items-center gap-3">
                    <Input
                        type="month"
                        aria-label="Month"
                        className="w-44"
                        value={month}
                        onChange={(e) => router.get('/admin/ai', { month: e.target.value })}
                    />
                </div>
                <div className="grid gap-3 sm:grid-cols-5">
                    {(
                        [
                            ['Calls', totals.calls],
                            ['Cost', money(totals.cost)],
                            ['Credits used', totals.credits],
                            ['Blocked', totals.blocked],
                            ['Failed', totals.failed],
                        ] as [string, string | number][]
                    ).map(([l, v]) => (
                        <Card key={l} className="p-4">
                            <p className="text-xs text-muted">{l}</p>
                            <p className="font-display text-xl font-bold tabular">{v}</p>
                        </Card>
                    ))}
                </div>
                <Card className="space-y-4 p-5">
                    <h2 className="font-semibold">By feature</h2>
                    <Table
                        head={['Feature', 'Calls', 'Credits', 'Cost']}
                        rows={byFeature.map((r) => [r.feature.replace('_', ' '), r.calls, r.credits, money(r.cost)])}
                    />
                    <h2 className="font-semibold">By provider and model</h2>
                    <Table
                        head={['Provider', 'Model', 'Calls', 'Tokens', 'Cost']}
                        rows={byModel.map((r) => [r.provider, r.model, r.calls, r.tokens.toLocaleString(), money(r.cost)])}
                    />
                    <h2 className="font-semibold">Top teams</h2>
                    <Table head={['Team', 'Calls', 'Credits', 'Cost']} rows={byTeam.map((r) => [r.team, r.calls, r.credits, money(r.cost)])} />
                    <h2 className="font-semibold">Blocked and failed</h2>
                    <Table head={['Outcome', 'Reason', 'Times']} rows={problems.map((r) => [r.status, r.reason, r.count])} />
                </Card>
                {prompts.map((p) => (
                    <PromptEditor key={p.key} p={p} />
                ))}
                <Card className="space-y-3 p-5">
                    <h2 className="font-semibold">Credit defaults</h2>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field label="Free credits per seat each month" error={c.errors.per_seat}>
                            <Input type="number" min="0" value={c.data.per_seat} onChange={(e) => c.setData('per_seat', Number(e.target.value))} />
                        </Field>
                        <Field label="With premium" error={c.errors.per_seat_premium}>
                            <Input
                                type="number"
                                min="0"
                                value={c.data.per_seat_premium}
                                onChange={(e) => c.setData('per_seat_premium', Number(e.target.value))}
                            />
                        </Field>
                        <Field label="Daily cap per team" error={c.errors.daily_cap}>
                            <Input type="number" min="1" value={c.data.daily_cap} onChange={(e) => c.setData('daily_cap', Number(e.target.value))} />
                        </Field>
                    </div>
                    <Button size="sm" loading={c.processing} onClick={() => c.post('/admin/ai/credits', { preserveScroll: true })}>
                        Save credit defaults
                    </Button>
                </Card>
            </div>
        </AdminLayout>
    );
}
