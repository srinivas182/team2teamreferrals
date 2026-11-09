import { useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Lock, Monitor, Plus, Smartphone, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { TemplateShell } from '@/components/admin/TemplateShell';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Segmented } from '@/components/ui/data';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

export type FormField = {
    key: string;
    label: string;
    type: string;
    required: boolean;
    sensitive: boolean;
    options?: string[];
    role?: string;
    help?: string;
};
type Props = {
    industry: { slug: string; name: string; family: string; terminology: { pro: string; client: string } };
    body: FormField[];
    source: string;
    version: number | null;
    history: { version: number; active: boolean; when: string }[];
    fieldTypes: Record<string, string>;
    roles: Record<string, string>;
    seo: Seo;
};

const slugify = (s: string) =>
    s
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_|_$/g, '')
        .slice(0, 40) || 'field';

/** What a sender sees. The same component will render the real form in Sprint 16. */
export function FormPreview({ fields, client }: { fields: FormField[]; client: string }) {
    return (
        <div className="space-y-4">
            {fields.map((f) => (
                <Field key={f.key} label={f.label.replace(/client/gi, client) + (f.required ? '' : ' (optional)')} hint={f.help}>
                    {f.type === 'textarea' ? (
                        <Textarea rows={3} />
                    ) : f.type === 'choice' ? (
                        <Select defaultValue="">
                            <option value="" disabled>
                                Choose…
                            </option>
                            {(f.options ?? []).map((o) => (
                                <option key={o}>{o}</option>
                            ))}
                        </Select>
                    ) : f.type === 'multi_choice' ? (
                        <div className="flex flex-wrap gap-2">
                            {(f.options ?? []).map((o) => (
                                <label key={o} className="flex items-center gap-1.5 rounded-full border border-line px-3 py-1 text-sm">
                                    <input type="checkbox" /> {o}
                                </label>
                            ))}
                        </div>
                    ) : f.type === 'boolean' ? (
                        <div className="flex gap-2">
                            {['Yes', 'No'].map((o) => (
                                <label key={o} className="flex items-center gap-1.5 rounded-md border border-line px-3 py-2 text-sm">
                                    <input type="radio" name={f.key} /> {o}
                                </label>
                            ))}
                        </div>
                    ) : f.type === 'money_range' ? (
                        <div className="grid grid-cols-2 gap-2">
                            <Input inputMode="numeric" placeholder="$ from" />
                            <Input inputMode="numeric" placeholder="$ to" />
                        </div>
                    ) : (
                        <Input type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'} />
                    )}
                </Field>
            ))}
        </div>
    );
}

export default function FormBuilder({ industry, body, source, version, history, fieldTypes, roles, seo }: Props) {
    const form = useForm<{ body: FormField[]; acknowledge: boolean }>({ body, acknowledge: false });
    const [device, setDevice] = useState<'phone' | 'desktop'>('phone');
    const fields = form.data.body;
    // Saved referrals store answers by key, so existing keys never change; new questions take theirs from the label.
    const [originalKeys] = useState(() => new Set(body.map((f) => f.key)));
    const rename = (i: number, label: string) => {
        const f = fields[i]!;
        if (originalKeys.has(f.key)) return set(i, { label });
        let key = slugify(label);
        while (fields.some((g, j) => j !== i && g.key === key)) key += '_2';
        set(i, { label, key });
    };
    const set = (i: number, patch: Partial<FormField>) =>
        form.setData(
            'body',
            fields.map((f, j) => (j === i ? { ...f, ...patch } : f)),
        );
    const move = (i: number, d: -1 | 1) => {
        const next = [...fields];
        const [x] = next.splice(i, 1);
        next.splice(i + d, 0, x!);
        form.setData('body', next);
    };
    const save = (acknowledge: boolean) => {
        form.transform((d) => ({ ...d, acknowledge }));
        form.put(`/admin/industries/${industry.slug}/form`, { preserveScroll: true });
    };
    return (
        <TemplateShell
            title={`Referral form · ${industry.name}`}
            slug={industry.slug}
            kind="form"
            source={source}
            version={version}
            history={history}
            onSave={save}
            saving={form.processing}
        >
            <SeoHead seo={seo} />
            <div className="grid gap-6 xl:grid-cols-[1.2fr_1fr]">
                <div className="space-y-3">
                    {fields.map((f, i) => (
                        <Card key={i} className="p-4">
                            <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                                <Field label="Question">
                                    <Input value={f.label} onChange={(e) => rename(i, e.target.value)} />
                                </Field>
                                <Field label="Type">
                                    <Select
                                        value={f.type}
                                        onChange={(e) =>
                                            set(i, {
                                                type: e.target.value,
                                                options: ['choice', 'multi_choice'].includes(e.target.value) ? (f.options ?? ['', '']) : undefined,
                                            })
                                        }
                                    >
                                        {Object.entries(fieldTypes).map(([k, v]) => (
                                            <option key={k} value={k}>
                                                {v}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            </div>
                            {['choice', 'multi_choice'].includes(f.type) && (
                                <Field label="Options (one per line)" className="mt-3">
                                    <Textarea
                                        rows={3}
                                        value={(f.options ?? []).join('\n')}
                                        onChange={(e) => set(i, { options: e.target.value.split('\n') })}
                                    />
                                </Field>
                            )}
                            <div className="mt-3 flex flex-wrap items-center gap-4 text-sm">
                                <label className="flex items-center gap-1.5">
                                    <input type="checkbox" checked={f.required} onChange={(e) => set(i, { required: e.target.checked })} /> Required
                                </label>
                                <label className="flex items-center gap-1.5">
                                    <input type="checkbox" checked={f.sensitive} onChange={(e) => set(i, { sensitive: e.target.checked })} />{' '}
                                    <Lock className="size-3.5" aria-hidden /> Sensitive (never public)
                                </label>
                                <Select
                                    aria-label="Role"
                                    value={f.role ?? ''}
                                    onChange={(e) => set(i, { role: e.target.value || undefined })}
                                    className="h-9 w-auto"
                                >
                                    <option value="">No special role</option>
                                    {Object.entries(roles).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v.split(' (')[0]}
                                        </option>
                                    ))}
                                </Select>
                                <span className="font-mono text-xs text-subtle">{f.key}</span>
                                <span className="ml-auto flex gap-1">
                                    <Button size="icon" variant="ghost" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)}>
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Move down"
                                        disabled={i === fields.length - 1}
                                        onClick={() => move(i, 1)}
                                    >
                                        <ArrowDown />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Remove ${f.label}`}
                                        onClick={() =>
                                            form.setData(
                                                'body',
                                                fields.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </span>
                            </div>
                        </Card>
                    ))}
                    <Button
                        variant="secondary"
                        onClick={() => {
                            const label = 'New question';
                            let key = slugify(label);
                            while (fields.some((f) => f.key === key)) key += '_2';
                            form.setData('body', [
                                ...fields.slice(0, -1),
                                { key, label, type: 'text', required: false, sensitive: false },
                                ...fields.slice(-1),
                            ]);
                        }}
                    >
                        <Plus /> Add a question
                    </Button>
                </div>
                <div className="xl:sticky xl:top-20 xl:self-start">
                    <div className="mb-3 flex items-center gap-3">
                        <h2 className="font-semibold">What senders see</h2>
                        <Badge>{fields.filter((f) => f.required).length} required</Badge>
                        <div className="ml-auto">
                            <Segmented
                                label="Preview size"
                                value={device}
                                onChange={setDevice}
                                options={[
                                    ['phone', 'Phone'],
                                    ['desktop', 'Desktop'],
                                ]}
                            />
                        </div>
                    </div>
                    <div
                        className={cn(
                            'mx-auto overflow-y-auto rounded-2xl border border-line bg-surface p-5 shadow-md',
                            device === 'phone' ? 'max-h-[640px] max-w-[375px]' : 'max-h-[640px]',
                        )}
                    >
                        <div className="mb-4 flex items-center gap-2 text-xs text-muted">
                            {device === 'phone' ? <Smartphone className="size-4" aria-hidden /> : <Monitor className="size-4" aria-hidden />}
                            Send a {industry.terminology.client} to a {industry.terminology.pro}
                        </div>
                        <FormPreview fields={fields} client={industry.terminology.client} />
                    </div>
                </div>
            </div>
        </TemplateShell>
    );
}
