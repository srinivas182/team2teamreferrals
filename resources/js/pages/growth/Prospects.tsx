import { Link, router, useForm } from '@inertiajs/react';
import { LayoutGrid, List, Upload, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { GrowthShell, TEMP_TONE } from '@/components/growth/GrowthShell';
import type { GrowthShared } from '@/components/growth/GrowthShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';

type Row = {
    id: string;
    name: string;
    email: string;
    business: string | null;
    stage: string;
    score: number;
    temp: 'hot' | 'warm' | 'cold';
    source: string;
    city: string | null;
    industry: string | null;
    owner: string | null;
    lastActivity: string | null;
    unsubscribed: boolean;
};
type Filters = Partial<Record<'q' | 'stage' | 'source' | 'industry' | 'state' | 'owner' | 'temp' | 'tasks' | 'view', string>>;
type Props = GrowthShared & {
    filters: Filters;
    prospects: { data: Row[]; total: number; links: { url: string | null; label: string; active: boolean }[] } | null;
    board: { stage: string; label: string; count: number; items: Row[] }[] | null;
    counts: { total: number; hot: number; myTasks: number };
    views: { id: number; name: string; filters: Filters }[];
    importPreview: { token: string; name: string; headers: string[]; guess: string[]; sample: string[][] } | null;
    importResult: { added: number; updated: number; skipped: number; problems: string[] } | null;
};

/** Prospects: people who might join, with filters, a stage board, bulk changes, adding and importing. */
export default function Prospects(props: Props) {
    const { filters, prospects, board, counts, views, base, actions, stages, sources, people, industries, importPreview, importResult } = props;
    const [selected, setSelected] = useState<string[]>([]);
    const [adding, setAdding] = useState(false);
    const go = (next: Filters) =>
        router.get(base, Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v)), {
            preserveState: true,
            preserveScroll: true,
        });
    const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const bulk = (data: Record<string, unknown>) =>
        router.post(actions.bulk, { ids: selected, ...data }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    return (
        <GrowthShell shared={props} title="Prospects">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-muted">
                    {counts.total} prospects · {counts.hot} hot ·{' '}
                    <button type="button" className="text-primary" onClick={() => go({ tasks: filters.tasks ? '' : 'mine' })}>
                        {counts.myTasks} open tasks for you
                    </button>
                </p>
                <div className="flex gap-2">
                    <Button size="sm" variant="secondary" onClick={() => go({ view: filters.view === 'board' ? 'list' : 'board' })}>
                        {filters.view === 'board' ? <List /> : <LayoutGrid />} {filters.view === 'board' ? 'List' : 'Board'}
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => setAdding(!adding)}>
                        <UserPlus /> Add
                    </Button>
                    {props.layout === 'admin' && (
                        <Button size="sm" variant="ghost" asChild>
                            <Link href="/admin/growth/settings">Email settings</Link>
                        </Button>
                    )}
                </div>
            </div>
            {adding && <AddProspect base={base} industries={industries} />}
            <ImportCard actions={actions} preview={importPreview} result={importResult} fields={props.importFields} />
            <Card className="flex flex-wrap items-end gap-2 p-4">
                <Field label="Search" className="min-w-48 flex-1">
                    <Input
                        defaultValue={filters.q ?? ''}
                        placeholder="Name, email or business"
                        onKeyDown={(e) => e.key === 'Enter' && go({ q: e.currentTarget.value })}
                    />
                </Field>
                <Field label="Stage">
                    <Select value={filters.stage ?? ''} onChange={(e) => go({ stage: e.target.value })}>
                        <option value="">Any</option>
                        {Object.entries(stages).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="Source">
                    <Select value={filters.source ?? ''} onChange={(e) => go({ source: e.target.value })}>
                        <option value="">Any</option>
                        {Object.entries(sources).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </Select>
                </Field>
                {industries.length > 0 && (
                    <Field label="Industry">
                        <Select value={filters.industry ?? ''} onChange={(e) => go({ industry: e.target.value })}>
                            <option value="">Any</option>
                            {industries.map((i) => (
                                <option key={i.id} value={String(i.id)}>
                                    {i.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                )}
                <Field label="State">
                    <Input
                        className="w-20"
                        maxLength={2}
                        defaultValue={filters.state ?? ''}
                        onKeyDown={(e) => e.key === 'Enter' && go({ state: e.currentTarget.value.toUpperCase() })}
                    />
                </Field>
                <Field label="Owner">
                    <Select value={filters.owner ?? ''} onChange={(e) => go({ owner: e.target.value })}>
                        <option value="">Anyone</option>
                        <option value="mine">Mine</option>
                        <option value="none">Nobody yet</option>
                        {people.map((p) => (
                            <option key={p.id} value={String(p.id)}>
                                {p.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="Lead score">
                    <Select value={filters.temp ?? ''} onChange={(e) => go({ temp: e.target.value })}>
                        <option value="">Any</option>
                        <option value="hot">Hot</option>
                        <option value="warm">Warm</option>
                        <option value="cold">Cold</option>
                    </Select>
                </Field>
                <SaveView action={actions.views} filters={filters} />
            </Card>
            {views.length > 0 && (
                <div className="flex flex-wrap gap-2 text-sm">
                    <span className="text-muted">Saved views:</span>
                    {views.map((v) => (
                        <span key={v.id} className="inline-flex items-center gap-1">
                            <button type="button" className="text-primary" onClick={() => router.get(base, v.filters)}>
                                {v.name}
                            </button>
                            <button
                                type="button"
                                aria-label={`Delete view ${v.name}`}
                                className="text-muted"
                                onClick={() => router.delete(`${base}/views/${v.id}`, { preserveScroll: true })}
                            >
                                ×
                            </button>
                        </span>
                    ))}
                </div>
            )}
            {selected.length > 0 && (
                <Card className="flex flex-wrap items-center gap-2 p-3 text-sm">
                    <span>{selected.length} selected:</span>
                    <Select
                        className="w-44"
                        defaultValue=""
                        onChange={(e) =>
                            e.target.value &&
                            bulk({
                                action: 'stage',
                                stage: e.target.value,
                                lost_reason: e.target.value === 'lost' ? window.prompt('Why were they lost?') : null,
                            })
                        }
                    >
                        <option value="">Move to stage…</option>
                        {Object.entries(stages).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </Select>
                    <Select
                        className="w-44"
                        defaultValue=""
                        onChange={(e) =>
                            e.target.value && bulk({ action: 'owner', owner_id: e.target.value === 'none' ? null : Number(e.target.value) })
                        }
                    >
                        <option value="">Give to…</option>
                        <option value="none">Nobody</option>
                        {people.map((p) => (
                            <option key={p.id} value={String(p.id)}>
                                {p.name}
                            </option>
                        ))}
                    </Select>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            window.confirm(`Forget ${selected.length} prospects? Everything about them is deleted.`) && bulk({ action: 'forget' })
                        }
                    >
                        Forget
                    </Button>
                </Card>
            )}
            {board ? (
                <div className="grid gap-3 overflow-x-auto md:grid-cols-4 xl:grid-cols-7">
                    {board.map((col) => (
                        <div key={col.stage} className="min-w-44 space-y-2">
                            <h3 className="text-sm font-semibold">
                                {col.label} <span className="font-normal text-muted">({col.count})</span>
                            </h3>
                            {col.items.map((r) => (
                                <Link
                                    key={r.id}
                                    href={`${base}/${r.id}`}
                                    className="block rounded-md border border-line bg-surface p-2 text-sm hover:border-primary"
                                >
                                    <span className="block font-medium">{r.name}</span>
                                    <span className="block text-xs text-muted">{r.city ?? r.industry ?? r.email}</span>
                                    <Badge tone={TEMP_TONE[r.temp]}>{r.temp}</Badge>
                                </Link>
                            ))}
                        </div>
                    ))}
                </div>
            ) : (
                <Card className="overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs text-muted">
                            <tr>
                                <th className="p-3">
                                    <input
                                        type="checkbox"
                                        aria-label="Select all"
                                        className="size-4"
                                        checked={!!prospects && selected.length === prospects.data.length && selected.length > 0}
                                        onChange={(e) => setSelected(e.target.checked ? (prospects?.data.map((r) => r.id) ?? []) : [])}
                                    />
                                </th>
                                <th className="p-3 font-medium">Prospect</th>
                                <th className="p-3 font-medium">Stage</th>
                                <th className="p-3 font-medium">Score</th>
                                <th className="p-3 font-medium">Where</th>
                                <th className="p-3 font-medium">Source</th>
                                <th className="p-3 font-medium">Owner</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {prospects?.data.map((r) => (
                                <tr key={r.id}>
                                    <td className="p-3">
                                        <input
                                            type="checkbox"
                                            aria-label={`Select ${r.name}`}
                                            className="size-4"
                                            checked={selected.includes(r.id)}
                                            onChange={() => toggle(r.id)}
                                        />
                                    </td>
                                    <td className="p-3">
                                        <Link href={`${base}/${r.id}`} className="font-medium text-primary">
                                            {r.name}
                                        </Link>
                                        <span className="block text-xs text-muted">
                                            {r.business ?? r.email}
                                            {r.unsubscribed ? ' · unsubscribed' : ''}
                                        </span>
                                    </td>
                                    <td className="p-3">{stages[r.stage]}</td>
                                    <td className="p-3">
                                        <Badge tone={TEMP_TONE[r.temp]}>
                                            {r.temp} {r.score}
                                        </Badge>
                                    </td>
                                    <td className="p-3">
                                        {r.city ?? '—'}
                                        {r.industry ? <span className="block text-xs text-muted">{r.industry}</span> : null}
                                    </td>
                                    <td className="p-3">{sources[r.source] ?? r.source}</td>
                                    <td className="p-3">{r.owner ?? '—'}</td>
                                </tr>
                            ))}
                            {prospects?.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="p-6 text-center text-muted">
                                        No prospects match.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                    {prospects && prospects.links.length > 3 && (
                        <nav className="flex flex-wrap gap-1 border-t border-line p-3 text-sm" aria-label="Pages">
                            {prospects.links.map((l, i) =>
                                l.url ? (
                                    <Link
                                        key={i}
                                        href={l.url}
                                        preserveScroll
                                        className={`rounded px-2 py-1 ${l.active ? 'bg-primary text-on-primary' : ''}`}
                                    >
                                        <span dangerouslySetInnerHTML={{ __html: l.label }} />
                                    </Link>
                                ) : null,
                            )}
                        </nav>
                    )}
                </Card>
            )}
        </GrowthShell>
    );
}

function AddProspect({ base, industries }: { base: string; industries: { id: number; name: string }[] }) {
    const f = useForm({ email: '', first_name: '', last_name: '', business: '', phone: '', industry_id: '' });
    return (
        <Card className="grid gap-3 p-4 sm:grid-cols-3">
            <Field label="Email" error={f.errors.email}>
                <Input type="email" value={f.data.email} onChange={(e) => f.setData('email', e.target.value)} />
            </Field>
            <Field label="First name">
                <Input value={f.data.first_name} onChange={(e) => f.setData('first_name', e.target.value)} />
            </Field>
            <Field label="Last name">
                <Input value={f.data.last_name} onChange={(e) => f.setData('last_name', e.target.value)} />
            </Field>
            <Field label="Business">
                <Input value={f.data.business} onChange={(e) => f.setData('business', e.target.value)} />
            </Field>
            <Field label="Phone">
                <Input value={f.data.phone} onChange={(e) => f.setData('phone', e.target.value)} />
            </Field>
            {industries.length > 0 && (
                <Field label="Industry">
                    <Select value={f.data.industry_id} onChange={(e) => f.setData('industry_id', e.target.value)}>
                        <option value="">—</option>
                        {industries.map((i) => (
                            <option key={i.id} value={String(i.id)}>
                                {i.name}
                            </option>
                        ))}
                    </Select>
                </Field>
            )}
            <div className="sm:col-span-3">
                <Button loading={f.processing} onClick={() => f.post(base)}>
                    Add prospect
                </Button>
            </div>
        </Card>
    );
}

function SaveView({ action, filters }: { action: string; filters: Filters }) {
    return (
        <Button
            size="sm"
            variant="ghost"
            onClick={() => {
                const name = window.prompt('Name this view');
                if (name) router.post(action, { name, filters: { ...filters } }, { preserveScroll: true });
            }}
        >
            Save view
        </Button>
    );
}

function ImportCard({
    actions,
    preview,
    result,
    fields,
}: {
    actions: GrowthShared['actions'];
    preview: Props['importPreview'];
    result: Props['importResult'];
    fields: Record<string, string>;
}) {
    const up = useForm<{ file: File | null }>({ file: null });
    const conf = useForm({ token: preview?.token ?? '', name: preview?.name ?? '', mapping: preview?.guess ?? [], not_purchased: false });
    if (result) {
        return (
            <Card className="space-y-1 p-4 text-sm">
                <p className="font-semibold">
                    Imported: {result.added} added, {result.updated} updated, {result.skipped} skipped.
                </p>
                {result.problems.map((p) => (
                    <p key={p} className="text-muted">
                        {p}
                    </p>
                ))}
            </Card>
        );
    }
    if (preview) {
        return (
            <Card className="space-y-3 p-4 text-sm">
                <h2 className="font-semibold">Match the columns in {preview.name}</h2>
                <div className="overflow-x-auto">
                    <table className="text-left text-xs">
                        <thead>
                            <tr>
                                {preview.headers.map((h, i) => (
                                    <th key={i} className="p-1 align-bottom">
                                        <span className="block font-medium">{h}</span>
                                        <Select
                                            className="mt-1 w-36"
                                            aria-label={`Field for ${h}`}
                                            value={conf.data.mapping[i] ?? ''}
                                            onChange={(e) =>
                                                conf.setData(
                                                    'mapping',
                                                    preview.headers.map((_, j) => (j === i ? e.target.value : (conf.data.mapping[j] ?? ''))),
                                                )
                                            }
                                        >
                                            <option value="">Skip</option>
                                            {Object.entries(fields).map(([k, v]) => (
                                                <option key={k} value={k}>
                                                    {v}
                                                </option>
                                            ))}
                                        </Select>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {preview.sample.map((r, i) => (
                                <tr key={i}>
                                    {preview.headers.map((_, j) => (
                                        <td key={j} className="p-1 text-muted">
                                            {r[j] ?? ''}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <label className="flex items-start gap-2">
                    <input
                        type="checkbox"
                        className="mt-1 size-4"
                        checked={conf.data.not_purchased}
                        onChange={(e) => conf.setData('not_purchased', e.target.checked)}
                    />
                    <span>
                        These are people we know or met (for example at events, or who asked about joining).{' '}
                        <strong>They weren’t bought from a list.</strong>
                    </span>
                </label>
                {Object.values(conf.errors).map((e) => (
                    <p key={e} className="text-danger">
                        {e}
                    </p>
                ))}
                <Button loading={conf.processing} onClick={() => conf.post(actions.importConfirm, { preserveScroll: true })}>
                    Import
                </Button>
            </Card>
        );
    }
    return (
        <details className="rounded-md border border-line p-3 text-sm">
            <summary className="cursor-pointer font-medium">
                <Upload className="inline size-4" /> Import a CSV
            </summary>
            <div className="mt-3 flex flex-wrap items-end gap-2">
                <Field label="CSV file (first row: column names)" error={up.errors.file}>
                    <Input type="file" accept=".csv,text/csv" onChange={(e) => up.setData('file', e.target.files?.[0] ?? null)} />
                </Field>
                <Button
                    size="sm"
                    loading={up.processing}
                    disabled={!up.data.file}
                    onClick={() => up.post(actions.import, { preserveScroll: true, forceFormData: true })}
                >
                    Next: match columns
                </Button>
            </div>
        </details>
    );
}
