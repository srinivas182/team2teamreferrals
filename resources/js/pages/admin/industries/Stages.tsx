import { useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { TemplateShell, type TemplateScope } from '@/components/admin/TemplateShell';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/field';
import type { Seo } from '@/types/seo';

type Stage = { key: string; label: string; kind: 'open' | 'won' | 'lost' };
type Props = {
    scope: TemplateScope;
    industry: { slug: string; name: string };
    body: Stage[];
    source: string;
    version: number | null;
    history: { version: number; active: boolean; when: string }[];
    seo: Seo;
};

export default function Stages({ scope, industry, body, source, version, history, seo }: Props) {
    const form = useForm<{ body: Stage[]; acknowledge: boolean }>({ body, acknowledge: false });
    const stages = form.data.body;
    const set = (i: number, patch: Partial<Stage>) =>
        form.setData(
            'body',
            stages.map((s, j) => (j === i ? { ...s, ...patch } : s)),
        );
    const move = (i: number, d: -1 | 1) => {
        const next = [...stages];
        const [x] = next.splice(i, 1);
        next.splice(i + d, 0, x!);
        form.setData('body', next);
    };
    const save = (acknowledge: boolean) => {
        form.transform((d) => ({ ...d, acknowledge }));
        form.put(scope.basePath, { preserveScroll: true });
    };
    return (
        <TemplateShell
            title={`Pipeline · ${industry.name}`}
            scope={scope}
            source={source}
            version={version}
            history={history}
            onSave={save}
            saving={form.processing}
        >
            <SeoHead seo={seo} />
            <Card className="max-w-3xl">
                <ul className="divide-y divide-line">
                    {stages.map((s, i) => (
                        <li key={i} className="flex flex-wrap items-center gap-3 p-3">
                            <span className="w-6 text-center font-display text-sm font-bold text-muted">{i + 1}</span>
                            <Input
                                aria-label="Stage name"
                                value={s.label}
                                className="min-w-40 flex-1"
                                onChange={(e) => set(i, { label: e.target.value })}
                            />
                            <Select
                                aria-label="Stage kind"
                                value={s.kind}
                                className="w-36"
                                onChange={(e) => set(i, { kind: e.target.value as Stage['kind'] })}
                            >
                                <option value="open">Open</option>
                                <option value="won">Won (closed)</option>
                                <option value="lost">Lost (closed)</option>
                            </Select>
                            <span className="flex gap-1">
                                <Button size="icon" variant="ghost" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)}>
                                    <ArrowUp />
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Move down"
                                    disabled={i === stages.length - 1}
                                    onClick={() => move(i, 1)}
                                >
                                    <ArrowDown />
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`Remove ${s.label}`}
                                    onClick={() =>
                                        form.setData(
                                            'body',
                                            stages.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
                <div className="border-t border-line p-3">
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => {
                            let key = 'stage';
                            let n = 1;
                            while (stages.some((s) => s.key === key)) key = `stage_${++n}`;
                            const firstClosed = stages.findIndex((s) => s.kind !== 'open');
                            const at = firstClosed === -1 ? stages.length : firstClosed;
                            form.setData('body', [...stages.slice(0, at), { key, label: 'New stage', kind: 'open' }, ...stages.slice(at)]);
                        }}
                    >
                        <Plus /> Add a stage
                    </Button>
                </div>
            </Card>
            <h2 className="mt-8 mb-3 font-semibold">How the board will look</h2>
            <div className="flex gap-3 overflow-x-auto pb-2">
                {stages.map((s, i) => (
                    <div key={i} className="w-48 shrink-0 rounded-lg border border-line bg-bg p-3">
                        <div className="flex items-center justify-between text-sm font-semibold">
                            {s.label}
                            {s.kind !== 'open' && <Badge tone={s.kind === 'won' ? 'success' : 'danger'}>{s.kind === 'won' ? 'Won' : 'Lost'}</Badge>}
                        </div>
                        <div className="mt-3 h-14 rounded-md border border-dashed border-line" />
                    </div>
                ))}
            </div>
        </TemplateShell>
    );
}
