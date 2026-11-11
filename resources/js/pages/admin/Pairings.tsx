import { useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Props = {
    industries: { id: number; name: string; tier: string }[];
    rules: { id: number; from: string; to: string; allowed: boolean; note: string }[];
    status?: string;
    seo: Seo;
};

/** Admin → Introduction pairings: allow or block specific industry pairs, each with a counsel note. */
export default function Pairings({ industries, rules, status, seo }: Props) {
    const f = useForm({ from: '', to: '', allowed: false, note: '' });
    const sel = 'h-10 w-full rounded-md border border-line bg-surface px-2';
    return (
        <AdminLayout title="Introduction pairings">
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4">
                    {statusMessage(status)}
                </Alert>
            )}
            <Card className="mb-6 max-w-3xl space-y-3 p-6">
                <p className="text-sm text-muted">
                    All pairs are allowed unless blocked here. Fees apply only when both industries allow fees; every other introduction is credited,
                    with no fee.
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="From industry" error={f.errors.from}>
                        <select className={sel} value={f.data.from} onChange={(e) => f.setData('from', e.target.value)}>
                            <option value="">Choose…</option>
                            {industries.map((i) => (
                                <option key={i.id} value={i.id}>
                                    {i.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="To industry">
                        <select className={sel} value={f.data.to} onChange={(e) => f.setData('to', e.target.value)}>
                            <option value="">Choose…</option>
                            {industries.map((i) => (
                                <option key={i.id} value={i.id}>
                                    {i.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={f.data.allowed} onChange={(e) => f.setData('allowed', e.target.checked)} /> Allowed (untick to
                    block)
                </label>
                <Field label="Counsel note" error={f.errors.note}>
                    <Input value={f.data.note} onChange={(e) => f.setData('note', e.target.value)} />
                </Field>
                <Button
                    disabled={!f.data.from || !f.data.to}
                    onClick={() => f.post('/admin/pairings', { preserveScroll: true, onSuccess: () => f.reset() })}
                >
                    Save rule
                </Button>
            </Card>
            <Card className="max-w-3xl p-6">
                <h2 className="mb-2 font-semibold">Rules</h2>
                {rules.length === 0 && <p className="text-sm text-muted">No pairs blocked.</p>}
                <ul className="divide-y divide-line text-sm">
                    {rules.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-center gap-2 py-2">
                            <span className="font-medium">
                                {r.from} → {r.to}
                            </span>
                            <Badge tone={r.allowed ? 'success' : 'danger'}>{r.allowed ? 'Allowed' : 'Blocked'}</Badge>
                            <span className="text-muted">{r.note}</span>
                        </li>
                    ))}
                </ul>
            </Card>
        </AdminLayout>
    );
}
