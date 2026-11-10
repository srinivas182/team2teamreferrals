import { router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Lock, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type S = { key: string; label: string; kind: string; client_label?: string | null; limit_days?: number | string | null };
type Props = {
    group: { slug: string; name: string; locked: boolean };
    stages: S[];
    industryStages: S[];
    counts: Record<string, number>;
    isAdmin: boolean;
    status?: string;
    seo: Seo;
};

const slug = (t: string) =>
    t
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 30) || 'stage';

/** Group owners shape their network's stages; referrals in a removed stage go where the owner chooses. */
export default function GroupPipeline({ group, stages: initial, industryStages, counts, isAdmin, status, seo }: Props) {
    const { auth, errors } = usePage<SharedProps & { errors: Record<string, string | string[]> }>().props;
    const [stages, setStages] = useState<S[]>(initial);
    const [moves, setMoves] = useState<Record<string, string>>({});
    const disabled = group.locked && !isAdmin;
    const keys = stages.map((s) => s.key);
    const removed = Object.entries(counts).filter(([k, n]) => n > 0 && !keys.includes(k));
    const set = (i: number, p: Partial<S>) => setStages(stages.map((s, k) => (k === i ? { ...s, ...p } : s)));
    const swap = (i: number, j: number) => {
        const next = [...stages];
        [next[i], next[j]] = [next[j]!, next[i]!];
        setStages(next);
    };
    const errList = ([] as string[]).concat(errors.stages ?? [], errors.moves ?? []);
    return (
        <PortalLayout title={`${group.name}: pipeline stages`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}
            {group.locked && (
                <Alert tone="info" className="mb-4 max-w-4xl">
                    <Lock className="mr-1 inline size-4" aria-hidden /> The platform has locked this pipeline.
                    {isAdmin ? ' You can still edit it as an admin.' : ''}
                </Alert>
            )}
            {errList.length > 0 && (
                <Alert tone="danger" className="mb-4 max-w-4xl">
                    {errList.map((e) => (
                        <div key={e}>{e}</div>
                    ))}
                </Alert>
            )}
            <Card className="max-w-4xl overflow-x-auto p-0">
                <table className="w-full min-w-[760px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['', 'Stage name', 'Kind', 'What the client sees', 'Time limit (days)', 'Referrals', ''].map((h, i) => (
                                <th key={i} scope="col" className="px-3 py-2 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {stages.map((s, i) => (
                            <tr key={s.key} className="border-t border-line">
                                <td className="px-3 py-2">
                                    <div className="flex flex-col">
                                        <button aria-label="Move up" disabled={disabled || i === 0} onClick={() => swap(i, i - 1)}>
                                            <ArrowUp className="size-4" />
                                        </button>
                                        <button aria-label="Move down" disabled={disabled || i === stages.length - 1} onClick={() => swap(i, i + 1)}>
                                            <ArrowDown className="size-4" />
                                        </button>
                                    </div>
                                </td>
                                <td className="px-3 py-2">
                                    <Input
                                        aria-label="Stage name"
                                        disabled={disabled}
                                        value={s.label}
                                        onChange={(e) => set(i, { label: e.target.value })}
                                    />
                                </td>
                                <td className="px-3 py-2">
                                    <Select aria-label="Kind" disabled={disabled} value={s.kind} onChange={(e) => set(i, { kind: e.target.value })}>
                                        <option value="open">In progress</option>
                                        <option value="won">Won</option>
                                        <option value="lost">Lost</option>
                                    </Select>
                                </td>
                                <td className="px-3 py-2">
                                    <Input
                                        aria-label="Client-friendly name"
                                        placeholder={s.label}
                                        disabled={disabled}
                                        value={s.client_label ?? ''}
                                        onChange={(e) => set(i, { client_label: e.target.value })}
                                    />
                                </td>
                                <td className="px-3 py-2">
                                    <Input
                                        aria-label="Time limit in days"
                                        type="number"
                                        min="1"
                                        max="90"
                                        className="w-20"
                                        disabled={disabled || s.kind !== 'open'}
                                        value={s.limit_days ?? ''}
                                        onChange={(e) => set(i, { limit_days: e.target.value })}
                                    />
                                </td>
                                <td className="px-3 py-2 text-muted tabular">{counts[s.key] ?? 0}</td>
                                <td className="px-3 py-2">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remove ${s.label}`}
                                        disabled={disabled}
                                        onClick={() => setStages(stages.filter((_, k) => k !== i))}
                                    >
                                        <Trash2 />
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
            {removed.length > 0 && (
                <Card className="mt-4 max-w-4xl space-y-2 p-5">
                    <h2 className="font-semibold">Where should these referrals go?</h2>
                    {removed.map(([k, n]) => (
                        <div key={k} className="flex items-center gap-3 text-sm">
                            <span className="w-64">
                                {n} referral(s) in the removed “{initial.find((s) => s.key === k)?.label ?? k}”
                            </span>
                            <Select
                                aria-label={`Move referrals from ${k}`}
                                className="w-60"
                                value={moves[k] ?? ''}
                                onChange={(e) => setMoves({ ...moves, [k]: e.target.value })}
                            >
                                <option value="">Choose a stage…</option>
                                {stages
                                    .filter((s) => s.kind === 'open')
                                    .map((s) => (
                                        <option key={s.key} value={s.key}>
                                            {s.label}
                                        </option>
                                    ))}
                            </Select>
                        </div>
                    ))}
                </Card>
            )}
            <div className="mt-4 flex max-w-4xl flex-wrap gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    disabled={disabled}
                    onClick={() => {
                        let key = slug('new stage');
                        while (keys.includes(key)) key += '_x';
                        const won = stages.findIndex((s) => s.kind !== 'open');
                        const next = [...stages];
                        next.splice(won < 0 ? next.length : won, 0, { key, label: 'New stage', kind: 'open' });
                        setStages(next);
                    }}
                >
                    <Plus /> Add stage
                </Button>
                <Button variant="ghost" size="sm" disabled={disabled} onClick={() => setStages(industryStages)}>
                    Use the industry’s stages
                </Button>
                <Button
                    className="ml-auto"
                    disabled={disabled || removed.some(([k]) => !moves[k])}
                    onClick={() => router.put(`/app/group/${group.slug}/pipeline`, { stages, moves }, { preserveScroll: true })}
                >
                    Save pipeline
                </Button>
                {isAdmin && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.post(`/app/group/${group.slug}/pipeline/lock`, { locked: !group.locked }, { preserveScroll: true })}
                    >
                        <Lock /> {group.locked ? 'Unlock' : 'Lock'} pipeline
                    </Button>
                )}
            </div>
        </PortalLayout>
    );
}
