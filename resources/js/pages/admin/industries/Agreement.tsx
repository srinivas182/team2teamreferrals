import { Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, FileDown, FileText, GitCompare, Lock, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Clause = { key: string; title: string; text: string; required: boolean; optional: boolean; enabled: boolean; fee: boolean };
type Version = {
    version: number;
    active: boolean;
    approved: boolean;
    approvedAt: string | null;
    counsel: string | null;
    note: string | null;
    when: string;
};
type Diff = { key: string; title: string; status: string; parts: [string, string][] };
type Props = {
    industry: { slug: string; name: string; tier: string; tierLabel: string; allowsFees: boolean; terminology: { pro: string; client: string } };
    current: { clauses: Clause[]; version: number | null; approved: boolean; source: string };
    signing: number | null;
    signingScope: string | null;
    scope: { basePath: string; backHref: string; backLabel: string; group: string | null };
    history: Version[];
    fields: Record<string, string>;
    feeFields: string[];
    defaults: Clause[];
    sample: Record<string, string>;
    addenda: { stateId: number; code: string; name: string; text: string }[];
    states: { id: number; name: string; code: string }[];
    compare: Diff[] | null;
    compareVersions: { a: number; b: number } | null;
    status?: string;
    seo: Seo;
};

const fill = (t: string, d: Record<string, string>) => t.replace(/\{([a-z_]+)\}/g, (m, k: string) => d[k] ?? m);

function summary(clauses: Clause[], d: Record<string, string>, defaults: Clause[]) {
    const has = (k: string) => clauses.some((c) => c.key === k && c.enabled);
    const fee = clauses.find((c) => c.key === 'fee');
    const defaultFee = defaults.find((c) => c.key === 'fee');
    const out = [`${d.sender_team} is introducing ${d.client} to ${d.receiver_team} in ${d.city}.`];
    if (has('fee'))
        out.push(
            fee && defaultFee && fee.text !== defaultFee.text
                ? `Fee terms: ${fill(fee.text, d)}`
                : `If ${d.client} completes a transaction within ${d.protection_months} months, ${d.receiver_team} pays ${d.sender_team} ${d.fee_terms}, within ${d.fee_due_days} days of closing.`,
            'The fee is paid business to business. Team to Team never collects or holds it.',
        );
    if (has('no_fee')) out.push('No money changes hands for this introduction.');
    if (has('cancellation')) out.push('Either side can cancel in writing before the client signs.');
    out.push(`If there’s a disagreement, the laws of ${d.governing_state} apply${has('mediation') ? ', after trying mediation first.' : '.'}`);
    return out;
}

export default function Agreement({
    industry,
    scope,
    signingScope,
    defaults,
    current,
    signing,
    history,
    fields,
    feeFields,
    sample,
    addenda,
    states,
    compare,
    compareVersions,
    status,
    seo,
}: Props) {
    const form = useForm<{ clauses: Clause[] }>({ clauses: current.clauses });
    const [focus, setFocus] = useState(0);
    const [approving, setApproving] = useState(false);
    const approve = useForm({ counsel: '', note: '' });
    const [stateId, setStateId] = useState<number>(addenda[0]?.stateId ?? states.find((s) => s.code === 'TX')?.id ?? states[0]?.id ?? 0);
    const addendum = useForm({ text: addenda.find((a) => a.stateId === stateId)?.text ?? '' });
    const [cmp, setCmp] = useState({ a: compareVersions?.a ?? history[1]?.version ?? 0, b: compareVersions?.b ?? history[0]?.version ?? 0 });
    const errors = form.errors as Record<string, string>;
    const cl = form.data.clauses;
    const set = (i: number, patch: Partial<Clause>) =>
        form.setData(
            'clauses',
            cl.map((c, k) => (k === i ? { ...c, ...patch } : c)),
        );
    const move = (i: number, d: number) => {
        const n = [...cl];
        const [x] = n.splice(i, 1);
        n.splice(i + d, 0, x!);
        form.setData('clauses', n);
    };
    const insert = (field: string) => set(focus, { text: `${cl[focus]?.text ?? ''}{${field}}` });
    const dirty = JSON.stringify(cl) !== JSON.stringify(current.clauses);

    if (industry.tier === 'C') {
        return (
            <AdminLayout title={`${industry.name}: agreement`}>
                <SeoHead seo={seo} />
                <Alert tone="info" className="max-w-2xl">
                    {industry.name} is a directory-only industry (Tier C). There is no referral agreement: members are listed and contacted directly,
                    and only the client’s consent to share their details is recorded.
                </Alert>
            </AdminLayout>
        );
    }

    return (
        <AdminLayout
            title={scope.group ? `${scope.group}: agreement` : `${industry.name}: agreement`}
            actions={
                scope.group ? undefined : (
                    <>
                        <Button variant="secondary" asChild>
                            <a href={`/admin/industries/${industry.slug}/agreement/preview.pdf`} target="_blank" rel="noreferrer">
                                <FileText /> PDF preview
                            </a>
                        </Button>
                        <Button variant="secondary" asChild>
                            <a href={`/admin/industries/${industry.slug}/agreement/counsel-pack.pdf`}>
                                <FileDown /> Counsel review pack
                            </a>
                        </Button>
                    </>
                )
            }
        >
            <SeoHead seo={seo} />
            <Link href={scope.backHref} className="text-sm text-muted hover:text-ink">
                {scope.backLabel}
            </Link>
            <div className="mt-3 mb-5 flex flex-wrap gap-2">
                <Badge tone={industry.tier === 'A' ? 'success' : 'warning'}>
                    Tier {industry.tier} · {industry.tierLabel}
                </Badge>
                {signing ? (
                    <Badge tone="success">
                        Members sign {scope.group && signingScope === 'industry' ? `the industry’s v${signing}` : `v${signing}`}
                    </Badge>
                ) : (
                    <Badge tone="danger">No approved version: members can’t sign yet</Badge>
                )}
                {current.version && !current.approved && <Badge tone="warning">Draft v{current.version} awaiting approval</Badge>}
                {current.source === 'default' && <Badge>Default wording</Badge>}
            </div>
            {status && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}
            {!industry.allowsFees && (
                <Alert tone="info" className="mb-4 max-w-4xl">
                    This tier doesn’t allow referral fees between members. Fee clauses and fee fields are blocked.
                </Alert>
            )}
            {errors.clauses && (
                <Alert tone="danger" className="mb-4 max-w-4xl">
                    {errors.clauses}
                </Alert>
            )}

            <div className="grid gap-6 xl:grid-cols-[1.2fr_1fr]">
                <div className="space-y-4">
                    {cl.map((c, i) => (
                        <Card key={c.key + i} className={cn('p-5', !c.enabled && 'opacity-60')}>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-display text-sm font-bold text-muted">{i + 1}</span>
                                <Input
                                    aria-label="Clause title"
                                    className="h-9 max-w-xs"
                                    value={c.title}
                                    onChange={(e) => set(i, { title: e.target.value })}
                                />
                                {c.required && (
                                    <Badge>
                                        <Lock className="size-3" aria-hidden /> Required
                                    </Badge>
                                )}
                                {c.fee && <Badge tone="info">Fee terms</Badge>}
                                <div className="ml-auto flex items-center gap-1">
                                    {c.optional && (
                                        <label className="mr-2 flex items-center gap-1.5 text-sm">
                                            <input type="checkbox" checked={c.enabled} onChange={(e) => set(i, { enabled: e.target.checked })} />{' '}
                                            Include
                                        </label>
                                    )}
                                    <Button variant="ghost" size="icon" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)}>
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Move down"
                                        disabled={i === cl.length - 1}
                                        onClick={() => move(i, 1)}
                                    >
                                        <ArrowDown />
                                    </Button>
                                    {!c.required && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove ${c.title}`}
                                            onClick={() =>
                                                form.setData(
                                                    'clauses',
                                                    cl.filter((_, k) => k !== i),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </div>
                            </div>
                            <Field label="Wording" className="mt-3" error={errors[`clauses.${i}.text`] ?? errors[`clauses.${i}.key`]}>
                                <Textarea rows={3} value={c.text} onFocus={() => setFocus(i)} onChange={(e) => set(i, { text: e.target.value })} />
                            </Field>
                        </Card>
                    ))}
                    <Card className="p-5">
                        <p className="text-sm font-medium">Insert a merge field into clause {focus + 1}</p>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {Object.entries(fields)
                                .filter(([k]) => industry.allowsFees || !feeFields.includes(k))
                                .map(([k, label]) => (
                                    <button
                                        key={k}
                                        type="button"
                                        title={label}
                                        className="rounded border border-line px-2 py-0.5 font-mono text-xs hover:border-primary"
                                        onClick={() => insert(k)}
                                    >
                                        {`{${k}}`}
                                    </button>
                                ))}
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() =>
                                    form.setData('clauses', [
                                        ...cl,
                                        {
                                            key: `custom_${cl.length + 1}`,
                                            title: 'New clause',
                                            text: '',
                                            required: false,
                                            optional: true,
                                            enabled: true,
                                            fee: false,
                                        },
                                    ])
                                }
                            >
                                <Plus /> Add clause
                            </Button>
                            <Button loading={form.processing} disabled={!dirty} onClick={() => form.put(scope.basePath, { preserveScroll: true })}>
                                Save as new version
                            </Button>
                            <Button variant="ghost" size="sm" onClick={() => router.delete(scope.basePath, { preserveScroll: true })}>
                                {scope.group ? 'Use the industry’s agreement' : 'Back to default wording'}
                            </Button>
                        </div>
                        <p className="mt-2 text-xs text-muted">
                            Saved versions wait for a super admin’s approval. Until then, members keep signing the last approved version.
                        </p>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card className="p-6">
                        <h2 className="font-semibold">In plain English</h2>
                        <p className="text-xs text-muted">Shown to both parties before they sign.</p>
                        <ul className="mt-3 list-disc space-y-1.5 pl-5 text-sm">
                            {summary(cl, sample, defaults).map((l) => (
                                <li key={l}>{l}</li>
                            ))}
                        </ul>
                    </Card>
                    <Card className="max-h-[520px] overflow-y-auto p-6">
                        <h2 className="font-semibold">Preview with sample data</h2>
                        <ol className="mt-3 space-y-3 text-sm">
                            {cl
                                .filter((c) => c.enabled)
                                .map((c, k) => (
                                    <li key={c.key + k}>
                                        <div className="font-medium">
                                            {k + 1}. {c.title}
                                        </div>
                                        <p className="text-muted">{fill(c.text, sample)}</p>
                                    </li>
                                ))}
                        </ol>
                    </Card>
                    <Card className="p-6">
                        <div className="flex items-center gap-2">
                            <h2 className="font-semibold">Versions</h2>
                            <Button size="sm" className="ml-auto" onClick={() => setApproving(true)}>
                                <ShieldCheck /> Approve current
                            </Button>
                        </div>
                        {history.length === 0 ? (
                            <p className="mt-2 text-sm text-muted">Default wording, never saved. Approving saves it as version 1.</p>
                        ) : (
                            <ul className="mt-3 space-y-2 text-sm">
                                {history.map((v) => (
                                    <li key={v.version} className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">v{v.version}</span>
                                        <span className="text-muted">{v.when}</span>
                                        {v.approved ? (
                                            <Badge tone="success">Approved{v.counsel ? ` · ${v.counsel}` : ''}</Badge>
                                        ) : (
                                            <Badge tone="warning">Awaiting approval</Badge>
                                        )}
                                        {v.active && <Badge tone="info">Draft</Badge>}
                                        <a
                                            className="ml-auto text-primary"
                                            href={
                                                scope.group
                                                    ? undefined
                                                    : `/admin/industries/${industry.slug}/agreement/preview.pdf?version=${v.version}`
                                            }
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            PDF
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {history.length > 1 && (
                            <div className="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4">
                                <Field label="Compare">
                                    <Select value={cmp.a} onChange={(e) => setCmp({ ...cmp, a: +e.target.value })}>
                                        {history.map((v) => (
                                            <option key={v.version} value={v.version}>
                                                v{v.version}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="with">
                                    <Select value={cmp.b} onChange={(e) => setCmp({ ...cmp, b: +e.target.value })}>
                                        {history.map((v) => (
                                            <option key={v.version} value={v.version}>
                                                v{v.version}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Button
                                    variant="secondary"
                                    onClick={() => router.get(scope.basePath, cmp, { preserveScroll: true, preserveState: true })}
                                >
                                    <GitCompare /> Compare
                                </Button>
                            </div>
                        )}
                        {compare && (
                            <div className="mt-4 space-y-3 text-sm">
                                {compare.length === 0 && <p className="text-muted">No differences.</p>}
                                {compare.map((d) => (
                                    <div key={d.key}>
                                        <div className="font-medium">
                                            {d.title} <Badge>{d.status}</Badge>
                                        </div>
                                        <p className="mt-1">
                                            {d.parts.map(([t, s], k) => (
                                                <span
                                                    key={k}
                                                    className={cn(
                                                        t === '+' && 'bg-[#ECFDF3] text-success dark:bg-success/20',
                                                        t === '-' && 'bg-[#FDECEE] text-danger line-through dark:bg-danger/20',
                                                    )}
                                                >
                                                    {s}
                                                </span>
                                            ))}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>
                    {!scope.group && (
                        <Card className="p-6">
                            <h2 className="font-semibold">State addenda</h2>
                            <p className="mt-1 text-sm text-muted">
                                Extra wording added automatically when the receiving team is in that state (for example, required license and
                                brokerage details).
                            </p>
                            <Field label="State" className="mt-3">
                                <Select
                                    value={stateId}
                                    onChange={(e) => {
                                        const id = +e.target.value;
                                        setStateId(id);
                                        addendum.setData('text', addenda.find((a) => a.stateId === id)?.text ?? '');
                                    }}
                                >
                                    {states.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                            {addenda.some((a) => a.stateId === s.id) ? ' ✓' : ''}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Addendum wording" hint="Leave empty to remove it." className="mt-3">
                                <Textarea rows={3} value={addendum.data.text} onChange={(e) => addendum.setData('text', e.target.value)} />
                            </Field>
                            <Button
                                className="mt-3"
                                variant="secondary"
                                loading={addendum.processing}
                                onClick={() =>
                                    addendum.put(`/admin/industries/${industry.slug}/agreement/addenda/${stateId}`, { preserveScroll: true })
                                }
                            >
                                Save addendum
                            </Button>
                        </Card>
                    )}
                </div>
            </div>

            <Dialog open={approving} onOpenChange={setApproving}>
                <DialogContent title="Approve this agreement" description="Members will sign this exact version from now on. Record who reviewed it.">
                    <div className="space-y-4">
                        <Field label="Reviewed by (attorney and firm)" error={approve.errors.counsel}>
                            <Input value={approve.data.counsel} onChange={(e) => approve.setData('counsel', e.target.value)} />
                        </Field>
                        <Field label="Approval note and any conditions" error={approve.errors.note}>
                            <Textarea rows={3} value={approve.data.note} onChange={(e) => approve.setData('note', e.target.value)} />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <Button variant="ghost" onClick={() => setApproving(false)}>
                                Cancel
                            </Button>
                            <Button
                                loading={approve.processing}
                                disabled={dirty}
                                onClick={() =>
                                    approve.post(`${scope.basePath}/approve`, {
                                        preserveScroll: true,
                                        onSuccess: () => setApproving(false),
                                    })
                                }
                            >
                                Approve
                            </Button>
                        </div>
                        {dirty && (
                            <p className="text-sm text-warning">Save your changes first, so the version you approve is the one you reviewed.</p>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
