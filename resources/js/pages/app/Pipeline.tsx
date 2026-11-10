import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Stage = { key: string; label: string; kind: 'open' | 'won' | 'lost' };
type CardT = { id: string; stage: string; client: string; territory: string; sender: string; days: number; flags: string[] };
type Props = { groups: { slug: string; name: string }[]; group: string | null; stages: Stage[]; cards: CardT[]; status?: string; seo: Seo };

/** Received referrals by stage: drag and drop on desktop, swipe between stages on a phone. */
export default function Pipeline({ groups, group, stages, cards, status, seo }: Props) {
    const { auth, errors } = usePage<SharedProps & { errors: Record<string, string> }>().props;
    const [attention, setAttention] = useState(false);
    const [over, setOver] = useState<string | null>(null);
    const [closing, setClosing] = useState<{ card: CardT; stage: Stage } | null>(null);
    const [form, setForm] = useState({ date: '', value: '', reason: '' });
    const shown = attention ? cards.filter((c) => c.flags.length > 0) : cards;
    const move = (card: CardT, key: string) => {
        const stage = stages.find((s) => s.key === key);
        if (!stage || key === card.stage) return;
        if (stage.kind !== 'open') {
            setForm({ date: '', value: '', reason: '' });
            setClosing({ card, stage });
            return;
        }
        router.post(`/app/referrals/${card.id}/stage`, { stage: key }, { preserveScroll: true });
    };
    return (
        <PortalLayout title="Pipeline" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4">
                    {statusMessage(status)}
                </Alert>
            )}
            {groups.length === 0 ? (
                <Card className="max-w-xl p-6 text-muted">Referrals you accept appear here, by stage.</Card>
            ) : (
                <>
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        {groups.length > 1 && (
                            <Select
                                aria-label="Network"
                                className="w-64"
                                value={group ?? ''}
                                onChange={(e) => router.get('/app/pipeline', { group: e.target.value })}
                            >
                                {groups.map((g) => (
                                    <option key={g.slug} value={g.slug}>
                                        {g.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={attention} onChange={(e) => setAttention(e.target.checked)} /> Needs attention only
                        </label>
                    </div>
                    <div className="-mx-5 flex snap-x snap-mandatory gap-4 overflow-x-auto px-5 pb-4 md:mx-0 md:px-0" aria-label="Stages">
                        {stages.map((s) => {
                            const col = shown.filter((c) => c.stage === s.key);
                            const hot = col.filter((c) => c.flags.length > 0).length;
                            return (
                                <section
                                    key={s.key}
                                    aria-label={s.label}
                                    className={cn(
                                        'w-[85vw] shrink-0 snap-center rounded-lg bg-bg p-3 md:w-72',
                                        over === s.key && 'ring-2 ring-primary',
                                    )}
                                    onDragOver={(e) => {
                                        e.preventDefault();
                                        setOver(s.key);
                                    }}
                                    onDragLeave={() => setOver(null)}
                                    onDrop={(e) => {
                                        e.preventDefault();
                                        setOver(null);
                                        const card = cards.find((c) => c.id === e.dataTransfer.getData('text/plain'));
                                        if (card) move(card, s.key);
                                    }}
                                >
                                    <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold">
                                        {s.label}
                                        <span className="text-muted tabular">{col.length}</span>
                                        {hot > 0 && (
                                            <Badge tone="warning">
                                                {hot} {hot === 1 ? 'needs' : 'need'} attention
                                            </Badge>
                                        )}
                                    </h2>
                                    <ul className="space-y-2">
                                        {col.map((c) => (
                                            <li
                                                key={c.id}
                                                draggable
                                                onDragStart={(e) => e.dataTransfer.setData('text/plain', c.id)}
                                                className="cursor-grab rounded-md border border-line bg-surface p-3 active:cursor-grabbing"
                                            >
                                                <Link href={`/app/referrals/${c.id}`} className="font-medium hover:underline">
                                                    {c.client}
                                                </Link>
                                                <div className="text-xs text-muted">
                                                    {c.territory} · from {c.sender} · {c.days}d in stage
                                                </div>
                                                {c.flags.map((f) => (
                                                    <div key={f} className="mt-1 flex items-center gap-1 text-xs text-warning">
                                                        <AlertTriangle className="size-3" aria-hidden /> {f}
                                                    </div>
                                                ))}
                                                <Select
                                                    aria-label={`Move ${c.client} to`}
                                                    className="mt-2 h-8 text-xs"
                                                    value=""
                                                    onChange={(e) => move(c, e.target.value)}
                                                >
                                                    <option value="">Move to…</option>
                                                    {stages
                                                        .filter((x) => x.key !== c.stage)
                                                        .map((x) => (
                                                            <option key={x.key} value={x.key}>
                                                                {x.label}
                                                            </option>
                                                        ))}
                                                </Select>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            );
                        })}
                    </div>
                </>
            )}
            {closing && (
                <div
                    className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 sm:items-center"
                    role="dialog"
                    aria-modal="true"
                    aria-label={closing.stage.label}
                >
                    <Card className="w-full max-w-md space-y-3 p-6">
                        <h2 className="font-semibold">
                            {closing.card.client}: {closing.stage.label}
                        </h2>
                        {closing.stage.kind === 'won' ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Closing date" error={errors.closing_date}>
                                    <Input type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
                                </Field>
                                <Field label="Value (USD)" error={errors.closing_value}>
                                    <Input type="number" min="0" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
                                </Field>
                            </div>
                        ) : (
                            <Field label="Why didn’t it go ahead?" error={errors.lost_reason}>
                                <Input value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
                            </Field>
                        )}
                        <div className="flex gap-2">
                            <Button
                                disabled={closing.stage.kind === 'won' ? !form.date || form.value === '' : !form.reason.trim()}
                                onClick={() =>
                                    router.post(
                                        `/app/referrals/${closing.card.id}/stage`,
                                        {
                                            stage: closing.stage.key,
                                            closing_date: form.date || null,
                                            closing_value: form.value || null,
                                            lost_reason: form.reason || null,
                                        },
                                        { preserveScroll: true, onSuccess: () => setClosing(null) },
                                    )
                                }
                            >
                                Confirm
                            </Button>
                            <Button variant="ghost" onClick={() => setClosing(null)}>
                                Cancel
                            </Button>
                        </div>
                    </Card>
                </div>
            )}
        </PortalLayout>
    );
}
